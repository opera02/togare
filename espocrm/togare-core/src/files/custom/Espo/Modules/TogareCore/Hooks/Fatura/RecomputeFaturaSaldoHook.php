<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\Fatura;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Modules\TogareCore\Entities\Fatura;
use Espo\Modules\TogareCore\Services\FaturaSaldoService;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Recalcula saldo/status da Fatura quando valorBruto muda (P11 — Story 6.3 review).
 *
 * Dispara FaturaSaldoService::recompute quando:
 *  - não é novo (isNew=false)
 *  - não veio do próprio recompute (anti-loop via _fromRecompute)
 *  - valorBruto foi alterado
 *
 * Order = 20 (após ValidateFaturaFieldsHook=10 e DefaultFaturaAssignmentHook=15,
 * antes de AuditFaturaHook=50).
 *
 * @implements AfterSave<Fatura>
 */
final class RecomputeFaturaSaldoHook implements AfterSave
{
    public static int $order = 20;

    public function __construct(
        private readonly FaturaSaldoService $faturaSaldoService,
    ) {
    }

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (! $entity instanceof Fatura) {
            return;
        }

        // Anti-loop: saves originados do próprio recompute não disparam novo recompute.
        if ($options->get('silent') === true && $options->get('_fromRecompute') === true) {
            return;
        }

        // Só recomputa pós-criação, quando valorBruto efetivamente mudou.
        if ($entity->isNew()) {
            return;
        }

        if (! $entity->isAttributeChanged('valorBruto')) {
            return;
        }

        $faturaId = (string) ($entity->getId() ?? '');
        if ($faturaId === '') {
            return;
        }

        $this->faturaSaldoService->recompute($faturaId);
    }
}
