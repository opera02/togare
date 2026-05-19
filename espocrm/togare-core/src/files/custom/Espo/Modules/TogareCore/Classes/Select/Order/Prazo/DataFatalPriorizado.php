<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Classes\Select\Order\Prazo;

use Espo\Core\Select\Order\Item;
use Espo\Core\Select\Order\Orderer;
use Espo\ORM\Query\SelectBuilder;

/**
 * Orderer custom para `dataFatal` da entity Prazo (Story 4a.5 fix-pass v0.20.1).
 *
 * **Por que existe?** Smoke F1 da 4a.5 (2026-05-06) revelou que o EspoCRM
 * `Espo\Core\Select\Order\Applier::applyOrder` injeta automaticamente
 * `[Attribute::ID, $order]` como tiebreaker secundário ANTES dos orders
 * adicionados por boolFilters via `$queryBuilder->order()`. Resultado: a query
 * gerada pelo dashlet `togare-prazos-do-dia` ficava
 *   `ORDER BY data_fatal ASC, id ASC, prioridade_weight DESC`
 * — `prioridade_weight DESC` virava terciário inútil (ids únicos consomem o
 * empate antes).
 *
 * **Solução do framework EspoCRM 9.x**: registrar Orderer custom em
 * `selectDefs/Prazo.json::ordererClassNameMap.dataFatal`. O Applier usa o
 * orderer (em vez do orderBy default) e o `id ASC` é adicionado APÓS o
 * `apply()` retornar. Resultado:
 *   `ORDER BY data_fatal ASC, prioridade_weight DESC, id ASC`
 * — desempate por urgência funciona como esperado (urgente=4 > alta=3 >
 * normal=2 > baixa=1).
 *
 * **Comportamento global**: registrar este Orderer em `selectDefs` aplica a
 * QUALQUER query que ordene Prazo por `dataFatal` (não só o dashlet do
 * Briefing). Decisão pragmática: quanto mais urgente o prazo, primeiro —
 * coerente em listas/exports/calendar. Se algum caller específico precisar
 * SÓ por dataFatal, pode usar outro field como orderBy.
 *
 * Decisão #2 da Story 4a.5 (Plano C atualizado).
 */
final class DataFatalPriorizado implements Orderer
{
    public function apply(SelectBuilder $queryBuilder, Item $item): void
    {
        $order = $item->getOrder() ?? 'ASC';

        // Order primário: dataFatal na direção pedida (asc por default).
        $queryBuilder->order('dataFatal', $order);

        // Order secundário: prioridade_weight DESC sempre (urgente=4 em cima).
        // Independente da direção do primário — faz sentido ter "mais urgente
        // primeiro" tanto em listas crescentes quanto decrescentes de data.
        $queryBuilder->order('prioridadeWeight', 'DESC');
    }
}
