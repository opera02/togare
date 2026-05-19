<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\LancamentoFinanceiro;

use Espo\Core\Hook\Hook\AfterRemove;
use Espo\Core\Hook\Hook\AfterSave;
use Espo\Modules\TogareCore\Entities\LancamentoFinanceiro;
use Espo\Modules\TogareCore\Services\FaturaSaldoService;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\RemoveOptions;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Recompute valorPago + saldo + status da Fatura após qualquer mudança em
 * LancamentoFinanceiro vinculado (Story 6.3 Decisão #5).
 *
 * **Por que Hook no LancamentoFinanceiro e NÃO no Fatura:** o lançamento é o
 * CAUSADOR; a Fatura é a RECEPTORA. Pattern correto = Hook no causador.
 *
 * **Idempotente:** FaturaSaldoService::recompute pode ser chamado N vezes com
 * mesmo input e produz mesmo output. Saves do service são silent + _fromRecompute
 * para evitar loop com AuditFaturaHook + ValidateFaturaFieldsHook.
 *
 * **AfterSave (insert E update) + AfterRemove:**
 *  - Insert pagamento → fatura.valorPago +=, saldo recomputed, status pode transitar.
 *  - Update pagamento (raro mas possível: corrigir valor) → recompute.
 *  - Delete pagamento → reverte; saldo volta.
 *  - Insert/update/delete de tipos avulsos (despesa, receita, acerto) sem faturaId → no-op.
 *
 * **Defensivo \Throwable:** falha do recompute NÃO bloqueia o save do
 * lançamento (registro do lançamento é prioritário; saldo pode ser
 * recomputado depois).
 *
 * Order = 20.
 *
 * @implements AfterSave<LancamentoFinanceiro>
 * @implements AfterRemove<LancamentoFinanceiro>
 */
final class RecomputeFaturaSaldoHook implements AfterSave, AfterRemove
{
    public static int $order = 20;

    public function __construct(
        private readonly FaturaSaldoService $faturaSaldoService,
    ) {
    }

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (! $entity instanceof LancamentoFinanceiro) {
            return;
        }

        $faturaId = (string) ($entity->get('faturaId') ?? '');
        if ($faturaId === '') {
            return; // lançamento avulso — sem fatura vinculada, nada a recomputar
        }

        $this->safeRecompute($faturaId);

        // Se faturaId MUDOU (edge case: mover pagamento de fatura A para fatura B),
        // recomputar a fatura anterior também.
        if (! $entity->isNew()) {
            $faturaAnteriorId = (string) ($entity->getFetched('faturaId') ?? '');
            if ($faturaAnteriorId !== '' && $faturaAnteriorId !== $faturaId) {
                $this->safeRecompute($faturaAnteriorId);
            }
        }
    }

    public function afterRemove(Entity $entity, RemoveOptions $options): void
    {
        if (! $entity instanceof LancamentoFinanceiro) {
            return;
        }

        $faturaId = (string) ($entity->get('faturaId') ?? '');
        if ($faturaId === '') {
            return;
        }

        $this->safeRecompute($faturaId);
    }

    private function safeRecompute(string $faturaId): void
    {
        try {
            $this->faturaSaldoService->recompute($faturaId);
        } catch (\Throwable $e) {
            TogareLogger::event(
                'error',
                'lancamento.recompute_fatura_failed',
                'RecomputeFaturaSaldoHook: falha ao recomputar saldo da fatura (não-bloqueante)',
                [
                    'faturaId' => $faturaId,
                    'error' => $e->getMessage(),
                ],
            );
        }
    }
}
