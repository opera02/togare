<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Classes\Select\Bool\Prazo;

use DateTimeImmutable;
use Espo\Core\Select\Bool\Filter;
use Espo\Modules\TogareCore\Entities\Prazo;
use Espo\ORM\Query\Part\Where\OrGroupBuilder;
use Espo\ORM\Query\SelectBuilder;

/**
 * Bool filter "Protocolados (últimos 30 dias)" — Prazos concluídos via
 * protocolo nas últimas 4 semanas. Útil para dashboard de produtividade.
 *
 * Story 4a.3.1 / AC8. Cutoff calculado em PHP (`-30 days`) — portável + testável.
 */
class ProtocoladosUltimos30d implements Filter
{
    public function apply(SelectBuilder $queryBuilder, OrGroupBuilder $orGroupBuilder): void
    {
        $cutoff = (new DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s');

        $queryBuilder->where([
            'status' => Prazo::STATUS_PROTOCOLADO,
            'modifiedAt>=' => $cutoff,
        ]);
    }
}
