<?php

declare(strict_types=1);

namespace Espo\Modules\TogareDjen\Migration;

use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;

/**
 * Cria a tabela `togare_djen_user_state` (Story 4a.1 — Decisão #3.1).
 *
 * Tabela auxiliar 1:1 com User (PK=user_id) que rastreia:
 *   - oab_number / oab_uf — denormalizados do User entityDef patch (cache leve
 *     pra job não fazer JOIN no User a cada execução).
 *   - last_synced_at — datetime da última janela enfileirada com sucesso.
 *   - last_sync_error — última mensagem de erro do adapter (truncated 1000
 *     chars) — útil para Sócio/Admin diagnosticar via Health Panel futuro.
 *
 * NÃO carrega `tenant_id` — tabela de fluxo, não business entity (memory
 * feedback_extension_bundled_pattern.md cobre só entidades de negócio).
 *
 * SQLite (testes unit) omite ENGINE/CHARSET por compat (mesmo padrão das
 * migrations TPU).
 */
// phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps
final class V001__create_togare_djen_user_state implements MigrationInterface
{
    public function version(): string
    {
        return 'V001__create_togare_djen_user_state';
    }

    public function up(PDO $pdo): void
    {
        $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $engine = $isMysql ? 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS togare_djen_user_state (
                user_id VARCHAR(24) NOT NULL PRIMARY KEY,
                oab_number VARCHAR(20) NULL DEFAULT NULL,
                oab_uf CHAR(2) NULL DEFAULT NULL,
                last_synced_at DATETIME NULL DEFAULT NULL,
                last_sync_error TEXT NULL DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            ) {$engine}
        ");

        try {
            $pdo->exec(
                'CREATE INDEX IF NOT EXISTS idx_togare_djen_user_state_oab '
                . 'ON togare_djen_user_state (oab_number, oab_uf)'
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Falha ao criar índice idx_togare_djen_user_state_oab: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS togare_djen_user_state');
    }
}
