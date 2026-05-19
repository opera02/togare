<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Migration;

use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;

/**
 * V017 — Story 4b.4: adiciona coluna `failure_category VARCHAR(40) NULL` em
 * `togare_queue_items` + índice composto auxiliar
 * `idx_togare_queue_failure_category (queue_name, status, failure_category)`.
 *
 * **Por que:** ADR 0009 alinha retry × circuit breaker. Quando o CB do
 * `DjenAdapter` fecha após cooldown, o worker reagenda items presos via
 * `QueueService::rescheduleAfterCircuitBreakerClose($queueName, $failureCategory)`
 * — que filtra pelo valor desta coluna. Sem `failure_category`, a única
 * alternativa seria filtrar `last_error LIKE 'djen sync window failed:%'`
 * (frágil, não-indexável).
 *
 * **Categoria é texto livre snake_case (não enum):** convenção documentada
 * no ADR §2 — categorias evoluem por adapter (`adapter_unavailable`,
 * `license_expired`, `forbidden`, `rate_limit_exceeded` futuros). Validação
 * fica no código que chama `markFailed`, não no schema.
 *
 * **Idempotência:** sintaxe driver-aware MariaDB vs SQLite via
 * `PDO::ATTR_DRIVER_NAME`; try/catch tolera SQLSTATE 42S21/42000/1060/1061
 * (coluna/índice já existe). Pattern derivado da V015.
 *
 * **Down:** no-op intencional (preserva dados; pattern V010/V012/V013/V015).
 */
// phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps
final class V017__add_queue_items_failure_category implements MigrationInterface
{
    public function version(): string
    {
        return 'V017__add_queue_items_failure_category';
    }

    public function up(PDO $pdo): void
    {
        $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';

        // 1. ADD COLUMN failure_category — sintaxe portável MariaDB+SQLite.
        $this->execIgnoringDuplicate(
            $pdo,
            'ALTER TABLE togare_queue_items ADD COLUMN failure_category VARCHAR(40) DEFAULT NULL',
        );

        // 2. CREATE INDEX composto — sintaxe difere por driver.
        if ($isMysql) {
            $this->execIgnoringDuplicate(
                $pdo,
                'ALTER TABLE togare_queue_items ADD INDEX idx_togare_queue_failure_category '
                . '(queue_name, status, failure_category)',
            );
        } else {
            // SQLite (testes) — sintaxe portável.
            $this->execIgnoringDuplicate(
                $pdo,
                'CREATE INDEX idx_togare_queue_failure_category '
                . 'ON togare_queue_items (queue_name, status, failure_category)',
            );
        }

        // 3. Audit log entry — count_total para sanity check.
        $this->writeAuditLog($pdo);
    }

    public function down(PDO $pdo): void
    {
        // No-op intencional (preserva dados; pattern V010/V012/V013/V015).
    }

    private function execIgnoringDuplicate(PDO $pdo, string $sql): void
    {
        try {
            $pdo->exec($sql);
        } catch (\PDOException $e) {
            $msg = $e->getMessage();
            if (
                \str_contains($msg, 'Duplicate key name')
                || \str_contains($msg, 'Duplicate column name')
                || \stripos($msg, 'already exists') !== false
                || \stripos($msg, 'duplicate column') !== false
            ) {
                return;
            }
            throw $e;
        }
    }

    private function writeAuditLog(PDO $pdo): void
    {
        try {
            $stmt = $pdo->query('SELECT COUNT(*) AS c FROM togare_queue_items');
            if ($stmt === false) {
                return;
            }
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $total = $row !== false ? (int) $row['c'] : 0;

            $context = \json_encode(
                [
                    'count_total' => $total,
                    'note' => 'Story 4b.4 / ADR 0009: failure_category coluna nova + index composto. '
                        . 'Suporta QueueService::rescheduleAfterCircuitBreakerClose para alinhar retry × CB.',
                ],
                JSON_UNESCAPED_UNICODE,
            );
            if ($context === false) {
                $context = '{}';
            }

            $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
            $occurredAt = $isMysql ? 'NOW(3)' : "datetime('now')";

            $insert = $pdo->prepare(
                'INSERT INTO togare_audit_log '
                . '(id, occurred_at, event, entity_type, entity_id, user_id, user_name, ip_address, user_agent, correlation_id, context_json) '
                . "VALUES (:id, {$occurredAt}, :event, :entity_type, NULL, NULL, :user_name, NULL, NULL, NULL, :context_json)",
            );
            if ($insert === false) {
                return;
            }
            $insert->execute([
                'id' => \bin2hex(\random_bytes(16)),
                'event' => 'togare_queue_items.failure_category_added_v017',
                'entity_type' => 'Migration',
                'user_name' => 'system:migration',
                'context_json' => $context,
            ]);
        } catch (\Throwable) {
            // togare_audit_log pode não existir em testes isolados — pular.
        }
    }
}
