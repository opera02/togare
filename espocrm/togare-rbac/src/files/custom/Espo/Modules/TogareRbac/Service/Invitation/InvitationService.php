<?php

declare(strict_types=1);

namespace Espo\Modules\TogareRbac\Service\Invitation;

use Espo\Core\Exceptions\BadRequest;
use Espo\Entities\Role;
use Espo\Entities\User;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\EntityManager;
use Espo\Tools\UserSecurity\Password\Service as PasswordService;

/**
 * Facade testável que encapsula o flow de convite de usuário:
 *  1. Valida que cada role pertence aos 8 seedados da Story 2.1.
 *  2. Cria User com `type=regular`, `isActive=true` e roles atribuídos.
 *  3. Dispara `Espo\Tools\UserSecurity\Password\Service::sendAccessInfoForNewUser`
 *     nativo, que cria a `PasswordChangeRequest` e envia o email via
 *     `Sender::sendAccessInfo` (template `accessInfo/pt_BR/{subject,body}.tpl`).
 *
 * Este service NÃO substitui o fluxo `POST /api/v1/User` nativo do EspoCRM
 * (que continua funcionando via UI Admin → Users). É uma alternativa
 * programática útil para:
 *  - Testes E2E (smoke).
 *  - Wizard pós-primeiro-login (Story 2.6) que convida usuários em massa.
 *  - Scripts/CLI internos.
 */
class InvitationService
{
    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly PasswordService $passwordService,
    ) {
    }

    /**
     * @param list<string> $roleIds IDs de Role pré-existentes (validados contra a tabela `role`).
     * @throws BadRequest se os roleIds forem inválidos ou se o User violar regras (email duplicado, etc.).
     */
    public function invite(
        string $userName,
        string $emailAddress,
        string $firstName,
        string $lastName,
        array $roleIds,
    ): User {
        if ($roleIds === []) {
            throw BadRequest::createWithBody(
                'É preciso atribuir ao menos um role ao convidar um usuário.',
                (string) \json_encode(['reason' => 'role_required'], JSON_UNESCAPED_UNICODE),
            );
        }

        $this->assertRolesExist($roleIds);

        /** @var User $user */
        $user = $this->entityManager->getNewEntity(User::ENTITY_TYPE);
        $user->set([
            'userName' => $userName,
            'emailAddress' => $emailAddress,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'type' => User::TYPE_REGULAR,
            'isActive' => true,
            'rolesIds' => $roleIds,
        ]);

        $this->entityManager->saveEntity($user);

        $this->passwordService->sendAccessInfoForNewUser($user);

        TogareLogger::event(
            'info',
            'user.invitation.dispatched',
            \sprintf("Invitation dispatched para '%s' via InvitationService.", $userName),
            [
                'userId' => $user->getId(),
                'userName' => $userName,
                'emailAddress' => $emailAddress,
                'roleIds' => $roleIds,
            ],
        );

        return $user;
    }

    /**
     * @param list<string> $roleIds
     * @throws BadRequest
     */
    private function assertRolesExist(array $roleIds): void
    {
        $found = $this->entityManager
            ->getRDBRepository(Role::ENTITY_TYPE)
            ->where(['id' => $roleIds, 'deleted' => false])
            ->count();

        if ($found !== \count($roleIds)) {
            throw BadRequest::createWithBody(
                \sprintf('Um ou mais role IDs informados não existem (esperado %d, encontrado %d).', \count($roleIds), $found),
                (string) \json_encode(['reason' => 'role_not_found', 'roleIds' => $roleIds], JSON_UNESCAPED_UNICODE),
            );
        }
    }
}
