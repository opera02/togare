<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore;

use Espo\Modules\TogareCore\Services\QueueItemStatus;
use Espo\Modules\TogareCore\Services\QueueService;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\EntityManager;
use PDO;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Testes unit do QueueService usando SQLite in-memory.
 *
 * **Limitação documentada:** SQLite não suporta SELECT ... FOR UPDATE SKIP LOCKED.
 * O QueueService detecta driver via PDO::ATTR_DRIVER_NAME e omite a cláusula
 * em SQLite — então testes single-worker passam. Concorrência real (2 workers
 * simultâneos sem pegar item duplicado) fica para o Spike 1b.S2 com MariaDB.
 *
 * Cada teste roda em processo separado pois QueueService emite logs via
 * TogareLogger (singleton estático).
 */
final class QueueServiceTest extends TestCase
{
    private PDO $pdo;
    private QueueService $queue;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Cria schema V004 + V017 inline (sintaxe compatível com SQLite).
        $this->pdo->exec("
            CREATE TABLE togare_queue_items (
                id VARCHAR(32) NOT NULL PRIMARY KEY,
                queue_name VARCHAR(64) NOT NULL,
                idempotency_key VARCHAR(200) NOT NULL,
                payload TEXT NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                retry_count INTEGER NOT NULL DEFAULT 0,
                last_error TEXT NULL,
                next_retry_at DATETIME NULL,
                processing_started_at DATETIME NULL,
                completed_at DATETIME NULL,
                correlation_id VARCHAR(64) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                failure_category VARCHAR(40) NULL
            )
        ");
        $this->pdo->exec('CREATE UNIQUE INDEX uk_togare_queue_idempotency ON togare_queue_items (idempotency_key)');
        $this->pdo->exec(
            'CREATE INDEX idx_togare_queue_failure_category ON togare_queue_items (queue_name, status, failure_category)',
        );

        // Inicializa TogareLogger com streams de memória (não poluem output).
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        TogareLogger::init('test', null, $stdout, $stderr);

        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willReturn($this->pdo);
        $this->queue = new QueueService($em);
    }

    #[RunInSeparateProcess]
    public function testEnqueueInsertsItem(): void
    {
        $id = $this->queue->enqueue('djen', ['pubId' => 'abc'], 'djen.pub.abc');

        self::assertSame(32, strlen($id));

        $row = $this->fetchById($id);
        self::assertNotNull($row);
        self::assertSame('djen', $row['queue_name']);
        self::assertSame('djen.pub.abc', $row['idempotency_key']);
        self::assertSame(QueueItemStatus::PENDING, $row['status']);
        self::assertSame(0, (int) $row['retry_count']);

        $payload = json_decode((string) $row['payload'], true);
        self::assertSame(['pubId' => 'abc'], $payload);
    }

    #[RunInSeparateProcess]
    public function testEnqueueIsIdempotent(): void
    {
        $first = $this->queue->enqueue('djen', ['pubId' => 'abc'], 'djen.pub.abc');
        $second = $this->queue->enqueue('djen', ['pubId' => 'ignorado'], 'djen.pub.abc');

        self::assertSame($first, $second);

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM togare_queue_items')->fetchColumn();
        self::assertSame(1, $count);
    }

    #[RunInSeparateProcess]
    public function testClaimMovesToProcessing(): void
    {
        $this->queue->enqueue('djen', ['a' => 1], 'k1');
        $this->queue->enqueue('djen', ['a' => 2], 'k2');
        $this->queue->enqueue('djen', ['a' => 3], 'k3');

        $items = $this->queue->claim('djen', 2);

        self::assertCount(2, $items);
        self::assertSame(['a' => 1], $items[0]['payload']);
        self::assertSame(['a' => 2], $items[1]['payload']);

        $claimedIds = array_column($items, 'id');
        foreach ($claimedIds as $id) {
            $row = $this->fetchById($id);
            self::assertSame(QueueItemStatus::PROCESSING, $row['status']);
            self::assertNotNull($row['processing_started_at']);
        }

        // Segunda claim pega o item restante (k3).
        $rest = $this->queue->claim('djen', 5);
        self::assertCount(1, $rest);
        self::assertSame(['a' => 3], $rest[0]['payload']);
    }

