<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Classes\Select\Bool\Prazo;

use Espo\Core\Select\Bool\Filter;
use Espo\Entities\User;
use Espo\Modules\TogareCore\Entities\Prazo;
use Espo\ORM\Query\Part\Where\OrGroupBuilder;
use Espo\ORM\Query\SelectBuilder;

/**
 * Bool filter "Meus rascunhos" — Prazos com status `rascunho` E
 * assignedUserId = current user. Visão Advogado: publicações sem match
 * de Processo na sua carteira para revisar manualmente.
 *
 * Story 4a.3 / AC11. Story 4a.3.1: status renomeado + signature corrigida
 * para apply().
 */
class MeusRascunhos implements Filter
{
    public function __construct(
        private readonly User $user,
    ) {
    }

    public function apply(SelectBuilder $queryBuilder, OrGroupBuilder $orGroupBuilder): void
    {
        $queryBuilder->where([
            'status' => Prazo::STATUS_RASCUNHO,
            'assignedUserId' => $this->user->getId(),
        ]);
    }
}
