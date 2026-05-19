<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Migration;

use Espo\Modules\TogareCore\Migration\V018__create_togare_documento_log;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Cobre Migration V018 (Story 5.2).
 *
 * V018 cria apenas a tabela auxiliar `togare_documento_log` (audit append-only
 * de eventos de Documento, principalmente soft-purge tombstones). A tabela da
 * entity `documento` é criada pelo rebuild do EspoCRM via entityDefs.
 */
final class V018MigrationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function testUpCriaTabelaTogareDocumentoLog(): void
    {
        (new V018__create_togare_documento_log())->up($this->pdo);

        self::assertContains('togare_documento_log', $this->getTableNames());
    }

    public function testTogareDocumentoLogTemColunasEsperadas(): void
    {
        (new V018__create_togare_documento_log())->up($this->pdo);

        $columns = $this->getColumnNames('togare_documento_log');
        $expected = ['id', 'event', 'documento_id', 'user_id', 'payload', 'created_at'];

        foreach ($expected as $col) {
            self::assertContains($col, $columns, "Coluna '{$col}' deve existir em togare_documento_log");
        }
    }

    public function testTogareDocumentoLogTiposPrincipaisSeguemContrato(): void
    {
        (new V018__create_togare_documento_log())->up($this->pdo);

        $types = $this->getColumnTypes('togare_documento_log');

        self::assertSame('VARCHAR(17)', strtoupper($types['documento_id']));
        self::assertSame('VARCHAR(17)', strtoupper($types['user_id']));
        self::assertSame('JSON', strtoupper($types['payload']));
    }

    public function testUpCriaIndexesEsperados(): void
    {
        (new V018__create_togare_documento_log())->up($this->pdo);

        $indexes = $this->getIndexNames('togare_documento_log');
        self::assertContains('idx_documento_log_event_created_at', $indexes);
        self::assertContains('idx_documento_log_documento_id', $indexes);
    }

    public function testReExecucaoIdempotente(): void
    {
        $migration = new V018__create_togare_documento_log();
        $migration->up($this->pdo);

        try {
            $migration->up($this->pdo);
            self::assertTrue(true, 'Re-run nao lancou — idempotencia OK');
        } catch (\PDOException $e) {
            self::fail('Re-run lancou excecao nao relacionada a duplicacao: ' . $e->getMessage());
        }
    }

    public function testDownEhNoOp(): void
    {
        (new V018__create_togare_documento_log())->up($this->pdo);
        $tablesBefore = $this->getTableNames();

        (new V018__create_togare_documento_log())->down($this->pdo);

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

    /** @return array<string, string> */
    private function getColumnTypes(string $table): array
    {
        $stmt = $this->pdo->query("PRAGMA table_info({$table})");
        self::assertNotFalse($stmt);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $types = [];
        foreach ($rows as $row) {
            $types[(string) $row['name']] = (string) $row['type'];
        }
        return $types;
    }
}
