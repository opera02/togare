<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Migration;

use Espo\Modules\TogareCore\Migration\V017__add_queue_items_failure_category;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Cobre Migration V017 (Story 4b.4 / ADR 0009): adição da coluna
 * `failure_category VARCHAR(40) NULL` em `togare_queue_items` + índice
 * composto auxiliar `idx_togare_queue_failure_category`.
 *
 * SQLite usado para isolamento. SQLite suporta `ALTER TABLE ADD COLUMN`
 * desde 3.2.0 e `CREATE INDEX` portável; o INDEX é criado via SQLite-style
 * (sintaxe peculiar do MariaDB `ALTER TABLE ADD INDEX` é coberta pelo
 * branch driver-aware).
 */
final class V017MigrationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Schema togare_queue_items pré-V017 (réplica do V004).
        $this->pdo->exec("
            CREATE TABLE togare_queue_items (
                id VARCHAR(32) NOT NULL PRIMARY KEY,
                queue_name VARCHAR(64) NOT NULL,
                idempotency_key VARCHAR(200) NOT NULL,
                payload TEXT NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                retry_count INTEGER NOT NULL DEFAULT 0,
                last_error TEXT NULL,
                next_retry_at DATETIME NULL,
                processing_started_at DATETIME NULL,
                completed_at DATETIME NULL,
                correlation_id VARCHAR(64) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            )
        ");
        $this->pdo->exec(
            'CREATE UNIQUE INDEX uk_togare_queue_idempotency ON togare_queue_items (idempotency_key)',
        );
    }

    public function testUpAdicionaColunaFailureCategoryNullable(): void
    {
        (new V017__add_queue_items_failure_category())->up($this->pdo);

        // SQLite reporta o schema via PRAGMA table_info.
        $cols = $this->pdo->query("PRAGMA table_info(togare_queue_items)")->fetchAll(PDO::FETCH_ASSOC);
        $colNames = \array_map(static fn ($r) => (string) $r['name'], $cols);

        self::assertContains('failure_category', $colNames, 'Coluna failure_category deve existir após up()');

        $col = \array_values(\array_filter($cols, static fn ($r) => $r['name'] === 'failure_category'))[0];
        self::assertSame(0, (int) $col['notnull'], 'failure_category deve ser NULL (notnull=0)');
        // SQLite PRAGMA pode reportar 'NULL' literal (string) OU php null para
        // colunas declaradas `DEFAULT NULL`. Aceitar ambos os shapes.
        self::assertTrue(
            $col['dflt_value'] === null || \strtoupper((string) $col['dflt_value']) === 'NULL',
            'failure_category default deve ser NULL (php null OR sqlite literal "NULL")',
        );
    }

    public function testUpCriaIndexFailureCategoryCompondoQueueNameStatus(): void
    {
        (new V017__add_queue_items_failure_category())->up($this->pdo);

        // SQLite reporta índices via PRAGMA index_list + index_info.
        $indices = $this->pdo->query("PRAGMA index_list(togare_queue_items)")->fetchAll(PDO::FETCH_ASSOC);
        $names = \array_map(static fn ($r) => (string) $r['name'], $indices);

        self::assertContains(
            'idx_togare_queue_failure_category',
            $names,
            'Index idx_togare_queue_failure_category deve existir após up()',
        );

        $info = $this->pdo->query(
            "PRAGMA index_info(idx_togare_queue_failure_category)",
        )->fetchAll(PDO::FETCH_ASSOC);
        $cols = \array_map(static fn ($r) => (string) $r['name'], $info);
        self::assertSame(['queue_name', 'status', 'failure_category'], $cols, 'Ordem das colunas do índice composto');
    }

    public function testUpEhIdempotenteEmReExecucao(): void
    {
        $migration = new V017__add_queue_items_failure_category();
        $migration->up($this->pdo);

        // Re-run não deve lançar — try/catch de duplicate column/index.
        try {
            $migration->up($this->pdo);
            self::assertTrue(true, 'Re-run não lançou — caminho idempotente OK');
        } catch (\PDOException $e) {
            self::fail('Re-run lançou erro inesperado: ' . $e->getMessage());
        }
    }

    public function testUpInsereAuditLogQuandoTabelaAuditExiste(): void
    {
        // Cria togare_audit_log mínimo (réplica do V006).
        $this->pdo->exec("
            CREATE TABLE togare_audit_log (
                id VARCHAR(32) PRIMARY KEY,
                occurred_at DATETIME NOT NULL,
                event VARCHAR(255) NOT NULL,
                entity_type VARCHAR(255) NULL,
                entity_id VARCHAR(64) NULL,
                user_id VARCHAR(64) NULL,
                user_name VARCHAR(255) NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(500) NULL,
                correlation_id VARCHAR(64) NULL,
                context_json TEXT NULL
            )
        ");

        // Insere 3 rows pre-existentes pra count_total ser ≥ 3 no audit context.
        for ($i = 1; $i <= 3; $i++) {
            $this->pdo->exec(
                "INSERT INTO togare_queue_items (id, queue_name, idempotency_key, payload, status, created_at, updated_at) "
                . "VALUES ('id{$i}', 'djen', 'k{$i}', '{}', 'pending', '2026-05-09', '2026-05-09')",
            );
        }

        (new V017__add_queue_items_failure_category())->up($this->pdo);

        $row = $this->pdo->query(
            "SELECT event, context_json FROM togare_audit_log "
            . "WHERE event = 'togare_queue_items.failure_category_added_v017'",
        )->fetch(PDO::FETCH_ASSOC);

        self::assertNotFalse($row, 'Audit log entry deve ter sido inserida');
        self::assertSame('togare_queue_items.failure_category_added_v017', $row['event']);

        $ctx = \json_decode((string) $row['context_json'], true);
        self::assertSame(3, $ctx['count_total'] ?? null, 'count_total deve refletir rows preexistentes');
        self::assertStringContainsString('ADR 0009', $ctx['note'] ?? '', 'note deve referenciar ADR 0009');
    }

    public function testUpPreservaDadosPreExistentesComFailureCategoryNull(): void
    {
        // Pré-V017: 3 rows existentes (sem coluna failure_category).
        for ($i = 1; $i <= 3; $i++) {
            $this->pdo->exec(
                "INSERT INTO togare_queue_items (id, queue_name, idempotency_key, payload, status, created_at, updated_at) "
                . "VALUES ('id{$i}', 'djen', 'k{$i}', '{}', 'pending', '2026-05-09', '2026-05-09')",
            );
        }

        (new V017__add_queue_items_failure_category())->up($this->pdo);

        // Após up, os 3 rows preservados; failure_category = NULL (default).
        $rows = $this->pdo->query(
            "SELECT id, failure_category FROM togare_queue_items ORDER BY id",
        )->fetchAll(PDO::FETCH_ASSOC);

        self::assertCount(3, $rows, '3 rows preservados pós-Migration');
        foreach ($rows as $row) {
            self::assertNull($row['failure_category'], "Row {$row['id']}: failure_category deve ser NULL");
        }
    }

    public function testDownEhNoOp(): void
    {
        for ($i = 1; $i <= 2; $i++) {
            $this->pdo->exec(
                "INSERT INTO togare_queue_items (id, queue_name, idempotency_key, payload, status, created_at, updated_at) "
                . "VALUES ('id{$i}', 'djen', 'k{$i}', '{}', 'pending', '2026-05-09', '2026-05-09')",
            );
        }

        $migration = new V017__add_queue_items_failure_category();
        $migration->up($this->pdo);
        $countAfterUp = (int) $this->pdo->query('SELECT COUNT(*) FROM togare_queue_items')->fetchColumn();

        $migration->down($this->pdo);
        $countAfterDown = (int) $this->pdo->query('SELECT COUNT(*) FROM togare_queue_items')->fetchColumn();

        self::assertSame($countAfterUp, $countAfterDown, 'Down preserva estado; é no-op');
    }
}
