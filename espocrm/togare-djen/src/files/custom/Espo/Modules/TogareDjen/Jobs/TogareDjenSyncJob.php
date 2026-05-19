<?php

declare(strict_types=1);

namespace Espo\Modules\TogareDjen\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareDjen\Services\DjenWindowEnqueuer;
use Espo\Modules\TogareLicensing\Service\ReadOnlyGate as LicenseReadOnlyGate;
use Throwable;

/**
 * Scheduled job diário: enfileira janelas DJEN por advogado (Story 4a.1 — AC4/AC5).
 *
 * Configurado em `Resources/metadata/app/scheduledJobs.json` para
 * cron `0 6 * * 1-5` (seg-sex 06:00 — TZ do daemon, recomendado
 * `TZ: America/Sao_Paulo` no espocrm-daemon, já setado desde Story 3.3).
 *
 * Guard inicial via `LicenseReadOnlyGate::isBlocked('togare-djen')` (AC5):
 * se licença em modo read-only, retorna early sem enfileirar.
 *
 * Captura QUALQUER exception não tratada do enqueuer e loga como
 * `djen.sync.job_failed` — NÃO relança (cron deve continuar agendando
 * próximas execuções; falha isolada não pode quebrar o daemon).
 */
final class TogareDjenSyncJob implements Job
{
    public function __construct(
        private readonly DjenWindowEnqueuer $enqueuer,
        private readonly LicenseReadOnlyGate $licenseGate,
    ) {
    }

    public function run(Data $data): void
    {
        try {
            TogareLogger::event(
                'info',
                'djen.sync.started',
                'Sync DJEN iniciado',
                [],
            );

            if ($this->licenseGate->isBlocked('togare-djen')) {
                TogareLogger::event(
                    'warning',
                    'djen.sync.skipped_license_expired',
                    'Sync DJEN pausado — licença em modo somente leitura',
                    [],
                );
                return;
            }

            $totals = $this->enqueuer->enqueueWindowsForAllAdvogados();

            TogareLogger::event(
                'info',
                'djen.sync.completed',
                'Sync DJEN concluído',
                $totals,
            );
        } catch (Throwable $e) {
            TogareLogger::event(
                'error',
                'djen.sync.job_failed',
                'Job de sync DJEN falhou: ' . $e->getMessage(),
                [
                    'exception' => \get_class($e),
                    'trace' => \substr($e->getTraceAsString(), 0, 4096),
                ],
            );
            // NÃO RELANÇAR — cron deve continuar agendando.
        }
    }
}
