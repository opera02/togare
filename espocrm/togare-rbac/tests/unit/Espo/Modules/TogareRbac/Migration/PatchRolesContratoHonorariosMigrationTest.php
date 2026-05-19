<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareRbac\Migration;

use Espo\Modules\TogareRbac\Migration\V008__patch_roles_add_contrato_honorarios;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Story 6.1 - V008 garante ContratoHonorarios em roles existentes.
 */
final class PatchRolesContratoHonorariosMigrationTest extends TestCase
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

    public function testAplicaPoliticaQuandoScopeAusente(): void
    {
        $this->insertRole('id-socio', 'Sócio/Admin', ['scopeList' => [], 'scopeLevel' => []]);
        $this->insertRole('id-fin', 'Financeiro', ['scopeList' => [], 'scopeLevel' => []]);
        $this->insertRole('id-adv', 'Advogado', ['scopeList' => [], 'scopeLevel' => []]);
        $this->insertRole('id-ass', 'Assistente/Estagiário', ['scopeList' => [], 'scopeLevel' => []]);
        $this->insertRole('id-sec', 'Secretária', ['scopeList' => [], 'scopeLevel' => []]);

        (new V008__patch_roles_add_contrato_honorarios())->up($this->pdo);

        self::assertSame('all', $this->loadRoleData('Sócio/Admin')['scopeLevel']['ContratoHonorarios']);
        self::assertSame('all', $this->loadRoleData('Financeiro')['scopeLevel']['ContratoHonorarios']);
        self::assertSame(
            ['read' => 'own', 'edit' => 'no', 'create' => 'no', 'delete' => 'no'],
            $this->loadRoleData('Advogado')['scopeLevel']['ContratoHonorarios'],
        );
        self::assertSame(
            ['read' => 'own', 'edit' => 'no', 'create' => 'no', 'delete' => 'no'],
            $this->loadRoleData('Assistente/Estagiário')['scopeLevel']['ContratoHonorarios'],
        );
        self::assertSame('no', $this->loadRoleData('Secretária')['scopeLevel']['ContratoHonorarios']);
    }

    public function testAtualizaPoliticasLegadasConhecidas(): void
    {
        $this->insertRole('id-fin', 'Financeiro', [
            'scopeList' => ['ContratoHonorarios'],
            'scopeLevel' => [
                'ContratoHonorarios' => ['read' => 'all', 'edit' => 'team', 'create' => 'team', 'delete' => 'no'],
            ],
        ]);
        $this->insertRole('id-adv', 'Advogado', [
            'scopeList' => ['ContratoHonorarios'],
            'scopeLevel' => ['ContratoHonorarios' => 'no'],
        ]);
        $this->insertRole('id-ass', 'Assistente/Estagiário', [
            'scopeList' => ['ContratoHonorarios'],
            'scopeLevel' => ['ContratoHonorarios' => 'no'],
        ]);

        (new V008__patch_roles_add_contrato_honorarios())->up($this->pdo);

        self::assertSame('all', $this->loadRoleData('Financeiro')['scopeLevel']['ContratoHonorarios']);
        self::assertSame(
            ['read' => 'own', 'edit' => 'no', 'create' => 'no', 'delete' => 'no'],
            $this->loadRoleData('Advogado')['scopeLevel']['ContratoHonorarios'],
        );
        self::assertSame(
            ['read' => 'own', 'edit' => 'no', 'create' => 'no', 'delete' => 'no'],
            $this->loadRoleData('Assistente/Estagiário')['scopeLevel']['ContratoHonorarios'],
        );
    }

    public function testPreservaCustomizacaoExistenteDoAdmin(): void
    {
        $custom = ['read' => 'all', 'edit' => 'no', 'create' => 'no', 'delete' => 'no'];
        $this->insertRole('id-adv', 'Advogado', [
            'scopeList' => ['ContratoHonorarios'],
            'scopeLevel' => ['ContratoHonorarios' => $custom],
        ]);

        (new V008__patch_roles_add_contrato_honorarios())->up($this->pdo);

        self::assertSame($custom, $this->loadRoleData('Advogado')['scopeLevel']['ContratoHonorarios']);
    }

    public function testEhIdempotente(): void
    {
        $this->insertRole('id-fin', 'Financeiro', ['scopeList' => [], 'scopeLevel' => []]);

        $migration = new V008__patch_roles_add_contrato_honorarios();
        $migration->up($this->pdo);
        $migration->up($this->pdo);

        $data = $this->loadRoleData('Financeiro');
        self::assertSame(['ContratoHonorarios'], $data['scopeList']);
        self::assertSame('all', $data['scopeLevel']['ContratoHonorarios']);
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
            'data' => \json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'modified_at' => '2026-05-14 10:00:00',
        ]);
    }

    /** @return array<string, mixed> */
    private function loadRoleData(string $name): array
    {
        $stmt = $this->pdo->prepare('SELECT data FROM role WHERE name = :name');
        $stmt->execute(['name' => $name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row, "Role {$name} ausente no banco de teste");

        return \json_decode((string) $row['data'], true, 512, JSON_THROW_ON_ERROR);
    }
}
