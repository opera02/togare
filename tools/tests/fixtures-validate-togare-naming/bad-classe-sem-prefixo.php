<?php

/**
 * Fixture BAD — namespace sem Togare\ e classe sem prefixo.
 * Esperado: validator bloqueia (exit 1) com 2 erros R1.
 */

declare(strict_types=1);

namespace App\Bad\Services;

class MauvaisService
{
    public function ping(): string
    {
        return 'pong';
    }
}
