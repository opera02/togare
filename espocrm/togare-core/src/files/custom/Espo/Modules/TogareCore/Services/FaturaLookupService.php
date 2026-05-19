<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services;

use DateTimeImmutable;
use Espo\Modules\TogareCore\Entities\Fatura;
use Espo\ORM\EntityManager;

/**
 * Lookup de Faturas em aberto / vencidas — API estável (Story 6.3 Decisão #10
 * + AC5).
 *
 * Consumidores planejados:
 *  - Story 6.2 (GateBanner cobrança sem contrato — pode também sinalizar "tem
 *    fatura em aberto" no painel do cliente).
 *  - Story 10.1 (Briefing do dia 8 configs por role — sócio/admin vê
 *    `hasFaturasEmAberto()` como sinal no dashboard matinal).
 *
 * O service NÃO impõe ACL — caller (Controller/Hook/View) é responsável por
 * checkScope/checkEntity. Pattern ContratoHonorariosLookupService.
 */
final class FaturaLookupService
{
    public function __construct(
        private readonly EntityManager $entityManager,
    ) {
    }

    /**
     * Retorna Faturas em aberto (status ∈ {emitida, parcialmente_paga, vencida})
     * para um cliente, ordenadas por dataVencimento ASC (mais próximas primeiro).
     *
     * @return list<Fatura>
     */
    public function findEmAbertoByCliente(string $clienteId): array
    {
        if ($clienteId === '') {
            return [];
        }

        $repository = $this->entityManager->getRDBRepository(Fatura::ENTITY_TYPE);

        $collection = $repository->where([
            'clienteId' => $clienteId,
            'deleted' => false,
            'status' => Fatura::STATUSES_OPEN,
        ])->order('dataVencimento', 'ASC')->find();

        $faturas = [];
        foreach ($collection as $entity) {
            if ($entity instanceof Fatura) {
                $faturas[] = $entity;
            }
        }

        return $faturas;
    }

    /**
     * Retorna Faturas vencidas (status=vencida) em uma data de referência.
     * Útil para relatórios e alertas administrativos.
     *
     * @return list<Fatura>
     */
    public function findVencidas(?DateTimeImmutable $reference = null): array
    {
        $repository = $this->entityManager->getRDBRepository(Fatura::ENTITY_TYPE);

        $collection = $repository->where([
            'deleted' => false,
            'status' => Fatura::STATUS_VENCIDA,
        ])->order('dataVencimento', 'ASC')->find();

        $faturas = [];
        foreach ($collection as $entity) {
            if ($entity instanceof Fatura) {
                $faturas[] = $entity;
            }
        }

        return $faturas;
    }

    /**
     * Indica se o cliente tem ao menos uma fatura em aberto.
     * Usado pelo briefing do Sócio/Admin (Story 10.1) e potencialmente pelo
     * painel do cliente como sinal visual rápido.
     */
    public function hasFaturasEmAberto(string $clienteId): bool
    {
        return $this->findEmAbertoByCliente($clienteId) !== [];
    }
}
