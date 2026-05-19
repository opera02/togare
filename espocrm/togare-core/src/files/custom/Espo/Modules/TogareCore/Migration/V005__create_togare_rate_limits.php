<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Migration;

use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;

/**
 * Cria tabela do rate limiter (sliding window em MariaDB — ver RateLimiter).
 *
 * Decisão arquitetural: Redis não é usado por ora — o volume MVP é baixo
 * (DJEN 30 req/min + auth 5/15min). Se medições apontarem gargalo, migrar
 * RateLimiter para Redis é alteração contida (1 classe).
 */
// phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps
final class V005__create_togare_rate_limits implements MigrationInterface
{
    public function version(): string
    {
        return 'V005__create_togare_rate_limits';
    }

    public function up(PDO $pdo): void
    {
        $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $engine = $isMysql ? 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS togare_rate_limits (
                rate_key VARCHAR(200) NOT NULL PRIMARY KEY,
                counter INT UNSIGNED NOT NULL DEFAULT 0,
                window_started_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            ) {$engine}
        ");
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS togare_rate_limits');
    }
}
