<?php

declare(strict_types=1);

/**
 * Entrypoint do container `togare-djen-worker` (Story 4a.1 — AC12).
 *
 * Loop infinito que reclama items da fila `djen` via DjenWorkerService.
 * Variante B canônica da Spike 1b.S2 (1 container consumer por queue_name).
 *
 * Boot:
 *   - require do bootstrap.php do EspoCRM (autoloader composer + framework).
 *   - Resolve DjenWorkerService via container DI.
 *
 * Loop:
 *   - DjenWorkerService::processOne() retorna bool (true=processou; false=fila vazia).
 *   - Se vazia, sleep 5s antes de tentar de novo (evita CPU spin).
 *   - A cada IDLE_TICKS_BEFORE_RECLAIM=100 iterações ociosas, chama
 *     QueueService::reclaimStuck('djen', 600) — items em `processing`
 *     há >10min voltam para `pending`.
 *
 * Graceful shutdown:
 *   - Trapeia SIGTERM/SIGINT (Docker stop) e termina o loop limpo.
 *   - Item em processamento atual termina antes do exit.
 */

$bootstrapPath = '/var/www/html/bootstrap.php';
if (! is_file($bootstrapPath)) {
    fwrite(STDERR, "[togare-djen-worker] FATAL: bootstrap.php não encontrado em {$bootstrapPath}\n");
    exit(1);
}
require $bootstrapPath;

use Espo\Core\Application;
use Espo\Modules\TogareCore\Services\QueueService;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareDjen\Services\DjenWorkerService;

$queueName = (string) (getenv('TOGARE_QUEUE_NAME') ?: 'djen');
if ($queueName !== 'djen') {
    fwrite(STDERR, "[togare-djen-worker] FATAL: TOGARE_QUEUE_NAME esperado 'djen', recebido '{$queueName}'\n");
    exit(1);
}

$app = new Application();
$container = $app->getContainer();

// Story 4a.3 — worker precisa de "system user" no container para que
// saveEntity em hooks de stream funcione (Stream/Notification/etc do
// EspoCRM core acessam o serviço 'user'). Fora do contexto HTTP/Cron,
// o container não autentica ninguém — chamamos explicitamente o pattern
// usado por ApplicationRunners/RunnerRunner.php (`setupSystemUser=true`).
$container->get('applicationUser')->setupSystemUser();

TogareLogger::init('togare-djen-worker', $container);

/** @var DjenWorkerService $worker */
$worker = $container->get('togareDjenWorker');
/** @var QueueService $queueService */
$queueService = $container->get('togareCoreQueueService');

$shouldExit = false;
$signalHandler = static function (int $signal) use (&$shouldExit): void {
    fwrite(STDOUT, "[togare-djen-worker] Recebido sinal {$signal} — encerrando após item atual\n");
    TogareLogger::event(
        'info',
        'djen.worker.shutdown_requested',
        "Recebido sinal {$signal}",
        ['signal' => $signal],
    );
    $shouldExit = true;
};

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, $signalHandler);
    pcntl_signal(SIGINT, $signalHandler);
}

TogareLogger::event(
    'info',
    'queue.worker.started',
    'Worker DJEN iniciado',
    [
        'queueName' => $queueName,
        'pid' => getmypid(),
    ],
);
fwrite(STDOUT, "[togare-djen-worker] Iniciado (queueName={$queueName}, pid=" . getmypid() . ")\n");

$idleTicks = 0;
$processedCount = 0;
$IDLE_TICKS_BEFORE_RECLAIM = 100;
$IDLE_SLEEP_SECONDS = 5;
$RECLAIM_THRESHOLD_SECONDS = 600;

while (! $shouldExit) {
    try {
        $processed = $worker->processOne();
        if ($processed) {
            $processedCount++;
            $idleTicks = 0;
            continue;
        }

        $idleTicks++;
        if ($idleTicks >= $IDLE_TICKS_BEFORE_RECLAIM) {
            $reclaimed = $queueService->reclaimStuck($queueName, $RECLAIM_THRESHOLD_SECONDS);
            if ($reclaimed > 0) {
                TogareLogger::event(
                    'info',
                    'djen.worker.reclaim_swept',
                    "reclaimStuck recuperou {$reclaimed} items travados",
                    ['queueName' => $queueName, 'reclaimed' => $reclaimed],
                );
            }
            $idleTicks = 0;
        }

        sleep($IDLE_SLEEP_SECONDS);
    } catch (\Throwable $e) {
        // Worker NUNCA pode morrer por erro inesperado — loga e continua.
        TogareLogger::event(
            'error',
            'djen.worker.loop_error',
            'Erro inesperado no loop do worker: ' . $e->getMessage(),
            [
                'exception' => get_class($e),
                'trace' => substr($e->getTraceAsString(), 0, 4096),
            ],
        );
        sleep($IDLE_SLEEP_SECONDS);
    }
}

TogareLogger::event(
    'info',
    'djen.worker.stopped',
    'Worker DJEN encerrado limpo',
    [
        'queueName' => $queueName,
        'processedCount' => $processedCount,
    ],
);
fwrite(STDOUT, "[togare-djen-worker] Encerrado (processedCount={$processedCount})\n");
exit(0);
