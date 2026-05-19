<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Classes\Select\Bool\Prazo;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Select\Bool\Filter;
use Espo\Entities\User;
use Espo\Modules\TogareCore\Entities\Prazo;
use Espo\ORM\Query\Part\Where\OrGroupBuilder;
use Espo\ORM\Query\SelectBuilder;

/**
 * Bool filter "Para hoje" — Prazos pendentes do advogado COM
 * `dataCumprimento <= today` OU `dataCumprimento IS NULL`.
 *
 * Story 4a.5.1 / AC4 / Decisão #4. Substitui `meusPendentes` no dashlet
 * BriefingDoDia (Story 4a.5) — agora o briefing reflete o que o advogado
 * planejou fazer HOJE (não a soma de TODOS os pendentes do escritório
 * inclusive os que vencem em 25 dias).
 *
 * **WHERE clause:**
 *   status IN ('pendente', 'atrasado_reagendado', 'aguardando_cliente', 'aguardando_correcao')
 *     AND assignedUserId = :currentUser
 *     AND (dataCumprimento IS NULL OR dataCumprimento <= :today)
 *
 * **Default seguro (Decisão #4 — Felipe):** prazos sem `dataCumprimento` setado
 * (ex.: pré-V013 ou criação sem default) **CAEM no painel hoje** — advogado
 * não esquece. Alternativa rejeitada: "fila ainda não planejados separada"
 * (semântica complexa pra MVP magro).
 *
 * **Cutoff calculado em PHP** (não SQL `CURDATE()`) — pattern
 * `ProtocoladosUltimos30d`. Portável (SQLite tests + MariaDB prod), testável,
 * TZ-aware (`America/Sao_Paulo` explícito — não confia em TZ do servidor).
 *
 * **Pattern:** independente de `MeusPendentes` (não estende). Decisão #4 —
 * todos os boolFilters do Prazo são independentes; mudança em MeusPendentes
 * não impacta PendentesParaHoje silenciosamente. Custo: 6 linhas duplicadas
 * das constants de status.
 */
class PendentesParaHoje implements Filter
{
    public function __construct(
        private readonly User $user,
    ) {
    }

    public function apply(SelectBuilder $queryBuilder, OrGroupBuilder $orGroupBuilder): void
    {
        $today = (new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo')))
            ->format('Y-m-d');

        $queryBuilder->where([
            'status' => [
                Prazo::STATUS_PENDENTE,
                Prazo::STATUS_REAGENDADO,
                Prazo::STATUS_AGUARDANDO_CLIENTE,
                Prazo::STATUS_AGUARDANDO_CORRECAO,
            ],
            'assignedUserId' => $this->user->getId(),
            'OR' => [
                ['dataCumprimento' => null],
                ['dataCumprimento<=' => $today],
            ],
        ]);
    }
}
