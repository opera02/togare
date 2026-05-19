<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Services\Health;

use Espo\Modules\TogareCore\Contracts\ValueObject\HealthCheckResult;
use Espo\Modules\TogareCore\Services\Health\BackupHealthProvider;
use PHPUnit\Framework\TestCase;

/**
 * Story 10.2 / AC2 — tile Backup. Espelha o limiar 26h de healthcheck.sh.
 */
final class BackupHealthProviderTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = \sys_get_temp_dir() . '/togare-backup-test-' . \bin2hex(\random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        if (\is_file($this->tmp)) {
            @\unlink($this->tmp);
        }
    }

    public function testNomeDoProvider(): void
    {
        self::assertSame('backup', (new BackupHealthProvider($this->tmp))->name());
    }

    public function testSentinelaAusenteEhAmareloNaoVermelho(): void
    {
        // Instalação nova: nunca rodou ≠ falhou. AC2 — calmo, não alarme.
        $r = (new BackupHealthProvider('/caminho/inexistente/last-success.json'))->check();

        self::assertSame(HealthCheckResult::STATUS_DEGRADED, $r->status);
        self::assertSame('Backup ainda não rodou. Ver log.', $r->message);
        self::assertNotSame(HealthCheckResult::STATUS_UNHEALTHY, $r->status);
    }

    public function testBackupRecenteEhVerde(): void
    {
        $ts = (new \DateTimeImmutable('-2 hours'))->format('c');
        \file_put_contents($this->tmp, \json_encode(['timestamp' => $ts]));

        $r = (new BackupHealthProvider($this->tmp))->check();

        self::assertSame(HealthCheckResult::STATUS_HEALTHY, $r->status);
        self::assertStringContainsString('Último backup há', $r->message);
    }

    public function testBackupMinutosFormataEmMinutos(): void
    {
        $ts = (new \DateTimeImmutable('-10 minutes'))->format('c');
        \file_put_contents($this->tmp, \json_encode(['timestamp' => $ts]));

        $r = (new BackupHealthProvider($this->tmp))->check();

        self::assertSame(HealthCheckResult::STATUS_HEALTHY, $r->status);
        self::assertStringContainsString('min', $r->message);
    }

    public function testBackupStaleAcimaDe26hEhVermelhoComVerLog(): void
    {
        $ts = (new \DateTimeImmutable('-30 hours'))->format('c');
        \file_put_contents($this->tmp, \json_encode(['timestamp' => $ts]));

        $r = (new BackupHealthProvider($this->tmp))->check();

        self::assertSame(HealthCheckResult::STATUS_UNHEALTHY, $r->status);
        self::assertStringContainsString('Backup atrasado', $r->message);
        self::assertStringContainsString('Ver log.', $r->message);
    }

    public function testSentinelaInvalidaEhAmareloNaoVermelho(): void
    {
        // JSON sem 'timestamp' ≡ "ainda não rodou" — não é falha confirmada (AC2).
        \file_put_contents($this->tmp, '{"naoTemTimestamp": true}');

        $r = (new BackupHealthProvider($this->tmp))->check();

        self::assertSame(HealthCheckResult::STATUS_DEGRADED, $r->status);
        self::assertSame('Backup ainda não rodou. Ver log.', $r->message);
        self::assertNotSame(HealthCheckResult::STATUS_UNHEALTHY, $r->status);
    }

    public function testLimiarConstanteEh26(): void
    {
        self::assertSame(26, BackupHealthProvider::THRESHOLD_HOURS);
    }
}
