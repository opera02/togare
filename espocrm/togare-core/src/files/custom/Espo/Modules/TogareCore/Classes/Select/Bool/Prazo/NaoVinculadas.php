<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Classes\Select\Bool\Prazo;

use Espo\Core\Select\Bool\Filter;
use Espo\Modules\TogareCore\Entities\Prazo;
use Espo\ORM\Query\Part\Where\OrGroupBuilder;
use Espo\ORM\Query\SelectBuilder;

/**
 * Bool filter "Não vinculadas" — todos os Prazos com status `rascunho`
 * (visão Sócio/Admin: triagem global das publicações sem match de Processo).
 *
 * Story 4a.3 / AC11. Story 4a.3.1: status renomeado de
 * `rascunho_nao_vinculado` para `rascunho` (V009 mapping). Smoke F1 4a.3.1
 * descobriu signature errada (getWhere) — corrigida para apply() conforme
 * interface real do EspoCRM 9.x (`Espo\Core\Select\Bool\Filter`).
 */
class NaoVinculadas implements Filter
{
    public function apply(SelectBuilder $queryBuilder, OrGroupBuilder $orGroupBuilder): void
    {
        $queryBuilder->where(['status' => Prazo::STATUS_RASCUNHO]);
    }
}
