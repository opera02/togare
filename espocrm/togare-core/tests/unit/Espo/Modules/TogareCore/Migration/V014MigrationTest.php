<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Migration;

use Espo\Modules\TogareCore\Migration\V014__create_publicacao_ambigua;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Cobre Migration V014 (Story 4b.1a, AC2 + AC3).
 *
 * A V014 cria apenas a tabela auxiliar `togare_ambiguity_log`. A tabela da
 * entidade `publicacao_ambigua` e criada pelo rebuild do EspoCRM a partir do
 * entityDefs/PublicacaoAmbigua.json, como as demais entidades Togare.
 */
final class V014MigrationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function testUpCriaTabelaTogareAmbiguityLog(): void
    {
        (new V014__create_publicacao_ambigua())->up($this->pdo);

        self::assertContains('togare_ambiguity_log', $this->getTableNames());
    }

    public function testTogareAmbiguityLogTemColunasEsperadas(): void
    {
        (new V014__create_publicacao_ambigua())->up($this->pdo);

        $columns = $this->getColumnNames('togare_ambiguity_log');
        $expected = [
            'id', 'publicacao_ambigua_id', 'decided_by_user_id', 'decided_at',
            'decision_type', 'chosen_processo_id', 'prazo_criado_id',
            'candidates_snapshot', 'excerpt', 'texto_publicacao', 'created_at',
        ];

        foreach ($expected as $col) {
            self::assertContains($col, $columns, "Coluna '{$col}' deve existir em togare_ambiguity_log");
        }
    }

    public function testUpCriaIndexesEsperados(): void
    {
        (new V014__create_publicacao_ambigua())->up($this->pdo);

        $logIndexes = $this->getIndexNames('togare_ambiguity_log');
        self::assertContains('idx_ambiguity_log_pub', $logIndexes);
        self::assertContains('idx_ambiguity_log_chosen_proc', $logIndexes);
        self::assertContains('idx_ambiguity_log_decision_type', $logIndexes);
        self::assertContains('idx_ambiguity_log_created_at', $logIndexes);
    }

    public function testReExecucaoNaoLancaEManelaIdempotencia(): void
    {
        $migration = new V014__create_publicacao_ambigua();
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
        (new V014__create_publicacao_ambigua())->up($this->pdo);
        $tablesBefore = $this->getTableNames();

        (new V014__create_publicacao_ambigua())->down($this->pdo);

        self::assertSame($tablesBefore, $this->getTableNames(), 'Down preserva tabelas.');
    }

    public function testWriteAuditLogDefensivoQuandoTabelaNaoExiste(): void
    {
        try {
            (new V014__create_publicacao_ambigua())->up($this->pdo);
            self::assertTrue(true, 'V014 nao falha quando togare_audit_log ausente');
        } catch (\Throwable $e) {
            self::fail('V014 falhou por causa de audit log inexistente: ' . $e->getMessage());
        }
    }

    public function testWriteAuditLogInsereEntryQuandoTabelaExiste(): void
    {
        $this->pdo->exec("CREATE TABLE togare_audit_log (
            id VARCHAR(32) PRIMARY KEY,
            occurred_at DATETIME,
            event VARCHAR(64),
            entity_type VARCHAR(64),
            entity_id VARCHAR(32) NULL,
            user_id VARCHAR(32) NULL,
            user_name VARCHAR(128) NULL,
            ip_address VARCHAR(64) NULL,
            user_agent TEXT NULL,
            correlation_id VARCHAR(64) NULL,
            context_json TEXT NULL
        )");

        (new V014__create_publicacao_ambigua())->up($this->pdo);

        self::assertContains('togare_audit_log', $this->getTableNames(), 'audit log preservado');
        self::assertContains('togare_ambiguity_log', $this->getTableNames(), 'ambiguity log criado');

        $stmt = $this->pdo->query(
            "SELECT event, entity_type, user_name, context_json
             FROM togare_audit_log
             WHERE event = 'togare_ambiguity_log.schema_created_v014'"
        );
        self::assertNotFalse($stmt);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row, 'V014 deve inserir audit log quando togare_audit_log existe');
        self::assertSame('Migration', $row['entity_type']);
        self::assertSame('system:migration', $row['user_name']);

        $context = \json_decode((string) $row['context_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $context['count_total'] ?? null);
        self::assertStringContainsString('togare_ambiguity_log', (string) ($context['note'] ?? ''));
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
