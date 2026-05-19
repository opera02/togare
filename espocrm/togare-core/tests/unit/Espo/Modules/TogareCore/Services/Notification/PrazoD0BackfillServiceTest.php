<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Services\Notification;

use DateTimeImmutable;
use Espo\Modules\TogareCore\Migration\V016__create_togare_prazo_lembrete;
use Espo\Modules\TogareCore\Services\Notification\PrazoD0BackfillService;
use Espo\Modules\TogareCore\Services\Notification\PrazoLembreteConstants;
use PDO;
use PHPUnit\Framework\TestCase;

final class PrazoD0BackfillServiceTest extends TestCase
{
    private PDO $pdo;
    private PrazoD0BackfillService $service;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        (new V016__create_togare_prazo_lembrete())->up($this->pdo);
        $this->pdo->exec('CREATE TABLE prazo (
            id VARCHAR(24) NOT NULL PRIMARY KEY,
            status VARCHAR(32) NOT NULL,
            data_fatal DATE NOT NULL,
            deleted INT NOT NULL DEFAULT 0
        )');

        $this->service = new PrazoD0BackfillService();
    }

    public function testBackfillCriaD0ParaParesComLembretesExistentes(): void
    {
        $this->seedPrazo('prazo-001', 'pendente', '2026-06-01');
        $this->seedExistingLembrete('prazo-001', 'adv-001', PrazoLembreteConstants::MARCO_D7);
        $this->seedExistingLembrete('prazo-001', 'socio-001', PrazoLembreteConstants::MARCO_D1);

        $inserted = $this->service->backfill(
            $this->pdo,
            new DateTimeImmutable('2026-05-09 12:00:00', new \DateTimeZone('UTC')),
        );

        self::assertSame(2, $inserted);

        $rows = $this->fetchD0Rows('prazo-001');
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame('D-0', $row['marco']);
            self::assertSame('both', $row['canal']);
            self::assertSame('pending', $row['status']);
            self::assertSame('2026-06-01 03:05:00', $row['scheduled_for']);
        }
    }

    public function testBackfillEhIdempotente(): void
    {
        $now = new DateTimeImmutable('2026-05-09 12:00:00', new \DateTimeZone('UTC'));
        $this->seedPrazo('prazo-001', 'pendente', '2026-06-01');
        $this->seedExistingLembrete('prazo-001', 'adv-001', PrazoLembreteConstants::MARCO_D3);

        self::assertSame(1, $this->service->backfill($this->pdo, $now));
        self::assertSame(0, $this->service->backfill($this->pdo, $now));
        self::assertCount(1, $this->fetchD0Rows('prazo-001'));
    }

    public function testBackfillNaoCriaD0ParaDataFatalPassada(): void
    {
        $this->seedPrazo('prazo-001', 'pendente', '2026-05-08');
        $this->seedExistingLembrete('prazo-001', 'adv-001', PrazoLembreteConstants::MARCO_D1);

        $inserted = $this->service->backfill(
            $this->pdo,
            new DateTimeImmutable('2026-05-09 12:00:00', new \DateTimeZone('UTC')),
        );

        self::assertSame(0, $inserted);
        self::assertCount(0, $this->fetchD0Rows('prazo-001'));
    }

    public function testBackfillParaHojeDepoisDe0005AgendaProcessamentoImediato(): void
    {
        $now = new DateTimeImmutable('2026-06-01 12:34:00', new \DateTimeZone('UTC'));
        $this->seedPrazo('prazo-001', 'pendente', '2026-06-01');
        $this->seedExistingLembrete('prazo-001', 'adv-001', PrazoLembreteConstants::MARCO_D1);

        self::assertSame(1, $this->service->backfill($this->pdo, $now));

        $rows = $this->fetchD0Rows('prazo-001');
        self::assertCount(1, $rows);
        self::assertSame('2026-06-01 12:34:00', $rows[0]['scheduled_for']);
    }

    public function testBackfillPulaStatusFinalEDeleted(): void
    {
        $now = new DateTimeImmutable('2026-05-09 12:00:00', new \DateTimeZone('UTC'));
        $this->seedPrazo('prazo-final', 'protocolado', '2026-06-01');
        $this->seedPrazo('prazo-deleted', 'pendente', '2026-06-01', deleted: 1);
        $this->seedExistingLembrete('prazo-final', 'adv-001', PrazoLembreteConstants::MARCO_D1);
        $this->seedExistingLembrete('prazo-deleted', 'adv-001', PrazoLembreteConstants::MARCO_D1);

        self::assertSame(0, $this->service->backfill($this->pdo, $now));
        self::assertCount(0, $this->fetchD0Rows('prazo-final'));
        self::assertCount(0, $this->fetchD0Rows('prazo-deleted'));
    }

    private function seedPrazo(string $id, string $status, string $dataFatal, int $deleted = 0): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO prazo (id, status, data_fatal, deleted) VALUES (:id, :status, :data_fatal, :deleted)');
        $stmt->execute([
            ':id' => $id,
            ':status' => $status,
            ':data_fatal' => $dataFatal,
            ':deleted' => $deleted,
        ]);
    }

    private function seedExistingLembrete(string $prazoId, string $userId, string $marco): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO togare_prazo_lembrete
            (id, prazo_id, user_id, marco, canal, scheduled_for, status, attempt_count, created_at, modified_at)
            VALUES (:id, :prazo_id, :user_id, :marco, :canal, :scheduled_for, :status, 0, :created_at, :modified_at)');
        $stmt->execute([
            ':id' => \bin2hex(\random_bytes(12)),
            ':prazo_id' => $prazoId,
            ':user_id' => $userId,
            ':marco' => $marco,
            ':canal' => PrazoLembreteConstants::CANAL_BOTH,
            ':scheduled_for' => '2026-05-01 12:00:00',
            ':status' => PrazoLembreteConstants::STATUS_SENT,
            ':created_at' => '2026-05-01 12:00:00',
            ':modified_at' => '2026-05-01 12:00:00',
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchD0Rows(string $prazoId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM togare_prazo_lembrete WHERE prazo_id = :pid AND marco = :marco ORDER BY user_id');
        $stmt->execute([':pid' => $prazoId, ':marco' => PrazoLembreteConstants::MARCO_D0]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows === false ? [] : $rows;
    }
}
