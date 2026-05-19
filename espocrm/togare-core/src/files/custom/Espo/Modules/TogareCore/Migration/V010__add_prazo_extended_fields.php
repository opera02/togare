<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Migration;

use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;

/**
 * V010 — Story 4a.3.1: 6 colunas + 4 indexes adicionados em `prazo`.
 *
 * **Contexto:** rebuild.php do EspoCRM 9.x cria/atualiza colunas via metadata
 * (entityDefs/Prazo.json), mas em escritórios com a tabela já existente da
 * Story 4a.3 o rebuild parcial pode não criar todas as 6 colunas + 4 indexes.
 * Esta migration é defensiva (idempotente): aplica cada ALTER/CREATE com
 * try/catch DUPLICATE COLUMN/KEY (mesmo pattern V007).
 *
 * Colunas novas:
 *  - descricao             TEXT NULL                       (F1.10)
 *  - prioridade            VARCHAR(16) NOT NULL DEFAULT 'normal'  (F1.11)
 *  - tipo_prazo            VARCHAR(64) NULL                (F1.12 — Apêndice A PRD)
 *  - motivo_reagendamento  VARCHAR(500) NULL               (F1.7 — required quando status=atrasado_reagendado)
 *  - cliente_id            VARCHAR(24) NULL                (auto-link via AutoLinkClientHook)
 *  - parte_contraria_id    VARCHAR(24) NULL                (idem)
 *
 * Indexes:
 *  - idx_prazo_prioridade           — filtro briefing/list
 *  - idx_prazo_tipo_prazo           — filtro categórico
 *  - idx_prazo_cliente_id           — JOIN N:1 Cliente
 *  - idx_prazo_parte_contraria_id   — JOIN N:1 ParteContraria
 *
 * Down: no-op intencional — preserva dados (manual decision para reverter).
 */
// phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps
final class V010__add_prazo_extended_fields implements MigrationInterface
{
    public function version(): string
    {
        return 'V010__add_prazo_extended_fields';
    }

    public function up(PDO $pdo): void
    {
        $statements = [
            'ALTER TABLE prazo ADD COLUMN descricao TEXT NULL',
            "ALTER TABLE prazo ADD COLUMN prioridade VARCHAR(16) NOT NULL DEFAULT 'normal'",
            'ALTER TABLE prazo ADD COLUMN tipo_prazo VARCHAR(64) NULL',
            'ALTER TABLE prazo ADD COLUMN motivo_reagendamento VARCHAR(500) NULL',
            'ALTER TABLE prazo ADD COLUMN cliente_id VARCHAR(24) NULL',
            'ALTER TABLE prazo ADD COLUMN parte_contraria_id VARCHAR(24) NULL',
            'CREATE INDEX idx_prazo_prioridade ON prazo (prioridade)',
            'CREATE INDEX idx_prazo_tipo_prazo ON prazo (tipo_prazo)',
            'CREATE INDEX idx_prazo_cliente_id ON prazo (cliente_id)',
            'CREATE INDEX idx_prazo_parte_contraria_id ON prazo (parte_contraria_id)',
        ];

        foreach ($statements as $sql) {
            try {
                $pdo->exec($sql);
            } catch (\PDOException $e) {
                $msg = $e->getMessage();
                // Idempotência: tolera coluna/index já existente.
                if (
                    \str_contains($msg, 'Duplicate column name')
                    || \str_contains($msg, 'Duplicate key name')
                    || \str_contains($msg, 'already exists')
                ) {
                    continue;
                }
                throw $e;
            }
        }
    }

    public function down(PDO $pdo): void
    {
        // No-op intencional — preserva dados.
        // Reversão = decisão manual do operador (DROP COLUMN apaga conteúdo).
    }
}
