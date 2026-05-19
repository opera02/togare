<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Migration;

use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;

/**
 * V015 — Fix-pass Story 4b.1b (B21): UNIQUE em prazo.source_pub_id +
 * dedup defensivo de soft-deleted históricos.
 *
 * **Bug B21 descoberto no smoke F1 da Story 4b.1b:** a Migration V008 da
 * Story 4a.3 deveria ter criado `UNIQUE INDEX prazo_source_pub_id_unique`
 * em `prazo.source_pub_id` (idempotência cross-table com publicacao_ambigua,
 * race protection do worker DJEN concorrente). Em campo, o índice
 * existente é `IDX_SOURCE_PUB_ID` NÃO-UNIQUE — provavelmente uma versão
 * antiga do entityDefs criou o índice como non-unique antes da V008
 * tentar adicionar UNIQUE; a V008 com try/catch DUPLICATE silenciou o
 * erro e nunca aplicou UNIQUE.
 *
 * **Por que este fix-pass agora:** sem UNIQUE, a Story 4b.1b
 * `AmbiguityResolverService::resolve` perde a defesa de race condition
 * em produção (PHPUnit cobre lógica via `isDuplicateKeyThrowable`, mas o
 * banco real não bloqueia 2 INSERTs concorrentes com mesmo source_pub_id).
 *
 * **Estratégia de dedup:**
 *  - O bloqueio histórico para criar UNIQUE são 2 source_pub_id duplicados
 *    no banco do dev (597580620 e 598087345 — cada um com 1 row deleted=0
 *    + 1 row deleted=1, lixo de re-execução do sync DJEN no smoke da 4a.3).
 *  - **Decisão #1 desta Migration**: hard-delete TODOS os prazos
 *    soft-deleted (`deleted=1`) que tenham `source_pub_id NOT NULL`.
 *    Justificativa: rows soft-deleted são lixeira EspoCRM sem uso
 *    operacional; após o sync DJEN, qualquer Prazo importado que foi
 *    deletado pela UI já cumpriu seu propósito. Hard-delete não causa
 *    perda de dado relevante (audit log preserva eventos).
 *  - **Decisão #2**: se ainda restarem duplicatas ATIVAS (deleted=0)
 *    mesmo após hard-delete dos soft, a Migration ABORTA com mensagem
 *    explícita — operações precisam decidir manualmente qual row
 *    preservar (não há regra automática segura para 2 rows ativos com
 *    mesmo source_pub_id).
 *
 * Ordem de operações:
 *  1. Audit pré-fix: contar quantos rows serão hard-deletados.
 *  2. Hard-delete soft-deleted com source_pub_id NOT NULL.
 *  3. Verificar duplicatas ativas restantes — abortar se houver.
 *  4. DROP INDEX IDX_SOURCE_PUB_ID (não-unique).
 *  5. ADD UNIQUE INDEX prazo_source_pub_id_unique.
 *  6. Audit log entry `prazo.schema_migrated_v015`.
 *
 * Idempotência: try/catch em DROP/ADD INDEX (já existe / not exists).
 * Down: no-op intencional (preserva dados; pattern V010/V012/V013).
 */
// phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps
final class V015__add_prazo_source_pub_id_unique implements MigrationInterface
{
    public function version(): string
    {
        return 'V015__add_prazo_source_pub_id_unique';
    }

