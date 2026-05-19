<?php

declare(strict_types=1);

namespace Espo\Modules\TogareTpu\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareTpu\Services\TpuSyncService;
use Throwable;

/**
 * Scheduled job mensal: sincroniza catálogo TPU (Story 3.3 — AC2).
 *
 * Configurado em `Resources/metadata/app/scheduledJobs.json` para
 * cron `0 3 1 * *` (todo dia 1 às 3h, TZ do daemon — recomendado setar
 * `TZ: America/Sao_Paulo` no espocrm-daemon, ver Dev Notes §11).
 *
 * Captura QUALQUER exception do sync e loga como `tpu.sync.job_failed` —
 * NÃO relança (cron deve continuar agendando próximas execuções; falha
 * isolada não pode quebrar o daemon).
 */
final class TogareTpuSyncJob implements Job
{
    public function __construct(
        private readonly TpuSyncService $sync,
    ) {
    }

    public function run(Data $data): void
    {
        try {
            $result = $this->sync->syncAll();
            if ($result['failures'] !== []) {
                TogareLogger::event(
                    'warning',
                    'tpu.sync.job_partial_failure',
                    'Sync TPU concluído com falhas parciais — catálogos afetados não foram atualizados',
                    ['failures' => $result['failures']],
                );
            }
        } catch (Throwable $e) {
            TogareLogger::event(
                'error',
                'tpu.sync.job_failed',
                'Job de sync TPU falhou: ' . $e->getMessage(),
                [
                    'exception' => get_class($e),
                    'trace' => substr($e->getTraceAsString(), 0, 4096),
                ],
            );
            // NÃO RELANÇAR — cron deve continuar agendando.
        }
    }
}
