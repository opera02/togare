<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Migration;

use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;

/**
 * V008 — Story 4a.3 / Decisão #3: UNIQUE INDEX em `source_pub_id` da
 * tabela `prazo`.
 *
 * **Por que separada da V007:** algumas versões do MariaDB 10.x suportam
 * `WHERE source_pub_id IS NOT NULL` em CREATE INDEX (partial unique index);
 * outras não. Isolar o tratamento permite ajustar a sintaxe sem mexer no
 * resto dos indexes auxiliares (V007).
 *
 * **Plano A (preferido):** UNIQUE INDEX simples — em MariaDB/MySQL, UNIQUE
 * com coluna nullable PERMITE múltiplas linhas com `source_pub_id = NULL`
 * (semântica padrão SQL). Funciona para a Story 4a.3:
 *   - Prazos vindos do DJEN sempre têm `sourcePubId` populado (id da
 *     publicação Comunica) → UNIQUE garante 1 Prazo por publicação.
 *   - Prazos manuais futuros (Growth) terão `sourcePubId=NULL` → UNIQUE
 *     simples NÃO colide entre si.
 *
 * **Plano B (não usado por desnecessário):** partial UNIQUE
 * `WHERE source_pub_id IS NOT NULL` — só seria necessário se SQL exigisse
 * NULL único; não é o caso.
 *
 * Idempotente via try/catch sobre constraint duplicate.
 */
// phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps
final class V008__add_prazo_source_pub_id_unique implements MigrationInterface
{
    public function version(): string
    {
        return 'V008__add_prazo_source_pub_id_unique';
    }

    public function up(PDO $pdo): void
    {
        $sql = 'CREATE UNIQUE INDEX prazo_source_pub_id_unique ON prazo (source_pub_id)';

        try {
            $pdo->exec($sql);
        } catch (\PDOException $e) {
            $msg = $e->getMessage();
            // Já existe — idempotente
            if (\str_contains($msg, 'Duplicate key name')
                || \str_contains($msg, 'already exists')
                || \str_contains($msg, 'Duplicate column')
            ) {
                return;
            }
            throw $e;
        }
    }

    public function down(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $sql = $driver === 'mysql'
            ? 'DROP INDEX prazo_source_pub_id_unique ON prazo'
            : 'DROP INDEX IF EXISTS prazo_source_pub_id_unique';

        try {
            $pdo->exec($sql);
        } catch (\PDOException $e) {
            $msg = $e->getMessage();
            if (\str_contains($msg, "doesn't exist")
                || \str_contains($msg, 'no such index')
                || \str_contains($msg, 'check that')
            ) {
                return;
            }
            throw $e;
        }
    }
}
