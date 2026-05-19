<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Migration;

use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;

/**
 * V016 — Story 4b.2: cria tabela auxiliar `togare_prazo_lembrete` (subsistema
 * togare-core/Notifications & Reminders — ADR-04) + 4 indexes (1 PK + 1 UNIQUE
 * + 2 secundários).
 *
 * **Por que tabela auxiliar (não entity EspoCRM)**: mesmo pattern de
 * `togare_audit_log` (V006) e `togare_ambiguity_log` (V014). Não é exposta via
 * REST/UI nativa; acesso exclusivo via Hook `EnqueuePrazoLembretesHook` (write)
 * e `PrazoReminderJob` (read+write+update). RBAC implícito (sem entityDefs/scopes).
 *
 * **Decisões #1 e #4 da Story 4b.2:**
 *  - Migration **V016** (ADR-04 nominou "V011" mas V011 foi pulada).
 *  - UNIQUE INDEX `(prazo_id, user_id, marco)` é a defesa de idempotência
 *    primária. INSERT IGNORE / INSERT OR IGNORE no Hook bate em UNIQUE em
 *    re-execuções.
 *
 * **Marcos suportados (varchar):** `D-7`, `D-3`, `D-1`,
 * `status_atrasado_reagendado`, `status_aguardando_cliente`,
 * `status_aguardando_correcao`. (D-0 reservado para Story 4b.3.)
 *
 * Pattern V010/V012/V013/V014: try/catch DUPLICATE COLUMN/KEY/already exists →
 * idempotência. Audit log entry `togare_prazo_lembrete.schema_created_v016`
 * defensiva (try/catch \\Throwable se togare_audit_log não existir em testes
 * isolados). Down: no-op intencional (preserva dados).
 */
// phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps
final class V016__create_togare_prazo_lembrete implements MigrationInterface
{
    public function version(): string
    {
        return 'V016__create_togare_prazo_lembrete';
    }

    public function up(PDO $pdo): void
    {
        $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $engine = $isMysql ? 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        // CREATE TABLE — colunas alinhadas ao AC1 da Story 4b.2.
        // Volume estimado: 50 advs × 5 prazos × 4 marcos = 1000 entries/dia
        // (ADR-04 §"Consequências"). Sem partições no MVP.
        $statements = [
            "CREATE TABLE IF NOT EXISTS togare_prazo_lembrete (
                id VARCHAR(24) NOT NULL PRIMARY KEY,
                prazo_id VARCHAR(24) NOT NULL,
                user_id VARCHAR(17) NOT NULL,
                marco VARCHAR(32) NOT NULL,
                canal VARCHAR(16) NOT NULL DEFAULT 'both',
                scheduled_for DATETIME NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'pending',
                sent_at DATETIME NULL,
                attempt_count INT NOT NULL DEFAULT 0,
                last_error TEXT NULL,
                created_at DATETIME NOT NULL,
                modified_at DATETIME NOT NULL
            ) {$engine}",
            // Decisão #4 — UNIQUE garante idempotência cross-execução do Hook.
            'CREATE UNIQUE INDEX prazo_lembrete_unique
                ON togare_prazo_lembrete (prazo_id, user_id, marco)',
            // Query principal do PrazoReminderJob (pega pendentes vencidos).
            'CREATE INDEX idx_prazo_lembrete_status_scheduled
                ON togare_prazo_lembrete (status, scheduled_for)',
            // Listagem futura "meus lembretes".
            'CREATE INDEX idx_prazo_lembrete_user
                ON togare_prazo_lembrete (user_id)',
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
        // No-op intencional (preserva dados; pattern V010/V012/V013/V014).
    }

    /**
     * Grava entry de audit `togare_prazo_lembrete.schema_created_v016`
     * registrando estado pós-V016 (count_total=0 — tabela acabou de ser criada).
     * Try/catch defensivo (pattern V013/V014).
     */
    private function writeAuditLog(PDO $pdo): void
    {
        try {
            $context = \json_encode(
                [
                    'count_total' => 0,
                    'note' => 'Tabela togare_prazo_lembrete criada (subsistema togare-core/Notifications & Reminders ADR-04 — Story 4b.2 alertas D-7/D-3/D-1 + status dirigidos via PrazoReminderJob).',
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
                'event' => 'togare_prazo_lembrete.schema_created_v016',
                'entity_type' => 'Migration',
                'user_name' => 'system:migration',
                'context_json' => $context,
            ]);
        } catch (\Throwable) {
            // togare_audit_log pode não existir em testes isolados — pular.
        }
    }
}
