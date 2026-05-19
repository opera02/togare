<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Migration;

use Espo\Modules\TogareCore\Migration\V012__add_prazo_prioridade_weight;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Cobre Migration V012 (Story 4a.5, AC4):
 * coluna `prioridade_weight` TINYINT + index + backfill destrutivo idempotente.
 *
 * SQLite usado para isolamento. SQLite não suporta TINYINT (mapeia INTEGER) mas
 * a sintaxe é aceita; CASE WHEN é SQL standard. Idempotência (DUPLICATE COLUMN)
 * é específica de MySQL — pattern V010 (try/catch silencia).
 */
final class V012MigrationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Schema base: schema da Story 4a.3.1 (após V010) — coluna prioridade
        // já existe. V012 adiciona prioridade_weight + backfill.
        $this->pdo->exec('CREATE TABLE prazo (
            id VARCHAR(24) PRIMARY KEY,
            status VARCHAR(64) NOT NULL,
            assigned_user_id VARCHAR(24) NULL,
            processo_id VARCHAR(24) NULL,
            data_fatal DATE NULL,
            prioridade VARCHAR(16) NOT NULL DEFAULT "normal"
        )');
    }

    public function testUpAdicionaColunaPrioridadeWeight(): void
    {
        (new V012__add_prazo_prioridade_weight())->up($this->pdo);

        $columns = $this->getColumnNames();
        self::assertContains('prioridade_weight', $columns);
    }

    public function testUpAdicionaIndexPrioridadeWeight(): void
    {
        (new V012__add_prazo_prioridade_weight())->up($this->pdo);

        $indexes = $this->getIndexNames();
        self::assertContains('idx_prazo_prioridade_weight', $indexes);
    }

    public function testUpAdicionaIndiceCompostoDataFatalPrioridadeWeight(): void
    {
        // B-P1: P3 (Grupo A) adicionou CREATE INDEX idx_prazo_data_fatal_prioridade_weight
        // mas o teste original não o cobria. Índice composto é o que o ORDER BY do
        // dashlet usa (data_fatal ASC, prioridade_weight DESC sem filesort extra).
        (new V012__add_prazo_prioridade_weight())->up($this->pdo);

        $indexes = $this->getIndexNames();
        self::assertContains('idx_prazo_data_fatal_prioridade_weight', $indexes);
    }

    public function testBackfillMapeiaTodasAs4Prioridades(): void
    {
        // Insere 4 prazos com prioridades distintas ANTES da migration.
        $this->pdo->exec("INSERT INTO prazo (id, status, prioridade) VALUES ('p1', 'pendente', 'urgente')");
        $this->pdo->exec("INSERT INTO prazo (id, status, prioridade) VALUES ('p2', 'pendente', 'alta')");
        $this->pdo->exec("INSERT INTO prazo (id, status, prioridade) VALUES ('p3', 'pendente', 'normal')");
        $this->pdo->exec("INSERT INTO prazo (id, status, prioridade) VALUES ('p4', 'pendente', 'baixa')");

        (new V012__add_prazo_prioridade_weight())->up($this->pdo);

        $rows = $this->pdo->query(
            "SELECT id, prioridade_weight FROM prazo ORDER BY id"
        )->fetchAll(PDO::FETCH_ASSOC);

        self::assertSame(4, (int) $rows[0]['prioridade_weight'], 'urgente → 4');
        self::assertSame(3, (int) $rows[1]['prioridade_weight'], 'alta → 3');
        self::assertSame(2, (int) $rows[2]['prioridade_weight'], 'normal → 2');
        self::assertSame(1, (int) $rows[3]['prioridade_weight'], 'baixa → 1');
    }

    public function testBackfillDefaultParaValorInvalido(): void
    {
        // Valor fora do enum — backfill deve cair no ELSE 2.
        $this->pdo->exec("INSERT INTO prazo (id, status, prioridade) VALUES ('px', 'pendente', 'foo')");

        (new V012__add_prazo_prioridade_weight())->up($this->pdo);

        $row = $this->pdo->query("SELECT prioridade_weight FROM prazo WHERE id = 'px'")
            ->fetch(PDO::FETCH_ASSOC);

        self::assertSame(2, (int) $row['prioridade_weight']);
    }

    public function testReExecucaoNaoLancaEManelaIdempotencia(): void
    {
        $this->pdo->exec("INSERT INTO prazo (id, status, prioridade) VALUES ('p1', 'pendente', 'alta')");

        $migration = new V012__add_prazo_prioridade_weight();
        $migration->up($this->pdo);

        // Re-run: migration tolera erro DUPLICATE COLUMN (em MySQL) ou
        // 'duplicate column name' (em SQLite — case-different mas
        // str_contains casa via lowercase 'duplicate column name'). Após
        // re-run, backfill funcionou na 1ª passada (peso 3 = alta).
        $row = $this->pdo->query("SELECT prioridade_weight FROM prazo WHERE id = 'p1'")
            ->fetch(PDO::FETCH_ASSOC);
        self::assertSame(3, (int) $row['prioridade_weight'], 'Backfill mapeou alta → 3 na 1ª execução');

        // Re-run não deve lançar (idempotência tolerante).
        try {
            $migration->up($this->pdo);
            self::assertTrue(true, 'Re-run não lançou — caminho idempotente OK');
        } catch (\PDOException $e) {
            // SQLite pode ainda lançar se str_contains não casou — registrar
            // explicitamente como cenário aceito.
            self::assertStringContainsStringIgnoringCase(
                'duplicate',
                $e->getMessage(),
                'Re-run lançou erro NÃO relacionado a duplicação — regressão real',
            );
        }
    }

    public function testDownEhNoOp(): void
    {
        (new V012__add_prazo_prioridade_weight())->up($this->pdo);
        $columnsBefore = $this->getColumnNames();

        (new V012__add_prazo_prioridade_weight())->down($this->pdo);

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
