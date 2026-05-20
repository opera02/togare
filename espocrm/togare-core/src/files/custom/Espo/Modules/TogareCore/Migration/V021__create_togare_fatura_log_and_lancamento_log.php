<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Migration;

use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;

/**
 * V021 — Story 6.3: cria 2 tabelas auxiliares append-only:
 *  - `togare_fatura_log` — audit append-only de eventos de Fatura
 *    (created/modified/status_changed/cancelled/removed), escrita via PDO
 *    direto pelo AuditFaturaHook E pelo FaturaSaldoService::transitionStatus.
 *  - `togare_lancamento_financeiro_log` — audit append-only de eventos de
 *    LancamentoFinanceiro (created/modified/removed/estorno_aplicado),
 *    escrita pelo AuditLancamentoHook.
 *
 * **Importante:** as tabelas de entidade `fatura` e `lancamento_financeiro`
 * são criadas automaticamente pelo EspoCRM via rebuild a partir dos
 * entityDefs (mesmo pattern V018/V020).
 *
 * Pattern V018/V020: try/catch DUPLICATE COLUMN/KEY/already exists → idempotência.
 * Audit log entries `togare_fatura_log.schema_created_v021` e
 * `togare_lancamento_financeiro_log.schema_created_v021` defensivas.
 * Down: no-op intencional.
 */
// phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps
final class V021__create_togare_fatura_log_and_lancamento_log implements MigrationInterface
{
    public function version(): string
    {
        return 'V021__create_togare_fatura_log_and_lancamento_log';
    }

    public function up(PDO $pdo): void
    {
        $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $engine = $isMysql ? 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        $statements = [
            "CREATE TABLE IF NOT EXISTS togare_fatura_log (
                id VARCHAR(32) NOT NULL PRIMARY KEY,
                event VARCHAR(50) NOT NULL,
                fatura_id VARCHAR(17) NULL,
                user_id VARCHAR(17) NULL,
                payload JSON NULL,
                created_at DATETIME NOT NULL
            ) {$engine}",
            'CREATE INDEX idx_fatura_log_event_created_at ON togare_fatura_log (event, created_at)',
            'CREATE INDEX idx_fatura_log_fatura_id ON togare_fatura_log (fatura_id)',
            "CREATE TABLE IF NOT EXISTS togare_lancamento_financeiro_log (
                id VARCHAR(32) NOT NULL PRIMARY KEY,
                event VARCHAR(50) NOT NULL,
                lancamento_id VARCHAR(17) NULL,
                user_id VARCHAR(17) NULL,
                payload JSON NULL,
                created_at DATETIME NOT NULL
            ) {$engine}",
            'CREATE INDEX idx_lancamento_financeiro_log_event_created_at ON togare_lancamento_financeiro_log (event, created_at)',
            'CREATE INDEX idx_lancamento_financeiro_log_lancamento_id ON togare_lancamento_financeiro_log (lancamento_id)',
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

        $this->writeAuditLog($pdo, 'togare_fatura_log.schema_created_v021', 'Tabela togare_fatura_log criada (audit append-only de Fatura; tabela fatura criada pelo EspoCRM rebuild a partir do entityDefs).');
        $this->writeAuditLog($pdo, 'togare_lancamento_financeiro_log.schema_created_v021', 'Tabela togare_lancamento_financeiro_log criada (audit append-only de LancamentoFinanceiro; tabela lancamento_financeiro criada pelo EspoCRM rebuild a partir do entityDefs).');
    }

    public function down(PDO $pdo): void
    {
        // No-op intencional (preserva dados; mesmo pattern V018/V020).
    }

    /**
     * Grava entry de audit registrando estado pós-V021.
     * Try/catch defensivo (togare_audit_log pode não existir em testes isolados).
     */
    private function writeAuditLog(PDO $pdo, string $event, string $note): void
    {
        try {
            $context = \json_encode(
                [
                    'count_total' => 0,
                    'note' => $note,
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
                'event' => $event,
                'entity_type' => 'Migration',
                'user_name' => 'system:migration',
                'context_json' => $context,
            ]);
        } catch (\Throwable) {
            // togare_audit_log pode não existir em testes isolados — pular.
        }
    }
}
