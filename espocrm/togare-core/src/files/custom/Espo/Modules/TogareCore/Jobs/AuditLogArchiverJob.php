<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\EntityManager;
use PDO;

/**
 * Scheduled job semanal que **alerta** sobre rows em `togare_audit_log`
 * com `occurred_at` >24m (Story 2.4 — política de retenção NFR10).
 *
 * Por que NÃO deleta:
 *   NFR10 exige imutabilidade. O lockdown SQL (`audit-log-lockdown.sh`)
 *   bloqueia DELETE no app user de propósito. O job apenas avisa o admin
 *   via TogareLogger; admin decide quando arquivar (export manual via
 *   `mariadb-dump --where`) e, se quiser, deletar com a senha root.
 *
 * Cron padrão (scheduledJobs.json): Domingo 04:00 (cron `0 4 * * 0`) — fora
 * de horário comercial; semanal é suficiente (~1MB/dia/escritório).
 */
final class AuditLogArchiverJob implements Job
{
    public function __construct(
        private readonly EntityManager $entityManager,
    ) {
    }

    public function run(Data $data): void
    {
        $pdo = $this->entityManager->getPDO();

        // G1: match explícito com default throw — allowlist de drivers conhecidos.
        // PDO não suporta bind de expressões SQL, então a interpolação é necessária;
        // o match garante que apenas literais seguros chegam à query.
        $driverName = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $cutoffSql = match ($driverName) {
            'mysql'  => 'DATE_SUB(NOW(), INTERVAL 24 MONTH)',
            'sqlite' => "datetime('now', '-24 months')",
            default  => throw new \RuntimeException("Driver PDO não suportado: {$driverName}"),
        };

        // G2: captura PDOException para registrar via TogareLogger antes de encerrar.
        try {
            $stmt = $pdo->query(
                "SELECT COUNT(*) AS cnt, MIN(occurred_at) AS oldest
                 FROM togare_audit_log
                 WHERE occurred_at < {$cutoffSql}"
            );
            $raw = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            TogareLogger::event(
                'error',
                'audit.archive.query_failed',
                'Falha ao consultar togare_audit_log: ' . $e->getMessage(),
                ['exception' => $e->getMessage()],
            );
            return;
        }

        // G3: fetch() retorna false quando não há rows — normalizar para array vazio.
        $row    = \is_array($raw) ? $raw : [];
        $count  = (int) ($row['cnt'] ?? 0);
        $oldest = $row['oldest'] ?? null;

        if ($count > 0) {
            TogareLogger::event(
                'warning',
                'audit.archive.pending',
                \sprintf(
                    'Audit log tem %d rows >24m. Arquivamento manual necessário (NFR10).',
                    $count,
                ),
                [
                    'count'  => $count,
                    'oldest' => $oldest,
                    // G4+G5: aspas simples evitam expansão de variáveis PHP;
                    // $MARIADB_CONTAINER e $ESPOCRM_DB_NAME são variáveis shell
                    // (ver docker/.env). Substituir nextcloud-crm-mariadb-1 pelo
                    // valor de MARIADB_CONTAINER do seu ambiente antes de rodar.
                    'suggestedCommand' =>
                        'docker exec -i "$MARIADB_CONTAINER" mariadb-dump'
                        . ' --single-transaction'
                        . ' --where="occurred_at < DATE_SUB(NOW(), INTERVAL 24 MONTH)"'
                        . ' "$ESPOCRM_DB_NAME" togare_audit_log'
                        . ' > audit-archive-$(date +%Y%m%d).sql',
                ],
            );
            return;
        }

        TogareLogger::event(
            'info',
            'audit.archive.ok',
            'Audit log dentro do horizonte 24m.',
            ['oldest' => $oldest ?? 'tabela vazia'],
        );
    }
}
