<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Migration;

use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;

/**
 * Fixture viva — tabela de smoke para exercitar o MigrationRunner.
 *
 * Pode ser dropada quando V004+ criar tabelas reais do togare-core
 * (togare_queue_items na Story 1a.4c, togare_audit_log em Epic 2).
 */
// phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps
final class V002__create_togare_core_smoke implements MigrationInterface
{
    public function version(): string
    {
        return 'V002__create_togare_core_smoke';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS togare_core_smoke (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                created_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS togare_core_smoke');
    }
}
