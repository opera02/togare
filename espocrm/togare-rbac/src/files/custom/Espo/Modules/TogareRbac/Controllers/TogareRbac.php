<?php

declare(strict_types=1);

namespace Espo\Modules\TogareRbac\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\RequestNull;
use Espo\Core\Authentication\Login\Data as LoginData;
use Espo\Core\Authentication\LoginFactory;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\TogareRbac\Service\Mfa\BackupCodeService;
use Espo\ORM\EntityManager;
use Espo\Repositories\UserData as UserDataRepository;
use stdClass;

/**
 * Endpoints REST do togare-rbac para gestão de backup codes MFA.
 *
 * POST /api/v1/TogareRbac/action/regenerateMfaBackupCodes
 *   Body: {"password": "..."}
 *   Requer: MFA ativo no usuário. Verifica senha antes de regenerar.
 *   Retorna: {"codes": [...], "warning": "..."}
 *
 * GET /api/v1/TogareRbac/action/mfaBackupCodesStatus
 *   Retorna: {"total": N, "used": N, "remaining": N}
 *
 * Story 2.3 — AC10.
 */
class TogareRbac
{
    public function __construct(
        private readonly User $user,
        private readonly EntityManager $em,
        private readonly LoginFactory $loginFactory,
        private readonly BackupCodeService $backupCodeService,
    ) {
        if ($this->user->isPortal() || $this->user->isApi()) {
            throw new Forbidden('Endpoint não disponível para este tipo de usuário.');
        }
    }

    public function postActionRegenerateMfaBackupCodes(Request $request): stdClass
    {
        $body = $request->getParsedBody();
        $password = \is_object($body) && isset($body->password) ? (string) $body->password : '';

        if ($password === '') {
            throw new Forbidden('Senha obrigatória.');
        }

        $this->verifyPassword($password);

        $userData = $this->getUserData();
        if (! $userData || ! $userData->get('auth2FA')) {
            throw new BadRequest('Ative o MFA antes de gerar códigos de backup.');
        }

        $codes = $this->backupCodeService->regenerate($this->user, 8);

        $response = new stdClass();
        $response->codes = $codes;
        $response->warning = 'Estes códigos serão exibidos apenas uma vez. Anote em local seguro.';

        return $response;
    }

    public function getActionMfaBackupCodesStatus(Request $request): stdClass
    {
        $status = $this->backupCodeService->status($this->user);

        return (object) $status;
    }

    private function verifyPassword(string $password): void
    {
        $userName = (string) $this->user->get('userName');

        $loginData = LoginData::createBuilder()
            ->setUsername($userName)
            ->setPassword($password)
            ->build();

        $login = $this->loginFactory->createDefault();
        $result = $login->login($loginData, new RequestNull());

        if ($result->isFail()) {
            throw new Forbidden('Senha incorreta.');
        }
    }

    private function getUserData(): ?\Espo\Entities\UserData
    {
        $userId = $this->user->getId();
        if (! $userId) {
            return null;
        }

        /** @var UserDataRepository $repo */
        $repo = $this->em->getRepository('UserData');

        return $repo->getByUserId($userId);
    }
}
