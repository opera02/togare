<?php

declare(strict_types=1);

namespace Espo\Modules\TogareRbac\Service\Mfa;

use Espo\Entities\User;
use Espo\ORM\EntityManager;

/**
 * Determina se MFA é obrigatório para um dado usuário (NFR9 do PRD Togare).
 *
 * Regras:
 * - Admin nativo EspoCRM (isAdmin=true) → obrigatório.
 * - Usuário com role "Sócio/Admin" (seedado na Story 2.1) → obrigatório.
 * - Portal, API, demais roles → opcional.
 *
 * Story 2.3.
 */
class MfaPolicyResolver
{
    public const ROLE_NAME_SOCIO_ADMIN = 'Sócio/Admin';

    public function __construct(
        private readonly EntityManager $em,
    ) {
    }

    public function isMfaRequired(User $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isPortal() || $user->isApi()) {
            return false;
        }

        $roleIds = $user->getLinkMultipleIdList('roles');
        if ($roleIds === []) {
            return false;
        }

        $count = $this->em->getRDBRepository('Role')
            ->where([
                'id' => $roleIds,
                'name' => self::ROLE_NAME_SOCIO_ADMIN,
            ])
            ->count();

        return $count > 0;
    }
}
