<?php

/**
 * Fixture OK — migration criando tabela togare_*.
 * Esperado: validator aceita (exit 0).
 */

declare(strict_types=1);

namespace Togare\Smoke\Migration;

class V001__create_togare_queue_items
{
    public function up(): void
    {
        $sql = "CREATE TABLE togare_queue_items (id INT PRIMARY KEY)";
        // ...
    }
}
