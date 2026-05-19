<?php

declare(strict_types=1);

namespace Tests\Unit\Espo\Modules\TogareDjen\Services;

use DateTimeImmutable;
use Espo\Modules\TogareCore\Services\QueueService;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareDjen\Services\DjenUserStateRepository;
use Espo\Modules\TogareDjen\Services\DjenWindowEnqueuer;
use PHPUnit\Framework\TestCase;

/**
 * Story 4a.1 — DjenWindowEnqueuer (AC4/AC5/AC7/AC8/AC9/AC10).
 *
 * Cobre:
 *  - Enqueue 3 advs com OAB → 3 items + last_synced_at atualizado.
 *  - Bootstrap (sem last_synced_at) usa cap D-7 (AC8).
 *  - Cap D-7 em last_synced_at antigo (AC9).
 *  - Idempotência via UNIQUE de QueueService::enqueue (AC7).
 */
final class DjenWindowEnqueuerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TogareLogger::reset();
    }

    public function testEnfileira3AdvogadosComOabComoSyncWindow(): void
    {
        $today = new DateTimeImmutable('2026-05-08');
        $advogados = [
            ['userId' => 'alice', 'oab' => '111', 'uf' => 'SP', 'lastSyncedAt' => '2026-05-07 06:00:00'],
            ['userId' => 'bob', 'oab' => '222', 'uf' => 'RJ', 'lastSyncedAt' => '2026-05-07 06:00:00'],
            ['userId' => 'charlie', 'oab' => '333', 'uf' => 'MG', 'lastSyncedAt' => '2026-05-07 06:00:00'],
        ];

        $repo = $this->createMock(DjenUserStateRepository::class);
        $repo->method('findActiveAdvogados')->willReturn($advogados);
        $repo->expects($this->exactly(3))->method('getOrCreate');
        $repo->expects($this->exactly(3))->method('updateLastSyncedAt');

        $enqueueCalls = [];
        $queue = $this->createMock(QueueService::class);
        $queue->method('enqueue')->willReturnCallback(
            function (string $queueName, array $payload, string $key) use (&$enqueueCalls): string {
                $enqueueCalls[] = ['queueName' => $queueName, 'payload' => $payload, 'key' => $key];
                return 'item-' . \count($enqueueCalls);
            }
        );

        $enqueuer = new DjenWindowEnqueuer($queue, $repo);
        $totals = $enqueuer->enqueueWindowsForAllAdvogados($today);

        $this->assertSame(['usersTotal' => 3, 'enqueued' => 3, 'skipped' => 0, 'errors' => 0], $totals);
        $this->assertCount(3, $enqueueCalls);

        foreach ($enqueueCalls as $call) {
            $this->assertSame('djen', $call['queueName']);
            $this->assertSame('sync_window', $call['payload']['type']);
            $this->assertSame('2026-05-07', $call['payload']['dataInicio']);
            $this->assertSame('2026-05-08', $call['payload']['dataFim']);
            $this->assertStringStartsWith('djen.sync.', $call['key']);
            $this->assertStringEndsWith('.2026-05-07.2026-05-08', $call['key']);
        }
    }

    public function testBootstrapSemLastSyncedAtUsaCapD7(): void
    {
        $today = new DateTimeImmutable('2026-05-08');
        $repo = $this->createMock(DjenUserStateRepository::class);
        $repo->method('findActiveAdvogados')->willReturn([
            ['userId' => 'fresh', 'oab' => '999', 'uf' => 'SP', 'lastSyncedAt' => null],
        ]);

        $captured = null;
        $queue = $this->createMock(QueueService::class);
        $queue->method('enqueue')->willReturnCallback(
            function (string $q, array $p, string $k) use (&$captured): string {
                $captured = $p;
                return 'item-1';
            }
        );

        $enqueuer = new DjenWindowEnqueuer($queue, $repo);
        $enqueuer->enqueueWindowsForAllAdvogados($today);

        $this->assertSame('2026-05-01', $captured['dataInicio'], 'Bootstrap usa today-7d (AC8)');
        $this->assertSame('2026-05-08', $captured['dataFim']);
    }

    public function testCapD7QuandoLastSyncedAtMuitoAntigo(): void
    {
        $today = new DateTimeImmutable('2026-05-08');
        $repo = $this->createMock(DjenUserStateRepository::class);
        $repo->method('findActiveAdvogados')->willReturn([
            ['userId' => 'old', 'oab' => '777', 'uf' => 'SP', 'lastSyncedAt' => '2026-04-01 06:00:00'],
        ]);

        $captured = null;
        $queue = $this->createMock(QueueService::class);
        $queue->method('enqueue')->willReturnCallback(
            function (string $q, array $p, string $k) use (&$captured): string {
                $captured = $p;
                return 'item-1';
            }
        );

        $enqueuer = new DjenWindowEnqueuer($queue, $repo);
        $enqueuer->enqueueWindowsForAllAdvogados($today);

        $this->assertSame('2026-05-01', $captured['dataInicio'], 'last_synced_at antigo capado em today-7d (AC9)');

        $events = \array_column(TogareLogger::getRecorded(), 'event');
        $this->assertContains('djen.sync.window_capped', $events);
    }

    public function testIdempotencyKeyUsaFormatoCanonico(): void
    {
        $today = new DateTimeImmutable('2026-05-08');
        $repo = $this->createMock(DjenUserStateRepository::class);
        $repo->method('findActiveAdvogados')->willReturn([
            ['userId' => 'felipe-id', 'oab' => '462034', 'uf' => 'SP', 'lastSyncedAt' => '2026-05-07 06:00:00'],
        ]);

        $capturedKey = null;
        $queue = $this->createMock(QueueService::class);
        $queue->method('enqueue')->willReturnCallback(
            function (string $q, array $p, string $k) use (&$capturedKey): string {
                $capturedKey = $k;
                return 'item-1';
            }
        );

        $enqueuer = new DjenWindowEnqueuer($queue, $repo);
        $enqueuer->enqueueWindowsForAllAdvogados($today);

        $this->assertSame('djen.sync.felipe-id.2026-05-07.2026-05-08', $capturedKey);
    }

    public function testFalhaPorUserNaoQuebraOsOutros(): void
    {
        $today = new DateTimeImmutable('2026-05-08');
        $repo = $this->createMock(DjenUserStateRepository::class);
        $repo->method('findActiveAdvogados')->willReturn([
            ['userId' => 'alice', 'oab' => '111', 'uf' => 'SP', 'lastSyncedAt' => null],
            ['userId' => 'broken', 'oab' => '222', 'uf' => 'RJ', 'lastSyncedAt' => null],
            ['userId' => 'charlie', 'oab' => '333', 'uf' => 'MG', 'lastSyncedAt' => null],
        ]);

        $queue = $this->createMock(QueueService::class);
        $queue->method('enqueue')->willReturnCallback(
            static function (string $q, array $p, string $k): string {
                if (\str_contains($k, 'broken')) {
                    throw new \RuntimeException('synthetic failure');
                }
                return 'item-' . $k;
            }
        );

        $enqueuer = new DjenWindowEnqueuer($queue, $repo);
        $totals = $enqueuer->enqueueWindowsForAllAdvogados($today);

        $this->assertSame(3, $totals['usersTotal']);
        $this->assertSame(2, $totals['enqueued']);
        $this->assertSame(0, $totals['skipped']);
        $this->assertSame(1, $totals['errors']);
    }
}
