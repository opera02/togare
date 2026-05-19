<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareNextcloudBridge\Services;

use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareNextcloudBridge\Exception\NextcloudFileNotFoundException;
use Espo\Modules\TogareNextcloudBridge\Exception\NextcloudUnavailableException;
use Espo\Modules\TogareNextcloudBridge\Services\OcsApiClient;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * AC #12 da Story 5.3: cobertura do método novo `streamWebDav` no OcsApiClient.
 *
 * Usa o suporte `file://` do baseUrl (já existente na bridge — Decisão #9 da
 * Story 5.1) — não precisa de Nextcloud real nem mock cURL.
 */
final class OcsApiClientStreamWebDavTest extends TestCase
{
    private string $stateFile;
    private string $fixturesDir;

    protected function setUp(): void
    {
        TogareLogger::reset();
        $this->stateFile = \sys_get_temp_dir()
            . '/togare-nextcloud-stream-test-' . \bin2hex(\random_bytes(4)) . '.json';

        // Cria diretório de fixtures temporário pra cada teste — isolamento total.
        $this->fixturesDir = \sys_get_temp_dir() . '/togare-stream-fix-' . \bin2hex(\random_bytes(4));
        \mkdir($this->fixturesDir, 0775, true);
    }

    protected function tearDown(): void
    {
        if (\is_file($this->stateFile)) {
            @\unlink($this->stateFile);
        }
        $this->rmrf($this->fixturesDir);
    }

    public function testStreamWebDavRecusaQuandoOutputStreamNaoEResource(): void
    {
        $client = $this->makeClient();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('streamWebDav: $outputStream deve ser resource aberto');
        /** @phpstan-ignore-next-line argumento inválido proposital */
        $client->streamWebDav('clientes/abc/x.pdf', 'not-a-resource');
    }

    public function testStreamWebDavEscreveBytesNoStreamViaFileFixture(): void
    {
        // Cria fixture binário em `togare/clientes/abc/x.pdf` sob fixturesDir.
        $expected = \str_repeat("BINARY-CHUNK-1024;", 1000); // ~17KB
        $expectedSha = \hash('sha256', $expected);
        $this->writeFixture('clientes/abc/x.pdf', $expected);

        $client = $this->makeClient();
        $out = \fopen('php://memory', 'w+b');
        self::assertNotFalse($out);

        $client->streamWebDav('clientes/abc/x.pdf', $out);

        \rewind($out);
        $got = \stream_get_contents($out);
        \fclose($out);

        self::assertSame(\strlen($expected), \strlen((string) $got), 'bytes count diverge');
        self::assertSame($expectedSha, \hash('sha256', (string) $got), 'sha256 diverge');
    }

    public function testStreamWebDavLancaFileNotFoundQuando404(): void
    {
        $client = $this->makeClient();
        $out = \fopen('php://memory', 'w+b');
        self::assertNotFalse($out);

        $this->expectException(NextcloudFileNotFoundException::class);
        try {
            $client->streamWebDav('clientes/inexistente/x.pdf', $out);
        } finally {
            if (\is_resource($out)) {
                \fclose($out);
            }
        }
    }

    public function testStreamWebDavPreservaBytesParaArquivoGrande(): void
    {
        // Confirma que chunks 1MB iteram corretamente e nada se perde no final.
        // Fixture ~2.5 MB força ≥3 iterações do loop fread/fwrite.
        $expected = '';
        for ($i = 0; $i < 2_600_000; $i++) {
            $expected .= \chr($i % 256);
        }
        $expectedSha = \hash('sha256', $expected);
        $this->writeFixture('processos/grande/x.bin', $expected);

        $client = $this->makeClient();
        $out = \fopen('php://memory', 'w+b');
        self::assertNotFalse($out);

        $client->streamWebDav('processos/grande/x.bin', $out);

        \rewind($out);
        $got = \stream_get_contents($out);
        \fclose($out);

        self::assertSame(\strlen($expected), \strlen((string) $got));
        self::assertSame($expectedSha, \hash('sha256', (string) $got));
    }

    public function testStreamWebDavLancaUnavailableSemEscreverBytesQuandoHttp503(): void
    {
        $beforeFirstByteCalled = false;
        $streamExecutor = static function (string $url, array $opts, callable $bodyHandler): array {
            $bodyHandler(503, '<html>Service Unavailable</html>');
            return [
                'ok' => true,
                'errno' => 0,
                'error' => '',
                'status' => 503,
                'size' => 32,
            ];
        };

        $client = $this->makeClient(
            baseUrl: 'http://nextcloud-test:80',
            streamExecutor: $streamExecutor,
        );
        $out = \fopen('php://memory', 'w+b');
        self::assertNotFalse($out);

        try {
            $client->streamWebDav(
                'processos/proc-123/x.pdf',
                $out,
                static function () use (&$beforeFirstByteCalled): void {
                    $beforeFirstByteCalled = true;
                },
            );
            self::fail('Esperava NextcloudUnavailableException');
        } catch (NextcloudUnavailableException) {
            \rewind($out);
            self::assertSame('', \stream_get_contents($out));
            self::assertFalse($beforeFirstByteCalled);
        } finally {
            if (\is_resource($out)) {
                \fclose($out);
            }
        }
    }

    // =========================================================
    // Helpers
    // =========================================================

    private function makeClient(?string $baseUrl = null, ?callable $streamExecutor = null): OcsApiClient
    {
        // baseUrl file:// → executeFileRequest do bridge cuida do simulador.
        $base = $baseUrl ?? 'file://' . \str_replace('\\', '/', $this->fixturesDir);

        return new OcsApiClient(
            baseUrl: $base,
            user: 'admin',
            password: 'pass',
            stateFilePath: $this->stateFile,
            clock: fn (): float => 1_715_000_000.0,
            sleeper: static fn (int $s) => null,
            streamExecutor: $streamExecutor,
        );
    }

    private function writeFixture(string $logicalPath, string $binary): void
    {
        // OcsApiClient resolve URL completa via `resolveWebDavUrl`, que sob
        // baseUrl `file://<dir>` produz `file://<dir>/remote.php/dav/files/<user>/togare/<path>`.
        // O simulador `executeFileRequest` parseia o path inteiro do URL e usa
        // como filesystem path real. Replicamos essa estrutura literal sob a
        // fixturesDir pra que o stream encontre os bytes.
        $full = $this->fixturesDir . '/remote.php/dav/files/admin/togare/' . $logicalPath;
        $dir = \dirname($full);
        if (! \is_dir($dir)) {
            \mkdir($dir, 0775, true);
        }
        \file_put_contents($full, $binary);
    }

    private function rmrf(string $path): void
    {
        if (! \is_dir($path)) {
            if (\is_file($path)) {
                @\unlink($path);
            }
            return;
        }
        foreach (\scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->rmrf($path . DIRECTORY_SEPARATOR . $entry);
        }
        @\rmdir($path);
    }
}
