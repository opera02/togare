<?php
/**
 * Spike 1b.S2 — enfileira N jobs sintéticos em filas configuráveis via
 * QueueService::enqueue() da Story 1a.4c. Força o path real do validator,
 * trigger de created_at, unique constraint em idempotency_key etc.
 *
 * Uso:
 *   docker compose -f docker-compose.spike.yml run --rm spike-cli \
 *     /opt/togare-spike/seed-jobs.php [--djen=N] [--internal=N] [--tpu=N] [--reset]
 *
 *   --reset  opcional: TRUNCATE togare_queue_items antes de enfileirar
 *            (cada run fica limpa; recomendado durante sanity).
 *   --djen=N      padrão 0; simulatedSleepSeconds=30 (simula DJEN parse).
 *   --internal=N  padrão 0; simulatedSleepSeconds=1  (simula envio de email).
 *   --tpu=N       padrão 0; simulatedSleepSeconds=5  (simula lookup TPU).
 *
 *   idempotency_key gerado com timestamp para não colidir entre runs mesmo
 *   sem --reset. Formato: spike-{queue}-{i}-{unixts}.
 */

declare(strict_types=1);

chdir('/usr/src/espocrm');
require '/usr/src/espocrm/bootstrap.php';

use Espo\Modules\TogareCore\Services\QueueService;
use Espo\Modules\TogareCore\Services\TogareLogger;

$opts = \getopt('', ['djen::', 'internal::', 'tpu::', 'reset']);
$djenN     = (int) ($opts['djen']     ?? 0);
$internalN = (int) ($opts['internal'] ?? 0);
$tpuN      = (int) ($opts['tpu']      ?? 0);
$reset     = \array_key_exists('reset', $opts);

$pdo = new PDO(
    \sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        (string) \getenv('TOGARE_DB_HOST'),
        (string) \getenv('TOGARE_DB_NAME'),
    ),
    (string) \getenv('TOGARE_DB_USER'),
    (string) \getenv('TOGARE_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

TogareLogger::init('togare-spike-seed');

if ($reset) {
    \fwrite(STDOUT, "[seed] --reset: TRUNCATE togare_queue_items…\n");
    $pdo->exec('TRUNCATE TABLE togare_queue_items');
}

// Story 1b.1.1.1-followup: QueueService recebe EntityManager (não PDO direto).
// Adapter anônimo standalone — ver explicação detalhada em queue-worker.php.
$em = new class($pdo) extends \Espo\ORM\EntityManager {
    public function __construct(private \PDO $emPdo) {}
    public function getPDO(): \PDO { return $this->emPdo; }
};
$queueService = new QueueService($em);
$ts = \time();

$enqueue = static function (string $queue, int $n, int $sleepSec) use ($queueService, $ts): int {
    $ok = 0;
    for ($i = 1; $i <= $n; $i++) {
        $key = \sprintf('spike-%s-%d-%d', $queue, $i, $ts);
        $queueService->enqueue($queue, [
            'simulatedSleepSeconds' => $sleepSec,
            'spikeJobId' => "spike-{$queue}-{$i}",
        ], $key);
        $ok++;
    }
    return $ok;
};

$counts = [
    'djen'     => $djenN     > 0 ? $enqueue('djen',     $djenN,     30) : 0,
    'internal' => $internalN > 0 ? $enqueue('internal', $internalN, 1)  : 0,
    'tpu'      => $tpuN      > 0 ? $enqueue('tpu',      $tpuN,      5)  : 0,
];

$total = \array_sum($counts);
\fwrite(STDOUT, \sprintf(
    "[seed] OK — enfileirados %d jobs: djen=%d internal=%d tpu=%d (ts=%d)\n",
    $total,
    $counts['djen'],
    $counts['internal'],
    $counts['tpu'],
    $ts,
));