    public function up(PDO $pdo): void
    {
        // 1. Contar soft-deleted que serão hard-deletados (audit context).
        $hardDeletedCount = $this->countSoftDeletedWithSourcePubId($pdo);

        // 2. Hard-delete dos soft-deleted históricos com source_pub_id.
        if ($hardDeletedCount > 0) {
            $pdo->exec(
                'DELETE FROM prazo WHERE deleted = 1 AND source_pub_id IS NOT NULL'
            );
        }

        // 3. Verificar duplicatas ATIVAS restantes — abortar se houver.
        $activeDups = $this->findActiveDuplicates($pdo);
        if ($activeDups !== []) {
            throw new \RuntimeException(
                'V015 abortada: source_pub_id duplicados em rows ATIVOS '
                . '(deleted=0) detectados — operações deve decidir manualmente '
                . 'qual preservar. source_pub_ids: ' . \implode(',', $activeDups),
            );
        }

        // 4. DROP non-unique index + ADD UNIQUE — sintaxe driver-aware
        //    (MariaDB usa ALTER TABLE; SQLite usa DROP INDEX / CREATE UNIQUE INDEX).
        $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';

        if ($isMysql) {
            $this->execIgnoringMissing(
                $pdo,
                'ALTER TABLE prazo DROP INDEX IDX_SOURCE_PUB_ID',
            );
            $this->execIgnoringDuplicate(
                $pdo,
                'ALTER TABLE prazo ADD UNIQUE INDEX prazo_source_pub_id_unique (source_pub_id)',
            );
        } else {
            // SQLite (testes) — sintaxe portável.
            $this->execIgnoringMissing(
                $pdo,
                'DROP INDEX IF EXISTS IDX_SOURCE_PUB_ID',
            );
            $this->execIgnoringDuplicate(
                $pdo,
                'CREATE UNIQUE INDEX prazo_source_pub_id_unique ON prazo (source_pub_id)',
            );
        }

        // 5. Audit log.
        $this->writeAuditLog($pdo, $hardDeletedCount);
    }

    public function down(PDO $pdo): void
    {
        // No-op intencional (preserva dados; pattern V010/V012/V013).
    }

    private function countSoftDeletedWithSourcePubId(PDO $pdo): int
    {
        try {
            $stmt = $pdo->query(
                'SELECT COUNT(*) AS c FROM prazo WHERE deleted = 1 AND source_pub_id IS NOT NULL'
            );
            if ($stmt === false) {
                return 0;
            }
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row !== false ? (int) $row['c'] : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return list<string>
     */
    private function findActiveDuplicates(PDO $pdo): array
    {
        try {
            $stmt = $pdo->query(
                'SELECT source_pub_id FROM prazo '
                . 'WHERE deleted = 0 AND source_pub_id IS NOT NULL '
                . 'GROUP BY source_pub_id HAVING COUNT(*) > 1'
            );
            if ($stmt === false) {
                return [];
            }
            $list = [];
            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                $list[] = (string) $row['source_pub_id'];
            }
            return $list;
        } catch (\Throwable) {
            return [];
        }
    }

    private function execIgnoringMissing(PDO $pdo, string $sql): void
    {
        try {
            $pdo->exec($sql);
        } catch (\PDOException $e) {
            $msg = $e->getMessage();
            if (
                \str_contains($msg, "check that column/key exists")
                || \str_contains($msg, 'check that it exists')
                || \str_contains($msg, "doesn't exist")
                || \stripos($msg, 'no such index') !== false
            ) {
                return;
            }
            throw $e;
        }
    }

    private function execIgnoringDuplicate(PDO $pdo, string $sql): void
    {
        try {
            $pdo->exec($sql);
        } catch (\PDOException $e) {
            $msg = $e->getMessage();
            if (
                \str_contains($msg, 'Duplicate key name')
                || \str_contains($msg, 'already exists')
                || \stripos($msg, 'already exists') !== false
            ) {
                return;
            }
            throw $e;
        }
    }

    private function writeAuditLog(PDO $pdo, int $hardDeletedCount): void
    {
        try {
            $stmt = $pdo->query('SELECT COUNT(*) AS c FROM prazo');
            if ($stmt === false) {
                return;
            }
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $total = $row !== false ? (int) $row['c'] : 0;

            $context = \json_encode(
                [
                    'count_total' => $total,
                    'hard_deleted_soft_count' => $hardDeletedCount,
                    'note' => 'B21 fix-pass: dropped IDX_SOURCE_PUB_ID non-unique + added prazo_source_pub_id_unique. Hard-deleted soft-deleted prazos com source_pub_id (lixeira histórica DJEN).',
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
                'event' => 'prazo.schema_migrated_v015',
                'entity_type' => 'Migration',
                'user_name' => 'system:migration',
                'context_json' => $context,
            ]);
        } catch (\Throwable) {
            // togare_audit_log pode não existir em testes isolados — pular.
        }
    }
}
