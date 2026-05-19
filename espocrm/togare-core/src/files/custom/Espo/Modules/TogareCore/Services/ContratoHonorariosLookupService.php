<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services;

use DateTimeImmutable;
use Espo\Modules\TogareCore\Entities\ContratoHonorarios;
use Espo\ORM\EntityManager;

/**
 * Lookup de ContratoHonorarios vigente — API estável consumida por:
 *  - Story 6.2 (GateBanner cobrança sem contrato) — `hasContratoVigente`
 *  - Story 6.3 (Fatura linkando ContratoHonorarios) — `findContratosVigentes`
 *
 * Regra de "vigente" (Decisão #8 da Story 6.1):
 *   `vigenciaInicio <= reference AND (vigenciaFim IS NULL OR vigenciaFim >= reference)`
 *
 * `processoId` opcional: quando informado, restringe a contratos que linkam
 * aquele processo OU contratos sem processos vinculados (fallback genérico do
 * cliente — coerente com `0..N processos` da spec L1263).
 *
 * O service NÃO impõe ACL — caller (Controller/Hook) é responsável por
 * checkScope/checkEntity. Service é puro lookup de domínio.
 */
/**
 * NÃO é `final` intencionalmente: ValidateFaturaFieldsHookTest (Story 6.3)
 * precisa mockar `hasContratoVigente` para testar o gate FR23 backend sem
 * carregar a entity ContratoHonorarios real.
 */
class ContratoHonorariosLookupService
{
    public function __construct(
        private readonly EntityManager $entityManager,
    ) {
    }

    /**
     * Retorna true se houver pelo menos 1 ContratoHonorarios vigente para o cliente
     * (opcionalmente restrito ao processo informado).
     */
    public function hasContratoVigente(
        string $clienteId,
        ?string $processoId = null,
        ?DateTimeImmutable $reference = null,
    ): bool {
        if ($clienteId === '') {
            return false;
        }

        $contratos = $this->findContratosVigentes($clienteId, $processoId, $reference);

        return $contratos !== [];
    }

    /**
     * Retorna lista de ContratoHonorarios vigentes (deleted=0) para o cliente.
     * Pode ser usada para construir mensagem do GateBanner (qual contrato existe,
     * qual modalidade, etc.) OU para popular dropdown de Fatura.
     *
     * Restrição quando $processoId informado:
     *  - contratos que linkam aquele $processoId via N:N OU
     *  - contratos SEM processos vinculados (cobertura genérica do cliente).
     *
     * @return list<ContratoHonorarios>
     */
    public function findContratosVigentes(
        string $clienteId,
        ?string $processoId = null,
        ?DateTimeImmutable $reference = null,
    ): array {
        if ($clienteId === '') {
            return [];
        }

        $reference = $reference ?? new DateTimeImmutable('today');
        $refDate = $reference->format('Y-m-d');

        $repository = $this->entityManager->getRDBRepository(ContratoHonorarios::ENTITY_TYPE);

        $collection = $repository->where([
            'clienteId' => $clienteId,
            'deleted' => false,
            'vigenciaInicio<=' => $refDate,
            'OR' => [
                'vigenciaFim' => null,
                'vigenciaFim>=' => $refDate,
            ],
        ])->order('createdAt', 'DESC')->find();

        $contratos = [];
        foreach ($collection as $entity) {
            if ($entity instanceof ContratoHonorarios) {
                $contratos[] = $entity;
            }
        }

        if ($processoId === null || $processoId === '') {
            return $contratos;
        }

        // Filtra por processo: mantém contratos com aquele processo linkado OR
        // contratos sem processos vinculados (genéricos do cliente).
        $filtered = [];
        foreach ($contratos as $contrato) {
            if ($this->contratoCobreProcesso($contrato, $processoId)) {
                $filtered[] = $contrato;
            }
        }

        return $filtered;
    }

    private function contratoCobreProcesso(ContratoHonorarios $contrato, string $processoId): bool
    {
        try {
            $processos = $this->entityManager
                ->getRDBRepository(ContratoHonorarios::ENTITY_TYPE)
                ->getRelation($contrato, 'processos')
                ->find();
        } catch (\Throwable) {
            return false;
        }

        $ids = [];
        foreach ($processos as $processo) {
            $id = (string) $processo->getId();
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids === [] || \in_array($processoId, $ids, true);
    }
}
