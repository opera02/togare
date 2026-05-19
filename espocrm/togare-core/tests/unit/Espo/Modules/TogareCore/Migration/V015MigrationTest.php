<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Migration;

use Espo\Modules\TogareCore\Migration\V015__add_prazo_source_pub_id_unique;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Cobre Migration V015 (fix-pass B21 da Story 4b.1b):
 * dedup defensivo de soft-deleted históricos com source_pub_id +
 * substituição do índice IDX_SOURCE_PUB_ID não-unique por
 * prazo_source_pub_id_unique UNIQUE.
 *
 * SQLite usado para isolamento. SQLite suporta `CREATE UNIQUE INDEX` mas a
 * sintaxe `ALTER TABLE ... DROP INDEX` é peculiar; a Migration usa try/catch
 * defensivo e em SQLite o DROP INDEX direto funciona via `DROP INDEX name`.
 *
 * **Testes não cobrem o ALTER TABLE DROP INDEX literal** (incompatível
 * SQLite/MariaDB) — focam no comportamento observável: dedup correto,
 * UNIQUE em vigor após up(), abort em duplicatas ativas.
 */
final class V015MigrationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Schema base do prazo pós-Story 4a.5.1 (V013). Inclui source_pub_id.
        $this->pdo->exec('CREATE TABLE prazo (
            id VARCHAR(24) PRIMARY KEY,
            status VARCHAR(64) NOT NULL,
            source_pub_id INTEGER NULL,
            deleted INTEGER NOT NULL DEFAULT 0
        )');
        // Índice não-unique original (estado bugado pré-V015).
        $this->pdo->exec('CREATE INDEX IDX_SOURCE_PUB_ID ON prazo (source_pub_id)');
    }

    public function testHardDeletaSoftDeletedComSourcePubIdNotNull(): void
    {
        // 2 rows duplicadas como no banco real (1 deleted=0 + 1 deleted=1)
        $this->pdo->exec("INSERT INTO prazo (id, status, source_pub_id, deleted) VALUES
            ('p-active-1', 'pendente', 597580620, 0),
            ('p-soft-1',   'rascunho', 597580620, 1),
            ('p-active-2', 'rascunho', 598087345, 0),
            ('p-soft-2',   'rascunho', 598087345, 1)");

        (new V015__add_prazo_source_pub_id_unique())->up($this->pdo);

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM prazo')->fetchColumn();
        self::assertSame(2, $count, 'Apenas as 2 rows ATIVAS devem permanecer; 2 soft-deleted hard-removidas');

        $remaining = $this->pdo->query('SELECT id FROM prazo ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['p-active-1', 'p-active-2'], $remaining);
    }

    public function testNaoToocaSoftDeletedSemSourcePubId(): void
    {
        // Soft-deleted manuais (criados via UI) sem source_pub_id NÃO devem ser hard-deletados.
        $this->pdo->exec("INSERT INTO prazo (id, status, source_pub_id, deleted) VALUES
            ('p-manual-soft', 'rascunho', NULL, 1),
            ('p-djen-soft',   'rascunho', 999999, 1)");

        (new V015__add_prazo_source_pub_id_unique())->up($this->pdo);

        $remaining = $this->pdo->query('SELECT id FROM prazo ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['p-manual-soft'], $remaining, 'Soft-deleted manual (source_pub_id NULL) preservado');
    }

    public function testAbortaQuandoDuplicatasAtivasRestam(): void
    {
        // Cenário catastrófico: 2 rows ATIVAS com mesmo source_pub_id.
        // Migration deve ABORTAR sem aplicar mudanças (operações decide manual).
        $this->pdo->exec("INSERT INTO prazo (id, status, source_pub_id, deleted) VALUES
            ('p-active-A', 'pendente', 555000111, 0),
            ('p-active-B', 'pendente', 555000111, 0)");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/V015 abortada/');
        $this->expectExceptionMessageMatches('/555000111/');

        (new V015__add_prazo_source_pub_id_unique())->up($this->pdo);
    }

    public function testCriaUniqueIndexAposDedupSucesso(): void
    {
        $this->pdo->exec("INSERT INTO prazo (id, status, source_pub_id, deleted) VALUES
            ('p-1', 'pendente', 100, 0),
            ('p-2', 'rascunho', 100, 1)");

        (new V015__add_prazo_source_pub_id_unique())->up($this->pdo);

        // Após Migration, tentar inserir novo row com mesmo source_pub_id deve falhar
        // por UNIQUE — confirma que prazo_source_pub_id_unique foi criado e está em vigor.
        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/UNIQUE/i');

        $this->pdo->exec("INSERT INTO prazo (id, status, source_pub_id, deleted) VALUES
            ('p-novo', 'pendente', 100, 0)");
    }

    public function testReExecucaoNaoLancaEManelaIdempotencia(): void
    {
        $this->pdo->exec("INSERT INTO prazo (id, status, source_pub_id, deleted) VALUES
            ('p-1', 'pendente', 200, 0)");

        $migration = new V015__add_prazo_source_pub_id_unique();
        $migration->up($this->pdo);

        // Re-run não deve lançar — try/catch DUPLICATE/missing.
        try {
            $migration->up($this->pdo);
            self::assertTrue(true, 'Re-run não lançou — caminho idempotente OK');
        } catch (\PDOException $e) {
            self::fail('Re-run lançou erro inesperado: ' . $e->getMessage());
        }
    }

    public function testDownEhNoOp(): void
    {
        $this->pdo->exec("INSERT INTO prazo (id, status, source_pub_id, deleted) VALUES
            ('p-1', 'pendente', 300, 0),
            ('p-2', 'rascunho', 300, 1)");

        $migration = new V015__add_prazo_source_pub_id_unique();
        $migration->up($this->pdo);
        $countAfterUp = (int) $this->pdo->query('SELECT COUNT(*) FROM prazo')->fetchColumn();

        $migration->down($this->pdo);
        $countAfterDown = (int) $this->pdo->query('SELECT COUNT(*) FROM prazo')->fetchColumn();

        self::assertSame($countAfterUp, $countAfterDown, 'Down preserva estado; é no-op');
    }
}
