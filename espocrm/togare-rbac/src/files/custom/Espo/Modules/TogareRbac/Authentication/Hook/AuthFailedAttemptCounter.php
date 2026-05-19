<?php

declare(strict_types=1);

namespace Espo\Modules\TogareRbac\Authentication\Hook;

use Espo\Core\Authentication\AuthenticationData;
use Espo\Core\Authentication\Hook\OnResult;
use Espo\Core\Authentication\Result;
use Espo\Core\Api\Request;
use Espo\Modules\TogareCore\Services\RateLimiter;

/**
 * Incrementa o contador de falhas quando uma tentativa de auth falha (NFR11).
 *
 * Registrado em onFailHookClassNameList — roda APENAS em falhas de credencial
 * normais. Quando BeforeLogin lança Forbidden (lockout ativo), este hook NÃO
 * roda (gotcha #10 — o auth flow não chega a executar).
 *
 * Usa check() para incrementar (não peek()). O resultado booleano é descartado:
 * a decisão de bloquear é responsabilidade do AuthLockoutEnforcer no próximo
 * request (padrão "consultar antes de commitar").
 */
final class AuthFailedAttemptCounter implements OnResult
{
    public function __construct(
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    public function process(Result $result, AuthenticationData $data, Request $request): void
    {
        if (! $result->isFail()) {
            return;
        }

        $username = $data->getUsername();

        if ($username === null || $username === '') {
            return;
        }

        $key = AuthRateLimitConfig::KEY_PREFIX . \mb_strtolower($username);

        $this->rateLimiter->check($key, AuthRateLimitConfig::LIMIT, AuthRateLimitConfig::WINDOW_SECONDS);
    }
}
