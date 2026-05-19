<?php

declare(strict_types=1);

namespace Espo\Modules\TogareRbac\Authentication\Hook;

use Espo\Core\Authentication\AuthenticationData;
use Espo\Core\Authentication\Hook\OnResult;
use Espo\Core\Authentication\Result;
use Espo\Core\Api\Request;
use Espo\Modules\TogareCore\Services\RateLimiter;

/**
 * Zera o contador de falhas quando o login tem sucesso (NFR11 UX).
 *
 * Decisão UX: sócia que errou 4× e acertou na 5ª não fica penalizada
 * pelo histórico — contador reseta. Contador "novo" permite 5 novas tentativas
 * independentes a partir do próximo evento de falha.
 *
 * Registrado em onSuccessHookClassNameList. Roda apenas em sucesso.
 */
final class AuthSuccessRateLimitReset implements OnResult
{
    public function __construct(
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    public function process(Result $result, AuthenticationData $data, Request $request): void
    {
        if ($result->isFail()) {
            return;
        }

        $username = $data->getUsername();

        if ($username === null || $username === '') {
            return;
        }

        $key = AuthRateLimitConfig::KEY_PREFIX . \mb_strtolower($username);

        $this->rateLimiter->reset($key);
    }
}
