<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Controllers\Record;
use Espo\Modules\TogareCore\Services\TogareBriefingService;
use stdClass;

/**
 * Story 10.1 — endpoint REST do BriefingDoDia role-aware.
 *
 * GET /api/v1/TogareBriefing/action/data
 *
 * Response (HTTP 200): { badges: [...], role: string, generatedAt: ISO8601 }
 *
 * Sem RBAC no nível do controller: qualquer usuário autenticado recebe seus
 * próprios badges. Role desconhecido retorna array vazio (AC2 + AC3).
 *
 * Estende `Record` conforme spec (P12 code review 2026-05-18). NÃO usa DI
 * constructor: `Record::__construct` é pesado e seu `$user` é não-readonly —
 * promover `readonly User $user` aqui causa fatal "Cannot redeclare ... as
 * readonly" (regressão P12 pega no re-smoke 2026-05-18). Padrão do projeto
 * (ver `Controllers/Fatura.php`): serviço via `injectableFactory`, usuário via
 * `$this->user` injetado pelo pai.
 */
class TogareBriefing extends Record
{
    public function getActionData(Request $request): stdClass
    {
        $briefingService = $this->injectableFactory->create(TogareBriefingService::class);

        return $this->toObject(
            $briefingService->getSummaryForUser($this->user)
        );
    }

    private function toObject(mixed $value): mixed
    {
        if (\is_array($value)) {
            $isList = \array_is_list($value);
            if ($isList) {
                return \array_map(fn ($v) => $this->toObject($v), $value);
            }
            $obj = new stdClass();
            foreach ($value as $k => $v) {
                $obj->{$k} = $this->toObject($v);
            }
            return $obj;
        }
        return $value;
    }
}
