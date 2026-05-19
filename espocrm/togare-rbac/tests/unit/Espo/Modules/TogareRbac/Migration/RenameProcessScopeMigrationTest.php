<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareRbac\Migration;

use Espo\Modules\TogareRbac\Migration\V002__rename_process_scope_to_processo;
use PDO;
use PHPUnit\Framework\TestCase;

final class RenameProcessScopeMigrationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("
            CREATE TABLE role (
                id VARCHAR(17) NOT NULL PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                deleted INTEGER NOT NULL DEFAULT 0,
                data MEDIUMTEXT,
                modified_at DATETIME
            )
        ");
    }

    public function testRenomeiaProcessParaProcessoPreservandoPermissoes(): void
    {
        $oldData = [
            'scopeList' => ['Cliente', 'ParteContraria', 'Process'],
            'scopeLevel' => [
                'Cliente' => 'team',
                'Process' => ['read' => 'team', 'edit' => 'own', 'create' => 'team', 'delete' => 'no'],
            ],
        ];
        $this->insertRole('adv-old', 'Advogado', $oldData);

        (new V002__rename_process_scope_to_processo())->up($this->pdo);

        $data = $this->loadRoleData('Advogado');
        self::assertContains('Processo', $data['scopeList']);
        self::assertNotContains('Process', $data['scopeList']);
        self::assertArrayHasKey('Processo', $data['scopeLevel']);
        self::assertArrayNotHasKey('Process', $data['scopeLevel']);
        self::assertSame('own', $data['scopeLevel']['Processo']['edit']);
    }

    public function testNaoSobrescreveProcessoJaCustomizado(): void
    {
        $customData = [
            'scopeList' => ['Process', 'Processo'],
            'scopeLevel' => [
                'Process' => 'all',
                'Processo' => ['read' => 'team', 'edit' => 'no', 'create' => 'no', 'delete' => 'no'],
            ],
        ];
        $this->insertRole('sec-old', 'Secretária', $customData);

        (new V002__rename_process_scope_to_processo())->up($this->pdo);

        $data = $this->loadRoleData('Secretária');
        self::assertSame(['Processo'], $data['scopeList']);
        self::assertArrayNotHasKey('Process', $data['scopeLevel']);
        self::assertSame('no', $data['scopeLevel']['Processo']['edit']);
    }

    /** @param array<string, mixed> $data */
    private function insertRole(string $id, string $name, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO role (id, name, deleted, data, modified_at) VALUES (:id, :name, 0, :data, NULL)',
        );
        $stmt->execute([
            'id' => $id,
            'name' => $name,
            'data' => \json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }

    /** @return array<string, mixed> */
    private function loadRoleData(string $name): array
    {
        $raw = (string) $this->pdo
            ->query("SELECT data FROM role WHERE name = " . $this->pdo->quote($name))
            ->fetchColumn();
        $decoded = \json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }
}
