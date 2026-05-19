<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Migration;

use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;

/**
 * V012 — Story 4a.5: coluna `prioridade_weight` (TINYINT) + index + backfill.
 *
 * **Contexto:** Story 4a.5 (BriefingDoDia) precisa ordenar Prazos por `dataFatal ASC`
 * e desempate por prioridade ENUM em ordem semântica (urgente > alta > normal >
 * baixa). Ordenação alfabética do enum stored produz ordem errada
 * (alfabética: alta < baixa < normal < urgente). Solução adotada (Decisão #2 da
 * Story 4a.5, Plano C): coluna stored `prioridade_weight` derivada por hook
 * BeforeSave (PrioridadeWeightHook order=10), indexável e estável.
 *
 * Mapping (espelhado em `PrioridadeWeightHook::PRIORIDADE_WEIGHT_MAP`):
 *   urgente → 4
 *   alta    → 3
 *   normal  → 2  (default; também aplicado a null/empty/inválido)
 *   baixa   → 1
 *
 * Backfill destrutivo idempotente — reaplica mapping a TODA linha existente
 * (deleted=0 OR deleted=1 ambos para compatibilidade com soft-delete EspoCRM).
 * Reaplicação sobrescreve com o mesmo valor → idempotente.
 *
 * Audit log entry `prazo.schema_migrated_v012` é gravada em `togare_audit_log`
 * com `count_total`, `count_updated_per_prioridade` (4 contadores) — registra
 * estado pré/pós-migration para compliance + rollback manual.
 *
 * Down: no-op intencional (preserva dados; mesmo pattern V010).
 */
// phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps
final class V012__add_prazo_prioridade_weight implements MigrationInterface
{
    public function version(): string
    {
        return 'V012__add_prazo_prioridade_weight';
    }

    public function up(PDO $pdo): void
    {
        $statements = [
            'ALTER TABLE prazo ADD COLUMN prioridade_weight TINYINT NOT NULL DEFAULT 2',
            'CREATE INDEX idx_prazo_prioridade_weight ON prazo (prioridade_weight)',
            // Composto (data_fatal, prioridade_weight) — espelha entityDefs::indexes.dataFatalPrioridadeWeight.
            // Cobre ORDER BY data_fatal ASC, prioridade_weight DESC sem filesort extra.
            'CREATE INDEX idx_prazo_data_fatal_prioridade_weight ON prazo (data_fatal, prioridade_weight)',
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
                ) {
                    continue;
                }
                throw $e;
            }
        }

        // Backfill destrutivo idempotente — atualiza TODAS as linhas mapeando
        // `prioridade` → `prioridade_weight`. Re-execução sobrescreve com mesmos
        // valores. SQLite não tem CASE em UPDATE no mesmo formato do MySQL?
        // CASE WHEN é SQL standard — funciona em ambos.
        $pdo->exec(
            "UPDATE prazo SET prioridade_weight = CASE prioridade "
            . "WHEN 'urgente' THEN 4 "
            . "WHEN 'alta' THEN 3 "
            . "WHEN 'normal' THEN 2 "
            . "WHEN 'baixa' THEN 1 "
            . "ELSE 2 END"
        );

        $this->writeAuditLog($pdo);
    }

    public function down(PDO $pdo): void
    {
        // No-op intencional (preserva dados; mesmo pattern V010/V009).
    }

    /**
     * Grava entry de audit no togare_audit_log registrando contagem pós-backfill
     * por prioridade. Try/catch defensivo — se audit log não existir (testes em
     * SQLite isolado), apenas pula sem bloquear migration.
     *
     * Schema V006 (Story 2.4): colunas `occurred_at`/`event`/`entity_type NOT NULL`/
     * `context_json` (NÃO `created_at`/`event_type`/`context`). Pattern espelha o
     * `writeAuditLog` da V009.
     */
    private function writeAuditLog(PDO $pdo): void
    {
        try {
            $stmt = $pdo->query(
                "SELECT prioridade_weight AS w, COUNT(*) AS c FROM prazo GROUP BY prioridade_weight"
            );
            if ($stmt === false) {
                return;
            }
            $perWeight = [];
            $total = 0;
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $w = (int) $row['w'];
                $c = (int) $row['c'];
                $perWeight['weight_' . $w] = $c;
                $total += $c;
            }

            $context = \json_encode(
                ['count_total' => $total, 'count_per_weight' => $perWeight],
                JSON_UNESCAPED_UNICODE
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
                'event' => 'prazo.schema_migrated_v012',
                'entity_type' => 'Migration',
                'user_name' => 'system:migration',
                'context_json' => $context,
            ]);
        } catch (\Throwable) {
            // togare_audit_log pode não existir em testes isolados — pular.
        }
    }
}
