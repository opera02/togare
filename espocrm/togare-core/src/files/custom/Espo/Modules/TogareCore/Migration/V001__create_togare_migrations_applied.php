<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Migration;

use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;

// phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps
final class V001__create_togare_migrations_applied implements MigrationInterface
{
    public function version(): string
    {
        return 'V001__create_togare_migrations_applied';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS togare_migrations_applied (
                schema_version VARCHAR(200) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL,
                checksum CHAR(64) NOT NULL
            )
        SQL);
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS togare_migrations_applied');
    }
}
