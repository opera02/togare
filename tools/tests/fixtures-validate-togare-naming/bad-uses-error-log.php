<?php

/**
 * Fixture BAD — usa error_log() sem escape hatch.
 * Esperado: validator bloqueia (exit 1) com R5.
 */

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services;

class BadService
{
    public function boom(): void
    {
        error_log('naughty');
    }
}
