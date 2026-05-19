<?php

/**
 * Fixture OK — classe com prefixo Togare + namespace Togare\*.
 * Esperado: validator aceita (exit 0).
 */

declare(strict_types=1);

namespace Togare\Smoke\Services;

class TogareSmokeService
{
    public function ping(): string
    {
        return 'pong';
    }
}
