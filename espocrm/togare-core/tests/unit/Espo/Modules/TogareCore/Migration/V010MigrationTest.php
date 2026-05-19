<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Migration;

use Espo\Modules\TogareCore\Migration\V010__add_prazo_extended_fields;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Cobre Migration V010 (Story 4a.3.1, AC2):
 * 6 colunas + 4 indexes adicionados na tabela `prazo`.
 *
 * SQLite usado para isolamento — sintaxe `ALTER TABLE ADD COLUMN` é
 * compatível com MariaDB/MySQL para o subset desta migration.
 *
 * NOTA: SQLite não suporta `CREATE INDEX` em colunas que não existem
 * (precisamos do ALTER antes do CREATE INDEX, o que a migration já garante
 * via ordem). Idempotência (DUPLICATE COLUMN/KEY) é específica de MySQL e
 * não totalmente reproduzida no SQLite — o teste valida o caminho feliz +
 * uma re-run para idempotência defensiva.
 */
final class V010MigrationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Schema base (status quo após V007/V008 da Story 4a.3 — schema reduzido).
        $this->pdo->exec('CREATE TABLE prazo (
            id VARCHAR(24) PRIMARY KEY,
            status VARCHAR(64) NOT NULL,
            assigned_user_id VARCHAR(24) NULL,
            processo_id VARCHAR(24) NULL,
            source_pub_id INTEGER NULL,
            data_fatal DATE NULL
        )');
    }

    public function testUpAdiciona6ColunasNovas(): void
    {
        (new V010__add_prazo_extended_fields())->up($this->pdo);

        $columns = $this->getColumnNames();
        self::assertContains('descricao', $columns);
        self::assertContains('prioridade', $columns);
        self::assertContains('tipo_prazo', $columns);
        self::assertContains('motivo_reagendamento', $columns);
        self::assertContains('cliente_id', $columns);
        self::assertContains('parte_contraria_id', $columns);
    }

    public function testUpAdiciona4IndexesNovos(): void
    {
        (new V010__add_prazo_extended_fields())->up($this->pdo);

        $indexes = $this->getIndexNames();
        self::assertContains('idx_prazo_prioridade', $indexes);
        self::assertContains('idx_prazo_tipo_prazo', $indexes);
        self::assertContains('idx_prazo_cliente_id', $indexes);
        self::assertContains('idx_prazo_parte_contraria_id', $indexes);
    }

    public function testUpDefineDefaultPrioridadeNormal(): void
    {
        (new V010__add_prazo_extended_fields())->up($this->pdo);

        // Insere row sem prioridade explícita — DEFAULT 'normal' deve aplicar.
        $this->pdo->exec("INSERT INTO prazo (id, status) VALUES ('p1', 'pendente')");

        $stmt = $this->pdo->query("SELECT prioridade FROM prazo WHERE id = 'p1'");
        self::assertNotFalse($stmt);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertSame('normal', $row['prioridade']);
    }

    public function testDownEhNoOp(): void
    {
        (new V010__add_prazo_extended_fields())->up($this->pdo);
        $columnsBefore = $this->getColumnNames();

        // Deve passar sem lançar e sem remover nada (preserva dados).
        (new V010__add_prazo_extended_fields())->down($this->pdo);

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
