<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareRbac\Service;

use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareRbac\Service\RoleSeeder;
use PDO;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Cobre AC1, AC2 e AC6 — seed inicial, idempotência, e tratamento de JSON inválido.
 */
final class RoleSeederTest extends TestCase
{
    private const SEED_DIR = __DIR__ . '/../../../../../../src/files/custom/Espo/Modules/TogareRbac/Resources/seed/roles';

    private PDO $pdo;

    protected function setUp(): void
    {
        $stdout = \fopen('php://memory', 'w+');
        $stderr = \fopen('php://memory', 'w+');
        TogareLogger::init('test-rbac', null, $stdout, $stderr);

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createRoleSchema();
    }

    private function createRoleSchema(): void
    {
        // Espelha o subset relevante da tabela `role` core do EspoCRM.
        $this->pdo->exec("
            CREATE TABLE role (
                id VARCHAR(17) NOT NULL PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                deleted INTEGER NOT NULL DEFAULT 0,
                assignment_permission VARCHAR(255) NOT NULL DEFAULT 'no',
                user_permission VARCHAR(255) NOT NULL DEFAULT 'no',
                message_permission VARCHAR(255) NOT NULL DEFAULT 'no',
                portal_permission VARCHAR(255) NOT NULL DEFAULT 'no',
                export_permission VARCHAR(255) NOT NULL DEFAULT 'no',
                mass_update_permission VARCHAR(255) NOT NULL DEFAULT 'no',
                audit_permission VARCHAR(255) NOT NULL DEFAULT 'no',
                data MEDIUMTEXT,
                field_data MEDIUMTEXT,
                created_at DATETIME,
                modified_at DATETIME
            )
        ");
    }

    #[RunInSeparateProcess]
    public function testSeedInicialPopulaOitoRoles(): void
    {
        $seeder = new RoleSeeder($this->pdo, TogareLogger::getInstance());
        $summary = $seeder->seedFromDir(self::SEED_DIR);

        $this->assertSame(['seeded' => 8, 'skipped' => 0, 'invalid' => 0], $summary);

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM role WHERE deleted=0')->fetchColumn();
        $this->assertSame(8, $count);

        $names = $this->pdo->query('SELECT name FROM role WHERE deleted=0 ORDER BY name')
            ->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame([
            'Advogado',
            'Assistente/Estagiário',
            'Cliente-portal',
            'Financeiro',
            'Marketing',
            'RH-lite',
            'Secretária',
            'Sócio/Admin',
        ], $names);
    }

    #[RunInSeparateProcess]
    public function testSegundaExecucaoEhIdempotenteESkipsNaoSobrescrevemCustomizacao(): void
    {
        $seeder = new RoleSeeder($this->pdo, TogareLogger::getInstance());

        // Primeira instalação.
        $seeder->seedFromDir(self::SEED_DIR);

        // Admin customiza role 'Secretária' adicionando uma permissão extra.
        $this->pdo->exec("
            UPDATE role SET data = '{\"customizado\":true}' WHERE name = 'Secretária'
        ");

        // Segunda instalação (rebuild ou bump de versão).
        $summary = $seeder->seedFromDir(self::SEED_DIR);

        $this->assertSame(['seeded' => 0, 'skipped' => 8, 'invalid' => 0], $summary);

        // Continua sendo apenas 8 rows.
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM role WHERE deleted=0')->fetchColumn();
        $this->assertSame(8, $count);

        // Customização do admin preservada.
        $secretariaData = $this->pdo->query("SELECT data FROM role WHERE name = 'Secretária'")
            ->fetchColumn();
        $this->assertSame('{"customizado":true}', $secretariaData);
    }

    #[RunInSeparateProcess]
    public function testSeedJsonInvalidoIsoladoNaoAbortaOSeedInteiro(): void
    {
        $tmpDir = \sys_get_temp_dir() . '/togare-rbac-seed-test-' . \uniqid();
        \mkdir($tmpDir);

        try {
            // 1 arquivo válido + 1 arquivo com JSON quebrado.
            \copy(self::SEED_DIR . '/socio-admin.json', $tmpDir . '/socio-admin.json');
            \file_put_contents($tmpDir . '/quebrado.json', '{ não é um json válido }');

            $seeder = new RoleSeeder($this->pdo, TogareLogger::getInstance());
            $summary = $seeder->seedFromDir($tmpDir);

            $this->assertSame(1, $summary['seeded'], 'O JSON válido foi seedado mesmo com outro inválido.');
            $this->assertSame(1, $summary['invalid']);
            $this->assertSame(0, $summary['skipped']);

            $names = $this->pdo->query('SELECT name FROM role')->fetchAll(PDO::FETCH_COLUMN);
            $this->assertSame(['Sócio/Admin'], $names);
        } finally {
            \array_map('unlink', \glob($tmpDir . '/*') ?: []);
            \rmdir($tmpDir);
        }
    }

    #[RunInSeparateProcess]
    public function testSeedDataJsonRoundtripPreservaScopes(): void
    {
        $seeder = new RoleSeeder($this->pdo, TogareLogger::getInstance());
        $seeder->seedFromDir(self::SEED_DIR);

        // Lê o role Advogado e confirma que `data` JSON contém scopeLevel.Processo.
        // Story 3.4 (v0.6.3) renomeou "Process" → "Processo" em todos os 8 roles.
        // Story 3.5 (v0.7.0) ajustou Advogado.Processo.read team→own (FR11).
        $rawData = (string) $this->pdo->query("SELECT data FROM role WHERE name = 'Advogado'")
            ->fetchColumn();
        $decoded = \json_decode($rawData, true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('scopeLevel', $decoded);
        $this->assertArrayHasKey('Processo', $decoded['scopeLevel']);
        // Processo do Advogado é objeto granular {read: own (FR11), edit: own, ...}.
        $this->assertSame('own', $decoded['scopeLevel']['Processo']['read']);
        $this->assertSame('own', $decoded['scopeLevel']['Processo']['edit']);
    }

    #[RunInSeparateProcess]
    public function testSeedNaoRecriaRoleComDeleted1(): void
    {
        $seeder = new RoleSeeder($this->pdo, TogareLogger::getInstance());

        // Primeira instalação — 8 roles criados.
        $seeder->seedFromDir(self::SEED_DIR);

        // Admin soft-deleta o role 'Secretária'.
        $this->pdo->exec("UPDATE role SET deleted = 1 WHERE name = 'Secretária'");

        // Segunda execução — seed não deve recriar o role deletado.
        $summary = $seeder->seedFromDir(self::SEED_DIR);

        $this->assertSame(['seeded' => 0, 'skipped' => 8, 'invalid' => 0], $summary);

        // Total de rows na tabela (deleted=0 e deleted=1) continua 8 — sem duplicata.
        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM role')->fetchColumn();
        $this->assertSame(8, $total);

        // A row deleted=1 permanece — não foi sobrescrita.
        $deletedCount = (int) $this->pdo->query("SELECT COUNT(*) FROM role WHERE name = 'Secretária' AND deleted = 1")->fetchColumn();
        $this->assertSame(1, $deletedCount);
    }

    #[RunInSeparateProcess]
    public function testSeedTopLevelPermissionsPersistidasCorretamente(): void
    {
        $seeder = new RoleSeeder($this->pdo, TogareLogger::getInstance());
        $seeder->seedFromDir(self::SEED_DIR);

        // Cliente-portal: portal_permission = 'yes'.
        $portalRole = $this->pdo->query("
            SELECT portal_permission, user_permission FROM role WHERE name = 'Cliente-portal'
        ")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('yes', $portalRole['portal_permission']);
        $this->assertSame('no', $portalRole['user_permission']);

        // Sócio/Admin: assignment=all, audit=all.
        $admin = $this->pdo->query("
            SELECT assignment_permission, audit_permission FROM role WHERE name = 'Sócio/Admin'
        ")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('all', $admin['assignment_permission']);
        $this->assertSame('all', $admin['audit_permission']);
    }
}
