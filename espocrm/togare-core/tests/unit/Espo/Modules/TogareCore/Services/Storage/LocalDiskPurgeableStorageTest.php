<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Services\Storage;

use DateInterval;
use Espo\Modules\TogareCore\Services\Storage\LocalDiskPurgeableStorage;
use Espo\Modules\TogareCore\Services\TogareLogger;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Cobre Story 6.0 AC2 — softPurge + restoreFromTombstone + cleanup recursivo
 * dos dirs vazios + tombstoneId 32-hex + log canônico.
 */
final class LocalDiskPurgeableStorageTest extends TestCase
{
    private string $tempRoot;
    /** @var resource */
    private $stdoutStream;
    /** @var resource */
    private $stderrStream;

    protected function setUp(): void
    {
        $this->tempRoot = \sys_get_temp_dir()
            . \DIRECTORY_SEPARATOR
            . 'togare-localdisk-purge-test-'
            . \bin2hex(\random_bytes(8));

        $this->stdoutStream = \fopen('php://memory', 'w+');
        $this->stderrStream = \fopen('php://memory', 'w+');

        TogareLogger::reset();
        TogareLogger::init('test-localdisk-purge', null, $this->stdoutStream, $this->stderrStream);
    }

    protected function tearDown(): void
    {
        TogareLogger::reset();
        $this->recursiveRmdir($this->tempRoot);
        if (\is_resource($this->stdoutStream)) {
            \fclose($this->stdoutStream);
        }
        if (\is_resource($this->stderrStream)) {
            \fclose($this->stderrStream);
        }
    }

    #[RunInSeparateProcess]
    public function testSoftPurgeMoveParaPurgedTombstoneERetornaIdHex32(): void
    {
        $storage = new LocalDiskPurgeableStorage($this->tempRoot);
        $storage->put('clientes/abc/file.pdf', 'bytes-secretos');

        $tombstoneId = $storage->softPurge('clientes/abc/file.pdf', new DateInterval('P30D'));

        // tombstoneId regex 32-hex (mesmo pattern do NextcloudPurgeableStorage)
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $tombstoneId);

        // Arquivo original sumiu
        self::assertFileDoesNotExist($this->tempRoot . '/clientes/abc/file.pdf');
        self::assertFalse($storage->exists('clientes/abc/file.pdf'));

        // Arquivo apareceu em .purged/<tid>/clientes/abc/file.pdf preservando bytes
        $tombstoneAbsFile = $this->tempRoot . '/.purged/' . $tombstoneId . '/clientes/abc/file.pdf';
        self::assertFileExists($tombstoneAbsFile);
        self::assertSame('bytes-secretos', \file_get_contents($tombstoneAbsFile));

