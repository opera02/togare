<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\TogareCore\Services\HealthCheckService;
use Espo\ORM\EntityManager;
use stdClass;
use Throwable;

/**
 * Story 10.2 / FR41 — endpoint REST do painel TogareHealth.
 *
 * Endpoint (rota stock controller-action do EspoCRM 9.x — sem entrada em
 * routes.json, mesmo padrão comprovado de TogareDjenStatus, Story 4b.4):
 *
 *   GET /api/v1/TogareHealth/action/data
 *
 * Response (HTTP 200): payload de `HealthCheckService::getPanel()`
 *   { tiles: [...6...], licenca: {...}|null, historico: [...], generatedAt }
 *
 * **RBAC (AC4 — NÃO-DEFERÍVEL):** somente Sócio/Admin. Demais roles
 * (Advogado, Secretária, Financeiro, etc.) recebem HTTP 403 Forbidden.
 * Gate: usuário administrador do sistema OU portador do role "Sócio/Admin"
 * (seedado por togare-rbac). Blindagem cruzada de informação sensível
 * (UX Key Design Challenge #1).
 *
 * Classe simples (não estende Record) — endpoint de leitura de estado de
 * runtime, sem entidade persistida (Decisão A2.2: histórico via audit log,
 * sem entidade HealthSnapshot). Construtor resolvido pelo InjectableFactory.
 */
class TogareHealth
{
    private const SOCIO_ADMIN_ROLE = 'Sócio/Admin';

    public function __construct(
        private readonly User $user,
        private readonly EntityManager $entityManager,
        private readonly HealthCheckService $healthCheckService,
    ) {
    }

    /**
     * @throws Forbidden
     */
    public function getActionData(Request $request): stdClass
    {
        if (! $this->hasAdminAccess()) {
            throw new Forbidden('HealthPanel é restrito a Sócio/Admin.');
        }

        return $this->toObject($this->healthCheckService->getPanel());
    }

    /**
     * Sócio/Admin = administrador do sistema OU portador do role "Sócio/Admin".
     */
    private function hasAdminAccess(): bool
    {
        if ($this->user->isAdmin()) {
            return true;
        }

        try {
            $roles = $this->entityManager
                ->getRDBRepository('User')
                ->getRelation($this->user, 'roles')
                ->find();
            foreach ($roles as $role) {
                if ((string) $role->get('name') === self::SOCIO_ADMIN_ROLE) {
                    return true;
                }
            }
        } catch (Throwable) {
            // Sem relação resolvível → nega por padrão (fail-closed).
        }

        return false;
    }

    /**
     * Converte o array do service em stdClass recursivo — payload vanilla
     * EspoCRM (sem wrapper {data,error}).
     */
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