    #[RunInSeparateProcess]
    public function testMarkFailedBackoff(): void
    {
        $id = $this->queue->enqueue('djen', ['x' => 1], 'k-backoff');
        $this->queue->claim('djen', 1);
        $this->queue->markFailed($id, 'timeout');

        $row = $this->fetchById($id);
        self::assertSame(QueueItemStatus::FAILED_RETRY, $row['status']);
        self::assertSame(1, (int) $row['retry_count']);
        self::assertSame('timeout', $row['last_error']);
        self::assertNotNull($row['next_retry_at']);
    }

    /**
     * Story 4a.1 (togare-core 0.15.0): markFailed aceita customDelaySeconds
     * opcional para call-sites com SLA específico (ex.: DjenWorkerService quer
     * next_retry_at = now+1h em falhas adapter — AC2/AC3 da 4a.1).
     *
     * Quando informado, sobrescreve o backoff exponencial (sem jitter).
     */
    #[RunInSeparateProcess]
    public function testMarkFailedComCustomDelaySecondsUsaValorLiteralSemJitter(): void
    {
        $id = $this->queue->enqueue('djen', ['x' => 1], 'k-customdelay');
        $this->queue->claim('djen', 1);

        $before = (new \DateTimeImmutable())->modify('+3600 seconds');
        $this->queue->markFailed($id, 'djen adapter 502', false, 3600);
        $after = (new \DateTimeImmutable())->modify('+3600 seconds');

        $row = $this->fetchById($id);
        self::assertSame(QueueItemStatus::FAILED_RETRY, $row['status']);
        self::assertSame(1, (int) $row['retry_count']);
        self::assertSame('djen adapter 502', $row['last_error']);
        self::assertNotNull($row['next_retry_at']);

        // next_retry_at deve estar entre now+3600s (no início do markFailed)
        // e now+3600s (no fim) — janela de tolerância de alguns segundos pra
        // diff de execução. SEM jitter (usado o valor literal 3600).
        $actual = new \DateTimeImmutable($row['next_retry_at']);
        self::assertGreaterThanOrEqual($before->getTimestamp() - 1, $actual->getTimestamp());
        self::assertLessThanOrEqual($after->getTimestamp() + 1, $actual->getTimestamp());

        // Verifica que o delay foi aproximadamente 3600s do start, NÃO 60s
        // (default backoff que seria aplicado sem o customDelay).
        $diffSecondsDoStart = $actual->getTimestamp() - $before->getTimestamp();
        self::assertGreaterThan(-5, $diffSecondsDoStart);
        self::assertLessThan(5, $diffSecondsDoStart, 'customDelay literal — sem jitter ±10%');
    }

    #[RunInSeparateProcess]
    public function testMarkFailedHitsDeadLetterAfterMax(): void
    {
        $id = $this->queue->enqueue('djen', ['x' => 1], 'k-dl');

        // Simula 5 ciclos claim+markFailed (MAX_RETRIES = 5).
        for ($i = 0; $i < 5; $i++) {
            // Forçar estado processing para permitir markFailed lógico.
            $this->pdo->exec("UPDATE togare_queue_items SET status='processing', next_retry_at=NULL WHERE id='{$id}'");
            $this->queue->markFailed($id, "falha {$i}");
        }

        $row = $this->fetchById($id);
        self::assertSame(QueueItemStatus::FAILED_DEAD_LETTER, $row['status']);
    }

    #[RunInSeparateProcess]
    public function testMarkDoneTransitionsToDone(): void
    {
        $id = $this->queue->enqueue('djen', ['x' => 1], 'k-done');
        $this->queue->claim('djen', 1);
        $this->queue->markDone($id);

        $row = $this->fetchById($id);
        self::assertSame(QueueItemStatus::DONE, $row['status']);
        self::assertNotNull($row['completed_at']);
    }

    // ============================================================
    // Story 4b.4 / ADR 0009 — failure_category + reschedule API
    // ============================================================

