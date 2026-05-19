<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services;

use DateTimeImmutable;
use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\Modules\TogareCore\Entities\Fatura;
use Espo\Modules\TogareCore\Entities\LancamentoFinanceiro;
use Espo\ORM\EntityManager;

/**
 * Service que recalcula valorPago + saldo + status de uma Fatura a partir dos
 * LancamentoFinanceiro vinculados (Decisão #4 + #5 + #9 da Story 6.3).
 *
 * **Regra contábil (Decisão #9):**
 *   valorPago = SUM(LancamentoFinanceiro WHERE tipo IN ('pagamento_total','pagamento_parcial') AND deleted=0)
 *             - SUM(LancamentoFinanceiro WHERE tipo='estorno' AND deleted=0)
 *   saldo = valorBruto - valorPago
 *
 * **Regra de status (Decisão #9):**
 *   - cancelada: TERMINAL — recompute não transita (só atualiza valorPago/saldo
 *     para auditoria; status preservado).
 *   - saldo == 0: paga
 *   - 0 < saldo < valorBruto AND today <= dataVencimento: parcialmente_paga
 *   - 0 < saldo < valorBruto AND today > dataVencimento: vencida
 *   - saldo == valorBruto AND today <= dataVencimento: emitida
 *   - saldo == valorBruto AND today > dataVencimento: vencida
 *
 * **Idempotente.** Reentrant-safe — múltiplas chamadas com mesmo input
 * produzem mesmo output. Não dispara loop com AuditFaturaHook porque o save
 * passa flag `silent=true` E `_fromRecompute=true` no options.
 *
 * Service usado por:
 *  - LancamentoFinanceiro/RecomputeFaturaSaldoHook (AfterSave + AfterRemove)
 *  - Fatura/ValidateFaturaFieldsHook (quando valorBruto muda pós-isNew=false)
 *  - Fatura Controller::getActionCancelar (via transitionStatus para 'cancelada')
 */
/**
 * NÃO é `final` intencionalmente: RecomputeFaturaSaldoHookTest precisa mockar
 * o serviço para isolar o teste do Hook do comportamento de recompute (que tem
 * cobertura própria em FaturaSaldoServiceTest).
 */
