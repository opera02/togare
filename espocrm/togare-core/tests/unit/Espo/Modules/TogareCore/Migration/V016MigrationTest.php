<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Migration;

use Espo\Modules\TogareCore\Migration\V016__create_togare_prazo_lembrete;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Cobre Migration V016 (Story 4b.2, AC1).
 *
 * Cria a tabela auxiliar `togare_prazo_lembrete` (subsistema togare-core/Notifications
 * & Reminders — ADR-04) sem entityDefs (acesso somente via Hook + Job).
 */
final class V016MigrationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function testUpCriaTabelaTogarePrazoLembrete(): void
    {
        (new V016__create_togare_prazo_lembrete())->up($this->pdo);

        self::assertContains('togare_prazo_lembrete', $this->getTableNames());
    }

    public function testTogarePrazoLembreteTemColunasEsperadas(): void
    {
        (new V016__create_togare_prazo_lembrete())->up($this->pdo);

        $columns = $this->getColumnNames('togare_prazo_lembrete');
        $expected = [
            'id', 'prazo_id', 'user_id', 'marco', 'canal',
            'scheduled_for', 'status', 'sent_at', 'attempt_count',
            'last_error', 'created_at', 'modified_at',
        ];

        foreach ($expected as $col) {
            self::assertContains($col, $columns, "Coluna '{$col}' deve existir em togare_prazo_lembrete");
        }
    }

    public function testUpCriaIndexesEsperados(): void
    {
        (new V016__create_togare_prazo_lembrete())->up($this->pdo);

        $indexes = $this->getIndexNames('togare_prazo_lembrete');
        self::assertContains('prazo_lembrete_unique', $indexes, 'UNIQUE (prazo_id, user_id, marco)');
        self::assertContains('idx_prazo_lembrete_status_scheduled', $indexes);
        self::assertContains('idx_prazo_lembrete_user', $indexes);
    }

    public function testUniqueIndexBloqueiaInsertDuplicado(): void
    {
        (new V016__create_togare_prazo_lembrete())->up($this->pdo);

        $row = [
            ':id' => 'lembrete-001',
            ':prazo_id' => 'prazo-aaa',
            ':user_id' => 'user-bbb',
            ':marco' => 'D-7',
            ':canal' => 'both',
            ':scheduled_for' => '2026-06-01 09:00:00',
            ':status' => 'pending',
            ':attempt_count' => 0,
            ':created_at' => '2026-05-09 10:00:00',
            ':modified_at' => '2026-05-09 10:00:00',
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO togare_prazo_lembrete
                (id, prazo_id, user_id, marco, canal, scheduled_for, status, attempt_count, created_at, modified_at)
             VALUES
                (:id, :prazo_id, :user_id, :marco, :canal, :scheduled_for, :status, :attempt_count, :created_at, :modified_at)'
        );
        self::assertNotFalse($stmt);
        $stmt->execute($row);

        $row2 = $row;
        $row2[':id'] = 'lembrete-002';

        $this->expectException(\PDOException::class);
        $stmt->execute($row2); // mesmo (prazo_id, user_id, marco) → UNIQUE conflict.
    }

    public function testReExecucaoNaoLancaEManelaIdempotencia(): void
    {
        $migration = new V016__create_togare_prazo_lembrete();
        $migration->up($this->pdo);

        try {
            $migration->up($this->pdo);
            self::assertTrue(true, 'Re-run nao lancou - idempotencia OK');
        } catch (\PDOException $e) {
            self::fail('Re-run lancou excecao nao relacionada a duplicacao: ' . $e->getMessage());
        }
    }

    public function testDownEhNoOp(): void
    {
        (new V016__create_togare_prazo_lembrete())->up($this->pdo);
        $tablesBefore = $this->getTableNames();

        (new V016__create_togare_prazo_lembrete())->down($this->pdo);

        self::assertSame($tablesBefore, $this->getTableNames(), 'Down preserva tabelas.');
    }

    public function testWriteAuditLogDefensivoQuandoTabelaNaoExiste(): void
    {
        try {
            (new V016__create_togare_prazo_lembrete())->up($this->pdo);
            self::assertTrue(true, 'V016 nao falha quando togare_audit_log ausente');
        } catch (\Throwable $e) {
            self::fail('V016 falhou por causa de audit log inexistente: ' . $e->getMessage());
        }
    }

    public function testWriteAuditLogInsereEntryQuandoTabelaExiste(): void
    {
        $this->pdo->exec("CREATE TABLE togare_audit_log (
            id VARCHAR(32) PRIMARY KEY,
            occurred_at DATETIME,
            event VARCHAR(120),
            entity_type VARCHAR(80),
            entity_id VARCHAR(32) NULL,
            user_id VARCHAR(32) NULL,
            user_name VARCHAR(128) NULL,
            ip_address VARCHAR(64) NULL,
            user_agent TEXT NULL,
            correlation_id VARCHAR(64) NULL,
            context_json TEXT NULL
        )");

        (new V016__create_togare_prazo_lembrete())->up($this->pdo);

        self::assertContains('togare_audit_log', $this->getTableNames(), 'audit log preservado');
        self::assertContains('togare_prazo_lembrete', $this->getTableNames(), 'prazo lembrete criado');

        $stmt = $this->pdo->query(
            "SELECT event, entity_type, user_name, context_json
             FROM togare_audit_log
             WHERE event = 'togare_prazo_lembrete.schema_created_v016'"
        );
        self::assertNotFalse($stmt);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row, 'V016 deve inserir audit log quando togare_audit_log existe');
        self::assertSame('Migration', $row['entity_type']);
        self::assertSame('system:migration', $row['user_name']);

        $context = \json_decode((string) $row['context_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $context['count_total'] ?? null);
        self::assertStringContainsString('togare_prazo_lembrete', (string) ($context['note'] ?? ''));
        self::assertStringContainsString('ADR-04', (string) ($context['note'] ?? ''));
    }

    /** @return list<string> */
    private function getTableNames(): array
    {
        $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
        self::assertNotFalse($stmt);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return \array_map(static fn (array $r): string => (string) $r['name'], $rows);
    }

    /** @return list<string> */
    private function getColumnNames(string $table): array
    {
        $stmt = $this->pdo->query("PRAGMA table_info({$table})");
        self::assertNotFalse($stmt);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return \array_map(static fn (array $r): string => (string) $r['name'], $rows);
    }

    /** @return list<string> */
    private function getIndexNames(string $table): array
    {
        $stmt = $this->pdo->query("PRAGMA index_list({$table})");
        self::assertNotFalse($stmt);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return \array_map(static fn (array $r): string => (string) $r['name'], $rows);
    }
}
