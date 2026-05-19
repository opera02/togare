<?php

/**
 * Fixture BAD — INSERT direto em togare_queue_items fora do QueueService.
 * Esperado: validator bloqueia (exit 1) com R6.
 */

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services;

use PDO;

class BadDirectInsertService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function boom(): void
    {
        $this->pdo->exec("INSERT INTO togare_queue_items (id, queue_name) VALUES ('x', 'djen')");
    }
}
