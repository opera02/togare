<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Migration;

use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;

/**
 * Cria tabela da fila (outbox pattern — ADR 0005).
 *
 * Colunas chave:
 *   - idempotency_key UNIQUE — previne dupla-execução silenciosa.
 *   - índice composto (queue_name, status, next_retry_at, created_at) —
 *     acelera o SELECT ... FOR UPDATE SKIP LOCKED do claim().
 *
 * Em SQLite (testes unit) o SQL funciona mesmo sem ENGINE=InnoDB.
 */
// phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps
final class V004__create_togare_queue_items implements MigrationInterface
{
    public function version(): string
    {
        return 'V004__create_togare_queue_items';
    }

    public function up(PDO $pdo): void
    {
        $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $engine = $isMysql ? 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS togare_queue_items (
                id VARCHAR(32) NOT NULL PRIMARY KEY,
                queue_name VARCHAR(64) NOT NULL,
                idempotency_key VARCHAR(200) NOT NULL,
                payload LONGTEXT NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                retry_count INT UNSIGNED NOT NULL DEFAULT 0,
                last_error TEXT NULL,
                next_retry_at DATETIME NULL,
                processing_started_at DATETIME NULL,
                completed_at DATETIME NULL,
                correlation_id VARCHAR(64) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            ) {$engine}
        ");

        $pdo->exec('CREATE UNIQUE INDEX uk_togare_queue_idempotency ON togare_queue_items (idempotency_key)');
        $pdo->exec('CREATE INDEX idx_togare_queue_claim ON togare_queue_items (queue_name, status, next_retry_at, created_at)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS togare_queue_items');
    }
}
