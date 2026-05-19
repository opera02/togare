<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareNextcloudBridge\Services;

use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareNextcloudBridge\Contracts\NextcloudClientContract;
use Espo\Modules\TogareNextcloudBridge\Exception\NextcloudFileNotFoundException;
use Espo\Modules\TogareNextcloudBridge\Services\NextcloudFileStorage;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * AC #3, #4, #5, #6 da Story 5.1: NextcloudFileStorage delega para
 * NextcloudClientContract + propaga 404 como RuntimeException + idempotência.
 */
final class NextcloudFileStorageTest extends TestCase
{
    protected function setUp(): void
    {
        TogareLogger::reset();
    }

    public function testPutDelegaPutWebDavEEmiteLog(): void
    {
        $client = $this->createMock(NextcloudClientContract::class);
        $client->expects($this->once())
            ->method('putWebDav')
            ->with('clientes/abc/contratos/2026-001.pdf', 'binary');

        (new NextcloudFileStorage($client))->put('clientes/abc/contratos/2026-001.pdf', 'binary');

        $events = TogareLogger::getRecorded();
        $putEvents = \array_filter($events, fn ($e) => $e['event'] === 'nextcloud.storage.put');
        $this->assertCount(1, $putEvents);
        $this->assertSame(6, $putEvents[\array_key_first($putEvents)]['context']['sizeBytes']);
    }

    public function testGetDelegaGetWebDavEPropagar404ComoRuntimeException(): void
    {
        // createStub (não createMock) — não há expectations a verificar.
        $client = $this->createStub(NextcloudClientContract::class);
        $client->method('getWebDav')
            ->willThrowException(new NextcloudFileNotFoundException('clientes/abc/missing.pdf'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Arquivo não encontrado');
        (new NextcloudFileStorage($client))->get('clientes/abc/missing.pdf');
    }

    public function testExistsDelegaSemException(): void
    {
        $client = $this->createStub(NextcloudClientContract::class);
        $client->method('existsWebDav')->willReturn(true);

        $this->assertTrue((new NextcloudFileStorage($client))->exists('clientes/abc/2026-001.pdf'));
    }

    public function testDeleteDelegaToleraIdempotencia(): void
    {
        $client = $this->createMock(NextcloudClientContract::class);
        $client->expects($this->exactly(2))
            ->method('deleteWebDav')
            ->with('clientes/abc/2026-001.pdf')
            ->willReturnOnConsecutiveCalls(true, false);

        $storage = new NextcloudFileStorage($client);
        $storage->delete('clientes/abc/2026-001.pdf');
        $storage->delete('clientes/abc/2026-001.pdf'); // 2ª chamada idempotente

        $deleteEvents = \array_values(\array_filter(
            TogareLogger::getRecorded(),
            fn ($e) => $e['event'] === 'nextcloud.storage.delete',
        ));
        $this->assertCount(2, $deleteEvents);
        $this->assertFalse($deleteEvents[0]['context']['was404']);
        $this->assertTrue($deleteEvents[1]['context']['was404']);
    }

    public function testValidaQueLogicalPathNaoComecaComBarra(): void
    {
        $client = $this->createStub(NextcloudClientContract::class);
        $this->expectException(InvalidArgumentException::class);
        (new NextcloudFileStorage($client))->put('/clientes/abc.pdf', 'x');
    }

    public function testValidaQueLogicalPathNaoContemTraversal(): void
    {
        $client = $this->createStub(NextcloudClientContract::class);
        $this->expectException(InvalidArgumentException::class);
        (new NextcloudFileStorage($client))->put('clientes/../etc/passwd', 'x');
    }

    /**
     * Story 6.0 — buildUri novo no FileStorageContract retorna esquema
     * canônico nextcloud:// para a impl. WebDAV.
     */
    public function testBuildUriRetornaNextcloudScheme(): void
    {
        $client = $this->createStub(NextcloudClientContract::class);
        $storage = new NextcloudFileStorage($client);

        $this->assertSame(
            'nextcloud://clientes/abc/x.pdf',
            $storage->buildUri('clientes/abc/x.pdf'),
        );
    }

    public function testBuildUriRejeitaLogicalPathInvalido(): void
    {
        $client = $this->createStub(NextcloudClientContract::class);
        $storage = new NextcloudFileStorage($client);

        $this->expectException(InvalidArgumentException::class);
        $storage->buildUri('/clientes/abc/x.pdf');
    }
}
