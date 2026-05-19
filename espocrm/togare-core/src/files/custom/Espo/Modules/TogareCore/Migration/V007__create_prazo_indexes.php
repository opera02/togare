<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Migration;

use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;

/**
 * V007 — Story 4a.3: indexes auxiliares na tabela `prazo`.
 *
 * **Contexto:** rebuild.php cria a tabela `prazo` automaticamente a partir
 * de entityDefs/Prazo.json (campos + types + sysfields). Os indexes
 * declarados no entityDefs `indexes` são refletidos pelo rebuild — esta
 * migration é defensiva (idempotente) para garantir os indexes mesmo
 * quando rebuild parcial não os criar (cenário rebuild já com a tabela
 * existente sem alguns indexes em ambientes onde houve drift de schema).
 *
 * Indexes garantidos:
 *  - idx_prazo_data_fatal           — queries D-7/D-3/D-1 (Story 4b.2)
 *  - idx_prazo_status_data_fatal    — listagem "pendentes ordenadas por urgência"
 *  - idx_prazo_processo_id          — painel relacional em Processo
 *  - idx_prazo_assigned_user_id     — ACL by-assignment + boolFilter Meus
 *  - idx_prazo_numero_processo_orig — busca CNJ em rascunho_nao_vinculado
 *
 * UNIQUE em source_pub_id é responsabilidade da V008 (separada para isolar
 * o tratamento defensivo de partial vs simple unique entre versões MariaDB).
 *
 * Idempotente: usa `CREATE INDEX IF NOT EXISTS` (MariaDB ≥10.5 suporta).
 * Down: DROP INDEX IF EXISTS.
 */
// phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps
final class V007__create_prazo_indexes implements MigrationInterface
{
    public function version(): string
    {
        return 'V007__create_prazo_indexes';
    }

    public function up(PDO $pdo): void
    {
        $statements = [
            'CREATE INDEX IF NOT EXISTS idx_prazo_data_fatal ON prazo (data_fatal)',
            'CREATE INDEX IF NOT EXISTS idx_prazo_status_data_fatal ON prazo (status, data_fatal)',
            'CREATE INDEX IF NOT EXISTS idx_prazo_processo_id ON prazo (processo_id)',
            'CREATE INDEX IF NOT EXISTS idx_prazo_assigned_user_id ON prazo (assigned_user_id)',
            'CREATE INDEX IF NOT EXISTS idx_prazo_numero_processo_orig ON prazo (numero_processo_original)',
        ];

        foreach ($statements as $sql) {
            try {
                $pdo->exec($sql);
            } catch (\PDOException $e) {
                // SQLite não suporta IF NOT EXISTS no índice em todas as versões.
                // Tenta sem IF NOT EXISTS — se já existir, captura silenciosamente.
                if (\str_contains($e->getMessage(), 'IF NOT EXISTS')) {
                    $alt = \str_replace('IF NOT EXISTS ', '', $sql);
                    try {
                        $pdo->exec($alt);
                    } catch (\PDOException $e2) {
                        if (! \str_contains($e2->getMessage(), 'already exists')) {
                            throw $e2;
                        }
                    }
                    continue;
                }
                if (! \str_contains($e->getMessage(), 'already exists')
                    && ! \str_contains($e->getMessage(), 'Duplicate key')
                ) {
                    throw $e;
                }
            }
        }
    }

    public function down(PDO $pdo): void
    {
        $statements = [
            'DROP INDEX IF EXISTS idx_prazo_data_fatal ON prazo',
            'DROP INDEX IF EXISTS idx_prazo_status_data_fatal ON prazo',
            'DROP INDEX IF EXISTS idx_prazo_processo_id ON prazo',
            'DROP INDEX IF EXISTS idx_prazo_assigned_user_id ON prazo',
            'DROP INDEX IF EXISTS idx_prazo_numero_processo_orig ON prazo',
        ];

        foreach ($statements as $sql) {
            try {
                $pdo->exec($sql);
            } catch (\PDOException $e) {
                // tolerante a "doesn't exist" / variantes SQLite
                if (! \str_contains($e->getMessage(), 'check that') && ! \str_contains($e->getMessage(), 'no such')) {
                    throw $e;
                }
            }
        }
    }
}
