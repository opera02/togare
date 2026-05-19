<?php

declare(strict_types=1);

namespace Espo\Modules\TogareRbac\Hooks\UserData;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Entities\User;
use Espo\Entities\UserData;
use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareRbac\Service\Mfa\MfaPolicyResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Hook BeforeSave em UserData: bloqueia desativação de MFA para Sócio/Admin.
 *
 * NFR9 do PRD Togare: MFA obrigatório e não-desativável para Sócio/Admin.
 *
 * Skip:
 *  - auth2FA não foi modificado nesta operação.
 *  - auth2FA sendo setado para true (ativação → permitida).
 *  - Usuário não é Sócio/Admin (outros roles podem desativar livremente — AC8).
 *
 * @implements BeforeSave<UserData>
 */
final class PreventMfaDisableForRequiredUsers implements BeforeSave
{
    public static int $order = 5;

    public function __construct(
        private readonly EntityManager $em,
        private readonly MfaPolicyResolver $resolver,
        private readonly AuditLogContract $auditLog,
    ) {
    }

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (! $entity instanceof UserData) {
            return;
        }

        if (! $entity->isAttributeChanged('auth2FA')) {
            return;
        }

        // Ativando MFA → sempre permitido.
        if ((bool) $entity->get('auth2FA') === true) {
            return;
        }

        $userId = $entity->get('userId');
        if (! $userId) {
            return;
        }

        $user = $this->em->getEntityById(User::ENTITY_TYPE, $userId);
        if (! $user) {
            return;
        }

        if (! $this->resolver->isMfaRequired($user)) {
            return;
        }

        // Resolver o nome dos roles para o log.
        $roleIds = $user->getLinkMultipleIdList('roles');
        $roleNames = [];
        foreach ($roleIds as $roleId) {
            $role = $this->em->getEntityById('Role', $roleId);
            if ($role) {
                $roleNames[] = (string) $role->get('name');
            }
        }

        $context = [
            'userId' => $user->getId(),
            'userName' => $user->get('userName'),
            'roles' => $roleNames,
            'attemptedBy' => $userId,
        ];

        TogareLogger::event(
            'warning',
            'mfa.required_role.violation',
            \sprintf(
                "Tentativa de desativar MFA para user '%s' bloqueada (NFR9).",
                (string) $user->get('userName'),
            ),
            $context,
        );

        // Dual-write em togare_audit_log antes do throw — auditoria precisa
        // capturar a tentativa mesmo que o BeforeSave aborte (Story 2.4 — FR37).
        $this->auditLog->log(
            'mfa.required_role.violation',
            'UserData',
            (string) $entity->getId(),
            $context,
        );

        throw Forbidden::createWithBody(
            'MFA não pode ser desativado para a role Sócio/Admin (NFR9 do PRD Togare).',
            (string) \json_encode(['reason' => 'mfa_required_for_role'], JSON_UNESCAPED_UNICODE),
        );
    }
}
