<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareRbac\Migration;

use Espo\Modules\TogareRbac\Migration\V010__patch_roles_add_funcionario;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Story 6.5 — V010 garante o scope `Funcionario` em roles existentes (FR32).
 *
 * Política: Sócio/Admin + RH-lite = all; os outros 6 roles = no
 * (blindagem cruzada — só RH gerencia funcionários).
 */
final class PatchRolesFuncionarioMigrationTest extends TestCase
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

    public function testAplicaPoliticaCanonicaEmTodosRoles(): void
    {
        $this->insertRole('id-socio', 'Sócio/Admin', ['scopeList' => [], 'scopeLevel' => []]);
        $this->insertRole('id-rh', 'RH-lite', ['scopeList' => [], 'scopeLevel' => []]);
        $this->insertRole('id-adv', 'Advogado', ['scopeList' => [], 'scopeLevel' => []]);
        $this->insertRole('id-ass', 'Assistente/Estagiário', ['scopeList' => [], 'scopeLevel' => []]);
        $this->insertRole('id-sec', 'Secretária', ['scopeList' => [], 'scopeLevel' => []]);
        $this->insertRole('id-fin', 'Financeiro', ['scopeList' => [], 'scopeLevel' => []]);
        $this->insertRole('id-mkt', 'Marketing', ['scopeList' => [], 'scopeLevel' => []]);
        $this->insertRole('id-portal', 'Cliente-portal', ['scopeList' => [], 'scopeLevel' => []]);

        (new V010__patch_roles_add_funcionario())->up($this->pdo);

        self::assertSame('all', $this->loadRoleData('Sócio/Admin')['scopeLevel']['Funcionario']);
        self::assertSame('all', $this->loadRoleData('RH-lite')['scopeLevel']['Funcionario']);
        self::assertSame('no', $this->loadRoleData('Advogado')['scopeLevel']['Funcionario']);
        self::assertSame('no', $this->loadRoleData('Assistente/Estagiário')['scopeLevel']['Funcionario']);
        self::assertSame('no', $this->loadRoleData('Secretária')['scopeLevel']['Funcionario']);
        self::assertSame('no', $this->loadRoleData('Financeiro')['scopeLevel']['Funcionario']);
        self::assertSame('no', $this->loadRoleData('Marketing')['scopeLevel']['Funcionario']);
        self::assertSame('no', $this->loadRoleData('Cliente-portal')['scopeLevel']['Funcionario']);

        foreach (['Sócio/Admin', 'RH-lite', 'Advogado', 'Cliente-portal'] as $r) {
            self::assertContains('Funcionario', $this->loadRoleData($r)['scopeList']);
        }
    }

    public function testAtualizaPoliticaLegadaConhecida(): void
    {
        // Instalação legada vazou `all` para um role que deve ser `no`.
        $this->insertRole('id-mkt', 'Marketing', [
            'scopeList' => ['Funcionario'],
            'scopeLevel' => ['Funcionario' => 'all'],
        ]);
        $this->insertRole('id-adv', 'Advogado', [
            'scopeList' => ['Funcionario'],
            'scopeLevel' => [
                'Funcionario' => ['read' => 'all', 'edit' => 'no', 'create' => 'no', 'delete' => 'no'],
            ],
        ]);

        (new V010__patch_roles_add_funcionario())->up($this->pdo);

        self::assertSame('no', $this->loadRoleData('Marketing')['scopeLevel']['Funcionario']);
        self::assertSame('no', $this->loadRoleData('Advogado')['scopeLevel']['Funcionario']);
    }

    public function testPreservaCustomizacaoDeliberadaDoAdmin(): void
    {
        // Valor não-legado: admin concedeu de propósito — NÃO sobrescrever.
        $custom = ['read' => 'own', 'edit' => 'own', 'create' => 'no', 'delete' => 'no'];
        $this->insertRole('id-sec', 'Secretária', [
            'scopeList' => ['Funcionario'],
            'scopeLevel' => ['Funcionario' => $custom],
        ]);

        (new V010__patch_roles_add_funcionario())->up($this->pdo);

        self::assertSame($custom, $this->loadRoleData('Secretária')['scopeLevel']['Funcionario']);
    }

    public function testCorrigeRoleDataComScopeListEScopeLevelMalformados(): void
    {
        $this->insertRole('id-rh', 'RH-lite', [
            'scopeList' => 'Funcionario',
            'scopeLevel' => 'all',
        ]);

        (new V010__patch_roles_add_funcionario())->up($this->pdo);

        $data = $this->loadRoleData('RH-lite');
        self::assertSame(['Funcionario'], $data['scopeList']);
        self::assertSame('all', $data['scopeLevel']['Funcionario']);
    }

    public function testEhIdempotente(): void
    {
        $this->insertRole('id-rh', 'RH-lite', ['scopeList' => [], 'scopeLevel' => []]);

        $migration = new V010__patch_roles_add_funcionario();
        $migration->up($this->pdo);
        $afterFirst = $this->loadRoleData('RH-lite');
        $migration->up($this->pdo);
        $afterSecond = $this->loadRoleData('RH-lite');

        self::assertSame($afterFirst, $afterSecond);
        // Sem duplicação de scope.
        self::assertSame(
            1,
            array_sum(array_map(static fn ($s) => $s === 'Funcionario' ? 1 : 0, $afterSecond['scopeList'])),
        );
    }

    public function testRoleAusenteNaoQuebra(): void
    {
        // Nenhuma role inserida — up() deve ser no-op silencioso.
        (new V010__patch_roles_add_funcionario())->up($this->pdo);

        $stmt = $this->pdo->query('SELECT COUNT(*) AS c FROM role');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertSame(0, (int) $row['c']);
    }

    /** @param array<string, mixed> $data */
    private function insertRole(string $id, string $name, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO role (id, name, deleted, data, modified_at) VALUES (:id, :name, 0, :data, :modified_at)',
        );
        $stmt->execute([
            'id' => $id,
            'name' => $name,
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'modified_at' => '2026-05-16 00:00:00',
        ]);
    }

    /** @return array<string, mixed> */
    private function loadRoleData(string $name): array
    {
        $stmt = $this->pdo->prepare('SELECT data FROM role WHERE name = :name AND deleted = 0');
        $stmt->execute(['name' => $name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException("Role {$name} não encontrada");
        }
        $data = json_decode((string) $row['data'], true);
        if (!is_array($data)) {
            throw new \RuntimeException("Data inválida para role {$name}");
        }
        return $data;
    }
}
