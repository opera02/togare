<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareNextcloudBridge\Services;

use DateInterval;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareNextcloudBridge\Contracts\NextcloudClientContract;
use Espo\Modules\TogareNextcloudBridge\Exception\NextcloudFileNotFoundException;
use Espo\Modules\TogareNextcloudBridge\Services\NextcloudPurgeableStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * AC #7, #8 da Story 5.1: softPurge gera tombstoneId 32-hex + MOVE para
 * .purged/<id>/; restoreFromTombstone faz MOVE inverso + RuntimeException
 * em tombstone inexistente.
 */
final class NextcloudPurgeableStorageTest extends TestCase
{
    protected function setUp(): void
    {
        TogareLogger::reset();
    }

    public function testSoftPurgeGeraTombstoneIdRegex32Hex(): void
    {
        // createStub — não há expectations a verificar (apenas regex no return).
        $client = $this->createStub(NextcloudClientContract::class);

        $tid = (new NextcloudPurgeableStorage($client))
            ->softPurge('clientes/abc/contratos/2026-001.pdf', new DateInterval('P30D'));

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $tid);
    }

    public function testSoftPurgeFazMoveParaPurgedComOriginalPathPreservado(): void
    {
        $captured = ['source' => null, 'destination' => null];
        $client = $this->createMock(NextcloudClientContract::class);
        $client->expects($this->once())
            ->method('moveWebDav')
            ->willReturnCallback(function (string $source, string $destination) use (&$captured): void {
                $captured['source'] = $source;
                $captured['destination'] = $destination;
            });

        $tid = (new NextcloudPurgeableStorage($client))
            ->softPurge('clientes/abc/contratos/2026-001.pdf', new DateInterval('P30D'));

        $this->assertSame('clientes/abc/contratos/2026-001.pdf', $captured['source']);
        $this->assertSame(
            ".purged/{$tid}/clientes/abc/contratos/2026-001.pdf",
            $captured['destination'],
        );
    }

    public function testRestoreFromTombstoneFazMoveInversoEDeletaDirVazio(): void
    {
        $tid = 'abcdef0123456789abcdef0123456789';
        $captured = ['move' => null, 'delete' => null];

        $client = $this->createMock(NextcloudClientContract::class);
        $client->method('propfindList')
            ->with(".purged/{$tid}")
            ->willReturn(['clientes/abc/contratos/2026-001.pdf']);

        $client->expects($this->once())
            ->method('moveWebDav')
            ->willReturnCallback(function (string $source, string $destination) use (&$captured): void {
                $captured['move'] = ['source' => $source, 'destination' => $destination];
            });

        $client->expects($this->once())
            ->method('deleteWebDav')
            ->willReturnCallback(function (string $path) use (&$captured): bool {
                $captured['delete'] = $path;
                return true;
            });

        (new NextcloudPurgeableStorage($client))->restoreFromTombstone($tid);

        $this->assertSame(".purged/{$tid}/clientes/abc/contratos/2026-001.pdf", $captured['move']['source']);
        $this->assertSame('clientes/abc/contratos/2026-001.pdf', $captured['move']['destination']);
        $this->assertSame(".purged/{$tid}", $captured['delete']);
    }

    public function testRestoreFromTombstonePercorreDiretoriosAteArquivoLeaf(): void
    {
        $tid = 'abcdef0123456789abcdef0123456789';
        $captured = ['move' => null, 'delete' => null];

        $client = $this->createMock(NextcloudClientContract::class);
        $client->method('propfindList')
            ->willReturnCallback(function (string $path) use ($tid): array {
                return match ($path) {
                    ".purged/{$tid}" => ['clientes/'],
                    ".purged/{$tid}/clientes" => ['abc/'],
                    ".purged/{$tid}/clientes/abc" => ['contratos/'],
                    ".purged/{$tid}/clientes/abc/contratos" => ['2026-001.pdf'],
                    default => [],
                };
            });

        $client->expects($this->once())
            ->method('moveWebDav')
            ->willReturnCallback(function (string $source, string $destination) use (&$captured): void {
                $captured['move'] = ['source' => $source, 'destination' => $destination];
            });

        $client->expects($this->once())
            ->method('deleteWebDav')
            ->willReturnCallback(function (string $path) use (&$captured): bool {
                $captured['delete'] = $path;
                return true;
            });

        (new NextcloudPurgeableStorage($client))->restoreFromTombstone($tid);

        $this->assertSame(".purged/{$tid}/clientes/abc/contratos/2026-001.pdf", $captured['move']['source']);
        $this->assertSame('clientes/abc/contratos/2026-001.pdf', $captured['move']['destination']);
        $this->assertSame(".purged/{$tid}", $captured['delete']);
    }

    public function testRestoreFromTombstoneInexistenteLancaRuntimeException(): void
    {
        $tid = 'abcdef0123456789abcdef0123456789';
        $client = $this->createStub(NextcloudClientContract::class);
        $client->method('propfindList')
            ->willThrowException(new NextcloudFileNotFoundException(".purged/{$tid}"));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Tombstone .* não existe ou já foi purgado/');
        (new NextcloudPurgeableStorage($client))->restoreFromTombstone($tid);
    }

    public function testRestoreFromTombstoneIdInvalidoLancaRuntimeException(): void
    {
        $client = $this->createStub(NextcloudClientContract::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/tombstoneId inválido/');
        (new NextcloudPurgeableStorage($client))->restoreFromTombstone('xyz-not-hex');
    }
}
