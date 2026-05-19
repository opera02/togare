<?php

declare(strict_types=1);

namespace Espo\Modules\TogareLicensing\Migration;

use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;

/**
 * Cria togare_module_status — 1 linha por módulo premium.
 *
 * Colunas chave:
 *   - module_name UNIQUE — garante idempotência do LicenseKeyService::activate
 *     (UPSERT controlado por app, não MERGE SQL).
 *   - índice (status, expires_at) — otimiza o scan do RevalidateLicensesJob:
 *     WHERE status='active' AND expires_at < NOW().
 *
 * Sem chaves externas. ModuleStatus é tabela isolada (não FK pra outras
 * entidades) — propositalmente, pra ReadOnlyGate poder ler antes de qualquer
 * outra entidade ser carregada.
 */
// phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps
final class V001__create_togare_module_status implements MigrationInterface
{
    public function version(): string
    {
        return 'V001__create_togare_module_status';
    }

    public function up(PDO $pdo): void
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

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS togare_module_status');
    }
}
