<?php

/**
 * Fixture OK — namespace Espo\Modules\TogareCore (convenção runtime EspoCRM).
 * Classe sem prefixo Togare é aceita porque o namespace já a localiza como Togare.
 * Esperado: validator aceita (exit 0).
 */

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services;

class MigrationRunner
{
    public function runPending(): int
    {
        return 0;
    }
}
