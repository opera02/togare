<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services;

use DateTimeImmutable;
use Espo\ORM\EntityManager;
use PDO;

/**
 * QueueService — fila de trabalho assíncrono do Togare (outbox pattern, ADR 0005).
 *
 * Único ponto de INSERT em togare_queue_items (regra R6 do validator).
 *
 * Consumo via SELECT ... FOR UPDATE SKIP LOCKED em MariaDB ≥10.6. Em SQLite
 * (testes unit), o SKIP LOCKED é omitido dinamicamente — single-worker ok,
 * concorrência real fica pro Spike 1b.S2.
 *
 * Integração obrigatória com TogareLogger em todos os eventos de fila.
 *
 * DI: recebe EntityManager pelo InjectableFactory do EspoCRM e cacheia o PDO
 * no boot (padrão idiomático do EspoCRM 9.3 — InjectableFactory não resolve
 * `PDO` direto porque não consegue injetar a string `$dsn`).
 */
final class QueueService
{
    public const MAX_RETRIES = 5;

    private readonly PDO $pdo;

    public function __construct(EntityManager $entityManager)
    {
        $this->pdo = $entityManager->getPDO();
    }

    /**
     * Insere um item na fila. Idempotente: mesma idempotency_key retorna o
     * id já existente sem criar nova linha.
     *
     * @param array<string, mixed> $payload
     * @return string id do item
     */
    public function enqueue(string $queueName, array $payload, string $idempotencyKey): string
    {
        // Checagem otimista: item pode já existir.
        $existing = $this->findIdByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            TogareLogger::event('debug', 'queue.item.duplicate', 'Item duplicado ignorado (idempotency)', [
                'queueName' => $queueName,
                'idempotencyKey' => $idempotencyKey,
                'itemId' => $existing,
            ]);
            return $existing;
        }

        $id = \bin2hex(\random_bytes(16));
        $now = $this->nowString();
        $correlationId = $this->resolveCorrelationId();

        try {
            $stmt = $this->pdo->prepare('
                INSERT INTO togare_queue_items
                    (id, queue_name, idempotency_key, payload, status, retry_count,
                     correlation_id, created_at, updated_at)
                VALUES
                    (:id, :queue, :idem, :payload, :status, 0, :corr, :now, :now2)
            ');
            $stmt->execute([
                ':id' => $id,
                ':queue' => $queueName,
                ':idem' => $idempotencyKey,
                ':payload' => \json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':status' => QueueItemStatus::PENDING,
                ':corr' => $correlationId,
                ':now' => $now,
                ':now2' => $now,
            ]);
        } catch (\PDOException $e) {
            // Race condition: outro worker inseriu mesma idempotency_key entre
            // o SELECT e o INSERT. UNIQUE constraint garante que só uma passa.
            if ($this->isDuplicateKey($e)) {
                $existing = $this->findIdByIdempotencyKey($idempotencyKey);
                if ($existing !== null) {
                    TogareLogger::event('debug', 'queue.item.duplicate', 'Race resolvida por UNIQUE constraint', [
                        'queueName' => $queueName,
                        'idempotencyKey' => $idempotencyKey,
                        'itemId' => $existing,
                    ]);
                    return $existing;
                }
            }
            throw $e;
        }

        TogareLogger::event('info', 'queue.item.enqueued', 'Item enfileirado', [
            'queueName' => $queueName,
            'idempotencyKey' => $idempotencyKey,
            'itemId' => $id,
        ]);

        return $id;
    }

