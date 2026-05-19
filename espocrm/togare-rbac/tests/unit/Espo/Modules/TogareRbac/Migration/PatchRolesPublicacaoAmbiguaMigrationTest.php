<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareRbac\Migration;

use Espo\Modules\TogareRbac\Migration\V006__patch_roles_add_publicacao_ambigua;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Story 4b.1a - V006 garante PublicacaoAmbigua em roles existentes.
 *
 * O seed e idempotente e pula roles ja existentes; a migration cobre upgrades
 * preservando customizacoes feitas pelo admin.
 */
final class PatchRolesPublicacaoAmbiguaMigrationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE role (
                id VARCHAR(17) NOT NULL PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                deleted INTEGER NOT NULL DEFAULT 0,
                data MEDIUMTEXT,
                modified_at DATETIME
            )',
        );
    }

    public function testAplicaScopeNasTresRolesOperacionais(): void
    {
        $this->insertRole('id-socio', 'Sócio/Admin', ['scopeList' => [], 'scopeLevel' => []]);
        $this->insertRole('id-adv', 'Advogado', ['scopeList' => [], 'scopeLevel' => []]);
        $this->insertRole('id-ass', 'Assistente/Estagiário', ['scopeList' => [], 'scopeLevel' => []]);

        (new V006__patch_roles_add_publicacao_ambigua())->up($this->pdo);

        $socio = $this->loadRoleData('Sócio/Admin');
        self::assertContains('PublicacaoAmbigua', $socio['scopeList']);
        self::assertSame('all', $socio['scopeLevel']['PublicacaoAmbigua']);

        $adv = $this->loadRoleData('Advogado');
        self::assertContains('PublicacaoAmbigua', $adv['scopeList']);
        self::assertSame(
            ['read' => 'team', 'edit' => 'team', 'create' => 'no', 'delete' => 'no'],
            $adv['scopeLevel']['PublicacaoAmbigua'],
        );

        $assistente = $this->loadRoleData('Assistente/Estagiário');
        self::assertContains('PublicacaoAmbigua', $assistente['scopeList']);
        self::assertSame(
            ['read' => 'team', 'edit' => 'no', 'create' => 'no', 'delete' => 'no'],
            $assistente['scopeLevel']['PublicacaoAmbigua'],
        );
    }

    public function testSecretariaPermaneceSemScopePublicacaoAmbigua(): void
    {
        $this->insertRole('id-sec', 'Secretária', ['scopeList' => [], 'scopeLevel' => []]);

        (new V006__patch_roles_add_publicacao_ambigua())->up($this->pdo);

        $data = $this->loadRoleData('Secretária');
        self::assertNotContains('PublicacaoAmbigua', $data['scopeList']);
        self::assertArrayNotHasKey('PublicacaoAmbigua', $data['scopeLevel']);
    }

    public function testPreservaCustomizacaoExistenteDoAdmin(): void
    {
        $this->insertRole('id-adv', 'Advogado', [
            'scopeList' => ['PublicacaoAmbigua'],
            'scopeLevel' => [
                'PublicacaoAmbigua' => ['read' => 'own', 'edit' => 'no', 'create' => 'no', 'delete' => 'no'],
            ],
        ]);

        (new V006__patch_roles_add_publicacao_ambigua())->up($this->pdo);

        $data = $this->loadRoleData('Advogado');
        self::assertSame(
            ['read' => 'own', 'edit' => 'no', 'create' => 'no', 'delete' => 'no'],
            $data['scopeLevel']['PublicacaoAmbigua'],
        );
    }

    public function testEhIdempotente(): void
    {
        $this->insertRole('id-socio', 'Sócio/Admin', ['scopeList' => [], 'scopeLevel' => []]);

        $migration = new V006__patch_roles_add_publicacao_ambigua();
        $migration->up($this->pdo);
        $migration->up($this->pdo);

        $data = $this->loadRoleData('Sócio/Admin');
        self::assertSame(['PublicacaoAmbigua'], $data['scopeList']);
        self::assertSame('all', $data['scopeLevel']['PublicacaoAmbigua']);
    }

    public function testRolesAusentesSaoIgnoradas(): void
    {
        (new V006__patch_roles_add_publicacao_ambigua())->up($this->pdo);

        $stmt = $this->pdo->query('SELECT COUNT(*) AS cnt FROM role');
        self::assertNotFalse($stmt);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertSame(0, (int) $row['cnt']);
    }

    public function testDownEhNoOp(): void
    {
        $this->insertRole('id-socio', 'Sócio/Admin', ['scopeList' => [], 'scopeLevel' => []]);
        $migration = new V006__patch_roles_add_publicacao_ambigua();
        $migration->up($this->pdo);

        $before = $this->loadRoleData('Sócio/Admin');
        $migration->down($this->pdo);

        self::assertSame($before, $this->loadRoleData('Sócio/Admin'));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function insertRole(string $id, string $name, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO role (id, name, deleted, data, modified_at) VALUES (:id, :name, 0, :data, :modified_at)',
        );
        $stmt->execute([
            'id' => $id,
            'name' => $name,
            'data' => \json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'modified_at' => '2026-05-07 10:00:00',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadRoleData(string $name): array
    {
        $stmt = $this->pdo->prepare('SELECT data FROM role WHERE name = :name');
        $stmt->execute(['name' => $name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row, "Role {$name} ausente no banco de teste");

        return \json_decode((string) $row['data'], true, 512, JSON_THROW_ON_ERROR);
    }
}
