<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Migration;

use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;

/**
 * V019 — Story 5.6: garante índice em `documento.prazo_id` para suportar
 * o XOR triplo e o link reverso `Prazo.documentos hasMany`.
 *
 * **Por que defensiva, não destrutiva:** o rebuild do EspoCRM, a partir do
 * novo link `prazo` em `entityDefs/Documento.json`, cria a coluna
 * `prazo_id varchar(17) NULL` automaticamente. O índice `prazoId` declarado
 * em `entityDefs/Documento.json::indexes` também é processado pelo rebuild
 * — porém, observamos historicamente (Story 4b.1a tabela auxiliar V014;
 * fix-pass V015 Story 4b.1b) que o rebuild pode não criar todos os índices
 * nomeados em todos os ambientes. Esta migration garante a existência do
 * índice `IDX_DOCUMENTO_PRAZO_ID` em produção sem sobrescrever schema vivo.
 *
 * **Ordem de operações:**
 *  1. Detectar driver PDO (mysql/mariadb vs sqlite).
 *  2. Verificar se coluna `prazo_id` existe — se NÃO, log warn + return
 *     (rebuild precisa rodar antes; pattern AfterInstall do extension
 *     dispara rebuild automaticamente, mas em ambientes onde a migration
 *     roda antes da rebuild, abortar limpo é melhor que falhar).
 *  3. Verificar se índice `IDX_DOCUMENTO_PRAZO_ID` existe — se SIM, return
 *     (idempotente).
 *  4. CREATE INDEX driver-aware.
 *  5. Audit log `documento.schema_v019_index_added`.
 *
 * Down: no-op (DROP INDEX em prod pode quebrar query plans; pattern
 * V010/V012/V013/V015).
 */
// phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps
final class V019__add_documento_prazo_id_index implements MigrationInterface
{
    private const INDEX_NAME = 'IDX_DOCUMENTO_PRAZO_ID';

    public function version(): string
    {
        return 'V019__add_documento_prazo_id_index';
    }

    public function up(PDO $pdo): void
    {
        $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';

        // 1. Verificar se coluna existe (rebuild precisa ter rodado).
        if (! $this->columnExists($pdo, $isMysql)) {
            $this->writeAuditLog($pdo, 'documento.schema_v019_column_missing', [
                'note' => 'V019 abortada limpo: coluna documento.prazo_id ainda não existe. Rodar rebuild EspoCRM (entityDefs cria a coluna a partir do link prazo).',
            ]);
            return;
        }

        // 2. Verificar se índice já existe (idempotência).
        if ($this->indexExists($pdo, $isMysql)) {
            $this->writeAuditLog($pdo, 'documento.schema_v019_index_already_present', [
                'index' => self::INDEX_NAME,
                'note' => 'Idempotência: índice já presente (rebuild ou migration anterior criou).',
            ]);
            return;
        }

        // 3. CREATE INDEX driver-aware.
        if ($isMysql) {
            $this->execIgnoringDuplicate(
                $pdo,
                'CREATE INDEX ' . self::INDEX_NAME . ' ON documento (prazo_id)',
            );
        } else {
            // SQLite (testes).
            $this->execIgnoringDuplicate(
                $pdo,
                'CREATE INDEX IF NOT EXISTS ' . self::INDEX_NAME . ' ON documento (prazo_id)',
            );
        }

        // 4. Audit log.
        $this->writeAuditLog($pdo, 'documento.schema_v019_index_added', [
            'index' => self::INDEX_NAME,
            'column' => 'documento.prazo_id',
            'note' => 'Story 5.6: índice criado para suportar XOR triplo + link reverso Prazo.documentos.',
        ]);
    }

    public function down(PDO $pdo): void
    {
        // No-op intencional (DROP INDEX em prod pode quebrar query plans;
        // pattern V010/V012/V013/V015).
    }

    private function columnExists(PDO $pdo, bool $isMysql): bool
    {
        try {
            if ($isMysql) {
                $stmt = $pdo->query(
                    "SELECT COUNT(*) AS c FROM information_schema.columns "
                    . "WHERE table_schema = DATABASE() "
                    . "AND table_name = 'documento' "
                    . "AND column_name = 'prazo_id'"
                );
            } else {
                // SQLite (testes).
                $stmt = $pdo->query("PRAGMA table_info(documento)");
                if ($stmt === false) {
                    return false;
                }
                while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                    if (((string) ($row['name'] ?? '')) === 'prazo_id') {
                        return true;
                    }
                }
                return false;
            }

            if ($stmt === false) {
                return false;
            }
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row !== false && (int) $row['c'] > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function indexExists(PDO $pdo, bool $isMysql): bool
    {
        try {
            if ($isMysql) {
                $stmt = $pdo->prepare(
                    'SHOW INDEX FROM documento WHERE Key_name = :name'
                );
                if ($stmt === false) {
                    return false;
                }
                $stmt->execute(['name' => self::INDEX_NAME]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                return $row !== false;
            }

            // SQLite (testes).
            $stmt = $pdo->query("PRAGMA index_list(documento)");
            if ($stmt === false) {
                return false;
            }
            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                if (((string) ($row['name'] ?? '')) === self::INDEX_NAME) {
                    return true;
                }
            }
            return false;
        } catch (\Throwable) {
            return false;
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

    /**
     * @param array<string, mixed> $payload
     */
    private function writeAuditLog(PDO $pdo, string $event, array $payload): void
    {
        try {
            $context = \json_encode($payload, JSON_UNESCAPED_UNICODE);
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
