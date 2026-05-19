<?php

declare(strict_types=1);

namespace Espo\Modules\TogareTpu\Migration;

use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;

/**
 * Cria a tabela `togare_tpu_assunto` (Story 3.3 — FR8/FR9).
 *
 * Schema idêntico a V001 (togare_tpu_classe). Catálogo CNJ de Assuntos
 * processuais. Ver V001 para detalhes da decisão de schema/prefixo.
 */
// phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps
final class V002__create_togare_tpu_assunto implements MigrationInterface
{
    public function version(): string
    {
        return 'V002__create_togare_tpu_assunto';
    }

    public function up(PDO $pdo): void
    {
        $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $engine = $isMysql ? 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS togare_tpu_assunto (
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
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_togare_tpu_assunto_ativo_nome ON togare_tpu_assunto (ativo, nome)');
        } catch (\Throwable $e) {
            throw new \RuntimeException('Falha ao criar índice idx_togare_tpu_assunto_ativo_nome: ' . $e->getMessage(), 0, $e);
        }
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS togare_tpu_assunto');
    }
}
