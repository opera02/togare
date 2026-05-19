<?php

/**
 * Fixture BAD — migration criando tabela sem prefixo togare_.
 * Esperado: validator bloqueia (exit 1) com 1 erro R3.
 */

declare(strict_types=1);

namespace Togare\Smoke\Migration;

class V001__create_bad_name
{
    public function up(): void
    {
        // Tabela sem prefixo togare_ — deve falhar R3.
        $sql = "CREATE TABLE queue_items (id INT PRIMARY KEY)";
        // ...
    }
}
