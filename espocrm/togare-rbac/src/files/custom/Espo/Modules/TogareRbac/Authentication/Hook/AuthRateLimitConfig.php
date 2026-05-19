<?php

declare(strict_types=1);

namespace Espo\Modules\TogareRbac\Authentication\Hook;

/**
 * Constantes compartilhadas entre os 3 hooks de rate limit de auth (NFR11).
 *
 * Centraliza LIMIT, WINDOW_SECONDS e KEY_PREFIX para evitar drift de valores
 * entre AuthLockoutEnforcer, AuthFailedAttemptCounter e AuthSuccessRateLimitReset.
 */
final class AuthRateLimitConfig
{
    public const LIMIT = 5;
    public const WINDOW_SECONDS = 900; // 15 min
    public const KEY_PREFIX = 'auth.failed.user:';

    private function __construct()
    {
    }
}
