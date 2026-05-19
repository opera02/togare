<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Migration;

use Espo\Modules\TogareCore\Migration\V009__migrate_prazo_status_enum_to_v11;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Cobre Migration V009 (Story 4a.3.1, AC1 + AC1.1):
 * status enum mapping destrutivo 6→9 valores.
 *
 * Mapping (sprint-change-proposal-2026-05-04 §7.2 + ADR-03 v1.1 §1):
 *   - rascunho_nao_vinculado → rascunho
 *   - confirmado             → pendente
 *   - cumprido               → protocolado
 *   - revertido              → pendente
 *   - pendente, descartado   → preservados
 *
 * Usa SQLite in-memory pra isolamento determinístico (mesmo pattern V003 togare-rbac).
 */
final class V009MigrationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Schema mínimo para os testes (mesmo subset usado em prod).
        $this->pdo->exec('CREATE TABLE prazo (
            id VARCHAR(24) PRIMARY KEY,
            status VARCHAR(64) NOT NULL,
            assigned_user_id VARCHAR(24) NULL,
            processo_id VARCHAR(24) NULL,
            source_pub_id INTEGER NULL,
            data_fatal DATE NULL
        )');

        // togare_audit_log (V006 da Story 2.4) — schema real:
        //   colunas `event`/`occurred_at`/`entity_type NOT NULL` (não `event_type`/`created_at`).
        //   user_id é varchar(17), entity_type é varchar(80) NOT NULL.
        $this->pdo->exec('CREATE TABLE togare_audit_log (
            id VARCHAR(32) PRIMARY KEY,
            occurred_at DATETIME NOT NULL,
            event VARCHAR(120) NOT NULL,
            entity_type VARCHAR(80) NOT NULL,
            entity_id VARCHAR(32) NULL,
            user_id VARCHAR(17) NULL,
            user_name VARCHAR(120) NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(500) NULL,
            correlation_id VARCHAR(64) NULL,
            context_json TEXT NULL
        )');

        // SQLite não tem NOW() built-in — substitui por CURRENT_TIMESTAMP.
        // O V009 usa NOW() — mas SQLite alias-a se interpretarmos: vamos
        // definir NOW() como user-defined function.
        $this->pdo->sqliteCreateFunction('NOW', fn () => \date('Y-m-d H:i:s'));
    }

    public function testUpFazRemapDeRascunhoNaoVinculadoParaRascunho(): void
    {
        $this->insertPrazo('p1', 'rascunho_nao_vinculado');

        (new V009__migrate_prazo_status_enum_to_v11())->up($this->pdo);

        self::assertSame('rascunho', $this->getStatus('p1'));
    }

    public function testUpFazRemapDeConfirmadoParaPendente(): void
    {
        $this->insertPrazo('p1', 'confirmado');

        (new V009__migrate_prazo_status_enum_to_v11())->up($this->pdo);

        self::assertSame('pendente', $this->getStatus('p1'));
    }

    public function testUpFazRemapDeCumpridoParaProtocolado(): void
    {
        $this->insertPrazo('p1', 'cumprido');

        (new V009__migrate_prazo_status_enum_to_v11())->up($this->pdo);

        self::assertSame('protocolado', $this->getStatus('p1'));
    }

    public function testUpFazRemapDeRevertidoParaPendente(): void
    {
        $this->insertPrazo('p1', 'revertido');

        (new V009__migrate_prazo_status_enum_to_v11())->up($this->pdo);

        self::assertSame('pendente', $this->getStatus('p1'));
    }

    public function testUpPreservaPendenteEDescartado(): void
    {
        $this->insertPrazo('p1', 'pendente');
        $this->insertPrazo('p2', 'descartado');

        (new V009__migrate_prazo_status_enum_to_v11())->up($this->pdo);

        self::assertSame('pendente', $this->getStatus('p1'));
        self::assertSame('descartado', $this->getStatus('p2'));
    }

    public function testUpEscreveAuditLogComBeforeAfterCounts(): void
    {
        $this->insertPrazo('p1', 'rascunho_nao_vinculado');
        $this->insertPrazo('p2', 'confirmado');
        $this->insertPrazo('p3', 'pendente');

        (new V009__migrate_prazo_status_enum_to_v11())->up($this->pdo);

        $stmt = $this->pdo->query(
            "SELECT context_json FROM togare_audit_log "
            . "WHERE event = 'prazo.schema_migrated_v009' ORDER BY occurred_at DESC LIMIT 1"
        );
        self::assertNotFalse($stmt);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        $context = \json_decode((string) $row['context_json'], true, 512, JSON_THROW_ON_ERROR);
        // SQLite GROUP BY ordena alfabeticamente; usamos canonicalizing para
        // ignorar ordem de chaves (MariaDB e SQLite divergem aqui).
        self::assertEqualsCanonicalizing(
            ['rascunho_nao_vinculado' => 1, 'confirmado' => 1, 'pendente' => 1],
            $context['before'],
        );
        self::assertEqualsCanonicalizing(['rascunho' => 1, 'pendente' => 2], $context['after']);
        self::assertArrayHasKey('mapping', $context);
        self::assertSame('rascunho', $context['mapping']['rascunho_nao_vinculado']);
    }

    public function testDownEhNoOp(): void
    {
        $this->insertPrazo('p1', 'rascunho');

        // Deve passar sem lançar e sem alterar nada.
        (new V009__migrate_prazo_status_enum_to_v11())->down($this->pdo);

        self::assertSame('rascunho', $this->getStatus('p1'));
    }

    public function testRerunIsIdempotentLogicallyForJaMapeados(): void
    {
        // Após primeiro up, status já está no nome novo. Re-rodar não deve quebrar.
        $this->insertPrazo('p1', 'pendente');

        $migration = new V009__migrate_prazo_status_enum_to_v11();
        $migration->up($this->pdo);
        $migration->up($this->pdo);

        self::assertSame('pendente', $this->getStatus('p1'));
    }

    private function insertPrazo(string $id, string $status): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO prazo (id, status) VALUES (:id, :status)');
        $stmt->execute(['id' => $id, 'status' => $status]);
    }

    private function getStatus(string $id): string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM prazo WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (string) $row['status'];
    }
}
