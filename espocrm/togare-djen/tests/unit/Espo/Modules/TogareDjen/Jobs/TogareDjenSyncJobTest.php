<?php

declare(strict_types=1);

namespace Tests\Unit\Espo\Modules\TogareDjen\Jobs;

use Espo\Core\Job\Job\Data;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareDjen\Jobs\TogareDjenSyncJob;
use Espo\Modules\TogareDjen\Services\DjenWindowEnqueuer;
use Espo\Modules\TogareLicensing\Service\ReadOnlyGate as LicenseReadOnlyGate;
use PHPUnit\Framework\TestCase;

/**
 * Story 4a.1 — TogareDjenSyncJob (AC4/AC5/AC14).
 *
 * Cobre:
 *  - Roda enqueuer quando licença OK.
 *  - Skipa quando ReadOnlyGate.isBlocked('togare-djen')=true (AC5).
 *  - Não relança em caso de Throwable inesperado (cron deve continuar).
 */
final class TogareDjenSyncJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TogareLogger::reset();

        // Stub class para LicenseReadOnlyGate caso o togare-licensing não
        // esteja autoloaded em testes standalone (mock direto via PHPUnit
        // exige a classe existir).
        if (! \class_exists(LicenseReadOnlyGate::class, false)) {
            eval('namespace Espo\\Modules\\TogareLicensing\\Service { class ReadOnlyGate { public function isBlocked(string $module): bool { return false; } } }');
        }
    }

    public function testRodaEnqueuerQuandoLicencaOk(): void
    {
        $enqueuer = $this->createMock(DjenWindowEnqueuer::class);
        $enqueuer->expects($this->once())
            ->method('enqueueWindowsForAllAdvogados')
            ->willReturn(['usersTotal' => 5, 'enqueued' => 5, 'skipped' => 0, 'errors' => 0]);

        $gate = $this->createMock(LicenseReadOnlyGate::class);
        $gate->method('isBlocked')->with('togare-djen')->willReturn(false);

        $job = new TogareDjenSyncJob($enqueuer, $gate);
        $job->run(Data::create([]));

        $events = \array_column(TogareLogger::getRecorded(), 'event');
        $this->assertContains('djen.sync.started', $events);
        $this->assertContains('djen.sync.completed', $events);
        $this->assertNotContains('djen.sync.skipped_license_expired', $events);
    }

    public function testSkipaQuandoLicencaEmReadOnly(): void
    {
        $enqueuer = $this->createMock(DjenWindowEnqueuer::class);
        $enqueuer->expects($this->never())->method('enqueueWindowsForAllAdvogados');

        $gate = $this->createMock(LicenseReadOnlyGate::class);
        $gate->method('isBlocked')->with('togare-djen')->willReturn(true);

        $job = new TogareDjenSyncJob($enqueuer, $gate);
        $job->run(Data::create([]));

        $events = \array_column(TogareLogger::getRecorded(), 'event');
        $this->assertContains('djen.sync.skipped_license_expired', $events);
        $this->assertNotContains('djen.sync.completed', $events);
    }

    public function testNaoRelancaEmCasoDeExceptionInesperada(): void
    {
        $enqueuer = $this->createMock(DjenWindowEnqueuer::class);
        $enqueuer->method('enqueueWindowsForAllAdvogados')
            ->willThrowException(new \RuntimeException('catastrophic'));

        $gate = $this->createMock(LicenseReadOnlyGate::class);
        $gate->method('isBlocked')->willReturn(false);

        $job = new TogareDjenSyncJob($enqueuer, $gate);

        try {
            $job->run(Data::create([]));
            $this->assertTrue(true, 'Job DEVE engolir exception (cron deve continuar agendando)');
        } catch (\Throwable $e) {
            $this->fail('Job não deve relançar exception — cron deve continuar agendando');
        }

        $events = \array_column(TogareLogger::getRecorded(), 'event');
        $this->assertContains('djen.sync.job_failed', $events);
    }
}