    /**
     * AC2: markFailed com 6º param `failureCategory` grava na coluna nova.
     */
    #[RunInSeparateProcess]
    public function testMarkFailedComFailureCategoryGravaNaColuna(): void
    {
        $id = $this->queue->enqueue('djen', ['x' => 1], 'k-cat-1');
        $this->queue->claim('djen', 1);

        $this->queue->markFailed($id, 'djen 502', false, 3600, 'adapter_unavailable');

        $row = $this->fetchById($id);
        self::assertSame(QueueItemStatus::FAILED_RETRY, $row['status']);
        self::assertSame('adapter_unavailable', $row['failure_category']);
    }

    /**
     * AC2: markFailed sem 6º param limpa a coluna failure_category.
     * Falha desconhecida não pode herdar categoria semântica antiga, pois isso
     * poderia habilitar reschedule indevido no fechamento do CB.
     */
    #[RunInSeparateProcess]
    public function testMarkFailedSemFailureCategoryLimpaColunaAnterior(): void
    {
        $id = $this->queue->enqueue('djen', ['x' => 1], 'k-cat-2');
        $this->queue->claim('djen', 1);

        // Pre-popula failure_category diretamente via SQL (simula 1ª falha
        // categorizada). Em seguida, simula uma 2ª markFailed sem informar
        // a categoria (falha genérica) — coluna deve ser limpa.
        $this->pdo->exec("UPDATE togare_queue_items SET failure_category='adapter_unavailable' WHERE id='{$id}'");
        $this->pdo->exec("UPDATE togare_queue_items SET status='processing', next_retry_at=NULL WHERE id='{$id}'");

        $this->queue->markFailed($id, 'sem categoria desta vez');

        $row = $this->fetchById($id);
        self::assertNull($row['failure_category'], 'failure_category deve ser limpo quando param é NULL');
    }

    /**
     * AC2: caminho dead_letter também grava failure_category quando informado.
     */
    #[RunInSeparateProcess]
    public function testMarkFailedDeadLetterTambemGravaFailureCategory(): void
    {
        $id = $this->queue->enqueue('djen', ['x' => 1], 'k-cat-dl');

        // Força permanent=true → caminho dead_letter na 1ª chamada.
        $this->pdo->exec("UPDATE togare_queue_items SET status='processing', next_retry_at=NULL WHERE id='{$id}'");
        $this->queue->markFailed($id, 'forbidden permanent', true, null, 'forbidden');

        $row = $this->fetchById($id);
        self::assertSame(QueueItemStatus::FAILED_DEAD_LETTER, $row['status']);
        self::assertSame('forbidden', $row['failure_category']);
    }

    /**
     * AC3: rescheduleAfterCircuitBreakerClose reagenda items que matcham
     * queue_name + status='failed_retry' + failure_category + next_retry_at>now.
     */
    #[RunInSeparateProcess]
    public function testRescheduleAfterCbCloseUpdatesItemsMatchingCategoryAndQueue(): void
    {
        // Insere 5 items failed_retry com next_retry_at futuro + categoria match.
        $futureRetryAt = (new \DateTimeImmutable())->modify('+45 minutes')->format('Y-m-d H:i:s');
        for ($i = 1; $i <= 5; $i++) {
            $id = "id-resched-{$i}";
            $this->pdo->exec(
                "INSERT INTO togare_queue_items "
                . "(id, queue_name, idempotency_key, payload, status, retry_count, "
                . " next_retry_at, failure_category, created_at, updated_at) "
                . "VALUES ('{$id}', 'djen', 'k-r-{$i}', '{}', 'failed_retry', 1, "
                . " '{$futureRetryAt}', 'adapter_unavailable', '2026-05-09', '2026-05-09')",
            );
        }

        $count = $this->queue->rescheduleAfterCircuitBreakerClose('djen', 'adapter_unavailable');

        self::assertSame(5, $count, '5 items devem ser reagendados');

        // Verifica que next_retry_at agora <= now (todos os 5).
        $rows = $this->pdo->query(
            "SELECT next_retry_at FROM togare_queue_items WHERE failure_category='adapter_unavailable'",
        )->fetchAll(PDO::FETCH_ASSOC);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        foreach ($rows as $row) {
            self::assertLessThanOrEqual($now, $row['next_retry_at']);
        }
    }

