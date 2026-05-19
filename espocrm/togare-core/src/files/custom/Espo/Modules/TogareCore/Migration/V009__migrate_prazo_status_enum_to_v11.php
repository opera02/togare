<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Migration;

use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;

/**
 * V009 — Story 4a.3.1: status enum mapping destrutivo (6→9 valores).
 *
 * Renomeia/reagrupa os status legados da Story 4a.3 (6 valores) para o
 * vocabulário canônico da v1.1 (8 visíveis + descartado oculto técnico).
 * Decisões fechadas em sprint-change-proposal-2026-05-04 §7 + ADR-03 v1.1 §1.
 *
 * Mapping:
 *   - rascunho_nao_vinculado → rascunho             (rename)
 *   - confirmado             → pendente             (D2 Felipe — semântica
 *                                                    "já foi para pendente após bind")
 *   - cumprido               → protocolado          (rename pt-BR jurídico)
 *   - revertido              → pendente             (vira evento puro
 *                                                    audit.prazo.revertido — ADR-03 v1.1 §1)
 *   - pendente               → pendente             (preservado)
 *   - descartado             → descartado           (preservado como 9º técnico oculto — D7 (b))
 *
 * Backup obrigatório (NFR21 + FR37): grava em `togare_audit_log` 1 entrada
 * `audit.event_type='prazo.schema_migrated_v009'` com counts antes/depois
 * por status original + mapping aplicado.
 *
 * Idempotência: MigrationRunner (Story 1a.4a) gerencia via tabela
 * `togare_migrations_applied` (1 row por version — re-run faz no-op no nível
 * do orquestrador). UPDATE em massa via CASE é seguro mesmo se re-rodar
 * pontualmente: status já mapeados continuam iguais (ELSE preserva).
 *
 * Volume esperado no piloto Felipe: 1 row (Prazo `rascunho_nao_vinculado` do
 * smoke F1 da 4a.3 → vira `rascunho`). Migration safe para volume zero.
 *
 * Down: no-op intencional (igual V003/V004) — reversão de enum destrutivo
 * exige restore de backup + reaplicar V008.
 */
// phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps
final class V009__migrate_prazo_status_enum_to_v11 implements MigrationInterface
{
    private const MAPPING = [
        'rascunho_nao_vinculado' => 'rascunho',
        'confirmado'             => 'pendente',
        'cumprido'               => 'protocolado',
        'revertido'              => 'pendente',
    ];

    public function version(): string
    {
        return 'V009__migrate_prazo_status_enum_to_v11';
    }

    public function up(PDO $pdo): void
    {
        $beforeCounts = $this->countByStatus($pdo);

        $pdo->exec(
            "UPDATE prazo SET status = CASE status "
            . "WHEN 'rascunho_nao_vinculado' THEN 'rascunho' "
            . "WHEN 'confirmado'             THEN 'pendente' "
            . "WHEN 'cumprido'               THEN 'protocolado' "
            . "WHEN 'revertido'              THEN 'pendente' "
            . "ELSE status END"
        );

        $afterCounts = $this->countByStatus($pdo);

        $this->writeAuditLog($pdo, 'prazo.schema_migrated_v009', [
            'before'  => $beforeCounts,
            'after'   => $afterCounts,
            'mapping' => self::MAPPING,
        ]);
    }

    public function down(PDO $pdo): void
    {
        // No-op intencional — migration enum destrutiva.
        // Reversão = restore de backup NFR21 + reaplicar V008.
    }

    /** @return array<string, int> */
    private function countByStatus(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT status, COUNT(*) AS cnt FROM prazo GROUP BY status');
        if ($rows === false) {
            return [];
        }

        $out = [];
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $statusKey = (string) ($row['status'] ?? '');
            if ($statusKey === '') {
                continue;
            }
            $out[$statusKey] = (int) ($row['cnt'] ?? 0);
        }

        return $out;
    }

    /**
     * Grava 1 linha em togare_audit_log (schema da V006 da Story 2.4).
     *
     * @param array<string, mixed> $context
     */
    private function writeAuditLog(PDO $pdo, string $eventType, array $context): void
    {
        $contextJson = (string) \json_encode(
            $context,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        // ID hex 32 chars (espelha pattern do AuditLogService da Story 2.4 sem dep direta).
        $id = \bin2hex(\random_bytes(16));

        // Schema da V006 (Story 2.4): colunas `event`/`occurred_at`/`entity_type NOT NULL`
        // (não `event_type`/`created_at`). entity_type='Migration' para registros de
        // migração — sinaliza que NÃO se refere a entidade de negócio.
        $stmt = $pdo->prepare(
            'INSERT INTO togare_audit_log '
            . '(id, occurred_at, event, entity_type, entity_id, user_id, user_name, ip_address, user_agent, correlation_id, context_json) '
            . 'VALUES (:id, NOW(3), :event, :entity_type, NULL, NULL, :user_name, NULL, NULL, NULL, :context_json)'
        );

        try {
            $stmt->execute([
                'id'           => $id,
                'event'        => $eventType,
                'entity_type'  => 'Migration',
                'user_name'    => 'system:migration',
                'context_json' => $contextJson,
            ]);
        } catch (\PDOException $e) {
            // Audit é defensivo — falha de log NÃO pode bloquear a migration.
            // Pattern alinhado a AuditPrazoHook (FR37 — audit nunca bloqueia).
            // Mensagem visível em error_log do PHP (sem dep do TogareLogger no contexto da migration).
            \error_log(
                '[V009 migration] Falha ao gravar audit log prazo.schema_migrated_v009: '
                . $e->getMessage()
            );
        }
    }
}
