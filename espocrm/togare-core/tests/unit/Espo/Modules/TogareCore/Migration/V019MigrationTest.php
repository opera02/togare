<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Migration;

use Espo\Modules\TogareCore\Migration\V019__add_documento_prazo_id_index;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Story 5.6 — cobre Migration V019 (índice defensivo idempotente em
 * `documento.prazo_id`).
 *
 * V019 NÃO cria a coluna (rebuild EspoCRM faz isso a partir do entityDefs).
 * V019 apenas garante o índice `IDX_DOCUMENTO_PRAZO_ID`, abortando limpo se
 * a coluna ainda não existir (cenário pré-rebuild).
 */
final class V019MigrationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function testCriaIndiceQuandoColunaExiste(): void
    {
        $this->createDocumentoTableWithPrazoId();

        (new V019__add_documento_prazo_id_index())->up($this->pdo);

        $indexes = $this->getIndexNames('documento');
        self::assertContains('IDX_DOCUMENTO_PRAZO_ID', $indexes);
    }

    public function testAbortaLimpoQuandoColunaNaoExiste(): void
    {
        $this->createDocumentoTableSemPrazoId();

        // Não deve lançar — log warning + return.
        (new V019__add_documento_prazo_id_index())->up($this->pdo);

        $indexes = $this->getIndexNames('documento');
        self::assertNotContains('IDX_DOCUMENTO_PRAZO_ID', $indexes);
    }

    public function testIdempotenteReRun(): void
    {
        $this->createDocumentoTableWithPrazoId();
        $migration = new V019__add_documento_prazo_id_index();

        $migration->up($this->pdo);

        try {
            $migration->up($this->pdo);
            self::assertTrue(true, 'Re-run não lançou — idempotência OK');
        } catch (\PDOException $e) {
            self::fail('Re-run lançou exceção não relacionada a duplicação: ' . $e->getMessage());
        }

        $indexes = $this->getIndexNames('documento');
        self::assertContains('IDX_DOCUMENTO_PRAZO_ID', $indexes);
    }

    public function testDownEhNoOp(): void
    {
        $this->createDocumentoTableWithPrazoId();
        (new V019__add_documento_prazo_id_index())->up($this->pdo);
        $indexesBefore = $this->getIndexNames('documento');

        (new V019__add_documento_prazo_id_index())->down($this->pdo);

        self::assertSame($indexesBefore, $this->getIndexNames('documento'), 'Down preserva índices.');
    }

    private function createDocumentoTableWithPrazoId(): void
    {
        $this->pdo->exec(
            'CREATE TABLE documento ('
            . 'id VARCHAR(17) PRIMARY KEY, '
            . 'processo_id VARCHAR(17) NULL, '
            . 'cliente_id VARCHAR(17) NULL, '
            . 'prazo_id VARCHAR(17) NULL'
            . ')'
        );
    }

    private function createDocumentoTableSemPrazoId(): void
    {
        $this->pdo->exec(
            'CREATE TABLE documento ('
            . 'id VARCHAR(17) PRIMARY KEY, '
            . 'processo_id VARCHAR(17) NULL, '
            . 'cliente_id VARCHAR(17) NULL'
            . ')'
        );
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
