<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Migration;

use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;

/**
 * V013 — Story 4a.5.1: coluna `data_cumprimento` (DATE NULL) + 2 indexes.
 *
 * **Contexto:** Story 4a.5.1 introduz o campo `dataCumprimento` na entity
 * Prazo — data INTERNA do escritório que representa "quando o advogado
 * planeja fazer este prazo". Distinta de `dataFatal` (deadline LEGAL do
 * tribunal). O dashlet BriefingDoDia muda de `meusPendentes` para
 * `pendentesParaHoje`, que filtra por `data_cumprimento <= today OR NULL`.
 *
 * Decisões #1+#4 da Story 4a.5.1:
 *  - Coluna nasce nullable — linhas pré-existentes ficam `NULL` e caem no
 *    painel via default seguro do boolFilter (advogado não esquece prazos
 *    sem planejamento setado).
 *  - SEM backfill — não computamos `dataFatal − 2 úteis` em massa para
 *    linhas pré-V013 (BUSINESS_DAYS_SUB não nativo MariaDB; PHP loop por
 *    linha custoso). Default só dispara em CRIAÇÃO via
 *    `DefaultDataCumprimentoHook` (BeforeSave order=15).
 *
 * Indexes:
 *  - idx_prazo_data_cumprimento          — filtro/order trivial.
 *  - idx_prazo_data_cumprimento_status   — composto (status, data_cumprimento).
 *    Cobre WHERE clause do `PendentesParaHoje`:
 *      status IN (...) AND (data_cumprimento IS NULL OR data_cumprimento <= ?)
 *    sem filesort/full-scan.
 *
 * Pattern V010/V012: try/catch DUPLICATE COLUMN/KEY/already exists →
 * idempotência. Audit log entry `prazo.schema_migrated_v013` defensiva
 * (try/catch \Throwable se togare_audit_log não existir em testes isolados).
 *
 * Down: no-op intencional (preserva dados; mesmo pattern V010/V012).
 */
// phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps
final class V013__add_prazo_data_cumprimento implements MigrationInterface
{
    public function version(): string
    {
        return 'V013__add_prazo_data_cumprimento';
    }

    public function up(PDO $pdo): void
    {
        $statements = [
            'ALTER TABLE prazo ADD COLUMN data_cumprimento DATE NULL',
            'CREATE INDEX idx_prazo_data_cumprimento ON prazo (data_cumprimento)',
            // Composto (status, data_cumprimento) — cobre PendentesParaHoje::apply()
            // sem filesort: WHERE status IN (...) AND (data_cumprimento IS NULL OR
            // data_cumprimento <= today). Mesma técnica do
            // idx_prazo_data_fatal_prioridade_weight (V012).
            'CREATE INDEX idx_prazo_data_cumprimento_status ON prazo (status, data_cumprimento)',
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
                    // SQLite messages diferem; case-insensitive defensivo.
                    || \stripos($msg, 'duplicate column name') !== false
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
        // No-op intencional (preserva dados; mesmo pattern V010/V012).
    }

    /**
     * Grava entry de audit `prazo.schema_migrated_v013` registrando contagem
     * total de prazos pós-V013 (sem dados modificados — só mudança de schema).
     * Try/catch defensivo — se audit log não existir (testes em SQLite isolado),
     * apenas pula sem bloquear migration.
     *
     * Pattern V012::writeAuditLog (Story 4a.5).
     */
    private function writeAuditLog(PDO $pdo): void
    {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) AS c FROM prazo");
            if ($stmt === false) {
                return;
            }
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $total = $row !== false ? (int) $row['c'] : 0;

            $context = \json_encode(
                [
                    'count_total' => $total,
                    'note' => 'Coluna nasce NULL para todas as linhas; default só dispara em criação via DefaultDataCumprimentoHook.',
                ],
                JSON_UNESCAPED_UNICODE,
            );
            if ($context === false) {
                $context = '{}';
            }

            $insert = $pdo->prepare(
                'INSERT INTO togare_audit_log '
                . '(id, occurred_at, event, entity_type, entity_id, user_id, user_name, ip_address, user_agent, correlation_id, context_json) '
                . 'VALUES (:id, NOW(3), :event, :entity_type, NULL, NULL, :user_name, NULL, NULL, NULL, :context_json)'
            );
            if ($insert === false) {
                return;
            }
            $insert->execute([
                'id' => \bin2hex(\random_bytes(16)),
                'event' => 'prazo.schema_migrated_v013',
                'entity_type' => 'Migration',
                'user_name' => 'system:migration',
                'context_json' => $context,
            ]);
        } catch (\Throwable) {
            // togare_audit_log pode não existir em testes isolados — pular.
        }
    }
}
