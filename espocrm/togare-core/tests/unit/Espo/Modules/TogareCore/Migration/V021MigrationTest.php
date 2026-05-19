<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Migration;

use Espo\Modules\TogareCore\Migration\V021__create_togare_fatura_log_and_lancamento_log;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Story 6.3 — cobre Migration V021 (togare_fatura_log + togare_lancamento_financeiro_log).
 *
 * Pattern V018/V020: cria 2 tabelas auxiliares append-only.
 */
final class V021MigrationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function testUpCriaAmbasTabelas(): void
    {
        (new V021__create_togare_fatura_log_and_lancamento_log())->up($this->pdo);

        $tables = $this->getTableNames();
        self::assertContains('togare_fatura_log', $tables);
        self::assertContains('togare_lancamento_financeiro_log', $tables);
    }

    public function testTogareFaturaLogTemColunasEsperadas(): void
    {
        (new V021__create_togare_fatura_log_and_lancamento_log())->up($this->pdo);

        $columns = $this->getColumnNames('togare_fatura_log');
        $expected = ['id', 'event', 'fatura_id', 'user_id', 'payload', 'created_at'];

        foreach ($expected as $col) {
            self::assertContains($col, $columns, "Coluna '{$col}' deve existir em togare_fatura_log");
        }
    }

    public function testTogareLancamentoFinanceiroLogTemColunasEsperadas(): void
    {
        (new V021__create_togare_fatura_log_and_lancamento_log())->up($this->pdo);

        $columns = $this->getColumnNames('togare_lancamento_financeiro_log');
        $expected = ['id', 'event', 'lancamento_id', 'user_id', 'payload', 'created_at'];

        foreach ($expected as $col) {
            self::assertContains($col, $columns, "Coluna '{$col}' deve existir em togare_lancamento_financeiro_log");
        }
    }

    public function testUpCriaIndexesEsperados(): void
    {
        (new V021__create_togare_fatura_log_and_lancamento_log())->up($this->pdo);

        $faturaIndexes = $this->getIndexNames('togare_fatura_log');
        self::assertContains('idx_fatura_log_event_created_at', $faturaIndexes);
        self::assertContains('idx_fatura_log_fatura_id', $faturaIndexes);

        $lancIndexes = $this->getIndexNames('togare_lancamento_financeiro_log');
        self::assertContains('idx_lancamento_financeiro_log_event_created_at', $lancIndexes);
        self::assertContains('idx_lancamento_financeiro_log_lancamento_id', $lancIndexes);
    }

    public function testReExecucaoIdempotente(): void
    {
        $migration = new V021__create_togare_fatura_log_and_lancamento_log();
        $migration->up($this->pdo);

        try {
            $migration->up($this->pdo);
            self::assertTrue(true, 'Re-run não lançou — idempotência OK');
        } catch (\PDOException $e) {
            self::fail('Re-run lançou excecao não relacionada a duplicacao: ' . $e->getMessage());
        }
    }

    public function testDownEhNoOp(): void
    {
        (new V021__create_togare_fatura_log_and_lancamento_log())->up($this->pdo);
        $tablesBefore = $this->getTableNames();

        (new V021__create_togare_fatura_log_and_lancamento_log())->down($this->pdo);

        self::assertSame($tablesBefore, $this->getTableNames(), 'Down preserva tabelas.');
    }

    /** @return list<string> */
    private function getTableNames(): array
    {
        $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
        self::assertNotFalse($stmt);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn (array $r): string => (string) $r['name'], $rows);
    }

    /** @return list<string> */
    private function getColumnNames(string $table): array
    {
        $stmt = $this->pdo->query("PRAGMA table_info({$table})");
        self::assertNotFalse($stmt);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn (array $r): string => (string) $r['name'], $rows);
    }

    /** @return list<string> */
    private function getIndexNames(string $table): array
    {
        $stmt = $this->pdo->query("PRAGMA index_list({$table})");
        self::assertNotFalse($stmt);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn (array $r): string => (string) $r['name'], $rows);
    }
}
