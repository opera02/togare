<?php

declare(strict_types=1);

namespace Espo\Modules\TogareRbac\AppParam;

use Espo\Entities\User;
use Espo\Modules\TogareRbac\Service\Mfa\MfaPolicyResolver;
use Espo\ORM\EntityManager;
use Espo\Tools\App\AppParam;

/**
 * AppParam custom: informa ao frontend se o usuário atual deve configurar MFA.
 *
 * Retorna true quando:
 *  - MfaPolicyResolver.isMfaRequired(currentUser) = true (Sócio/Admin ou Admin nativo)
 *  - E o usuário ainda não tem auth2FA=true no UserData
 *
 * Exposto via GET /api/v1/App/user → appParams.togareMfaRequired.
 * Frontend (Story 2.6 wizard) usa esta flag para forçar o setup antes de usar o sistema.
 *
 * Story 2.3 — AC9.
 */
final class TogareMfaRequired implements AppParam
{
    public function __construct(
        private readonly User $user,
        private readonly EntityManager $em,
        private readonly MfaPolicyResolver $resolver,
    ) {
    }

    public function get(): bool
    {
        if (! $this->resolver->isMfaRequired($this->user)) {
            return false;
        }

        $userId = $this->user->getId();
        if ($userId === null) {
            return false;
        }

        /** @var \Espo\Repositories\UserData $repo */
        $repo = $this->em->getRepository('UserData');
        $userData = $repo->getByUserId($userId);

        if ($userData === null) {
            return true;
        }

        return ! (bool) $userData->get('auth2FA');
    }
}
