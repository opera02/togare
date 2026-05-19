<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Migration;

use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;

/**
 * V020 — Story 6.1: cria tabela auxiliar `togare_contrato_honorarios_log`
 * (audit append-only de eventos de ContratoHonorarios, principalmente
 * soft-purge tombstones) + 2 indexes.
 *
 * **Importante:** a tabela de entidade `contrato_honorarios` é criada
 * automaticamente pelo EspoCRM via rebuild a partir do `entityDefs/
 * ContratoHonorarios.json` (mesmo pattern Cliente/Processo/Prazo/Audiencia/
 * PublicacaoAmbigua/Documento). Esta Migration foca apenas em
 * `togare_contrato_honorarios_log`, escrita via PDO direto pelo
 * SoftPurgeContratoHook (Decisão #5 da Story 6.1). Story futura de
 * hard-delete por janela (paralela à 5.5 do Documento) REUSARÁ esta mesma
 * tabela sem schema novo.
 *
 * Pattern V018 (togare_documento_log): try/catch DUPLICATE COLUMN/KEY/already
 * exists → idempotência. Audit log entry
 * `togare_contrato_honorarios_log.schema_created_v020` defensiva.
 * Down: no-op intencional.
 */
// phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps
final class V020__create_togare_contrato_honorarios_log implements MigrationInterface
{
    public function version(): string
    {
        return 'V020__create_togare_contrato_honorarios_log';
    }

    public function up(PDO $pdo): void
    {
        $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $engine = $isMysql ? 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        $statements = [
            "CREATE TABLE IF NOT EXISTS togare_contrato_honorarios_log (
                id VARCHAR(32) NOT NULL PRIMARY KEY,
                event VARCHAR(50) NOT NULL,
                contrato_id VARCHAR(17) NULL,
                user_id VARCHAR(17) NULL,
                payload JSON NULL,
                created_at DATETIME NOT NULL
            ) {$engine}",
            'CREATE INDEX idx_contrato_honorarios_log_event_created_at ON togare_contrato_honorarios_log (event, created_at)',
            'CREATE INDEX idx_contrato_honorarios_log_contrato_id ON togare_contrato_honorarios_log (contrato_id)',
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
        // No-op intencional (preserva dados; mesmo pattern V014/V018).
    }

    /**
     * Grava entry de audit registrando estado pós-V020.
     * Try/catch defensivo (togare_audit_log pode não existir em testes isolados).
     */
    private function writeAuditLog(PDO $pdo): void
    {
        try {
            $context = \json_encode(
                [
                    'count_total' => 0,
                    'note' => 'Tabela togare_contrato_honorarios_log criada (audit append-only de ContratoHonorarios; tabela contrato_honorarios criada pelo EspoCRM rebuild a partir do entityDefs).',
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
                'event' => 'togare_contrato_honorarios_log.schema_created_v020',
                'entity_type' => 'Migration',
                'user_name' => 'system:migration',
                'context_json' => $context,
            ]);
        } catch (\Throwable) {
            // togare_audit_log pode não existir em testes isolados — pular.
        }
    }
}
