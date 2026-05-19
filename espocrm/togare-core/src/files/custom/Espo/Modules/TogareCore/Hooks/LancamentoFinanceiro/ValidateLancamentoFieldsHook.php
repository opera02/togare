<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\LancamentoFinanceiro;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\TogareCore\Entities\Fatura;
use Espo\Modules\TogareCore\Entities\LancamentoFinanceiro;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Valida campos do LancamentoFinanceiro — Story 6.3 Decisão #7 + #8.
 *
 * Validações (na ordem):
 *  1. tipo obrigatório + entre os 6 canônicos.
 *  2. valor > 0.
 *  3. dataMovimento obrigatória.
 *  4. **Coerência tipo×fatura (Decisão #7):**
 *     - pagamento_total | pagamento_parcial | estorno → fatura obrigatória.
 *     - despesa_interna | receita_avulsa | acerto → fatura PROIBIDA.
 *  5. **formaPagamento (Decisão #8):**
 *     - tipo ∈ pagamentos/estorno → formaPagamento obrigatória.
 *     - tipo ∈ avulsos → formaPagamento PROIBIDA.
 *  6. fatura.status != cancelada (bloqueia lançamento em fatura cancelada).
 *  7. Cliente herdado de fatura.cliente quando fatura presente.
 *  8. Pagamento_total exige valor == fatura.saldo no momento.
 *  9. Pagamento_parcial exige 0 < valor < fatura.saldo.
 *  10. Estorno exige valor <= fatura.valorPago.
 *  11. Processo cross-cliente bloqueado.
 *
 * Order = 10 (executa antes de DefaultAssignment=15 + RecomputeFaturaSaldo=20
 * + Audit=50).
 *
 * @implements BeforeSave<LancamentoFinanceiro>
 */
final class ValidateLancamentoFieldsHook implements BeforeSave
{
    public static int $order = 10;

    public function __construct(
        private readonly EntityManager $entityManager,
    ) {
    }

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (! $entity instanceof LancamentoFinanceiro) {
            return;
        }

        $this->validateTipo($entity);
        $this->validateValor($entity);
        $this->validateDataMovimento($entity);
        $this->validateTipoVsFatura($entity);
        $this->validateFormaPagamento($entity);
        $this->validateFaturaNaoCancelada($entity);
        $this->inheritClienteFromFatura($entity);
        $this->validateValorContraSaldo($entity);
        $this->validateProcessoCrossCliente($entity);
    }

    private function validateTipo(LancamentoFinanceiro $entity): void
    {
        $tipo = (string) ($entity->get('tipo') ?? '');
        if ($tipo === '') {
            throw new BadRequest('Tipo do lançamento é obrigatório.');
        }
        if (! \in_array($tipo, LancamentoFinanceiro::TIPOS, true)) {
            throw new BadRequest('Tipo do lançamento inválido.');
        }
    }

    private function validateValor(LancamentoFinanceiro $entity): void
    {
        $valor = (float) ($entity->get('valor') ?? 0.0);
        if ($valor <= 0) {
            throw new BadRequest('Valor deve ser maior que zero.');
        }
    }

    private function validateDataMovimento(LancamentoFinanceiro $entity): void
    {
        $data = (string) ($entity->get('dataMovimento') ?? '');
        if ($data === '') {
            throw new BadRequest('Data do movimento é obrigatória.');
        }
    }

    private function validateTipoVsFatura(LancamentoFinanceiro $entity): void
    {
        $tipo = (string) $entity->get('tipo');
        $faturaId = (string) ($entity->get('faturaId') ?? '');

        if (\in_array($tipo, LancamentoFinanceiro::TIPOS_COM_FATURA, true) && $faturaId === '') {
            throw new BadRequest('Pagamentos e estornos exigem fatura vinculada.');
        }

        if (\in_array($tipo, LancamentoFinanceiro::TIPOS_AVULSOS, true) && $faturaId !== '') {
            throw new BadRequest('Este tipo de lançamento não admite fatura vinculada — desvincule a fatura ou troque o tipo.');
        }
    }

    private function validateFormaPagamento(LancamentoFinanceiro $entity): void
    {
        $tipo = (string) $entity->get('tipo');
        $forma = (string) ($entity->get('formaPagamento') ?? '');
        $exigeForma = \in_array($tipo, LancamentoFinanceiro::TIPOS_COM_FATURA, true);

        if ($exigeForma && ($forma === '' || ! \in_array($forma, LancamentoFinanceiro::FORMAS_PAGAMENTO, true))) {
            throw new BadRequest('Forma de pagamento é obrigatória para pagamentos e estornos.');
        }

        if (! $exigeForma && $forma !== '') {
            throw new BadRequest('Forma de pagamento não se aplica a lançamentos avulsos (despesa, receita ou acerto).');
        }
    }

    private function validateFaturaNaoCancelada(LancamentoFinanceiro $entity): void
    {
        $faturaId = (string) ($entity->get('faturaId') ?? '');
        if ($faturaId === '') {
            return;
        }

        $fatura = $this->entityManager
            ->getRDBRepository(Fatura::ENTITY_TYPE)
            ->getById($faturaId);
        if (! $fatura instanceof Fatura) {
            throw new BadRequest('Fatura informada não encontrada.');
        }

        if ((string) $fatura->get('status') === Fatura::STATUS_CANCELADA) {
            throw new BadRequest('Não é possível registrar lançamento em fatura cancelada.');
        }
    }

    private function inheritClienteFromFatura(LancamentoFinanceiro $entity): void
    {
        $faturaId = (string) ($entity->get('faturaId') ?? '');
        if ($faturaId === '') {
            // Avulso — cliente deve ser informado explicitamente
            $clienteId = (string) ($entity->get('clienteId') ?? '');
            if ($clienteId === '') {
                throw new BadRequest('Cliente é obrigatório.');
            }
            return;
        }

        $fatura = $this->entityManager
            ->getRDBRepository(Fatura::ENTITY_TYPE)
            ->getById($faturaId);
        if (! $fatura instanceof Fatura) {
            return;
        }

        $clienteFatura = (string) ($fatura->get('clienteId') ?? '');
        if ($clienteFatura === '') {
            return;
        }

        $clienteLanc = (string) ($entity->get('clienteId') ?? '');
        if ($clienteLanc === '') {
            $entity->set('clienteId', $clienteFatura);
            return;
        }

        if ($clienteLanc !== $clienteFatura) {
            throw new BadRequest('Cliente do lançamento deve ser o mesmo da fatura vinculada.');
        }
    }

    private function validateValorContraSaldo(LancamentoFinanceiro $entity): void
    {
        $tipo = (string) $entity->get('tipo');
        $faturaId = (string) ($entity->get('faturaId') ?? '');
        if ($faturaId === '' || ! \in_array($tipo, LancamentoFinanceiro::TIPOS_COM_FATURA, true)) {
            return;
        }

        $fatura = $this->entityManager
            ->getRDBRepository(Fatura::ENTITY_TYPE)
            ->getById($faturaId);
        if (! $fatura instanceof Fatura) {
            return;
        }

        $valor = (float) ($entity->get('valor') ?? 0.0);
        $saldo = (float) ($fatura->get('saldo') ?? 0.0);
        $valorPago = (float) ($fatura->get('valorPago') ?? 0.0);

        // Em edição, descontar o valor anterior do lançamento (se já contribuía pro saldo)
        if (! $entity->isNew()) {
            $valorAnterior = (float) ($entity->getFetched('valor') ?? 0.0);
            $tipoAnterior = (string) $entity->getFetched('tipo');
            if (\in_array($tipoAnterior, LancamentoFinanceiro::TIPOS_DE_PAGAMENTO, true)) {
                $saldo += $valorAnterior; // libera o saldo "ocupado" pelo lançamento atual
                $valorPago -= $valorAnterior;
            } elseif ($tipoAnterior === LancamentoFinanceiro::TIPO_ESTORNO) {
                $valorPago += $valorAnterior; // estorno anterior tirava de valorPago; reverte
                $saldo -= $valorAnterior;     // estorno anterior aumentava saldo; reverte (P7)
            }
        }

        if ($tipo === LancamentoFinanceiro::TIPO_PAGAMENTO_TOTAL) {
            if (\abs($valor - $saldo) > 0.001) {
                throw new BadRequest(
                    \sprintf(
                        'Pagamento total exige valor exatamente igual ao saldo da fatura (R$ %s). Use pagamento parcial se quiser pagar valor diferente.',
                        \number_format($saldo, 2, ',', '.')
                    )
                );
            }
        } elseif ($tipo === LancamentoFinanceiro::TIPO_PAGAMENTO_PARCIAL) {
            if ($valor > $saldo + 0.001) {
                throw new BadRequest(
                    \sprintf(
                        'O valor do pagamento (R$ %s) excede o saldo atual da fatura (R$ %s).',
                        \number_format($valor, 2, ',', '.'),
                        \number_format($saldo, 2, ',', '.')
                    )
                );
            }
        } elseif ($tipo === LancamentoFinanceiro::TIPO_ESTORNO) {
            if ($valor > $valorPago + 0.001) {
                throw new BadRequest(
                    \sprintf(
                        'Não é possível estornar mais do que já foi pago nesta fatura (R$ %s).',
                        \number_format($valorPago, 2, ',', '.')
                    )
                );
            }
        }
    }

    private function validateProcessoCrossCliente(LancamentoFinanceiro $entity): void
    {
        $processoId = (string) ($entity->get('processoId') ?? '');
        $clienteId = (string) ($entity->get('clienteId') ?? '');
        if ($processoId === '' || $clienteId === '') {
            return;
        }

        try {
            $stmt = $this->entityManager
                ->getQueryExecutor()
                ->execute(
                    $this->entityManager->getQueryBuilder()
                        ->select(['clienteId'])
                        ->from('ClienteProcesso')
                        ->where(['processoId' => $processoId])
                        ->build(),
                );
            $rows = $stmt->fetchAll();
        } catch (\Throwable $e) {
            // Fail-closed: erro de query no guarda de cross-cliente → bloquear por segurança.
            throw new BadRequest('Não foi possível validar o processo vinculado. Tente novamente. (' . $e->getMessage() . ')');
        }

        if ($rows === []) {
            return;
        }

        $clienteIds = \array_column($rows, 'clienteId');
        if (! \in_array($clienteId, $clienteIds, true)) {
            throw new BadRequest('O processo informado pertence a outro cliente.');
        }
    }
}
