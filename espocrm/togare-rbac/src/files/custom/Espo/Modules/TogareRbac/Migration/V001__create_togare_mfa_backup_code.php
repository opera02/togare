<?php

declare(strict_types=1);

namespace Espo\Modules\TogareRbac\Migration;

use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;

/**
 * Cria a tabela togare_mfa_backup_code para backup codes one-time-use.
 *
 * Story 2.3 — NFR9 (backup codes para Sócio/Admin sem telefone).
 * Convenção R3 satisfeita: prefix togare_ via nome da entity TogareMfaBackupCode.
 */
final class V001__create_togare_mfa_backup_code implements MigrationInterface
{
    public function version(): string
    {
        return 'V001__create_togare_mfa_backup_code';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS togare_mfa_backup_code (
                id VARCHAR(17) NOT NULL,
                deleted TINYINT(1) NOT NULL DEFAULT 0,
                user_id VARCHAR(17) NOT NULL,
                code_hash VARCHAR(255) NOT NULL,
                used TINYINT(1) NOT NULL DEFAULT 0,
                used_at DATETIME NULL DEFAULT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                INDEX idx_mfa_backup_user (user_id, deleted, used)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS togare_mfa_backup_code');
    }
}
