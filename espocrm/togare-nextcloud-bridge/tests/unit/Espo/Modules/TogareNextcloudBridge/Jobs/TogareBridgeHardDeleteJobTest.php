<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareNextcloudBridge\Jobs;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Job\Job\Data;
use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareNextcloudBridge\Contracts\NextcloudClientContract;
use Espo\Modules\TogareNextcloudBridge\Exception\NextcloudUnavailableException;
use Espo\Modules\TogareNextcloudBridge\Jobs\TogareBridgeHardDeleteJob;
use Espo\ORM\EntityManager;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Story 5.5 — cobre TogareBridgeHardDeleteJob (cron 0 3 * * *).
 *
 * Estratégia: PDO sqlite::memory: real com schema togare_documento_log
 * (replicado da Migration V018 — SQLite-compatible) → permite SELECT/INSERT
 * reais. Mocks PHPUnit para NextcloudClientContract (controlar
 * deleteWebDav happy/404/unavail) e AuditLogContract.
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class TogareBridgeHardDeleteJobTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        TogareLogger::reset();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE togare_documento_log (
                id VARCHAR(32) NOT NULL PRIMARY KEY,
                event VARCHAR(50) NOT NULL,
                documento_id VARCHAR(17) NULL,
                user_id VARCHAR(17) NULL,
                payload TEXT NULL,
                created_at DATETIME NOT NULL
            )'
        );
    }

    public function testRunSemCandidatosLogaBatchStartEnd(): void
    {
        $client = $this->createMock(NextcloudClientContract::class);
        $client->expects(self::never())->method('deleteWebDav');

        $audit = $this->createMock(AuditLogContract::class);
        $audit->expects(self::never())->method('log');

        $this->makeJob($client, $audit)->run(Data::create());

        $events = TogareLogger::getRecorded();
        self::assertNotEmpty($this->filterEventName($events, 'documento.hard_delete_batch_start'));
        $end = $this->filterEventName($events, 'documento.hard_delete_batch_end');
        self::assertCount(1, $end);
        $context = $end[0]['context'] ?? [];
        self::assertSame(0, $context['ok'] ?? -1);
        self::assertSame(0, $context['skipped_404'] ?? -1);
        self::assertFalse($context['interrupted'] ?? null);
    }

    public function testHardDeleteOkGravaRowEAuditLog(): void
    {
        $tid = $this->makeTombstoneId(1);
        $this->insertSoftPurged('soft-1', 'doc-1', $tid, 'processos/p-1/doc-1-foo.pdf', $this->isoDaysFromNow(-1));

        $client = $this->createMock(NextcloudClientContract::class);
        $client->method('deleteWebDav')
            ->willReturnCallback(function (string $path) use ($tid): bool {
                if ($path === ".purged/{$tid}/processos/p-1/doc-1-foo.pdf") {
                    return true;
                }
                // .purged/<tid> cleanup — não-bloqueante, retorna true
                return true;
            });

        $audit = $this->createMock(AuditLogContract::class);
        $auditCalls = [];
        $audit->method('log')->willReturnCallback(
            function (string $event, string $entityType, ?string $entityId, array $context) use (&$auditCalls): void {
                $auditCalls[] = compact('event', 'entityType', 'entityId', 'context');
            },
        );

        $this->makeJob($client, $audit)->run(Data::create());

        // Row hard_deleted gravada
        $hardRows = $this->fetchAllByEvent('documento.hard_deleted');
        self::assertCount(1, $hardRows);
        $payload = \json_decode((string) $hardRows[0]['payload'], true);
        self::assertSame($tid, $payload['tombstoneId']);
        self::assertSame('processos/p-1/doc-1-foo.pdf', $payload['logicalPath']);
        self::assertArrayHasKey('hardDeletedAt', $payload);
        self::assertArrayHasKey('durationDays', $payload);

        // AuditLog chamado 1x
        self::assertCount(1, $auditCalls);
        self::assertSame('documento.hard_deleted', $auditCalls[0]['event']);
        self::assertSame('Documento', $auditCalls[0]['entityType']);
        self::assertSame('doc-1', $auditCalls[0]['entityId']);
        self::assertSame('nextcloud', $auditCalls[0]['context']['storage']);
        self::assertSame('ok', $auditCalls[0]['context']['status']);
    }

    public function testDeleteWebDav404IdempotenteGravaRowHardDeletedComStatusSkipped404(): void
    {
        $tid = $this->makeTombstoneId(2);
        $this->insertSoftPurged('soft-2', 'doc-2', $tid, 'clientes/c-1/contrato.pdf', $this->isoDaysFromNow(-1));

        $client = $this->createMock(NextcloudClientContract::class);
        $client->method('deleteWebDav')->willReturn(false); // 404 idempotente

        $audit = $this->createMock(AuditLogContract::class);
        $audit->method('log');

        $this->makeJob($client, $audit)->run(Data::create());

        $hardRows = $this->fetchAllByEvent('documento.hard_deleted');
        self::assertCount(1, $hardRows, 'row hard_deleted gravada mesmo em 404 (idempotência interna)');

        $events = TogareLogger::getRecorded();
        $warning = $this->filterEventName($events, 'documento.hard_delete_skipped_404');
        self::assertCount(1, $warning);
        self::assertSame('warning', $warning[0]['level'] ?? null);

        $end = $this->filterEventName($events, 'documento.hard_delete_batch_end');
        self::assertSame(1, ($end[0]['context'] ?? [])['skipped_404'] ?? -1);
    }

    public function testTombstoneJaHardDeletadoSkipSemChamarDeleteWebDav(): void
    {
        $tid = $this->makeTombstoneId(3);
        $this->insertSoftPurged('soft-3', 'doc-3', $tid, 'processos/p-1/doc.pdf', $this->isoDaysFromNow(-2));
        $this->insertHardDeleted('hard-3', 'doc-3', $tid, 'processos/p-1/doc.pdf');

        $client = $this->createMock(NextcloudClientContract::class);
        $client->expects(self::never())->method('deleteWebDav');

        $audit = $this->createMock(AuditLogContract::class);
        $audit->expects(self::never())->method('log');

        $this->makeJob($client, $audit)->run(Data::create());

        // Não duplica row — só a row pre-existente.
        self::assertCount(1, $this->fetchAllByEvent('documento.hard_deleted'));

        $end = $this->filterEventName(TogareLogger::getRecorded(), 'documento.hard_delete_batch_end');
        self::assertSame(0, ($end[0]['context'] ?? [])['ok'] ?? -1);
        self::assertSame(0, ($end[0]['context'] ?? [])['already_done'] ?? -1);
    }

    public function testRowsHardDeletedAntigasNaoPrendemBatchENovaDueEhProcessada(): void
    {
        for ($i = 1; $i <= 201; $i++) {
            $tid = $this->makeTombstoneId(100 + $i);
            $this->insertSoftPurged('soft-old-' . $i, 'doc-old-' . $i, $tid, 'old/' . $i . '.pdf', $this->isoDaysFromNow(-10));
            $this->insertHardDeleted('hard-old-' . $i, 'doc-old-' . $i, $tid, 'old/' . $i . '.pdf');
        }

        $dueTid = $this->makeTombstoneId(500);
        $this->insertSoftPurged(
            'soft-new-due',
            'doc-new-due',
            $dueTid,
            'new/due.pdf',
            $this->isoDaysFromNow(-1),
            createdOffsetSec: 50,
        );

        $deletedPaths = [];
        $client = $this->createMock(NextcloudClientContract::class);
        $client->method('deleteWebDav')
            ->willReturnCallback(function (string $path) use (&$deletedPaths): bool {
                $deletedPaths[] = $path;
                return true;
            });

        $audit = $this->createMock(AuditLogContract::class);
        $audit->method('log');

        $this->makeJob($client, $audit)->run(Data::create());

        self::assertNotEmpty($deletedPaths);
        self::assertSame(".purged/{$dueTid}/new/due.pdf", $deletedPaths[0]);
        self::assertCount(202, $this->fetchAllByEvent('documento.hard_deleted'));
    }

    public function testBatchNaoExecutaMaisDe100HardDeletes(): void
    {
        for ($i = 1; $i <= 101; $i++) {
            $tid = $this->makeTombstoneId(700 + $i);
            $this->insertSoftPurged(
                'soft-limit-' . $i,
                'doc-limit-' . $i,
                $tid,
                'limit/' . $i . '.pdf',
                $this->isoDaysFromNow(-1),
            );
        }

        $client = $this->createMock(NextcloudClientContract::class);
        $client->method('deleteWebDav')->willReturn(true);

        $audit = $this->createMock(AuditLogContract::class);
        $audit->method('log');

        $this->makeJob($client, $audit)->run(Data::create());

        self::assertCount(100, $this->fetchAllByEvent('documento.hard_deleted'));

        $end = $this->filterEventName(TogareLogger::getRecorded(), 'documento.hard_delete_batch_end');
        self::assertSame(100, ($end[0]['context'] ?? [])['ok'] ?? -1);
    }

    public function testNextcloudUnavailableInterrompeBatchECountRemaining(): void
    {
        $tid1 = $this->makeTombstoneId(4);
        $tid2 = $this->makeTombstoneId(5);
        $tid3 = $this->makeTombstoneId(6);

        $this->insertSoftPurged('soft-4', 'doc-4', $tid1, 'a/1.pdf', $this->isoDaysFromNow(-1));
        // espaça created_at para garantir ordem FIFO determinística
        $this->insertSoftPurged('soft-5', 'doc-5', $tid2, 'a/2.pdf', $this->isoDaysFromNow(-1), createdOffsetSec: 1);
        $this->insertSoftPurged('soft-6', 'doc-6', $tid3, 'a/3.pdf', $this->isoDaysFromNow(-1), createdOffsetSec: 2);

        $client = $this->createMock(NextcloudClientContract::class);
        $callCount = 0;
        $client->method('deleteWebDav')
            ->willReturnCallback(function () use (&$callCount): bool {
                $callCount++;
                if ($callCount === 1) {
                    return true; // doc-4 OK
                }
                // doc-5 dispara Nextcloud unavail
                throw new NextcloudUnavailableException(['cause' => 'cb_opened']);
            });

        $audit = $this->createMock(AuditLogContract::class);
        $audit->method('log');

        $this->makeJob($client, $audit)->run(Data::create());

        // Apenas 1 row hard_deleted (doc-4); doc-5 e doc-6 não foram processados.
        $hardRows = $this->fetchAllByEvent('documento.hard_deleted');
        self::assertCount(1, $hardRows);
        $payload = \json_decode((string) $hardRows[0]['payload'], true);
        self::assertSame($tid1, $payload['tombstoneId']);

        $events = TogareLogger::getRecorded();
        $unavail = $this->filterEventName($events, 'documento.hard_delete_unavailable');
        self::assertCount(1, $unavail);
        self::assertSame(2, ($unavail[0]['context'] ?? [])['remainingInBatch'] ?? -1);

        $end = $this->filterEventName($events, 'documento.hard_delete_batch_end');
        self::assertTrue(($end[0]['context'] ?? [])['interrupted'] ?? false);
        self::assertSame(2, ($end[0]['context'] ?? [])['remaining'] ?? -1);
    }

    public function testPayloadMalformedSkipBatchContinua(): void
    {
        // soft-7: payload {} → malformed
        $this->insertRawSoftPurgedRow('soft-7', 'doc-7', '{}', $this->nowMinusSeconds(2));
        // soft-8: payload válido
        $tid = $this->makeTombstoneId(7);
        $this->insertSoftPurged('soft-8', 'doc-8', $tid, 'x/y.pdf', $this->isoDaysFromNow(-1), createdOffsetSec: 1);

        $client = $this->createMock(NextcloudClientContract::class);
        $client->method('deleteWebDav')->willReturn(true);

        $audit = $this->createMock(AuditLogContract::class);
        $audit->method('log');

        $this->makeJob($client, $audit)->run(Data::create());

        // doc-8 processado (continuou após o malformed)
        $hardRows = $this->fetchAllByEvent('documento.hard_deleted');
        self::assertCount(1, $hardRows);

        $events = TogareLogger::getRecorded();
        $malformed = $this->filterEventName($events, 'documento.hard_delete_payload_malformed');
        self::assertCount(1, $malformed);
        self::assertSame('error', $malformed[0]['level'] ?? null);
        self::assertSame('soft-7', ($malformed[0]['context'] ?? [])['documentoLogId'] ?? null);

        $end = $this->filterEventName($events, 'documento.hard_delete_batch_end');
        self::assertSame(1, ($end[0]['context'] ?? [])['ok'] ?? -1);
        self::assertSame(1, ($end[0]['context'] ?? [])['payload_malformed'] ?? -1);
    }

    public function testNotYetDueSkipSemLogEnemDeleteWebDav(): void
    {
        // hardDeleteAt no futuro → ainda não due
        $tid = $this->makeTombstoneId(8);
        $this->insertSoftPurged('soft-9', 'doc-9', $tid, 'z/w.pdf', $this->isoDaysFromNow(+5));

        $client = $this->createMock(NextcloudClientContract::class);
        $client->expects(self::never())->method('deleteWebDav');

        $audit = $this->createMock(AuditLogContract::class);
        $audit->expects(self::never())->method('log');

        $this->makeJob($client, $audit)->run(Data::create());

        self::assertCount(0, $this->fetchAllByEvent('documento.hard_deleted'));

        $end = $this->filterEventName(TogareLogger::getRecorded(), 'documento.hard_delete_batch_end');
        self::assertSame(0, ($end[0]['context'] ?? [])['ok'] ?? -1);
    }

    public function testBatchFifoOrdemAscPorCreatedAt(): void
    {
        // Insere FORA de ordem temporal pra garantir que o SELECT ORDER BY funciona
        $tidB = $this->makeTombstoneId(10);
        $tidA = $this->makeTombstoneId(11);

        // B é mais novo (created_at +2s); A é mais velho (created_at 0)
        $this->insertSoftPurged('soft-B', 'doc-B', $tidB, 'b/file.pdf', $this->isoDaysFromNow(-1), createdOffsetSec: 2);
        $this->insertSoftPurged('soft-A', 'doc-A', $tidA, 'a/file.pdf', $this->isoDaysFromNow(-1), createdOffsetSec: 0);

        $orderObserved = [];
        $client = $this->createMock(NextcloudClientContract::class);
        $client->method('deleteWebDav')
            ->willReturnCallback(function (string $path) use (&$orderObserved): bool {
                $orderObserved[] = $path;
                return true;
            });

        $audit = $this->createMock(AuditLogContract::class);
        $audit->method('log');

        $this->makeJob($client, $audit)->run(Data::create());

        // A primeira call deve ser a do tombstone A (mais velho)
        self::assertNotEmpty($orderObserved);
        self::assertStringContainsString($tidA, $orderObserved[0], 'FIFO: tombstone mais antigo processado primeiro');
        self::assertStringContainsString($tidB, $orderObserved[2] ?? '', 'segundo file delete deve ser do B (3a call = 1o file de B)');
    }

    public function testTombstoneIdInvalido32HexEhTratadoComoPayloadMalformed(): void
    {
        $this->insertRawSoftPurgedRow(
            'soft-bad',
            'doc-bad',
            \json_encode([
                'tombstoneId' => 'xyz-not-hex-32',
                'logicalPath' => 'x/y.pdf',
                'hardDeleteAt' => $this->isoDaysFromNow(-1),
            ]) ?: '{}',
            $this->nowMinusSeconds(1),
        );

        $client = $this->createMock(NextcloudClientContract::class);
        $client->expects(self::never())->method('deleteWebDav');

        $audit = $this->createMock(AuditLogContract::class);
        $audit->expects(self::never())->method('log');

        $this->makeJob($client, $audit)->run(Data::create());

        $events = TogareLogger::getRecorded();
        $malformed = $this->filterEventName($events, 'documento.hard_delete_payload_malformed');
        self::assertCount(1, $malformed);
    }

    // ============================================================
    // Helpers
    // ============================================================

    private function makeJob(NextcloudClientContract $client, AuditLogContract $audit): TogareBridgeHardDeleteJob
    {
        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willReturn($this->pdo);

        return new TogareBridgeHardDeleteJob($em, $client, $audit);
    }

    private function makeTombstoneId(int $seed): string
    {
        return \str_pad(\dechex($seed), 32, '0', STR_PAD_LEFT);
    }

    private function isoDaysFromNow(int $days): string
    {
        return (new DateTimeImmutable("now", new DateTimeZone('UTC')))
            ->modify(($days >= 0 ? '+' : '') . $days . ' days')
            ->format(\DateTimeInterface::ATOM);
    }

    private function nowMinusSeconds(int $sec): string
    {
        return (new DateTimeImmutable("now", new DateTimeZone('UTC')))
            ->modify('-' . $sec . ' seconds')
            ->format('Y-m-d H:i:s');
    }

    /**
     * Insere row event=documento.soft_purged em togare_documento_log com payload
     * estruturado.
     */
    private function insertSoftPurged(
        string $id,
        string $documentoId,
        string $tombstoneId,
        string $logicalPath,
        string $hardDeleteAtIso,
        int $createdOffsetSec = 0,
    ): void {
        $payload = \json_encode([
            'tombstoneId' => $tombstoneId,
            'logicalPath' => $logicalPath,
            'hardDeleteAt' => $hardDeleteAtIso,
            'retentionDays' => 30,
        ]) ?: '{}';

        $stmt = $this->pdo->prepare(
            'INSERT INTO togare_documento_log (id, event, documento_id, user_id, payload, created_at)
             VALUES (:id, :event, :documento_id, NULL, :payload, :created_at)'
        );
        $stmt->execute([
            ':id' => $id,
            ':event' => 'documento.soft_purged',
            ':documento_id' => $documentoId,
            ':payload' => $payload,
            ':created_at' => $this->nowMinusSeconds(60 - $createdOffsetSec), // oldest first when offset=0
        ]);
    }

    private function insertRawSoftPurgedRow(string $id, string $documentoId, string $rawPayload, string $createdAt): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO togare_documento_log (id, event, documento_id, user_id, payload, created_at)
             VALUES (:id, :event, :documento_id, NULL, :payload, :created_at)'
        );
        $stmt->execute([
            ':id' => $id,
            ':event' => 'documento.soft_purged',
            ':documento_id' => $documentoId,
            ':payload' => $rawPayload,
            ':created_at' => $createdAt,
        ]);
    }

    private function insertHardDeleted(
        string $id,
        string $documentoId,
        string $tombstoneId,
        string $logicalPath,
    ): void {
        $payload = \json_encode([
            'tombstoneId' => $tombstoneId,
            'logicalPath' => $logicalPath,
            'hardDeletedAt' => (new DateTimeImmutable("now", new DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            'durationDays' => 30,
        ]) ?: '{}';

        $stmt = $this->pdo->prepare(
            'INSERT INTO togare_documento_log (id, event, documento_id, user_id, payload, created_at)
             VALUES (:id, :event, :documento_id, NULL, :payload, :created_at)'
        );
        $stmt->execute([
            ':id' => $id,
            ':event' => 'documento.hard_deleted',
            ':documento_id' => $documentoId,
            ':payload' => $payload,
            ':created_at' => $this->nowMinusSeconds(10),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAllByEvent(string $event): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM togare_documento_log WHERE event = :e');
        $stmt->execute([':e' => $event]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return $rows;
    }

    /**
     * @param list<array{event: string, severity: string, message: string, context: array<string, mixed>}> $events
     * @return list<array{event: string, severity: string, message: string, context: array<string, mixed>}>
     */
    private function filterEventName(array $events, string $eventName): array
    {
        return \array_values(\array_filter(
            $events,
            static fn (array $e): bool => ($e['event'] ?? null) === $eventName,
        ));
    }
}
