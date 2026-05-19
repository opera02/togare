<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Classes\Select\Bool\Prazo;

use Espo\Core\Select\Bool\Filter;
use Espo\Modules\TogareCore\Entities\Prazo;
use Espo\ORM\Query\Part\Where\OrGroupBuilder;
use Espo\ORM\Query\SelectBuilder;

/**
 * Bool filter "Acompanhamento" — Prazos com status `acompanhamento`
 * (informativo, sem ação requerida).
 *
 * Story 4a.3.1 / AC8.
 */
class Acompanhamento implements Filter
{
    public function apply(SelectBuilder $queryBuilder, OrGroupBuilder $orGroupBuilder): void
    {
        $queryBuilder->where(['status' => Prazo::STATUS_ACOMPANHAMENTO]);
    }
}
