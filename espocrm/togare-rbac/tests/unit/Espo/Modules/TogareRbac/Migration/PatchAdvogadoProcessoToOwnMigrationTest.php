<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareRbac\Migration;

use Espo\Modules\TogareRbac\Migration\V003__patch_advogado_processo_to_own;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Story 3.5 — testa V003 migration que ajusta `Advogado.Processo.read` para `own`.
 *
 * Reproduz o pattern do RenameProcessScopeMigrationTest (V002) com SQLite
 * in-memory, sem dependência do EspoCRM real.
 */
final class PatchAdvogadoProcessoToOwnMigrationTest extends TestCase
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

    public function testAtualizaTeamGranularParaOwn(): void
    {
        $this->insertRole('adv-001', 'Advogado', [
            'scopeList' => ['Processo'],
            'scopeLevel' => [
                'Processo' => ['read' => 'team', 'edit' => 'own', 'create' => 'team', 'delete' => 'no'],
            ],
        ]);

        (new V003__patch_advogado_processo_to_own())->up($this->pdo);

        $data = $this->loadRoleData('Advogado');
        self::assertSame('own', $data['scopeLevel']['Processo']['read'], 'read deve virar own');
        self::assertSame('own', $data['scopeLevel']['Processo']['edit'], 'edit preservado');
        self::assertSame('team', $data['scopeLevel']['Processo']['create'], 'create preservado');
        self::assertSame('no', $data['scopeLevel']['Processo']['delete'], 'delete preservado');
    }

    public function testUpgradeStringTeamParaGranularOwn(): void
    {
        // Caminho defensivo — se admin editou no UI e Processo virou string "team"
        $this->insertRole('adv-002', 'Advogado', [
            'scopeList' => ['Processo'],
            'scopeLevel' => [
                'Processo' => 'team',
            ],
        ]);

        (new V003__patch_advogado_processo_to_own())->up($this->pdo);

        $data = $this->loadRoleData('Advogado');
        self::assertSame(
            ['read' => 'own', 'edit' => 'own', 'create' => 'team', 'delete' => 'no'],
            $data['scopeLevel']['Processo'],
            'String "team" deve virar granular alvo da Story 3.5',
        );
    }

    public function testJaOwnGranularNaoEModificada(): void
    {
        $this->insertRole('adv-003', 'Advogado', [
            'scopeList' => ['Processo'],
            'scopeLevel' => [
                'Processo' => ['read' => 'own', 'edit' => 'own', 'create' => 'team', 'delete' => 'no'],
            ],
        ]);

        // Captura modified_at NULL antes
        $stmt = $this->pdo->query('SELECT modified_at FROM role WHERE name = ' . $this->pdo->quote('Advogado'));
        self::assertNotFalse($stmt);
        $beforeModified = $stmt->fetchColumn();

        (new V003__patch_advogado_processo_to_own())->up($this->pdo);

        // Idempotência: modified_at NÃO deve ter mudado.
        $stmt = $this->pdo->query('SELECT modified_at FROM role WHERE name = ' . $this->pdo->quote('Advogado'));
        self::assertNotFalse($stmt);
        $afterModified = $stmt->fetchColumn();

        self::assertSame($beforeModified, $afterModified, 'No-op não deve atualizar modified_at');
    }

    public function testStringOwnNaoEModificada(): void
    {
        $this->insertRole('adv-004', 'Advogado', [
            'scopeList' => ['Processo'],
            'scopeLevel' => [
                'Processo' => 'own',
            ],
        ]);

        (new V003__patch_advogado_processo_to_own())->up($this->pdo);

        $data = $this->loadRoleData('Advogado');
        self::assertSame('own', $data['scopeLevel']['Processo'], 'String "own" deve permanecer string "own"');
    }

    public function testSocioAdminNaoEAfetada(): void
    {
        // Sócio/Admin com 'all' — admin role nunca pode ser tocada por esta migration.
        $this->insertRole('sa-001', 'Sócio/Admin', [
            'scopeList' => ['Processo'],
            'scopeLevel' => ['Processo' => 'all'],
        ]);
        // Outra role tentando passar como Advogado deve ser ignorada também — só o nome canônico.
        $this->insertRole('sec-001', 'Secretária', [
            'scopeList' => ['Processo'],
            'scopeLevel' => ['Processo' => ['read' => 'team', 'edit' => 'no', 'create' => 'no', 'delete' => 'no']],
        ]);

        (new V003__patch_advogado_processo_to_own())->up($this->pdo);

        self::assertSame('all', $this->loadRoleData('Sócio/Admin')['scopeLevel']['Processo']);
        self::assertSame(
            ['read' => 'team', 'edit' => 'no', 'create' => 'no', 'delete' => 'no'],
            $this->loadRoleData('Secretária')['scopeLevel']['Processo'],
            'Secretária preservada — V003 só mexe em Advogado',
        );
    }

    public function testReadAllManualNaoERebaixado(): void
    {
        // Se admin elevou manualmente Advogado.read=all (cenário improvável mas válido),
        // a migration NÃO rebaixa — fail-closed só para 'team' (estado pré-3.5 esperado).
        $this->insertRole('adv-005', 'Advogado', [
            'scopeList' => ['Processo'],
            'scopeLevel' => [
                'Processo' => ['read' => 'all', 'edit' => 'own', 'create' => 'team', 'delete' => 'no'],
            ],
        ]);

        (new V003__patch_advogado_processo_to_own())->up($this->pdo);

        $data = $this->loadRoleData('Advogado');
        self::assertSame('all', $data['scopeLevel']['Processo']['read'], 'Customização do admin preservada');
    }

    public function testRoleAdvogadoAusenteEhNoOp(): void
    {
        // Nenhuma row Advogado no DB → migration não falha.
        (new V003__patch_advogado_processo_to_own())->up($this->pdo);

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM role')->fetchColumn();
        self::assertSame(0, $count);
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
            ->query('SELECT data FROM role WHERE name = ' . $this->pdo->quote($name))
            ->fetchColumn();
        $decoded = \json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
