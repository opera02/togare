<?php

declare(strict_types=1);

namespace Espo\Modules\TogareTpu\Migration;

use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;

/**
 * Cria a tabela `togare_tpu_classe` (Story 3.3 — FR8/FR9/NFR4/NFR17/NFR25).
 *
 * Catálogo CNJ de Classes processuais. Acessada via PDO direto (não via ORM
 * EspoCRM) — tabela de catálogo, sem entityDef, sem scope. ADR-02 (entity
 * naming business) NÃO se aplica; prefixo `togare_tpu_` é satisfeito pela
 * regra R4 (tabela custom não-entidade exige prefixo togare_).
 *
 * `codigo` é PRIMARY KEY (BIGINT) — códigos CNJ são numéricos estáveis.
 * `last_synced_at` é atualizado a cada sync (idempotência via INSERT ON
 * DUPLICATE KEY UPDATE — AC3). Schema espelha V002 e V003 (assunto/movimento).
 *
 * SQLite (testes unit) omite ENGINE/CHARSET por compat (mesmo padrão de
 * V004/V006 do togare-core).
 */
// phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps
final class V001__create_togare_tpu_classe implements MigrationInterface
{
    public function version(): string
    {
        return 'V001__create_togare_tpu_classe';
    }

    public function up(PDO $pdo): void
    {
        $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $engine = $isMysql ? 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS togare_tpu_classe (
                codigo BIGINT NOT NULL PRIMARY KEY,
                nome VARCHAR(255) NOT NULL,
                pai_codigo BIGINT NULL DEFAULT NULL,
                glossario TEXT NULL DEFAULT NULL,
                ativo TINYINT NOT NULL DEFAULT 1,
                last_synced_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            ) {$engine}
        ");

        try {
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_togare_tpu_classe_ativo_nome ON togare_tpu_classe (ativo, nome)');
        } catch (\Throwable $e) {
            throw new \RuntimeException('Falha ao criar índice idx_togare_tpu_classe_ativo_nome: ' . $e->getMessage(), 0, $e);
        }
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS togare_tpu_classe');
    }
}