    /**
     * AC3: idempotência — segunda invocação consecutiva retorna 0
     * (cláusula WHERE next_retry_at > :now já não casa nada).
     */
    #[RunInSeparateProcess]
    public function testRescheduleAfterCbCloseEhIdempotente(): void
    {
        $futureRetryAt = (new \DateTimeImmutable())->modify('+45 minutes')->format('Y-m-d H:i:s');
        $this->pdo->exec(
            "INSERT INTO togare_queue_items "
            . "(id, queue_name, idempotency_key, payload, status, retry_count, "
            . " next_retry_at, failure_category, created_at, updated_at) "
            . "VALUES ('id-idem', 'djen', 'k-idem', '{}', 'failed_retry', 1, "
            . " '{$futureRetryAt}', 'adapter_unavailable', '2026-05-09', '2026-05-09')",
        );

        $first = $this->queue->rescheduleAfterCircuitBreakerClose('djen', 'adapter_unavailable');
        $second = $this->queue->rescheduleAfterCircuitBreakerClose('djen', 'adapter_unavailable');

        self::assertSame(1, $first);
        self::assertSame(0, $second, 'Segunda chamada é no-op idempotente');
    }

    /**
     * AC3: items com failure_category diferente NÃO são reagendados.
     */
    #[RunInSeparateProcess]
    public function testRescheduleAfterCbCloseNaoTocaItemsDeOutraCategoria(): void
    {
        $futureRetryAt = (new \DateTimeImmutable())->modify('+45 minutes')->format('Y-m-d H:i:s');
        $this->pdo->exec(
            "INSERT INTO togare_queue_items "
            . "(id, queue_name, idempotency_key, payload, status, retry_count, "
            . " next_retry_at, failure_category, created_at, updated_at) "
            . "VALUES ('id-license', 'djen', 'k-lic', '{}', 'failed_retry', 1, "
            . " '{$futureRetryAt}', 'license_expired', '2026-05-09', '2026-05-09')",
        );

        $count = $this->queue->rescheduleAfterCircuitBreakerClose('djen', 'adapter_unavailable');

        self::assertSame(0, $count, 'Item com categoria license_expired NÃO afetado');

        $row = $this->fetchById('id-license');
        self::assertSame($futureRetryAt, $row['next_retry_at'], 'next_retry_at preservado');
    }

    /**
     * AC3: items com status diferente de 'failed_retry' NÃO são reagendados
     * (ex.: pending, dead_letter, processing).
     */
    #[RunInSeparateProcess]
    public function testRescheduleAfterCbCloseNaoTocaItemsDeOutroStatus(): void
    {
        $futureRetryAt = (new \DateTimeImmutable())->modify('+45 minutes')->format('Y-m-d H:i:s');
        // pending + adapter_unavailable
        $this->pdo->exec(
            "INSERT INTO togare_queue_items "
            . "(id, queue_name, idempotency_key, payload, status, retry_count, "
            . " next_retry_at, failure_category, created_at, updated_at) "
            . "VALUES ('id-pending', 'djen', 'k-pend', '{}', 'pending', 0, "
            . " '{$futureRetryAt}', 'adapter_unavailable', '2026-05-09', '2026-05-09')",
        );
        // dead_letter + adapter_unavailable
        $this->pdo->exec(
            "INSERT INTO togare_queue_items "
            . "(id, queue_name, idempotency_key, payload, status, retry_count, "
            . " next_retry_at, failure_category, created_at, updated_at) "
            . "VALUES ('id-dl', 'djen', 'k-dl2', '{}', 'failed_dead_letter', 5, "
            . " '{$futureRetryAt}', 'adapter_unavailable', '2026-05-09', '2026-05-09')",
        );

        $count = $this->queue->rescheduleAfterCircuitBreakerClose('djen', 'adapter_unavailable');
        self::assertSame(0, $count);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM togare_queue_items WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}
