<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Migration;

use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;

/**
 * V014 — Story 4b.1a: cria tabela auxiliar `togare_ambiguity_log` (audit
 * append-only do flow F3 ambíguo) + 4 indexes.
 *
 * **Importante:** a tabela de entidade `publicacao_ambigua` é criada
 * automaticamente pelo EspoCRM via rebuild a partir do `entityDefs/PublicacaoAmbigua.json`
 * (mesmo pattern Cliente/Processo/Prazo/Audiencia — entities Togare NÃO usam
 * DDL explícita em Migration). Esta Migration foca apenas em `togare_ambiguity_log`,
 * que é tabela auxiliar (não entity) escrita via PDO direto pelo
 * AmbiguityResolverService da Story 4b.1b (D5 mãe — append-only audit/training-data
 * para hook IA Growth).
 *
 * Pattern V010/V012/V013: try/catch DUPLICATE COLUMN/KEY/already exists →
 * idempotência. Audit log entry `togare_ambiguity_log.schema_created_v014`
 * defensiva (try/catch \Throwable se togare_audit_log não existir em testes
 * isolados). Down: no-op intencional (preserva dados).
 */
// phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps
final class V014__create_publicacao_ambigua implements MigrationInterface
{
    public function version(): string
    {
        return 'V014__create_publicacao_ambigua';
    }

    public function up(PDO $pdo): void
    {
        $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $engine = $isMysql ? 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        $statements = [
            // Tabela auxiliar de audit append-only (Decisão #5 mãe).
            // Volume <100 rows/escritório/ano em piloto. Volume baixo → sem partições.
            "CREATE TABLE IF NOT EXISTS togare_ambiguity_log (
                id VARCHAR(32) NOT NULL PRIMARY KEY,
                publicacao_ambigua_id VARCHAR(24) NOT NULL,
                decided_by_user_id VARCHAR(24) NULL,
                decided_at DATETIME NOT NULL,
                decision_type VARCHAR(32) NOT NULL,
                chosen_processo_id VARCHAR(24) NULL,
                prazo_criado_id VARCHAR(24) NULL,
                candidates_snapshot TEXT NOT NULL,
                excerpt TEXT NULL,
                texto_publicacao TEXT NULL,
                created_at DATETIME NOT NULL
            ) {$engine}",
            'CREATE INDEX idx_ambiguity_log_pub ON togare_ambiguity_log (publicacao_ambigua_id)',
            'CREATE INDEX idx_ambiguity_log_chosen_proc ON togare_ambiguity_log (chosen_processo_id)',
            'CREATE INDEX idx_ambiguity_log_decision_type ON togare_ambiguity_log (decision_type)',
            'CREATE INDEX idx_ambiguity_log_created_at ON togare_ambiguity_log (created_at)',
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
        // No-op intencional (preserva dados; mesmo pattern V010/V012/V013).
    }

    /**
     * Grava entry de audit `togare_ambiguity_log.schema_created_v014` registrando
     * estado pós-V014 (count_total=0 — tabela acabou de ser criada).
     * Try/catch defensivo (pattern V013).
     */
    private function writeAuditLog(PDO $pdo): void
    {
        try {
            $context = \json_encode(
                [
                    'count_total' => 0,
                    'note' => 'Tabela togare_ambiguity_log criada (audit append-only do flow F3); tabela publicacao_ambigua é criada automaticamente pelo EspoCRM rebuild a partir do entityDefs.',
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
                'event' => 'togare_ambiguity_log.schema_created_v014',
                'entity_type' => 'Migration',
                'user_name' => 'system:migration',
                'context_json' => $context,
            ]);
        } catch (\Throwable) {
            // togare_audit_log pode não existir em testes isolados — pular.
        }
    }
}
