<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Services\Storage;

use Espo\Modules\TogareCore\Services\Storage\LocalDiskStorage;
use Espo\Modules\TogareCore\Services\TogareLogger;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Cobre Story 6.0 AC1 (put/get/exists/delete + path safety), AC3 (buildUri),
 * AC7 (realpath traversal block), AC8 (mode bits), AC9 (eventos canônicos).
 *
 * Setup cria tempdir único por teste; tearDown remove via rrmdir recursivo.
 * TogareLogger capturado em php://memory streams (pattern AuditLogServiceTest).
 */
final class LocalDiskStorageTest extends TestCase
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
            . 'togare-localdisk-test-'
            . \bin2hex(\random_bytes(8));

        $this->stdoutStream = \fopen('php://memory', 'w+');
        $this->stderrStream = \fopen('php://memory', 'w+');

        TogareLogger::reset();
        TogareLogger::init('test-localdisk', null, $this->stdoutStream, $this->stderrStream);
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

    // ───── put / get / exists / delete ─────────────────────────────────────

    #[RunInSeparateProcess]
    public function testPutCriaArquivoComBytesERetornaTrueExists(): void
    {
        $storage = new LocalDiskStorage($this->tempRoot);
        $storage->put('clientes/abc/file.pdf', 'hello-bytes');

        $absolute = $this->tempRoot . '/clientes/abc/file.pdf';
        self::assertFileExists($absolute);
        self::assertSame('hello-bytes', \file_get_contents($absolute));
        self::assertTrue($storage->exists('clientes/abc/file.pdf'));

        $event = $this->findEvent('localdisk.storage.put');
        self::assertNotNull($event, 'Evento localdisk.storage.put não emitido');
        self::assertSame('info', $event['level']);
        self::assertSame('clientes/abc/file.pdf', $event['context']['logicalPath']);
        self::assertSame(11, $event['context']['sizeBytes']);
    }

    #[RunInSeparateProcess]
    public function testGetRetornaBytesGravados(): void
    {
        $storage = new LocalDiskStorage($this->tempRoot);
        $storage->put('a/b/c.txt', 'conteúdo-pt-BR');

        self::assertSame('conteúdo-pt-BR', $storage->get('a/b/c.txt'));

        $event = $this->findEvent('localdisk.storage.get');
        self::assertNotNull($event);
        self::assertSame('debug', $event['level']);
    }

    #[RunInSeparateProcess]
    public function testGetArquivoInexistenteLancaRuntimeExceptionPtBr(): void
    {
        $storage = new LocalDiskStorage($this->tempRoot);

        try {
            $storage->get('inexistente/x.pdf');
            self::fail('RuntimeException esperada');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Arquivo não encontrado', $e->getMessage());
            self::assertStringContainsString('inexistente/x.pdf', $e->getMessage());
        }

        $miss = $this->findEvent('localdisk.storage.miss');
        self::assertNotNull($miss, 'Evento localdisk.storage.miss não emitido em get-404');
        self::assertSame('warning', $miss['level']);
    }

    #[RunInSeparateProcess]
    public function testDeleteIdempotenteEm404(): void
    {
        $storage = new LocalDiskStorage($this->tempRoot);
        $storage->put('a/file.pdf', 'bytes');

        // 1ª chamada: arquivo existe → was404=false
        $storage->delete('a/file.pdf');
        self::assertFalse($storage->exists('a/file.pdf'));

        // 2ª chamada: arquivo já foi → was404=true (idempotente, sem throw)
        $storage->delete('a/file.pdf');

        $deletes = $this->filterEvents('localdisk.storage.delete');
        self::assertCount(2, $deletes);
        self::assertFalse($deletes[0]['context']['was404']);
        self::assertTrue($deletes[1]['context']['was404']);
    }

    // ───── path safety ─────────────────────────────────────────────────────

    #[RunInSeparateProcess]
    public function testPathTraversalDoisPontosBlocked(): void
    {
        $storage = new LocalDiskStorage($this->tempRoot);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('segmento inválido');
        $storage->put('../escape.pdf', 'x');
    }

    #[RunInSeparateProcess]
    public function testPathAbsolutoBlocked(): void
    {
        $storage = new LocalDiskStorage($this->tempRoot);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('relativo');
        $storage->put('/etc/passwd', 'x');
    }

    #[RunInSeparateProcess]
    public function testPathTraversalSymlinkBlocked(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('symlink test não-portável em Windows host');
        }

        // Cria target FORA do root, e symlink DENTRO do root apontando pra fora.
        $outside = \sys_get_temp_dir() . '/togare-outside-' . \bin2hex(\random_bytes(4));
        \mkdir($outside, 0750, true);

        $storage = new LocalDiskStorage($this->tempRoot);
        \mkdir($this->tempRoot . '/clientes', 0750, true);
        \symlink($outside, $this->tempRoot . '/clientes/escape');

        try {
            $storage->put('clientes/escape/leak.pdf', 'leaked');
            self::fail('InvalidArgumentException esperada — symlink injection deveria ser bloqueado');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('symlink injection', $e->getMessage());
        } finally {
            // cleanup
            @\unlink($this->tempRoot . '/clientes/escape');
            $this->recursiveRmdir($outside);
        }

        // Confirma que o arquivo NÃO vazou pra fora do root.
        self::assertFalse(\file_exists($outside . '/leak.pdf'));
    }

    #[RunInSeparateProcess]
    public function testPutSobreSymlinkFinalNaoVazaParaForaDoRoot(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('symlink test não-portável em Windows host');
        }

        $outside = \sys_get_temp_dir() . '/togare-outside-' . \bin2hex(\random_bytes(4));
        \mkdir($outside, 0750, true);
        \file_put_contents($outside . '/secret.pdf', 'segredo-externo');

        $storage = new LocalDiskStorage($this->tempRoot);
        \symlink($outside . '/secret.pdf', $this->tempRoot . '/leak.pdf');

        try {
            $storage->put('leak.pdf', 'bytes-internos');

            self::assertSame('segredo-externo', \file_get_contents($outside . '/secret.pdf'));
            self::assertFalse(\is_link($this->tempRoot . '/leak.pdf'));
            self::assertSame('bytes-internos', \file_get_contents($this->tempRoot . '/leak.pdf'));
        } finally {
            @\unlink($this->tempRoot . '/leak.pdf');
            $this->recursiveRmdir($outside);
        }
    }

    #[RunInSeparateProcess]
    public function testGetSymlinkFinalParaForaDoRootBlocked(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('symlink test não-portável em Windows host');
        }

        $outside = \sys_get_temp_dir() . '/togare-outside-' . \bin2hex(\random_bytes(4));
        \mkdir($outside, 0750, true);
        \file_put_contents($outside . '/secret.pdf', 'segredo-externo');

        $storage = new LocalDiskStorage($this->tempRoot);
        \symlink($outside . '/secret.pdf', $this->tempRoot . '/leak.pdf');

        try {
            $storage->get('leak.pdf');
            self::fail('InvalidArgumentException esperada — get não deve seguir symlink para fora');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('fora do root', $e->getMessage());
        } finally {
            @\unlink($this->tempRoot . '/leak.pdf');
            $this->recursiveRmdir($outside);
        }
    }

    #[RunInSeparateProcess]
    public function testExistsSymlinkFinalParaForaDoRootBlocked(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('symlink test não-portável em Windows host');
        }

        $outside = \sys_get_temp_dir() . '/togare-outside-' . \bin2hex(\random_bytes(4));
        \mkdir($outside, 0750, true);
        \file_put_contents($outside . '/secret.pdf', 'segredo-externo');

        $storage = new LocalDiskStorage($this->tempRoot);
        \symlink($outside . '/secret.pdf', $this->tempRoot . '/leak.pdf');

        try {
            $storage->exists('leak.pdf');
            self::fail('InvalidArgumentException esperada — exists não deve seguir symlink para fora');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('fora do root', $e->getMessage());
        } finally {
            @\unlink($this->tempRoot . '/leak.pdf');
            $this->recursiveRmdir($outside);
        }
    }

    #[RunInSeparateProcess]
    public function testDeleteSymlinkFinalParaForaDoRootBlocked(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('symlink test não-portável em Windows host');
        }

        $outside = \sys_get_temp_dir() . '/togare-outside-' . \bin2hex(\random_bytes(4));
        \mkdir($outside, 0750, true);
        \file_put_contents($outside . '/secret.pdf', 'segredo-externo');

        $storage = new LocalDiskStorage($this->tempRoot);
        \symlink($outside . '/secret.pdf', $this->tempRoot . '/leak.pdf');

        try {
            $storage->delete('leak.pdf');
            self::fail('InvalidArgumentException esperada — delete não deve operar symlink para fora');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('fora do root', $e->getMessage());
        } finally {
            self::assertSame('segredo-externo', \file_get_contents($outside . '/secret.pdf'));
            @\unlink($this->tempRoot . '/leak.pdf');
            $this->recursiveRmdir($outside);
        }
    }

    // ───── buildUri ────────────────────────────────────────────────────────

    public function testBuildUriRetornaLocalScheme(): void
    {
        $storage = new LocalDiskStorage($this->tempRoot);

        self::assertSame('local://clientes/abc/x.pdf', $storage->buildUri('clientes/abc/x.pdf'));
    }

    public function testBuildUriRejeitaPathInvalido(): void
    {
        $storage = new LocalDiskStorage($this->tempRoot);

        $this->expectException(InvalidArgumentException::class);
        $storage->buildUri('../escape.pdf');
    }

    // ───── permissões ──────────────────────────────────────────────────────

    #[RunInSeparateProcess]
    public function testPermissoesFile0640EDir0750(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Unix mode bits não-portáveis em Windows host');
        }

        // umask defensivo — algumas envs CI vêm com 0027 e alteram fileperms()
        // após chmod explícito. Forçar 0022 garante que chmod 0640/0750 sobrevive.
        $oldUmask = \umask(0022);
        try {
            $storage = new LocalDiskStorage($this->tempRoot);
            $storage->put('subdir/file.pdf', 'bytes');

            $fileMode = \fileperms($this->tempRoot . '/subdir/file.pdf') & 0777;
            $dirMode = \fileperms($this->tempRoot . '/subdir') & 0777;

            self::assertSame(0640, $fileMode, \sprintf('file mode esperado 0640, recebido %o', $fileMode));
            self::assertSame(0750, $dirMode, \sprintf('dir mode esperado 0750, recebido %o', $dirMode));
        } finally {
            \umask($oldUmask);
        }
    }

    // ───── construtor ──────────────────────────────────────────────────────

    public function testConstructorRejectaRootPathVazio(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('rootPath não pode ser vazio');
        new LocalDiskStorage('');
    }

    public function testConstructorRejectaRootPathRelativo(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('absoluto');
        new LocalDiskStorage('relative/path');
    }

    // ───── helpers ─────────────────────────────────────────────────────────

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
    private function filterEvents(string $eventName): array
    {
        $out = [];
        foreach ($this->readAllEvents() as $event) {
            if ($event['event'] === $eventName) {
                $out[] = $event;
            }
        }
        return $out;
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
