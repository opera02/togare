<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareRbac\Migration;

use Espo\Modules\TogareRbac\Migration\V005__patch_roles_field_level_prazo;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Story 4a.3.1 — testa V005 migration que adiciona `data.fieldLevel.Prazo`
 * nas 4 roles operacionais (Sócio/Admin, Advogado, Assistente/Estagiário,
 * Secretária). Pattern espelha V003/V004.
 *
 * SQLite in-memory para isolamento determinístico.
 */
final class PatchRolesFieldLevelPrazoMigrationTest extends TestCase
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

    public function testAplicaFieldLevelEm4RolesOperacionais(): void
    {
        $this->insertRole('id-socio', 'Sócio/Admin', ['fieldLevel' => []]);
        $this->insertRole('id-adv', 'Advogado', ['fieldLevel' => []]);
        $this->insertRole('id-ass', 'Assistente/Estagiário', ['fieldLevel' => []]);
        $this->insertRole('id-sec', 'Secretária', ['fieldLevel' => []]);

        (new V005__patch_roles_field_level_prazo())->up($this->pdo);

        $socio = $this->loadRoleData('Sócio/Admin');
        self::assertSame('yes', $socio['fieldLevel']['Prazo']['descricao']);
        self::assertSame('yes', $socio['fieldLevel']['Prazo']['parteContraria']);

        $adv = $this->loadRoleData('Advogado');
        self::assertSame('yes', $adv['fieldLevel']['Prazo']['motivoReagendamento']);

        $ass = $this->loadRoleData('Assistente/Estagiário');
        self::assertSame('yes', $ass['fieldLevel']['Prazo']['cliente']);

        $sec = $this->loadRoleData('Secretária');
        self::assertSame('read', $sec['fieldLevel']['Prazo']['descricao']);
        self::assertSame('read', $sec['fieldLevel']['Prazo']['prioridade']);
    }

    public function testNaoToca4RolesNaoOperacionais(): void
    {
        $this->insertRole('id-fin', 'Financeiro', ['fieldLevel' => []]);
        $this->insertRole('id-mkt', 'Marketing', ['fieldLevel' => []]);
        $this->insertRole('id-rh', 'RH-lite', ['fieldLevel' => []]);
        $this->insertRole('id-port', 'Cliente-portal', ['fieldLevel' => []]);

        (new V005__patch_roles_field_level_prazo())->up($this->pdo);

        foreach (['Financeiro', 'Marketing', 'RH-lite', 'Cliente-portal'] as $name) {
            $data = $this->loadRoleData($name);
            self::assertArrayNotHasKey(
                'Prazo',
                $data['fieldLevel'] ?? [],
                "{$name}.fieldLevel.Prazo NÃO deve ser populado pela V005 (scope.no já bloqueia)",
            );
        }
    }

    public function testEhIdempotente(): void
    {
        $this->insertRole('id-socio', 'Sócio/Admin', ['fieldLevel' => []]);

        $migration = new V005__patch_roles_field_level_prazo();
        $migration->up($this->pdo);
        $migration->up($this->pdo);

        $data = $this->loadRoleData('Sócio/Admin');
        self::assertSame('yes', $data['fieldLevel']['Prazo']['descricao']);
        self::assertCount(6, $data['fieldLevel']['Prazo'], 'fieldLevel.Prazo continua com 6 entradas após re-run');
    }

    public function testPreservaCustomizacoesDoAdmin(): void
    {
        // Admin alterou descricao para 'no' via UI Admin → Roles.
        $this->insertRole('id-adv', 'Advogado', [
            'fieldLevel' => [
                'Prazo' => ['descricao' => 'no'],
            ],
        ]);

        (new V005__patch_roles_field_level_prazo())->up($this->pdo);

        $data = $this->loadRoleData('Advogado');
        self::assertSame('no', $data['fieldLevel']['Prazo']['descricao'], 'Customização do admin preservada');
        // Mas adiciona os outros 5 campos.
        self::assertSame('yes', $data['fieldLevel']['Prazo']['prioridade']);
        self::assertSame('yes', $data['fieldLevel']['Prazo']['tipoPrazo']);
    }

    public function testRolesAusentesNoBancoSaoIgnoradas(): void
    {
        // Não insere nenhuma role — migration deve passar sem erro.
        (new V005__patch_roles_field_level_prazo())->up($this->pdo);

        $stmt = $this->pdo->query('SELECT COUNT(*) AS cnt FROM role');
        self::assertNotFalse($stmt);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertSame(0, (int) $row['cnt']);
    }

    public function testDownEhNoOp(): void
    {
        $this->insertRole('id-socio', 'Sócio/Admin', ['fieldLevel' => []]);
        (new V005__patch_roles_field_level_prazo())->up($this->pdo);

        $beforeData = $this->loadRoleData('Sócio/Admin');

        (new V005__patch_roles_field_level_prazo())->down($this->pdo);

        $afterData = $this->loadRoleData('Sócio/Admin');
        self::assertSame($beforeData, $afterData);
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
            'modified_at' => '2026-05-04 10:00:00',
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
