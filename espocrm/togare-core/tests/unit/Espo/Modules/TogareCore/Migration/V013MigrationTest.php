<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Migration;

use Espo\Modules\TogareCore\Migration\V013__add_prazo_data_cumprimento;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Cobre Migration V013 (Story 4a.5.1, AC1):
 * coluna `data_cumprimento` DATE NULL + 2 indexes (simples + composto status).
 *
 * SQLite usado para isolamento (mesmo pattern V012MigrationTest). DATE em
 * SQLite é TEXT-affinity mas a sintaxe ALTER TABLE / CREATE INDEX é aceita.
 *
 * Sem backfill — coluna nasce NULL para todas as linhas pré-existentes
 * (Decisão #1+#4 da Story 4a.5.1: linhas pré-V013 caem no painel via boolFilter
 * `pendentesParaHoje` que aceita NULL como "ainda não planejado").
 */
final class V013MigrationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Schema base: schema da Story 4a.5 (após V010+V012). Inclui as colunas
        // que a V013 indexa (status) — data_cumprimento será adicionada pela V013.
        $this->pdo->exec('CREATE TABLE prazo (
            id VARCHAR(24) PRIMARY KEY,
            status VARCHAR(64) NOT NULL,
            assigned_user_id VARCHAR(24) NULL,
            processo_id VARCHAR(24) NULL,
            data_fatal DATE NULL,
            prioridade VARCHAR(16) NOT NULL DEFAULT "normal",
            prioridade_weight TINYINT NOT NULL DEFAULT 2
        )');
    }

    public function testUpAdicionaColunaDataCumprimento(): void
    {
        (new V013__add_prazo_data_cumprimento())->up($this->pdo);

        $columns = $this->getColumnNames();
        self::assertContains('data_cumprimento', $columns);
    }

    public function testUpAdicionaIndexSimplesDataCumprimento(): void
    {
        (new V013__add_prazo_data_cumprimento())->up($this->pdo);

        $indexes = $this->getIndexNames();
        self::assertContains('idx_prazo_data_cumprimento', $indexes);
    }

    public function testUpAdicionaIndiceCompostoStatusDataCumprimento(): void
    {
        // Composto cobre PendentesParaHoje::apply() — WHERE status IN (...)
        // AND (data_cumprimento IS NULL OR data_cumprimento <= today) sem
        // filesort. Espelha pattern V012 (idx_prazo_data_fatal_prioridade_weight).
        (new V013__add_prazo_data_cumprimento())->up($this->pdo);

        $indexes = $this->getIndexNames();
        self::assertContains('idx_prazo_data_cumprimento_status', $indexes);
    }

    public function testUpDeixaLinhasExistentesComDataCumprimentoNull(): void
    {
        // Decisão #1+#4 — sem backfill. Linhas pré-V013 ficam NULL.
        $this->pdo->exec("INSERT INTO prazo (id, status) VALUES ('p1', 'pendente')");
        $this->pdo->exec("INSERT INTO prazo (id, status) VALUES ('p2', 'protocolado')");

        (new V013__add_prazo_data_cumprimento())->up($this->pdo);

        $rows = $this->pdo->query("SELECT id, data_cumprimento FROM prazo ORDER BY id")
            ->fetchAll(PDO::FETCH_ASSOC);

        self::assertNull($rows[0]['data_cumprimento'], 'Linha p1 pré-V013 deve ficar com NULL');
        self::assertNull($rows[1]['data_cumprimento'], 'Linha p2 pré-V013 deve ficar com NULL');
    }

    public function testReExecucaoNaoLancaEManelaIdempotencia(): void
    {
        $this->pdo->exec("INSERT INTO prazo (id, status) VALUES ('p1', 'pendente')");

        $migration = new V013__add_prazo_data_cumprimento();
        $migration->up($this->pdo);

        // 1ª execução: coluna criada e linha continua NULL.
        $row = $this->pdo->query("SELECT data_cumprimento FROM prazo WHERE id = 'p1'")
            ->fetch(PDO::FETCH_ASSOC);
        self::assertNull($row['data_cumprimento'], 'Coluna nasce NULL após V013 (sem backfill)');

        // Re-run não deve lançar — pattern V010/V012 (try/catch DUPLICATE).
        try {
            $migration->up($this->pdo);
            self::assertTrue(true, 'Re-run não lançou — caminho idempotente OK');
        } catch (\PDOException $e) {
            self::assertStringContainsStringIgnoringCase(
                'duplicate',
                $e->getMessage(),
                'Re-run lançou erro NÃO relacionado a duplicação — regressão real',
            );
        }
    }

    public function testDownEhNoOp(): void
    {
        (new V013__add_prazo_data_cumprimento())->up($this->pdo);
        $columnsBefore = $this->getColumnNames();

        (new V013__add_prazo_data_cumprimento())->down($this->pdo);

        self::assertSame($columnsBefore, $this->getColumnNames());
    }

    /** @return list<string> */
    private function getColumnNames(): array
    {
        $stmt = $this->pdo->query("PRAGMA table_info(prazo)");
        self::assertNotFalse($stmt);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return \array_map(static fn (array $r): string => (string) $r['name'], $rows);
    }

    /** @return list<string> */
    private function getIndexNames(): array
    {
        $stmt = $this->pdo->query("PRAGMA index_list(prazo)");
        self::assertNotFalse($stmt);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return \array_map(static fn (array $r): string => (string) $r['name'], $rows);
    }
}
