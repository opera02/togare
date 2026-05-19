<?php
/**
 * Spike 1b.S2 — Plano B (ADR 0005b draft).
 *
 * Worker standalone **sem dependência** do framework EspoCRM. Implementa
 * o contrato do QueueService (SKIP LOCKED + pending/failed_retry) em SQL
 * direto via PDO. Usado dentro do container supervisord (alpine + php-cli)
 * para provar que o fallback funciona ponta-a-ponta mesmo se a imagem
 * espocrm/espocrm:9.3 sair da equação.
 *
 * NB: duplica ~60 linhas do QueueService real. Se o plano B virar produção
 * (promoção do ADR 0005b), vale deixar o QueueService acessível via
 * composer.json autoloading separado — aqui é deliberadamente simples.
 */

declare(strict_types=1);

$queueName = (string) (\getenv('TOGARE_QUEUE_NAME') ?: 'internal');
$dbHost    = (string) \getenv('TOGARE_DB_HOST');
$dbName    = (string) \getenv('TOGARE_DB_NAME');
$dbUser    = (string) \getenv('TOGARE_DB_USER');
$dbPass    = (string) \getenv('TOGARE_DB_PASSWORD');

$pid = \getmypid();
\fwrite(STDERR, "[fallback worker {$queueName}] pid={$pid} starting\n");

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
        \fwrite(STDERR, "[fallback worker {$queueName}] DB retry {$attempt}: {$e->getMessage()}\n");
        \sleep(1);
    }
}
if ($pdo === null) {
    \fwrite(STDERR, "[fallback worker {$queueName}] DB unavailable after 20 tries\n");
    exit(1);
}

\fwrite(STDERR, "[fallback worker {$queueName}] pid={$pid} ready — polling…\n");

$now = static fn (): string => (new DateTimeImmutable())->format('Y-m-d H:i:s');

// phpcs:ignore Squiz.ControlStructures.InlineControlStructure.NotAllowed
while (true) {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            SELECT id, payload
              FROM togare_queue_items
             WHERE queue_name = :q
               AND status IN ('pending','failed_retry')
               AND (next_retry_at IS NULL OR next_retry_at <= :now)
             ORDER BY created_at ASC
             LIMIT 1
             FOR UPDATE SKIP LOCKED
        ");
        $stmt->execute([':q' => $queueName, ':now' => $now()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            $pdo->commit();
            \usleep(200_000);
            continue;
        }

        $upd = $pdo->prepare("
            UPDATE togare_queue_items
               SET status='processing', processing_started_at=:ts, updated_at=:ts
             WHERE id=:id
        ");
        $upd->execute([':ts' => $now(), ':id' => $row['id']]);
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        \fwrite(STDERR, "[fallback worker {$queueName}] claim error: {$e->getMessage()}\n");
        \sleep(1);
        continue;
    }

    $payload  = \json_decode((string) $row['payload'], true) ?: [];
    $sleepSec = (int) ($payload['simulatedSleepSeconds'] ?? 0);
    $spikeId  = (string) ($payload['spikeJobId'] ?? $row['id']);
    $ts       = \date('H:i:s');
    \fwrite(STDERR, "[fallback worker {$queueName} {$ts}] claim {$spikeId} sleep={$sleepSec}s\n");

    try {
        if ($sleepSec > 0) {
            \sleep($sleepSec);
        }
        $done = $pdo->prepare("
            UPDATE togare_queue_items
               SET status='done', completed_at=:ts, updated_at=:ts
             WHERE id=:id AND status='processing'
        ");
        $done->execute([':ts' => $now(), ':id' => $row['id']]);
        $ts2 = \date('H:i:s');
        \fwrite(STDERR, "[fallback worker {$queueName} {$ts2}] DONE  {$spikeId}\n");
    } catch (\Throwable $e) {
        $fail = $pdo->prepare("
            UPDATE togare_queue_items
               SET status='failed_dead_letter', last_error=:err, updated_at=:ts
             WHERE id=:id
        ");
        $fail->execute([':err' => \substr($e->getMessage(), 0, 1000), ':ts' => $now(), ':id' => $row['id']]);
        \fwrite(STDERR, "[fallback worker {$queueName}] FAIL  {$spikeId}: {$e->getMessage()}\n");
    }
}