        // Log canônico
        $event = $this->findEvent('localdisk.storage.softpurge');
        self::assertNotNull($event);
        self::assertSame('info', $event['level']);
        self::assertSame($tombstoneId, $event['context']['tombstoneId']);
        self::assertSame('clientes/abc/file.pdf', $event['context']['logicalPath']);
        self::assertSame('P30D', $event['context']['retentionIso8601']);
    }

    #[RunInSeparateProcess]
    public function testSoftPurgeArquivoInexistenteLancaRuntimeException(): void
    {
        $storage = new LocalDiskPurgeableStorage($this->tempRoot);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('arquivo origem não encontrado');
        $storage->softPurge('nao-existe/x.pdf', new DateInterval('P1D'));
    }

    #[RunInSeparateProcess]
    public function testSoftPurgeSymlinkFinalParaForaDoRootBlocked(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('symlink test não-portável em Windows host');
        }

        $outside = \sys_get_temp_dir() . '/togare-outside-' . \bin2hex(\random_bytes(4));
        \mkdir($outside, 0750, true);
        \file_put_contents($outside . '/secret.pdf', 'segredo-externo');

        $storage = new LocalDiskPurgeableStorage($this->tempRoot);
        \symlink($outside . '/secret.pdf', $this->tempRoot . '/leak.pdf');

        try {
            $storage->softPurge('leak.pdf', new DateInterval('P1D'));
            self::fail('InvalidArgumentException esperada — softPurge não deve mover symlink para fora');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('fora do root', $e->getMessage());
        } finally {
            self::assertSame('segredo-externo', \file_get_contents($outside . '/secret.pdf'));
            @\unlink($this->tempRoot . '/leak.pdf');
            $this->recursiveRmdir($outside);
        }
    }

    #[RunInSeparateProcess]
    public function testRestoreFromTombstoneRenameiaDeVoltaECleanupDirsVazios(): void
    {
        $storage = new LocalDiskPurgeableStorage($this->tempRoot);
        $storage->put('clientes/xyz/file.pdf', 'volta-pra-mim');

        $tombstoneId = $storage->softPurge('clientes/xyz/file.pdf', new DateInterval('P30D'));

        $storage->restoreFromTombstone($tombstoneId);

        // Arquivo de volta no path original
        self::assertTrue($storage->exists('clientes/xyz/file.pdf'));
        self::assertSame('volta-pra-mim', $storage->get('clientes/xyz/file.pdf'));

        // Dir do tombstone foi limpo
        self::assertDirectoryDoesNotExist($this->tempRoot . '/.purged/' . $tombstoneId);

        $event = $this->findEvent('localdisk.storage.restore');
        self::assertNotNull($event);
        self::assertSame($tombstoneId, $event['context']['tombstoneId']);
        self::assertSame('clientes/xyz/file.pdf', $event['context']['restoredLogicalPath']);
    }

    #[RunInSeparateProcess]
    public function testRestoreNaoSobrescreveArquivoAtivoNoMesmoLogicalPath(): void
    {
        $storage = new LocalDiskPurgeableStorage($this->tempRoot);
        $storage->put('clientes/xyz/file.pdf', 'versao-antiga');
        $tombstoneId = $storage->softPurge('clientes/xyz/file.pdf', new DateInterval('P30D'));

        $storage->put('clientes/xyz/file.pdf', 'versao-nova');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('destino já existe');
        try {
            $storage->restoreFromTombstone($tombstoneId);
        } finally {
            self::assertSame('versao-nova', $storage->get('clientes/xyz/file.pdf'));
            self::assertFileExists($this->tempRoot . '/.purged/' . $tombstoneId . '/clientes/xyz/file.pdf');
        }
    }

    #[RunInSeparateProcess]
    public function testRestoreTombstoneInexistenteLancaRuntimeException(): void
    {
        $storage = new LocalDiskPurgeableStorage($this->tempRoot);

        $tombstoneIdValido = \str_repeat('a', 32); // 32-hex válido formato
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('não existe ou já foi purgado');
        $storage->restoreFromTombstone($tombstoneIdValido);
    }

    #[RunInSeparateProcess]
    public function testRestoreTombstoneIdInvalidoFormatLancaRuntimeException(): void
    {
        $storage = new LocalDiskPurgeableStorage($this->tempRoot);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tombstoneId inválido');
        $storage->restoreFromTombstone('nope-not-hex');
    }

    #[RunInSeparateProcess]
    public function testRestoreTombstoneVazioLancaRuntimeException(): void
    {
        $storage = new LocalDiskPurgeableStorage($this->tempRoot);
        $tombstoneId = \str_repeat('a', 32);

        // Cria dir tombstone vazio manualmente (sem arquivos dentro)
        \mkdir($this->tempRoot . '/.purged/' . $tombstoneId, 0750, true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('vazio');
        $storage->restoreFromTombstone($tombstoneId);
    }

    // ───── helpers (clone do LocalDiskStorageTest) ─────────────────────────

    /**
     * @return array{level:string,event:string,message:string,context:array<string,mixed>}|null
     */
    private function findEvent(string $eventName): ?array
    {
        foreach ($this->readAllEvents() as $event) {
            if ($event['event'] === $eventName) {
                return $event;
            }
        }
        return null;
    }

    /**
     * @return list<array{level:string,event:string,message:string,context:array<string,mixed>}>
     */
    private function readAllEvents(): array
    {
        $events = [];
        foreach ([$this->stdoutStream, $this->stderrStream] as $stream) {
            if (! \is_resource($stream)) {
                continue;
            }
            \rewind($stream);
            while (($line = \fgets($stream)) !== false) {
                $line = \trim($line);
                if ($line === '') {
                    continue;
                }
                $decoded = \json_decode($line, true);
                if (\is_array($decoded) && isset($decoded['event'])) {
                    $events[] = $decoded;
                }
            }
        }
        return $events;
    }

    private function recursiveRmdir(string $dir): void
    {
        if (! \is_dir($dir)) {
            return;
        }
        $items = @\scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . \DIRECTORY_SEPARATOR . $item;
            if (\is_link($path)) {
                @\unlink($path);
                continue;
            }
            if (\is_dir($path)) {
                $this->recursiveRmdir($path);
                continue;
            }
            @\unlink($path);
        }
        @\rmdir($dir);
    }
}
