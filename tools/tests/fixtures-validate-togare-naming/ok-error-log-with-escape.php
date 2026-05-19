<?php

/**
 * Fixture OK — usa error_log() COM escape hatch documentado.
 * Esperado: validator aceita (exit 0).
 */

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services;

class BootstrapThing
{
    public function preContainer(): void
    {
        error_log('runs before DI'); // escape hatch: bootstrap
    }
}
