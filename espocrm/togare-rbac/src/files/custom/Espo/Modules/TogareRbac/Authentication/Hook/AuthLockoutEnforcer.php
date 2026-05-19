<?php

declare(strict_types=1);

namespace Espo\Modules\TogareRbac\Authentication\Hook;

use Espo\Core\Authentication\AuthenticationData;
use Espo\Core\Authentication\Hook\BeforeLogin;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\Forbidden;
use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\Modules\TogareCore\Services\RateLimiter;
use Espo\Modules\TogareCore\Services\TogareLogger;

/**
 * Rejeita tentativas de login quando o usuário atingiu o limite de falhas (NFR11).
 *
 * Roda ANTES da validação de credenciais (BeforeLogin) — benefícios:
 *  1. Previne timing attacks: bcrypt verify não é executado em lockout ativo.
 *  2. Performance: rejeita early sem custo de DB/bcrypt.
 *
 * Dual-write em lockout: togare_audit_log (auth.lockout) + TogareLogger (JSON structured).
 * BeforeLogin lançando Forbidden NÃO dispara onFailHookClassNameList — gotcha #10:
 * apenas auth.lockout é registrado, SEM auth.login.failed duplicado.
 */
final class AuthLockoutEnforcer implements BeforeLogin
{
    public function __construct(
        private readonly RateLimiter $rateLimiter,
        private readonly AuditLogContract $auditLog,
    ) {
    }

    public function process(AuthenticationData $data, Request $request): void
    {
        $username = $data->getUsername();

        if ($username === null || $username === '') {
            return;
        }

        $key = AuthRateLimitConfig::KEY_PREFIX . \mb_strtolower($username);
        $ip = $request->getServerParam('REMOTE_ADDR');
        $ipStr = \is_string($ip) ? $ip : null;

        if ($this->rateLimiter->peek($key, AuthRateLimitConfig::LIMIT, AuthRateLimitConfig::WINDOW_SECONDS)) {
            return;
        }

        $context = \array_filter([
            'userName' => $username,
            'ipAddress' => $ipStr,
            'limit' => AuthRateLimitConfig::LIMIT,
            'windowSec' => AuthRateLimitConfig::WINDOW_SECONDS,
        ], static fn ($v): bool => $v !== null);

        $this->auditLog->log('auth.lockout', '*', null, $context);

        TogareLogger::event(
            'warning',
            'auth.lockout.blocked',
            "Login bloqueado por lockout para '{$username}'.",
            $context,
        );

        throw new Forbidden('Conta temporariamente bloqueada. Tente novamente em 15 minutos.');
    }
}
