<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\UserData;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Entities\User;
use Espo\Entities\UserData;
use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Audit afterSave em `UserData`: emite `user.mfa.enabled` ou
 * `user.mfa.disabled` quando o atributo `auth2FA` muda (FR37 + NFR9).
 *
 * Convive com `Hooks/UserData/PreventMfaDisableForRequiredUsers` (togare-rbac
 * Story 2.3) que roda em BeforeSave: aquele aborta antes do save em Sócio/
 * Admin tentando desativar; portanto este AfterSave nem dispara nesses casos
 * — o registro `mfa.required_role.violation` é emitido lá via dual-write da
 * Story 2.3.
 *
 * @implements AfterSave<UserData>
 */
final class MfaConfigChangeAudit implements AfterSave
{
    public static int $order = 60;

    public function __construct(
        private readonly AuditLogContract $auditLog,
        private readonly EntityManager $em,
    ) {
    }

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (! $entity instanceof UserData) {
            return;
        }

        if (! $entity->isAttributeChanged('auth2FA')) {
            return;
        }

        $userId = $entity->get('userId');
        $userName = null;
        if (\is_string($userId) && $userId !== '') {
            $user = $this->em->getEntityById(User::ENTITY_TYPE, $userId);
            if ($user instanceof User) {
                $userName = $user->get('userName');
            }
        }

        $enabled = (bool) $entity->get('auth2FA');
        $event = $enabled ? 'user.mfa.enabled' : 'user.mfa.disabled';

        // Ao desabilitar, auth2FAMethod normalmente é limpo no mesmo save —
        // get() retornaria null e perderíamos o método que estava ativo.
        // getFetched captura o estado pré-save (qual método foi removido).
        $method = $enabled
            ? $entity->get('auth2FAMethod')
            : $entity->getFetched('auth2FAMethod');

        $this->auditLog->log(
            $event,
            'UserData',
            (string) $entity->getId(),
            [
                'userId' => $userId,
                'userName' => $userName,
                'method' => $method,
            ],
        );
    }
}
