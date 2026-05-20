<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Migration;

use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;

/**
 * Cria a tabela `togare_audit_log` (Story 2.4 — FR37, NFR10).
 *
 * Append-only por desenho: a aplicação só recebe `SELECT, INSERT` na tabela
 * (script `docker/scripts/audit-log-lockdown.sh` revoga UPDATE/DELETE no nível
 * de banco — operação de DBA com senha root, fora do alcance do app user).
 *
 * Tipos:
 *   - id VARCHAR(32): UUID hex sem hífen — compat com togare_queue_items (V004).
 *   - occurred_at DATETIME(3): precisão ms — alinhado com TogareLogger.
 *   - entity_id VARCHAR(32): cabe IDs nativos (17) e UUIDs custom (32).
 *   - user_agent VARCHAR(500): cobre user-agents reais de browsers atuais.
 *   - context_json LONGTEXT: compat com SQLite em testes (sem JSON nativo).
 *
 * Sem soft-delete e sem created_at/updated_at: audit é evento histórico,
 * não entidade mutável. occurred_at é o único timestamp.
 */
// phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps
final class V006__create_togare_audit_log implements MigrationInterface
{
    public function version(): string
    {
        return 'V006__create_togare_audit_log';
    }

    public function up(PDO $pdo): void
    {
        $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $engine = $isMysql ? 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $occurredAtType = $isMysql ? 'DATETIME(3)' : 'DATETIME';

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS togare_audit_log (
                id VARCHAR(32) NOT NULL PRIMARY KEY,
                occurred_at {$occurredAtType} NOT NULL,
                event VARCHAR(120) NOT NULL,
                entity_type VARCHAR(80) NOT NULL,
                entity_id VARCHAR(32) NULL DEFAULT NULL,
                user_id VARCHAR(17) NULL DEFAULT NULL,
                user_name VARCHAR(120) NULL DEFAULT NULL,
                ip_address VARCHAR(45) NULL DEFAULT NULL,
                user_agent VARCHAR(500) NULL DEFAULT NULL,
                correlation_id VARCHAR(64) NULL DEFAULT NULL,
                context_json LONGTEXT NULL DEFAULT NULL
            ) {$engine}
        ");

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_occurred_at ON togare_audit_log (occurred_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_event_occurred ON togare_audit_log (event, occurred_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_entity ON togare_audit_log (entity_type, entity_id, occurred_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_user_occurred ON togare_audit_log (user_id, occurred_at)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS togare_audit_log');
    }
}
