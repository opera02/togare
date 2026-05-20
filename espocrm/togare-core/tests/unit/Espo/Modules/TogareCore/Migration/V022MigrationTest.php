<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Migration;

use Espo\Modules\TogareCore\Migration\V022__convert_legacy_collation;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Cobre Migration V022 (hotfix 0.39.1, collation MariaDB 11.4).
 *
 * Os testes rodam em SQLite — a migration tem early return para driver != mysql,
 * portanto `up()` é noop nesse ambiente. Isso é coberto, mais a lista de tabelas
 * alvo via reflection (consistência com nomes reais das V001–V021) e contrato
 * básico (version, down no-op).
 */
final class V022MigrationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function testVersionReturnsCorrectName(): void
    {
        $version = (new V022__convert_legacy_collation())->version();
        self::assertSame('V022__convert_legacy_collation', $version);
    }

    public function testUpEhNoopEmSqlite(): void
    {
        // Em SQLite não há ALTER TABLE CONVERT TO; a migration tem early return.
        // Não deve lançar exception, não deve afetar nenhuma tabela existente.
        (new V022__convert_legacy_collation())->up($this->pdo);

        self::assertSame([], $this->getTableNames(), 'SQLite: up() não deve criar tabelas');
    }

    public function testUpEhIdempotenteEmSqlite(): void
    {
        $migration = new V022__convert_legacy_collation();

        $migration->up($this->pdo);
        $migration->up($this->pdo); // rerun
        $migration->up($this->pdo); // rerun

        self::assertSame([], $this->getTableNames(), 'múltiplas execuções em SQLite continuam noop');
    }

    public function testDownEhNoopIntencional(): void
    {
        // Down não reverte (collation problemática era o bug). Apenas garantir que
        // chamar não levanta exception.
        (new V022__convert_legacy_collation())->down($this->pdo);

        self::assertTrue(true, 'down() noop não lança');
    }

    public function testListaDeTabelasContemAsTabelasTogareCore(): void
    {
        $reflection = new ReflectionClass(V022__convert_legacy_collation::class);
        $tables = $reflection->getConstant('TABLES');

        self::assertIsArray($tables);

        // Pelo menos as tabelas criadas pelas migrations V001-V021 do togare-core
        // que tinham CREATE TABLE sem COLLATE explícito.
        $expected = [
            'togare_migrations_applied',          // V001
            'togare_core_smoke',                  // V002
            'togare_queue_items',                 // V004
            'togare_rate_limits',                 // V005
            'togare_audit_log',                   // V006
            'togare_ambiguity_log',               // V014
            'togare_prazo_lembrete',              // V016
            'togare_documento_log',               // V018
            'togare_contrato_honorarios_log',     // V020
            'togare_fatura_log',                  // V021
            'togare_lancamento_financeiro_log',   // V021
        ];

        foreach ($expected as $expectedTable) {
            self::assertContains(
                $expectedTable,
                $tables,
                "Tabela '{$expectedTable}' deve estar na lista TABLES da V022",
            );
        }

        self::assertCount(11, $tables, 'Esperado 11 tabelas togare_* (V001-V021)');
    }

    public function testCollationAlvoEhUtf8mb4UnicodeCi(): void
    {
        $reflection = new ReflectionClass(V022__convert_legacy_collation::class);

        self::assertSame('utf8mb4', $reflection->getConstant('TARGET_CHARSET'));
        self::assertSame('utf8mb4_unicode_ci', $reflection->getConstant('TARGET_COLLATION'));
    }

    /**
     * @return list<string>
     */
    private function getTableNames(): array
    {
        $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
        if ($stmt === false) {
            return [];
        }
        $result = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return $result === false ? [] : array_map('strval', $result);
    }
}
