<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Migration;

use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;

/**
 * V022 — Hotfix 0.39.1: converte tabelas Togare legadas para
 * utf8mb4_unicode_ci.
 *
 * **Bug original:** MariaDB 11.4 mudou a collation default para
 * `utf8mb4_uca1400_ai_ci`. Tabelas Togare criadas em instalações pré-0.39.1
 * herdaram esse default (porque o DDL anterior não pinava COLLATE),
 * enquanto as tabelas nativas do EspoCRM continuam em `utf8mb4_unicode_ci`.
 * JOIN entre togare_* e tabela nativa do EspoCRM (ex.: `togare_prazo_lembrete`
 * ↔ `prazo` no PrazoD0BackfillService) dispara:
 *
 *     SQLSTATE[HY000]: 1267 Illegal mix of collations
 *     (utf8mb4_unicode_ci, IMPLICIT) and (utf8mb4_uca1400_ai_ci, IMPLICIT)
 *     for operation '='
 *
 * **Fix em 2 camadas (v0.39.1):**
 *  1. As V002/V004/V005/V006/V014/V016/V018/V020/V021 ganharam `COLLATE` na
 *     cláusula `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4` — cobre instalações NOVAS.
 *  2. **Esta migration** converte as tabelas LEGADAS já existentes em
 *     instalações que rodaram togare-core ≤ 0.39.0 sobre MariaDB 11.4+.
 *     Alterar migrations já-aplicadas não basta: `MigrationRunner` é estrito
 *     (só avisa quando checksum diverge, não re-aplica).
 *
 * **Idempotência:** `ALTER TABLE … CONVERT TO CHARACTER SET utf8mb4 COLLATE
 * utf8mb4_unicode_ci` sobre tabela já correta é noop seguro no MariaDB.
 * Mesmo assim, fazemos guard prévio em `information_schema.TABLES` para (a)
 * evitar reescrever metadata desnecessariamente e (b) não falhar se a tabela
 * não existir (ex.: instalação parcial / testes isolados).
 *
 * **SQLite:** pulado (driver != mysql) — DDL do SQLite ignora COLLATE de
 * tabela mesmo assim, e os testes isolados usam SQLite sem o bug.
 *
 * **Down:** no-op intencional (não reverte para uma collation reconhecidamente
 * problemática; pattern de V010/V012/V013/V014/V016).
 */
// phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps
final class V022__convert_legacy_collation implements MigrationInterface
{
    /**
     * Tabelas alvo. Apenas as togare_* que recebem JOIN com tabelas nativas
     * do EspoCRM (ex.: prazo, user) em qualquer query — onde o mix de
     * collations dispara 1267. `togare_migrations_applied` (V001) é interna
     * ao runner, sem JOIN externo; mesmo assim incluímos por higiene
     * (CONVERT TO é noop se já estiver na collation correta).
     */
    private const TABLES = [
        'togare_migrations_applied',          // V001
        'togare_core_smoke',                  // V002
        'togare_queue_items',                 // V004
        'togare_rate_limits',                 // V005
        'togare_audit_log',                   // V006
        'togare_ambiguity_log',               // V014
        'togare_prazo_lembrete',              // V016
        'togare_documento_log',               // V018
        'togare_contrato_honorarios_log',     // V020
        'togare_fatura_log',                  // V021
        'togare_lancamento_financeiro_log',   // V021
    ];

    private const TARGET_CHARSET = 'utf8mb4';
    private const TARGET_COLLATION = 'utf8mb4_unicode_ci';

    public function version(): string
    {
        return 'V022__convert_legacy_collation';
    }

    public function up(PDO $pdo): void
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return;
        }

        $converted = [];
        $skippedAlreadyCorrect = [];
        $skippedMissing = [];

        foreach (self::TABLES as $table) {
            $currentCollation = $this->fetchCurrentCollation($pdo, $table);

            if ($currentCollation === null) {
                $skippedMissing[] = $table;
                continue;
            }

            if ($currentCollation === self::TARGET_COLLATION) {
                $skippedAlreadyCorrect[] = $table;
                continue;
            }

            // Identificador da tabela é constante interna (literal seguro);
            // sem binding de tabela é o único caminho (DDL não aceita ?).
            $pdo->exec(\sprintf(
                'ALTER TABLE `%s` CONVERT TO CHARACTER SET %s COLLATE %s',
                $table,
                self::TARGET_CHARSET,
                self::TARGET_COLLATION,
            ));

            $converted[] = $table;
        }

        $this->writeAuditLog($pdo, $converted, $skippedAlreadyCorrect, $skippedMissing);
    }

    public function down(PDO $pdo): void
    {
        // No-op intencional: reverter para a collation problemática
        // recriaria o bug 1267. Sem rollback semântico para esta migration.
    }

    /**
     * Lê a collation atual da tabela no information_schema. Retorna null se
     * a tabela não existir no schema corrente (cenário de instalação parcial
     * ou teste isolado).
     */
    private function fetchCurrentCollation(PDO $pdo, string $table): ?string
    {
        $stmt = $pdo->prepare(
            'SELECT TABLE_COLLATION FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->execute(['t' => $table]);
        $value = $stmt->fetchColumn();
        if ($value === false || $value === null) {
            return null;
        }
        return (string) $value;
    }

    /**
     * Grava entry de audit `togare_legacy_collation.converted_v022` registrando
     * o que de fato mudou. Try/catch defensivo (pattern V013/V014/V016) — em
     * instalação onde `togare_audit_log` ainda não existir (testes isolados,
     * V001 falhou), a migration não pode falhar por isso.
     *
     * @param list<string> $converted
     * @param list<string> $skippedAlreadyCorrect
     * @param list<string> $skippedMissing
     */
    private function writeAuditLog(
        PDO $pdo,
        array $converted,
        array $skippedAlreadyCorrect,
        array $skippedMissing,
    ): void {
        try {
            $context = \json_encode(
                [
                    'target_collation' => self::TARGET_COLLATION,
                    'converted' => $converted,
                    'skipped_already_correct' => $skippedAlreadyCorrect,
                    'skipped_missing' => $skippedMissing,
                    'note' => 'Conversão de collation legacy (MariaDB 11.4+ default uca1400 → unicode_ci do EspoCRM). Hotfix togare-core 0.39.1.',
                ],
                JSON_UNESCAPED_UNICODE,
            );
            if ($context === false) {
                $context = '{}';
            }

            $insert = $pdo->prepare(
                'INSERT INTO togare_audit_log '
                . '(id, occurred_at, event, entity_type, entity_id, user_id, user_name, ip_address, user_agent, correlation_id, context_json) '
                . 'VALUES (:id, :occurred_at, :event, :entity_type, NULL, NULL, :user_name, NULL, NULL, NULL, :context_json)'
            );
            if ($insert === false) {
                return;
            }
            $insert->execute([
                'id' => \bin2hex(\random_bytes(16)),
                'occurred_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'event' => 'togare_legacy_collation.converted_v022',
                'entity_type' => 'Migration',
                'user_name' => 'system:migration',
                'context_json' => $context,
            ]);
        } catch (\Throwable) {
            // togare_audit_log pode não existir em testes isolados — pular.
        }
    }
}
