<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\EntityManager;

/**
 * Scheduled job mensal que remove items de togare_queue_items em status='done'
 * com mais de 90 dias — evita crescimento indefinido da tabela.
 *
 * Items em 'failed_dead_letter' NUNCA são removidos automaticamente — ficam
 * para auditoria até admin limpar manualmente.
 *
 * Cron padrão (configurado em scheduledJobs.json): dia 1 do mês, 03:00.
 */
final class TogareQueueCleanupJob implements Job
{
    public function __construct(
        private readonly EntityManager $entityManager,
    ) {
    }

    public function run(Data $data): void
    {
        $pdo = $this->entityManager->getPDO();

        $stmt = $pdo->prepare("
            DELETE FROM togare_queue_items
            WHERE status = 'done'
              AND completed_at < (NOW() - INTERVAL 90 DAY)
        ");
        $stmt->execute();

        TogareLogger::event('info', 'queue.cleanup.completed', 'Limpeza mensal da fila concluída', [
            'deletedCount' => $stmt->rowCount(),
            'retentionDays' => 90,
        ]);
    }
}
