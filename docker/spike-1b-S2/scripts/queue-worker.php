<?php
/**
 * Spike 1b.S2 — worker standalone que consome UMA fila específica do
 * togare_queue_items via QueueService do togare-core (Story 1a.4c).
 *
 * Segregação por queue_name acontece em container-level: 1 instância deste
 * worker por fila (worker-djen, worker-internal, …). Nenhum worker enxerga
 * filas alheias — o `claim()` é filtrado pelo TOGARE_QUEUE_NAME do env.
 *
 * Handler sintético: `payload.simulatedSleepSeconds` simula custo de
 * processamento (ex.: DJEN parsing 30s, envio de email 1s). Em produção,
 * handlers reais virão em módulos Epic 4a (togare-djen) etc.
 *
 * THROWAWAY — não embarcar no build de produção do togare-core.
 */

declare(strict_types=1);

chdir('/usr/src/espocrm');
require '/usr/src/espocrm/bootstrap.php';

use Espo\Modules\TogareCore\Services\QueueService;
use Espo\Modules\TogareCore\Services\TogareLogger;

$queueName = (string) (\getenv('TOGARE_QUEUE_NAME') ?: 'internal');
$dbHost    = (string) \getenv('TOGARE_DB_HOST');
$dbName    = (string) \getenv('TOGARE_DB_NAME');
$dbUser    = (string) \getenv('TOGARE_DB_USER');
$dbPass    = (string) \getenv('TOGARE_DB_PASSWORD');

$pid = \getmypid();
\fwrite(STDERR, "[worker {$queueName}] pid={$pid} starting (db={$dbHost}/{$dbName})\n");

TogareLogger::init("togare-spike-worker-{$queueName}");

// Retry connection até 20 tentativas (container depends_on:service_healthy
// nem sempre garante 100% que o MariaDB aceita conexão imediatamente).
$pdo = null;
for ($attempt = 1; $attempt <= 20; $attempt++) {
    try {
        $pdo = new PDO(
            "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
            $dbUser,
            $dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        break;
    } catch (\PDOException $e) {
        \fwrite(STDERR, "[worker {$queueName}] DB connect attempt {$attempt} failed: {$e->getMessage()}\n");
        \sleep(1);
    }
}
if ($pdo === null) {
    \fwrite(STDERR, "[worker {$queueName}] could not connect to DB after 20 attempts — exiting\n");
    exit(1);
}

// Story 1b.1.1.1-followup: QueueService recebe EntityManager (não PDO direto).
// Como este script standalone não tem o container do EspoCRM, encapsulamos o PDO
// numa classe anônima que estende EntityManager apenas para satisfazer o tipo
// e expor getPDO(). Não chamamos parent::__construct (EntityManager real exige
// múltiplas dependências do container que não temos aqui).
$em = new class($pdo) extends \Espo\ORM\EntityManager {
    public function __construct(private \PDO $emPdo) {}
    public function getPDO(): \PDO { return $this->emPdo; }
};
$queueService = new QueueService($em);

\fwrite(STDERR, "[worker {$queueName}] pid={$pid} ready — polling…\n");

$loopIdleUs = 200_000; // 200 ms entre polls vazios

// phpcs:ignore Squiz.ControlStructures.InlineControlStructure.NotAllowed
while (true) {
    $items = $queueService->claim($queueName, 1);
    if ($items === []) {
        \usleep($loopIdleUs);
        continue;
    }

    foreach ($items as $item) {
        $sleepSec = (int) ($item['payload']['simulatedSleepSeconds'] ?? 0);
        $spikeId  = (string) ($item['payload']['spikeJobId'] ?? $item['id']);
        $ts       = \date('H:i:s');
        \fwrite(STDERR, "[worker {$queueName} {$ts}] claim {$spikeId} id={$item['id']} sleep={$sleepSec}s\n");

        try {
            if ($sleepSec > 0) {
                \sleep($sleepSec);
            }
            $queueService->markDone($item['id']);
            $ts2 = \date('H:i:s');
            \fwrite(STDERR, "[worker {$queueName} {$ts2}] DONE  {$spikeId} id={$item['id']}\n");
        } catch (\Throwable $e) {
            $queueService->markFailed($item['id'], $e->getMessage());
            \fwrite(STDERR, "[worker {$queueName}] FAIL  {$spikeId}: {$e->getMessage()}\n");
        }
    }
}
