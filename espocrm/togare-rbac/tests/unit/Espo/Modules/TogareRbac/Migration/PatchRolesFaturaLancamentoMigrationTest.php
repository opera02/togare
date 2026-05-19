<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareRbac\Migration;

use Espo\Modules\TogareRbac\Migration\V009__patch_roles_add_fatura_lancamento;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Story 6.3 — V009 garante Fatura + LancamentoFinanceiro em roles existentes.
 *
 * Política (Decisão #10): Sócio/Admin + Financeiro full em ambas; Advogado +
 * Assistente read own em ambas; Secretária + Marketing + RH-lite + Cliente-portal no.
 */
final class PatchRolesFaturaLancamentoMigrationTest extends TestCase
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

    public function testAplicaPoliticaEmAmbosScopesParaTodosRoles(): void
    {
        $this->insertRole('id-socio', 'Sócio/Admin', ['scopeList' => [], 'scopeLevel' => []]);
        $this->insertRole('id-fin', 'Financeiro', ['scopeList' => [], 'scopeLevel' => []]);
        $this->insertRole('id-adv', 'Advogado', ['scopeList' => [], 'scopeLevel' => []]);
        $this->insertRole('id-ass', 'Assistente/Estagiário', ['scopeList' => [], 'scopeLevel' => []]);
        $this->insertRole('id-sec', 'Secretária', ['scopeList' => [], 'scopeLevel' => []]);
        $this->insertRole('id-mkt', 'Marketing', ['scopeList' => [], 'scopeLevel' => []]);
        $this->insertRole('id-rh', 'RH-lite', ['scopeList' => [], 'scopeLevel' => []]);
        $this->insertRole('id-portal', 'Cliente-portal', ['scopeList' => [], 'scopeLevel' => []]);

        (new V009__patch_roles_add_fatura_lancamento())->up($this->pdo);

        foreach (['Fatura', 'LancamentoFinanceiro'] as $scope) {
            self::assertSame('all', $this->loadRoleData('Sócio/Admin')['scopeLevel'][$scope]);
            self::assertSame('all', $this->loadRoleData('Financeiro')['scopeLevel'][$scope]);
            self::assertSame(
                ['read' => 'own', 'edit' => 'no', 'create' => 'no', 'delete' => 'no'],
                $this->loadRoleData('Advogado')['scopeLevel'][$scope],
            );
            self::assertSame(
                ['read' => 'own', 'edit' => 'no', 'create' => 'no', 'delete' => 'no'],
                $this->loadRoleData('Assistente/Estagiário')['scopeLevel'][$scope],
            );
            self::assertSame('no', $this->loadRoleData('Secretária')['scopeLevel'][$scope]);
            self::assertSame('no', $this->loadRoleData('Marketing')['scopeLevel'][$scope]);
            self::assertSame('no', $this->loadRoleData('RH-lite')['scopeLevel'][$scope]);
            self::assertSame('no', $this->loadRoleData('Cliente-portal')['scopeLevel'][$scope]);
        }
    }

    public function testAtualizaPoliticasLegadasConhecidas(): void
    {
        $this->insertRole('id-fin', 'Financeiro', [
            'scopeList' => ['Fatura'],
            'scopeLevel' => [
                'Fatura' => ['read' => 'all', 'edit' => 'team', 'create' => 'team', 'delete' => 'no'],
            ],
        ]);
        $this->insertRole('id-adv', 'Advogado', [
            'scopeList' => ['Fatura'],
            'scopeLevel' => ['Fatura' => 'no'],
        ]);

        (new V009__patch_roles_add_fatura_lancamento())->up($this->pdo);

        self::assertSame('all', $this->loadRoleData('Financeiro')['scopeLevel']['Fatura']);
        self::assertSame(
            ['read' => 'own', 'edit' => 'no', 'create' => 'no', 'delete' => 'no'],
            $this->loadRoleData('Advogado')['scopeLevel']['Fatura'],
        );
    }

    public function testPreservaCustomizacaoExistenteDoAdmin(): void
    {
        $custom = ['read' => 'all', 'edit' => 'no', 'create' => 'no', 'delete' => 'no'];
        $this->insertRole('id-adv', 'Advogado', [
            'scopeList' => ['Fatura'],
            'scopeLevel' => ['Fatura' => $custom],
        ]);

        (new V009__patch_roles_add_fatura_lancamento())->up($this->pdo);

        self::assertSame($custom, $this->loadRoleData('Advogado')['scopeLevel']['Fatura']);
    }

    public function testEhIdempotente(): void
    {
        $this->insertRole('id-fin', 'Financeiro', ['scopeList' => [], 'scopeLevel' => []]);

        $migration = new V009__patch_roles_add_fatura_lancamento();
        $migration->up($this->pdo);
        $migration->up($this->pdo);

        $data = $this->loadRoleData('Financeiro');
        self::assertContains('Fatura', $data['scopeList']);
        self::assertContains('LancamentoFinanceiro', $data['scopeList']);
        // Sem duplicação
        self::assertSame(2, array_sum(array_map(fn ($s) => $s === 'Fatura' ? 1 : 0, $data['scopeList'])) + array_sum(array_map(fn ($s) => $s === 'LancamentoFinanceiro' ? 1 : 0, $data['scopeList'])));
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
            'modified_at' => '2026-05-15 00:00:00',
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
