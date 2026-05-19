<?php

declare(strict_types=1);

namespace Espo\Modules\TogareLicensing\Migration;

use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;

/**
 * Story 1b.1.1.2-followup: unifica schema do ModuleStatus.
 *
 * Antes: a Story 1b.1 criou `togare_module_status` (PDO direto, validator R3
 * compliant). A Story 1b.1.1-patch criou clientDefs/scope/Controller para a
 * entidade `ModuleStatus`, e o rebuild do EspoCRM gerou automaticamente a
 * tabela `module_status` (snake_case do nome da entidade) — paralela à legada.
 *
 * Resultado: 2 tabelas para a mesma coisa. Services PDO escreviam em
 * togare_module_status; ORM lia de module_status (vazia) — listagem do Admin
 * Panel "Status dos Módulos" ficava sempre vazia.
 *
 * Esta migration:
 *   1. Copia linhas de togare_module_status → module_status (preservando
 *      módulo, status, expires_at, key_jti, etc.). Gera novo id 17-char no
 *      formato EspoCRM (a tabela ORM tem id varchar(17), incompatível com
 *      varchar(24) da legada).
 *   2. Drop togare_module_status.
 *
 * Idempotente: se togare_module_status não existe (ex.: install limpo de
 * 0.2.0 sem passar por 0.1.x), a migration faz nada e segue.
 *
 * Rollback recria togare_module_status com schema idêntico ao V001 mas NÃO
 * copia dados de volta (rollback assume contexto de desenvolvimento).
 */
// phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps
final class V002__migrate_module_status_drop_legacy implements MigrationInterface
{
    public function version(): string
    {
        return 'V002__migrate_module_status_drop_legacy';
    }

    public function up(PDO $pdo): void
    {
        if (! $this->tableExists($pdo, 'togare_module_status')) {
            // Install limpo de 0.2.0 — nada a migrar.
            return;
        }

        if (! $this->tableExists($pdo, 'module_status')) {
            // Defensivo: se rebuild ainda não criou a tabela ORM, abortar
            // sem perder dados. Operador roda rebuild antes do extension install.
            throw new \RuntimeException(
                'V002: tabela module_status não existe — execute `php command.php rebuild` antes de instalar togare-licensing 0.2.0.',
            );
        }

        $rows = $pdo->query('SELECT * FROM togare_module_status')->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $newId = $this->generateEspoId();

            $stmt = $pdo->prepare('
                INSERT INTO module_status
                    (id, deleted, module_name, status, installation_id, key_jti,
                     expires_at, last_validated_at, last_validation_outcome, activated_at)
                VALUES
                    (:id, 0, :module, :status, :inst, :jti, :exp, :lva, :outcome, :act)
            ');
            $stmt->execute([
                ':id' => $newId,
                ':module' => $row['module_name'],
                ':status' => $row['status'],
                ':inst' => $row['installation_id'] ?? null,
                ':jti' => $row['key_jti'] ?? null,
                ':exp' => $row['expires_at'] ?? null,
                ':lva' => $row['last_validated_at'] ?? null,
                ':outcome' => $row['last_validation_outcome'] ?? null,
                ':act' => $row['activated_at'] ?? null,
            ]);
        }

        $pdo->exec('DROP TABLE togare_module_status');
    }

    public function down(PDO $pdo): void
    {
        $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $engine = $isMysql ? 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS togare_module_status (
                id VARCHAR(24) NOT NULL PRIMARY KEY,
                module_name VARCHAR(100) NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'never_activated',
                installation_id VARCHAR(100) NULL,
                key_jti VARCHAR(100) NULL,
                expires_at DATETIME NULL,
                last_validated_at DATETIME NULL,
                last_validation_outcome VARCHAR(50) NULL,
                activated_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                modified_at DATETIME NOT NULL
            ) {$engine}
        ");

        $pdo->exec('CREATE UNIQUE INDEX uk_togare_module_status_name ON togare_module_status (module_name)');
        $pdo->exec('CREATE INDEX idx_togare_module_status_revalidate ON togare_module_status (status, expires_at)');
    }

    private function tableExists(PDO $pdo, string $tableName): bool
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $stmt = $pdo->prepare('SHOW TABLES LIKE :t');
            $stmt->execute([':t' => $tableName]);

            return $stmt->fetchColumn() !== false;
        }

        // SQLite (testes).
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :t");
        $stmt->execute([':t' => $tableName]);

        return $stmt->fetchColumn() !== false;
    }

    private function generateEspoId(): string
    {
        // EspoCRM ORM usa Util::generateId() que retorna 17 chars alfanuméricos.
        // Replicamos o formato sem depender do core (migration roda em bootstrap).
        return \substr(\bin2hex(\random_bytes(9)), 0, 17);
    }
}
