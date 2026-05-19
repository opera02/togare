<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore;

use Espo\Modules\TogareCore\Services\MigrationRunner;
use PDO;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Testes do MigrationRunner usando SQLite em memória + migrations fixture
 * escritas inline em arquivos temporários. Isolamento completo: não requer
 * MariaDB rodando.
 *
 * Fixtures usam sintaxe SQLite compatível (sem AUTO_INCREMENT, sem ENGINE).
 *
 * Cada teste roda em processo separado (RunInSeparateProcess) porque as
 * fixtures (re)declaram classes com nomes iguais entre testes — sem
 * isolamento, PHP acusa "class already in use".
 */
final class MigrationRunnerTest extends TestCase
{
    private PDO $pdo;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->tempDir = sys_get_temp_dir() . '/togare-mig-test-' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tempDir);
    }

    #[RunInSeparateProcess]
    public function testAppliesPendingInOrder(): void
    {
        $this->writeFixtureV001_applied();
        $this->writeFixtureV002_foo_table();

        $runner = new MigrationRunner($this->pdo);
        $applied = $runner->runPending($this->tempDir);

        self::assertSame(
            ['V001__create_togare_migrations_applied', 'V002__create_foo'],
            $applied,
        );

        // V001 criou a tabela de controle; V002 criou foo.
        $tables = $this->tableNames();
        self::assertContains('togare_migrations_applied', $tables);
        self::assertContains('foo', $tables);

        // 2 registros em togare_migrations_applied.
        $count = $this->pdo->query('SELECT COUNT(*) FROM togare_migrations_applied')->fetchColumn();
        self::assertSame(2, (int) $count);
    }

    #[RunInSeparateProcess]
    public function testIdempotentOnSecondRun(): void
    {
        $this->writeFixtureV001_applied();
        $this->writeFixtureV002_foo_table();

        $runner = new MigrationRunner($this->pdo);
        $runner->runPending($this->tempDir);
        $appliedSecondRun = $runner->runPending($this->tempDir);

        self::assertSame([], $appliedSecondRun);
    }

    #[RunInSeparateProcess]
    public function testRollbackRemovesRegistry(): void
    {
        $this->writeFixtureV001_applied();
        $this->writeFixtureV002_foo_table();

        $runner = new MigrationRunner($this->pdo);
        $runner->runPending($this->tempDir);

        $reverted = $runner->rollback('V002__create_foo', $this->tempDir);

        self::assertTrue($reverted);

        $tables = $this->tableNames();
        self::assertNotContains('foo', $tables);
        self::assertContains('togare_migrations_applied', $tables);

        $remaining = $this->pdo->query('SELECT schema_version FROM togare_migrations_applied')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['V001__create_togare_migrations_applied'], $remaining);

        // Rollback duas vezes é idempotente.
        $revertedAgain = $runner->rollback('V002__create_foo', $this->tempDir);
        self::assertFalse($revertedAgain);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function writeFixtureV001_applied(): void
    {
        $code = <<<'PHP'
<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Migration;

use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;

// phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps
final class V001__create_togare_migrations_applied implements MigrationInterface
{
    public function version(): string
    {
        return 'V001__create_togare_migrations_applied';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS togare_migrations_applied (
            schema_version VARCHAR(200) NOT NULL PRIMARY KEY,
            applied_at DATETIME NOT NULL,
            checksum CHAR(64) NOT NULL
        )");
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS togare_migrations_applied');
    }
}
PHP;
        file_put_contents(
            $this->tempDir . '/V001__create_togare_migrations_applied.php',
            $code,
        );
    }

    private function writeFixtureV002_foo_table(): void
    {
        $code = <<<'PHP'
<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Migration;

use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;

// phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps
final class V002__create_foo implements MigrationInterface
{
    public function version(): string
    {
        return 'V002__create_foo';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE foo (id INTEGER PRIMARY KEY, name VARCHAR(50))");
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS foo');
    }
}
PHP;
        file_put_contents($this->tempDir . '/V002__create_foo.php', $code);
    }

    /**
     * @return list<string>
     */
    private function tableNames(): array
    {
        $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