    /**
     * Consome até $batchSize items pending da fila. Em MariaDB, usa
     * SELECT ... FOR UPDATE SKIP LOCKED (concorrência segura); em SQLite,
     * omite (testes single-worker).
     *
     * @return list<array{id: string, queue_name: string, payload: array<string,mixed>, correlation_id: string|null, retry_count: int}>
     */
    public function claim(string $queueName, int $batchSize = 1): array
    {
        $supportsSkipLocked = $this->supportsSkipLocked();

        $this->pdo->beginTransaction();
        try {
            $suffix = $supportsSkipLocked ? 'FOR UPDATE SKIP LOCKED' : '';
            // Aceita items pending (nunca processados) ou failed_retry (aguardando
            // janela de backoff). Dead letter fica fora intencionalmente.
            $selectSql = "
                SELECT id, queue_name, payload, correlation_id, retry_count
                FROM togare_queue_items
                WHERE queue_name = :q
                  AND status IN (:status_pending, :status_retry)
                  AND (next_retry_at IS NULL OR next_retry_at <= :now)
                ORDER BY created_at ASC
                LIMIT {$batchSize}
                {$suffix}
            ";
            $stmt = $this->pdo->prepare($selectSql);
            $stmt->execute([
                ':q' => $queueName,
                ':status_pending' => QueueItemStatus::PENDING,
                ':status_retry' => QueueItemStatus::FAILED_RETRY,
                ':now' => $this->nowString(),
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($rows === []) {
                $this->pdo->commit();
                return [];
            }

            $ids = \array_map(static fn (array $row): string => (string) $row['id'], $rows);
            $placeholders = \implode(',', \array_fill(0, \count($ids), '?'));
            $now = $this->nowString();

            $upd = $this->pdo->prepare("
                UPDATE togare_queue_items
                SET status = ?, processing_started_at = ?, updated_at = ?
                WHERE id IN ({$placeholders})
            ");
            $upd->execute(\array_merge(
                [QueueItemStatus::PROCESSING, $now, $now],
                $ids,
            ));

            $this->pdo->commit();

            $items = [];
            foreach ($rows as $row) {
                $decoded = \json_decode((string) $row['payload'], true);
                $items[] = [
                    'id' => (string) $row['id'],
                    'queue_name' => (string) $row['queue_name'],
                    'payload' => \is_array($decoded) ? $decoded : [],
                    'correlation_id' => $row['correlation_id'] !== null ? (string) $row['correlation_id'] : null,
                    'retry_count' => (int) $row['retry_count'],
                ];
                TogareLogger::event('info', 'queue.item.claimed', 'Item reservado para processamento', [
                    'queueName' => $queueName,
                    'itemId' => $row['id'],
                    'retryCount' => (int) $row['retry_count'],
                ]);
            }

            return $items;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Marca item como concluído com sucesso.
     */
    public function markDone(string $itemId): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE togare_queue_items
            SET status = :done, completed_at = :now, updated_at = :now2
            WHERE id = :id AND status = :processing
        ');
        $now = $this->nowString();
        $stmt->execute([
            ':done' => QueueItemStatus::DONE,
            ':processing' => QueueItemStatus::PROCESSING,
            ':now' => $now,
            ':now2' => $now,
            ':id' => $itemId,
        ]);

        if ($stmt->rowCount() === 0) {
            TogareLogger::event(
                'warning',
                'queue.item.mark_done.mismatch',
                'markDone chamado mas item não estava em processing (id inexistente ou já em done/failed).',
                ['itemId' => $itemId]
            );
            return;
        }

        TogareLogger::event('info', 'queue.item.done', 'Item concluído', [
            'itemId' => $itemId,
        ]);
    }

    /**
     * Marca item como falho. Aplica backoff exponencial ou promove a dead letter
     * se atingiu MAX_RETRIES ou for permanent.
     *
     * @param ?int $customDelaySeconds  Story 4a.1 (togare-core 0.15.0): se
     *   informado, sobrescreve o cálculo padrão de backoff (60 * 2^retryCount
     *   ± jitter 10%) com valor literal em segundos sem jitter. Permite que
     *   call-sites com SLA específico (ex.: DjenWorkerService quer
     *   next_retry_at = now+1h em falhas adapter — AC2/AC3 da Story 4a.1)
     *   escolham o delay sem mudar o contrato dos consumidores existentes.
     *   Quando NULL (default), comportamento idêntico ao histórico.
     * @param ?string $failureCategory  Story 4b.4 / ADR 0009 (togare-core 0.28.0):
     *   categoria semântica da falha (snake_case lowercase, ex.:
     *   'adapter_unavailable', 'license_expired', 'forbidden',
     *   'rate_limit_exceeded'). Quando informado, é gravado na coluna
     *   `failure_category` (V017) E habilita o filtro de
     *   `rescheduleAfterCircuitBreakerClose($queueName, $failureCategory)`.
     *   Quando NULL (default), a coluna é gravada como NULL — falhas
     *   desconhecidas não devem herdar categoria semântica anterior.
     */
    public function markFailed(
        string $itemId,
        string $reason,
        bool $permanent = false,
        ?int $customDelaySeconds = null,
        ?string $failureCategory = null,
    ): void {
        $stmt = $this->pdo->prepare('SELECT retry_count FROM togare_queue_items WHERE id = :id');
        $stmt->execute([':id' => $itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            TogareLogger::event(
                'warning',
                'queue.item.mark_failed.not_found',
                'markFailed chamado com id inexistente.',
                ['itemId' => $itemId, 'reason' => $reason]
            );
            return;
        }

        $currentRetry = (int) $row['retry_count'];
        $nextRetry = $currentRetry + 1;
        $reasonTruncated = \substr($reason, 0, 1000);

        if ($permanent || $nextRetry >= self::MAX_RETRIES) {
            // Dead letter: sem próxima tentativa.
            $upd = $this->pdo->prepare('
                UPDATE togare_queue_items
                SET status = :dl,
                    last_error = :err,
                    updated_at = :now,
                    failure_category = :cat
                WHERE id = :id
            ');
            $upd->execute([
                ':dl' => QueueItemStatus::FAILED_DEAD_LETTER,
                ':err' => $reasonTruncated,
                ':now' => $this->nowString(),
                ':cat' => $failureCategory,
                ':id' => $itemId,
            ]);
            TogareLogger::event(
                'error',
                'queue.item.dead_letter',
                'Item promovido a dead letter',
                [
                    'itemId' => $itemId,
                    'reason' => $reasonTruncated,
                    'retryCount' => $currentRetry,
                    'permanent' => $permanent,
                ]
            );
            return;
        }

        // Retry: agendar next_retry_at.
        // - Se $customDelaySeconds foi informado (Story 4a.1), usa valor literal
        //   sem jitter — call-site controla a precisão (ex.: DjenWorkerService
        //   quer next_retry_at = now+1h por contrato AC2 da 4a.1).
        // - Senão, backoff exponencial padrão + jitter ±10% (comportamento
        //   histórico desde 1a.4c).
        if ($customDelaySeconds !== null && $customDelaySeconds > 0) {
            $totalDelay = $customDelaySeconds;
        } else {
            $delay = $this->formatRetryDelay($currentRetry);
            $jitter = \random_int(-(int) \floor($delay * 0.1), (int) \floor($delay * 0.1));
            $totalDelay = $delay + $jitter;
        }
        $nextRetryAt = (new DateTimeImmutable())->modify("+{$totalDelay} seconds")->format('Y-m-d H:i:s');

        $upd = $this->pdo->prepare('
            UPDATE togare_queue_items
            SET status = :retry,
                retry_count = :rc,
                last_error = :err,
                next_retry_at = :nra,
                updated_at = :now,
                failure_category = :cat
            WHERE id = :id
        ');
        $upd->execute([
            ':retry' => QueueItemStatus::FAILED_RETRY,
            ':rc' => $nextRetry,
            ':err' => $reasonTruncated,
            ':nra' => $nextRetryAt,
            ':now' => $this->nowString(),
            ':cat' => $failureCategory,
            ':id' => $itemId,
        ]);
        TogareLogger::event('warning', 'queue.item.retry', 'Item agendado para retry', [
            'itemId' => $itemId,
            'retryCount' => $nextRetry,
            'nextRetryAt' => $nextRetryAt,
            'reason' => $reasonTruncated,
        ]);

        // Item fica em status=failed_retry com next_retry_at futuro. O claim()
        // aceita status IN (pending, failed_retry) + next_retry_at <= NOW,
        // então o item é automaticamente re-reclamado quando a janela abre.
    }

    /**
     * Reclama items travados em processing há mais de $afterSeconds (worker
     * morreu sem markDone/markFailed). Volta pra pending com retry_count++.
     *
     * @return int número de items reclamados
     */
    public function reclaimStuck(string $queueName, int $afterSeconds = 600): int
    {
        $cutoff = (new DateTimeImmutable())
            ->modify("-{$afterSeconds} seconds")
            ->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare('
            UPDATE togare_queue_items
            SET status = :pending,
                retry_count = retry_count + 1,
                processing_started_at = NULL,
                updated_at = :now
            WHERE queue_name = :q
              AND status = :processing
              AND processing_started_at < :cutoff
        ');
        $stmt->execute([
            ':pending' => QueueItemStatus::PENDING,
            ':processing' => QueueItemStatus::PROCESSING,
            ':q' => $queueName,
            ':cutoff' => $cutoff,
            ':now' => $this->nowString(),
        ]);

        $count = $stmt->rowCount();
        if ($count > 0) {
            TogareLogger::event('warning', 'queue.item.reclaimed', 'Items reclamados após travamento', [
                'queueName' => $queueName,
                'count' => $count,
                'cutoff' => $cutoff,
            ]);
        }
        return $count;
    }

    /**
     * Story 4b.4 / ADR 0009 — alinhamento retry × circuit breaker.
     *
     * Quando o circuit breaker de um adapter (ex.: DjenAdapter) fecha após o
     * cooldown (10min), itens enfileirados durante a janela ficam aguardando
     * `next_retry_at = now + customDelaySeconds` (1h por default no
     * DjenWorkerService). Esse gap entre CB-recupera (10min) e o retry
     * agendado (1h) é desnecessário — a fonte voltou.
     *
     * Este método é chamado pelo worker ao detectar transição open→closed
     * do CB e reagenda **um único UPDATE atômico** todos os items elegíveis:
     *   - `queue_name = :q`
     *   - `status = 'failed_retry'`
     *   - `failure_category = :cat`
     *   - `next_retry_at > now` (idempotência intrínseca — segundo worker
     *     detectando simultaneamente faz no-op)
     *
     * Não toca `retry_count` (a tentativa atual permaneceu legítima — só o
     * agendamento mudou). Não toca `last_error` (preserva diagnóstico).
     *
     * @param string $queueName        Nome da fila (ex.: 'djen').
     * @param string $failureCategory  Categoria semântica (ex.: 'adapter_unavailable').
     * @return int Número de items reagendados (0 se idempotente / nenhum match).
     */
    public function rescheduleAfterCircuitBreakerClose(string $queueName, string $failureCategory): int
    {
        $now = $this->nowString();
        $stmt = $this->pdo->prepare('
            UPDATE togare_queue_items
            SET next_retry_at = :now,
                updated_at = :now2
            WHERE queue_name = :q
              AND status = :status
              AND failure_category = :cat
              AND next_retry_at > :now3
        ');
        $stmt->execute([
            ':now' => $now,
            ':now2' => $now,
            ':now3' => $now,
            ':q' => $queueName,
            ':status' => QueueItemStatus::FAILED_RETRY,
            ':cat' => $failureCategory,
        ]);

        $count = $stmt->rowCount();
        if ($count > 0) {
            TogareLogger::event(
                'info',
                'queue.items.rescheduled_after_cb_close',
                "Items reagendados após fechamento do circuit breaker",
                [
                    'queueName' => $queueName,
                    'failureCategory' => $failureCategory,
                    'count' => $count,
                ],
            );
        }
        // Quando count = 0, NÃO loga (evita ruído em ticks normais).
        return $count;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function findIdByIdempotencyKey(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT id FROM togare_queue_items WHERE idempotency_key = :k');
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : (string) $row['id'];
    }

    private function nowString(): string
    {
        return (new DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    private function resolveCorrelationId(): ?string
    {
        $header = $_SERVER['HTTP_X_TOGARE_CORRELATION_ID'] ?? null;
        return \is_string($header) && $header !== '' ? $header : null;
    }

    private function supportsSkipLocked(): bool
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        return $driver === 'mysql';
    }

    private function formatRetryDelay(int $retryCount): int
    {
        // 60 * 2^n → 60, 120, 240, 480, 960 segundos.
        return 60 * (2 ** $retryCount);
    }

    private function isDuplicateKey(\PDOException $e): bool
    {
        // MySQL/MariaDB: 23000 (integrity constraint) + 1062 (duplicate).
        // SQLite: 23000 também.
        $sqlstate = $e->getCode();
        $msg = \strtolower($e->getMessage());
        if ($sqlstate === '23000') {
            return true;
        }
        return \str_contains($msg, 'duplicate') || \str_contains($msg, 'unique constraint');
    }
}
