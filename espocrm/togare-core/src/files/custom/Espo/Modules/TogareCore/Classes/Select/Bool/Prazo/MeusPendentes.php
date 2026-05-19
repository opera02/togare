<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Classes\Select\Bool\Prazo;

use Espo\Core\Select\Bool\Filter;
use Espo\Entities\User;
use Espo\Modules\TogareCore\Entities\Prazo;
use Espo\ORM\Query\Part\Where\OrGroupBuilder;
use Espo\ORM\Query\SelectBuilder;

/**
 * Bool filter "Meus pendentes" — Prazos que precisam da ação do advogado E
 * assignedUserId = current user. Visão Advogado.
 *
 * Story 4a.3 / AC11. Story 4a.3.1 amplia a semântica para incluir todos os
 * status que demandam ação do responsável (alinhado ao "que precisa minha
 * ação" do briefing — sprint change proposal §4.2 / AC8). Smoke F1 4a.3.1
 * corrigiu signature de getWhere() para apply() conforme interface real.
 */
class MeusPendentes implements Filter
{
    public function __construct(
        private readonly User $user,
    ) {
    }

    public function apply(SelectBuilder $queryBuilder, OrGroupBuilder $orGroupBuilder): void
    {
        $queryBuilder->where([
            'status' => [
                Prazo::STATUS_PENDENTE,
                Prazo::STATUS_REAGENDADO,
                Prazo::STATUS_AGUARDANDO_CLIENTE,
                Prazo::STATUS_AGUARDANDO_CORRECAO,
            ],
            'assignedUserId' => $this->user->getId(),
        ]);
    }
}