class FaturaSaldoService
{
    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly AuditLogContract $auditLog,
    ) {
    }

    /**
     * Recalcula valorPago + saldo + status para a Fatura informada.
     * Persiste no banco com flag silent para não disparar AuditFaturaHook
     * em loop com RecomputeFaturaSaldoHook.
     *
     * Idempotente: re-execução produz mesmo resultado.
     *
     * @return array{valorPago: float, saldo: float, status: string}|null
     *   Retorna null se a Fatura não existe ou foi removida.
     */
    public function recompute(string $faturaId): ?array
    {
        if ($faturaId === '') {
            return null;
        }

        $repository = $this->entityManager->getRDBRepository(Fatura::ENTITY_TYPE);

        // P12: lock da row para prevenir recomputes concorrentes duplicando valorPago.
        // Best-effort — degrada graciosamente quando PDO indisponível (ex.: testes unitários).
        $pdo = null;
        $ownTx = false;
        try {
            $pdo = $this->entityManager->getPDO();
            $ownTx = ! $pdo->inTransaction();
            if ($ownTx) {
                $pdo->beginTransaction();
            }
            $lockStmt = $pdo->prepare('SELECT id FROM fatura WHERE id = ? AND deleted = 0 FOR UPDATE');
            $lockStmt->execute([$faturaId]);
            if ($lockStmt->fetch(\PDO::FETCH_ASSOC) === false) {
                if ($ownTx) {
                    $pdo->rollBack();
                }
                return null;
            }
        } catch (\Throwable) {
            // PDO indisponível ou fora de contexto transacional — prossegue sem lock.
            $pdo = null;
            $ownTx = false;
        }

        $fatura = $repository->getById($faturaId);

        if (! $fatura instanceof Fatura) {
            if ($ownTx && $pdo !== null) {
                try {
                    $pdo->rollBack();
                } catch (\Throwable) {
                }
            }
            return null;
        }

        $valorPago = $this->calcularValorPago($faturaId);
        $valorBruto = (float) ($fatura->get('valorBruto') ?? 0.0);
        $saldo = \round($valorBruto - $valorPago, 2);

        // P4: saldo negativo = valorBruto editado abaixo de valorPago. Log warning + clamp.
        if ($saldo < 0) {
            TogareLogger::event(
                'warning',
                'fatura.saldo.negativo',
                'FaturaSaldoService: saldo negativo detectado — valorBruto < valorPago; possível edição de valorBruto abaixo do pago ou estorno excedente. Clampado para zero.',
                ['faturaId' => $faturaId, 'valorBruto' => $valorBruto, 'valorPago' => $valorPago],
            );
            $saldo = 0.0;
        }

        $currentStatus = (string) ($fatura->get('status') ?? Fatura::STATUS_EMITIDA);
        $newStatus = $this->derivarStatus($currentStatus, $valorBruto, $saldo, $fatura->get('dataVencimento'));

        $changed = false;
        if (\abs((float) ($fatura->get('valorPago') ?? 0.0) - $valorPago) > 0.001) {
            $fatura->set('valorPago', $valorPago);
            $changed = true;
        }
        if (\abs((float) ($fatura->get('saldo') ?? 0.0) - $saldo) > 0.001) {
            $fatura->set('saldo', $saldo);
            $changed = true;
        }
        if ($newStatus !== $currentStatus) {
            $fatura->set('status', $newStatus);
            $changed = true;
        }

        if ($changed) {
            $repository->save($fatura, [
                'silent' => true,
                '_fromRecompute' => true,
            ]);

            try {
                $this->auditLog->log(
                    'fatura.saldo.recomputed',
                    Fatura::ENTITY_TYPE,
                    $faturaId,
                    [
                        'valorPago' => $valorPago,
                        'saldo' => $saldo,
                        'statusAntes' => $currentStatus,
                        'statusDepois' => $newStatus,
                    ],
                );
            } catch (\Throwable $e) {
                TogareLogger::event(
                    'warning',
                    'fatura.audit_log_failed',
                    'FaturaSaldoService::recompute: falha ao gravar audit log (não-bloqueante)',
                    ['faturaId' => $faturaId, 'error' => $e->getMessage()],
                );
            }
        }

        // P12: libera o lock da row.
        if ($ownTx && $pdo !== null) {
            try {
                $pdo->commit();
            } catch (\Throwable) {
            }
        }

        return [
            'valorPago' => $valorPago,
            'saldo' => $saldo,
            'status' => $newStatus,
        ];
    }

    /**
     * Transita Fatura para `cancelada` (ou outro status terminal explícito).
     * Usado pelo Fatura Controller::getActionCancelar.
     *
     * Idempotente: chamar 2× com mesmo status é no-op (a segunda chamada
     * detecta status atual == novo e retorna sem mexer).
     */
    public function transitionStatus(string $faturaId, string $newStatus, string $reason): bool
    {
        if ($faturaId === '' || ! \in_array($newStatus, Fatura::STATUSES, true)) {
            return false;
        }

        $repository = $this->entityManager->getRDBRepository(Fatura::ENTITY_TYPE);
        $fatura = $repository->getById($faturaId);

        if (! $fatura instanceof Fatura) {
            return false;
        }

        $currentStatus = (string) ($fatura->get('status') ?? '');
        if ($currentStatus === $newStatus) {
            return true; // idempotente — já está no status alvo
        }

        // P5: cancelada é estado terminal — não pode transitar para outro status.
        if ($currentStatus === Fatura::STATUS_CANCELADA) {
            TogareLogger::event(
                'warning',
                'fatura.transition_terminal_blocked',
                'FaturaSaldoService::transitionStatus: tentativa de des-cancelar fatura bloqueada',
                ['faturaId' => $faturaId, 'targetStatus' => $newStatus],
            );
            return false;
        }

        $fatura->set('status', $newStatus);
        if ($newStatus === Fatura::STATUS_CANCELADA) {
            $fatura->set('motivoCancelamento', $reason);
        }

        $repository->save($fatura, [
            'silent' => true,
            '_fromRecompute' => true,
        ]);

        try {
            $eventName = $newStatus === Fatura::STATUS_CANCELADA
                ? 'fatura.cancelled'
                : 'fatura.status_changed';
            $this->auditLog->log(
                $eventName,
                Fatura::ENTITY_TYPE,
                $faturaId,
                [
                    'statusAntes' => $currentStatus,
                    'statusDepois' => $newStatus,
                    'motivo' => $reason,
                ],
            );
        } catch (\Throwable $e) {
            TogareLogger::event(
                'warning',
                'fatura.audit_log_failed',
                'FaturaSaldoService::transitionStatus: falha ao gravar audit log (não-bloqueante)',
                ['faturaId' => $faturaId, 'error' => $e->getMessage()],
            );
        }

        return true;
    }

    /**
     * SUM defensivo de pagamentos menos estornos via QueryBuilder.
     */
    private function calcularValorPago(string $faturaId): float
    {
        $repository = $this->entityManager->getRDBRepository(LancamentoFinanceiro::ENTITY_TYPE);

        $collection = $repository->where([
            'faturaId' => $faturaId,
            'deleted' => false,
        ])->find();

        $valorPago = 0.0;
        foreach ($collection as $lancamento) {
            $tipo = (string) $lancamento->get('tipo');
            $valor = (float) ($lancamento->get('valor') ?? 0.0);

            if (\in_array($tipo, LancamentoFinanceiro::TIPOS_DE_PAGAMENTO, true)) {
                $valorPago += $valor;
            } elseif ($tipo === LancamentoFinanceiro::TIPO_ESTORNO) {
                $valorPago -= $valor;
            }
        }

        // Defensivo: estorno excedente (>= valor pago) zera valorPago em vez de negativar.
        // ValidateLancamentoFieldsHook já rejeita esse cenário no save; defesa em profundidade.
        if ($valorPago < 0) {
            $valorPago = 0.0;
        }

        return \round($valorPago, 2);
    }

    /**
     * Deriva status conforme regra Decisão #9.
     * cancelada é terminal — preservado independente do saldo.
     */
    private function derivarStatus(
        string $currentStatus,
        float $valorBruto,
        float $saldo,
        mixed $dataVencimento,
    ): string {
        if ($currentStatus === Fatura::STATUS_CANCELADA) {
            return Fatura::STATUS_CANCELADA;
        }

        if ($saldo <= 0.001) {
            return Fatura::STATUS_PAGA;
        }

        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        $vencido = false;
        if ($dataVencimento) {
            $venc = (string) $dataVencimento;
            $vencido = $venc < $today;
        }

        if ($saldo + 0.001 >= $valorBruto) {
            // saldo == valorBruto (nada foi pago)
            return $vencido ? Fatura::STATUS_VENCIDA : Fatura::STATUS_EMITIDA;
        }

        // 0 < saldo < valorBruto (pagamento parcial)
        return $vencido ? Fatura::STATUS_VENCIDA : Fatura::STATUS_PARCIALMENTE_PAGA;
    }
}
