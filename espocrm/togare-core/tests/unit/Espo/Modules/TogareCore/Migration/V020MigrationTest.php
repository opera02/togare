<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Migration;

use Espo\Modules\TogareCore\Migration\V020__create_togare_contrato_honorarios_log;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Story 6.1 — cobre Migration V020 (togare_contrato_honorarios_log).
 *
 * Pattern V018: cria tabela auxiliar append-only para tombstones de soft-purge
 * de ContratoHonorarios. Tabela da entity `contrato_honorarios` é criada pelo
 * rebuild EspoCRM a partir do entityDefs.
 */
final class V020MigrationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function testUpCriaTabelaTogareContratoHonorariosLog(): void
    {
        (new V020__create_togare_contrato_honorarios_log())->up($this->pdo);

        self::assertContains('togare_contrato_honorarios_log', $this->getTableNames());
    }

    public function testTogareContratoHonorariosLogTemColunasEsperadas(): void
    {
        (new V020__create_togare_contrato_honorarios_log())->up($this->pdo);

        $columns = $this->getColumnNames('togare_contrato_honorarios_log');
        $expected = ['id', 'event', 'contrato_id', 'user_id', 'payload', 'created_at'];

        foreach ($expected as $col) {
            self::assertContains($col, $columns, "Coluna '{$col}' deve existir em togare_contrato_honorarios_log");
        }
    }

    public function testTogareContratoHonorariosLogTiposPrincipaisSeguemContrato(): void
    {
        (new V020__create_togare_contrato_honorarios_log())->up($this->pdo);

        $types = $this->getColumnTypes('togare_contrato_honorarios_log');

        self::assertSame('VARCHAR(17)', strtoupper($types['contrato_id']));
        self::assertSame('VARCHAR(17)', strtoupper($types['user_id']));
        self::assertSame('JSON', strtoupper($types['payload']));
    }

    public function testUpCriaIndexesEsperados(): void
    {
        (new V020__create_togare_contrato_honorarios_log())->up($this->pdo);

        $indexes = $this->getIndexNames('togare_contrato_honorarios_log');
        self::assertContains('idx_contrato_honorarios_log_event_created_at', $indexes);
        self::assertContains('idx_contrato_honorarios_log_contrato_id', $indexes);
    }

    public function testReExecucaoIdempotente(): void
    {
        $migration = new V020__create_togare_contrato_honorarios_log();
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
        (new V020__create_togare_contrato_honorarios_log())->up($this->pdo);
        $tablesBefore = $this->getTableNames();

        (new V020__create_togare_contrato_honorarios_log())->down($this->pdo);

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
