<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Migration;

use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;

/**
 * V018 — Story 5.2: cria tabela auxiliar `togare_documento_log` (audit
 * append-only de eventos de Documento, principalmente soft-purge tombstones)
 * + 2 indexes.
 *
 * **Importante:** a tabela de entidade `documento` é criada automaticamente
 * pelo EspoCRM via rebuild a partir do `entityDefs/Documento.json` (mesmo
 * pattern Cliente/Processo/Prazo/Audiencia/PublicacaoAmbigua).
 *
 * Esta Migration foca apenas em `togare_documento_log`, escrita via PDO direto
 * pelo SoftPurgeDocumentoHook (Story 5.2 Decisão #10). Story 5.5 REUSA esta
 * mesma tabela sem schema novo (Decisão #2 da 5.5) — o `TogareBridgeHardDeleteJob`
 * grava row IRMÃ event=`documento.hard_deleted` com mesmo `tombstoneId` para
 * marcar o tombstone como hard-deletado; consulta de pendentes usa NOT EXISTS
 * sobre o mesmo log. Não há tabela `togare_bridge_tombstones`.
 *
 * Pattern V014: try/catch DUPLICATE COLUMN/KEY/already exists → idempotência.
 * Audit log entry `togare_documento_log.schema_created_v018` defensiva.
 * Down: no-op intencional.
 */
// phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps
final class V018__create_togare_documento_log implements MigrationInterface
{
    public function version(): string
    {
        return 'V018__create_togare_documento_log';
    }

    public function up(PDO $pdo): void
    {
        $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $engine = $isMysql ? 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        $statements = [
            "CREATE TABLE IF NOT EXISTS togare_documento_log (
                id VARCHAR(32) NOT NULL PRIMARY KEY,
                event VARCHAR(50) NOT NULL,
                documento_id VARCHAR(17) NULL,
                user_id VARCHAR(17) NULL,
                payload JSON NULL,
                created_at DATETIME NOT NULL
            ) {$engine}",
            'CREATE INDEX idx_documento_log_event_created_at ON togare_documento_log (event, created_at)',
            'CREATE INDEX idx_documento_log_documento_id ON togare_documento_log (documento_id)',
        ];

        foreach ($statements as $sql) {
            try {
                $pdo->exec($sql);
            } catch (\PDOException $e) {
                $msg = $e->getMessage();
                if (
                    \str_contains($msg, 'Duplicate column name')
                    || \str_contains($msg, 'Duplicate key name')
                    || \str_contains($msg, 'already exists')
                    || \stripos($msg, 'duplicate column name') !== false
                    || \stripos($msg, 'already exists') !== false
                ) {
                    continue;
                }
                throw $e;
            }
        }

        $this->writeAuditLog($pdo);
    }

    public function down(PDO $pdo): void
    {
        // No-op intencional (preserva dados; mesmo pattern V014).
    }

    /**
     * Grava entry de audit registrando estado pós-V018 (count_total=0 — tabela acabou de ser criada).
     * Try/catch defensivo.
     */
    private function writeAuditLog(PDO $pdo): void
    {
        try {
            $context = \json_encode(
                [
                    'count_total' => 0,
                    'note' => 'Tabela togare_documento_log criada (audit append-only de Documento); tabela documento é criada automaticamente pelo EspoCRM rebuild a partir do entityDefs.',
                ],
                JSON_UNESCAPED_UNICODE,
            );
            if ($context === false) {
                $context = '{}';
            }

            $insert = $pdo->prepare(
                'INSERT INTO togare_audit_log '
                . '(id, occurred_at, event, entity_type, entity_id, user_id, user_name, ip_address, user_agent, correlation_id, context_json) '
                . 'VALUES (:id, :occurred_at, :event, :entity_type, NULL, NULL, :user_name, NULL, NULL, NULL, :context_json)'
            );
            if ($insert === false) {
                return;
            }
            $insert->execute([
                'id' => \bin2hex(\random_bytes(16)),
                'occurred_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'event' => 'togare_documento_log.schema_created_v018',
                'entity_type' => 'Migration',
                'user_name' => 'system:migration',
                'context_json' => $context,
            ]);
        } catch (\Throwable) {
            // togare_audit_log pode não existir em testes isolados — pular.
        }
    }
}
