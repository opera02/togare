<?php

declare(strict_types=1);

namespace Espo\Modules\TogareRbac\Authentication\Mfa;

use Espo\Core\Api\Request;
use Espo\Core\Authentication\HeaderKey;
use Espo\Core\Authentication\Result;
use Espo\Core\Authentication\Result\Data as ResultData;
use Espo\Core\Authentication\Result\FailReason;
use Espo\Core\Authentication\TwoFactor\Login;
use Espo\Core\Authentication\TwoFactor\Totp\Util;
use Espo\Entities\User;
use Espo\Entities\UserData;
use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareRbac\Service\Mfa\BackupCodeService;
use Espo\ORM\EntityManager;
use Espo\Repositories\UserData as UserDataRepository;
use RuntimeException;

/**
 * Override do TotpLogin nativo para adicionar fallback com backup codes.
 *
 * Fluxo de login:
 *  1. Sem código → second step required (mesmo que o nativo).
 *  2. Com código de 6 dígitos → tenta TOTP via Util::verifyCode.
 *  3. TOTP falha → tenta BackupCodeService::consume (one-time-use).
 *  4. Ambos falham → Result::fail(CODE_NOT_VERIFIED).
 *
 * Gotcha: TotpLogin::verifyCode é private — replicamos a lógica (~10 LOC)
 * via Util::verifyCode diretamente (abordagem mais limpa que reflection).
 *
 * Override registrado em Resources/metadata/app/authentication2FAMethods.json
 * (não em containerServices.json — LoginFactory resolve via nome simbólico 'Totp').
 *
 * Story 2.3 — AC5, AC6.
 */
final class TogareTotpLogin implements Login
{
    public const NAME = 'Totp';

    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly Util $totp,
        private readonly BackupCodeService $backupCodeService,
        private readonly AuditLogContract $auditLog,
    ) {
    }

    public function login(Result $result, Request $request): Result
    {
        $code = $request->getHeader(HeaderKey::AUTHORIZATION_CODE);

        $user = $result->getUser();
        if (! $user) {
            throw new RuntimeException('No user.');
        }

        if (! $code) {
            return Result::secondStepRequired($user, ResultData::createWithMessage('enterTotpCode'));
        }

        // Normalizar: alguns apps mostram "123 456" ou "123-456".
        $normalized = \preg_replace('/[\s\-]/', '', $code) ?? $code;

        // Guard: código vazio ou curto demais não é TOTP (6 dígitos) nem backup code (8 chars).
        // Previne chamadas bcrypt desnecessárias com inputs inválidos (vetor DoS leve).
        if (\strlen($normalized) < 6) {
            return Result::fail(FailReason::CODE_NOT_VERIFIED);
        }

        // 1) Tenta TOTP.
        if ($this->verifyTotp($user, $normalized)) {
            return $result;
        }

        // 2) Fallback: backup code.
        if ($this->backupCodeService->consume($user, $normalized)) {
            TogareLogger::event(
                'info',
                'mfa.backup_code.login',
                \sprintf("Login via backup code MFA para user '%s'.", (string) $user->get('userName')),
                ['userId' => $user->getId()],
            );

            // Dual-write (Story 2.4 — FR37).
            $this->auditLog->log(
                'mfa.backup_code.login',
                'User',
                (string) $user->getId(),
                ['userName' => $user->get('userName')],
            );

            return $result;
        }

        TogareLogger::event(
            'warning',
            'mfa.backup_code.login.failed',
            \sprintf("Falha de login MFA para user '%s' (TOTP e backup code inválidos).", (string) $user->get('userName')),
            ['userId' => $user->getId()],
        );

        return Result::fail(FailReason::CODE_NOT_VERIFIED);
    }

    /**
     * Replica a lógica de TotpLogin::verifyCode (private) usando Util direto.
     * Workaround necessário porque TotpLogin::verifyCode é private (não herdável).
     */
    private function verifyTotp(User $user, string $code): bool
    {
        $userData = $this->getUserDataRepository()->getByUserId($user->getId());

        if (! $userData) {
            return false;
        }

        if (! $userData->get('auth2FA')) {
            return false;
        }

        if ($userData->get('auth2FAMethod') !== self::NAME) {
            return false;
        }

        $secret = $userData->get('auth2FATotpSecret');
        if (! $secret) {
            return false;
        }

        return $this->totp->verifyCode((string) $secret, $code);
    }

    /** @return UserDataRepository */
    private function getUserDataRepository(): mixed
    {
        return $this->entityManager->getRepository(UserData::ENTITY_TYPE);
    }
}
