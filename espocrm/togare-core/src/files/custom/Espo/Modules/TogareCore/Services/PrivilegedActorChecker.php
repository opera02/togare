<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services;

use Espo\Entities\Role;
use Espo\Entities\User;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Detecta se o usuário corrente é um "privileged actor" do escritório —
 * Sócio/Admin Togare ou system superuser nativo do EspoCRM.
 *
 * Usado pelo `EnforceAssignmentPolicyHook` (Story 3.5) para liberar mudanças
 * de `assignedUser` / `collaboratorsIds` em Processo. Mantemos a checagem em
 * uma classe dedicada (e não inline no hook) por 3 razões:
 *
 *  1. **Testabilidade.** O hook recebe esta classe via DI; testes unit usam
 *     `createMock(PrivilegedActorChecker::class)` sem precisar montar
 *     EntityManager + Role rows. A regra de detecção fica isolada e versionada.
 *  2. **Reuso futuro.** Stories 4a (DJEN), 6 (Financeiro), 7b (LGPD purga)
 *     também precisam responder "este user é Sócio/Admin?". Centralizar evita
 *     drift de definição entre módulos.
 *  3. **Defesa em profundidade.** A definição de "privileged actor" pode evoluir
 *     (ex.: incluir role "Sócio Sênior" futuramente) sem mexer em hooks.
 *
 * **Definição canônica de "privileged actor":**
 *  - `User::isAdmin() === true` (system superuser EspoCRM — sempre passa); OU
 *  - User tem role com `name === 'Sócio/Admin'` (role-name match — não usa
 *    `assignmentPermission === 'all'` como proxy porque a permission pode ser
 *    rebaixada no admin sem que o "dono do escritório" perca o status).
 *
 * **Não é privileged actor:** Advogado, Assistente, Secretária, Financeiro,
 * Marketing, RH-lite, Cliente-portal — qualquer combinação que não inclua
 * a role "Sócio/Admin" exato.
 *
 * **Falha graciosa:** se EntityManager não puder resolver as roles (ex.:
 * tabela ausente em testes que não rodam migrations), retornamos `false` —
 * fail-closed por design.
 *
 * **Não-final intencional:** PHPUnit `createMock(PrivilegedActorChecker::class)`
 * precisa derivar para gerar o doublé nos testes do `EnforceAssignmentPolicyHook`.
 * Mesmo trade-off de `RedisConnection` (togare-tpu Story 3.3) e do `EntityManagerStub`
 * — preferimos teste isolado a `final` decorativo.
 */
class PrivilegedActorChecker
{
    public const SOCIO_ADMIN_ROLE_NAME = 'Sócio/Admin';

    public function __construct(
        private readonly EntityManager $entityManager,
    ) {
    }

    public function isPrivileged(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        $roleIds = $user->getLinkMultipleIdList('roles');

        if ($roleIds === []) {
            return false;
        }

        foreach ($roleIds as $roleId) {
            try {
                $role = $this->entityManager->getEntityById(Role::ENTITY_TYPE, $roleId);
            } catch (Throwable) {
                continue;
            }

            if (! $role instanceof Role) {
                continue;
            }

            if ($role->get('name') === self::SOCIO_ADMIN_ROLE_NAME) {
                return true;
            }
        }

        return false;
    }
}
