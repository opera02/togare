<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Classes\Select\Bool\PublicacaoAmbigua;

use Espo\Core\Select\Bool\Filter;
use Espo\Entities\User;
use Espo\Modules\TogareCore\Entities\PublicacaoAmbigua;
use Espo\ORM\Query\Part\Where\OrGroupBuilder;
use Espo\ORM\Query\SelectBuilder;

/**
 * Bool filter "Precisa sua leitura" — PublicacaoAmbigua status=pendente_revisao
 * do advogado logado.
 *
 * Story 4b.1a / AC4. WHERE clause:
 *   status = 'pendente_revisao' AND assignedUserId = :currentUser
 *
 * Pattern espelha `MeusPendentes` da entity Prazo (User injetado via construtor;
 * filter portável SQLite-test + MariaDB-prod sem funções DB-específicas).
 *
 * Decisão #1 mãe — todos os boolFilters são independentes (não estende outro);
 * mudança neste filter não impacta outros silenciosamente.
 */
class PrecisaSuaLeitura implements Filter
{
    public function __construct(
        private readonly User $user,
    ) {
    }

    public function apply(SelectBuilder $queryBuilder, OrGroupBuilder $orGroupBuilder): void
    {
        $queryBuilder->where([
            'status' => PublicacaoAmbigua::STATUS_PENDENTE_REVISAO,
            'assignedUserId' => $this->user->getId(),
        ]);
    }
}
