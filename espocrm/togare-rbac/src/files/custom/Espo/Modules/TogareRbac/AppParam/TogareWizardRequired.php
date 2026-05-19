<?php

declare(strict_types=1);

namespace Espo\Modules\TogareRbac\AppParam;

use Espo\Entities\User;
use Espo\Modules\TogareRbac\Service\Mfa\MfaPolicyResolver;
use Espo\ORM\EntityManager;
use Espo\Tools\App\AppParam;

/**
 * AppParam custom: informa ao frontend se o Sócio/Admin deve ver o wizard
 * pós-primeiro-login (FR34, Story 2.6).
 *
 * Retorna true quando TODAS as condições são verdadeiras:
 *  - MfaPolicyResolver::isMfaRequired($user) === true (Sócio/Admin ou
 *    isAdmin nativo).
 *  - User.togareWizardCompleted === false.
 *  - UserData.auth2FA === true (precedência: MFA setup deve estar concluído
 *    antes do wizard disparar — gotcha gating Story 2.3 > Story 2.6).
 *
 * Exposto via GET /api/v1/App/user → appParams.togareWizardRequired.
 * Frontend (extensions.js) lê esta flag no boot e redireciona Sócio/Admin
 * para a rota do wizard.
 *
 * Espelha pattern de TogareMfaRequired (Story 2.3).
 */
final class TogareWizardRequired implements AppParam
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

        if ((bool) $this->user->get('togareWizardCompleted')) {
            return false;
        }

        $userId = $this->user->getId();
        // P34: guard string vazia além de null.
        if ($userId === null || $userId === '') {
            return false;
        }

        /** @var \Espo\Repositories\UserData $repo */
        $repo = $this->em->getRepository('UserData');
        $userData = $repo->getByUserId($userId);

        if ($userData === null) {
            return false; // sem userData → MFA ainda não configurado
        }

        if (! (bool) $userData->get('auth2FA')) {
            return false; // precedência: MFA primeiro
        }

        return true;
    }
}
