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
 * AC #2, #9, #10 da Story 5.1: HTTP layer + retry + circuit breaker.
 */
final class OcsApiClientTest extends TestCase
{
    private string $stateFile;
    /** @var list<array{0:string, 1:array<string,mixed>}> */
    private array $events = [];

    protected function setUp(): void
    {
        TogareLogger::reset();
        $this->stateFile = \sys_get_temp_dir() . '/togare-nextcloud-test-' . \bin2hex(\random_bytes(4)) . '.json';
        $this->events = [];
    }

    protected function tearDown(): void
    {
        if (\is_file($this->stateFile)) {
            @\unlink($this->stateFile);
        }
    }

    /**
     * @param list<array{status:int, body:string, headers?:list<string>}> $responses
     */
    private function makeClient(array $responses, ?string $stateFile = null): OcsApiClient
    {
        $cursor = 0;
        $http = function (string $url, array $opts) use (&$cursor, $responses): array {
            $r = $responses[$cursor] ?? ['status' => 500, 'body' => '', 'headers' => []];
            $cursor++;
            $r['headers'] ??= [];
            return $r;
        };

        return new OcsApiClient(
            baseUrl: 'http://nextcloud-test:80',
            user: 'admin',
            password: 'pass',
            stateFilePath: $stateFile ?? $this->stateFile,
            httpExecutor: $http,
            clock: fn (): float => 1_715_000_000.0,
            sleeper: static fn (int $s) => null,
            eventEmitter: function (string $name, array $payload): void {
                $this->events[] = [$name, $payload];
            },
        );
    }

    public function testPutWebDavOkRetorna201(): void
    {
        // ensureParentDirs faz MKCOL para root '', 'clientes', 'clientes/abc',
        // 'clientes/abc/contratos' = 4 MKCOLs + 1 PUT = 5 responses.
        // (Root togare/ é primeiro — fix B-NEW-7 do smoke F1.)
        $client = $this->makeClient([
            ['status' => 201, 'body' => ''],  // MKCOL togare/ (root)
            ['status' => 201, 'body' => ''],  // MKCOL clientes
            ['status' => 201, 'body' => ''],  // MKCOL clientes/abc
            ['status' => 201, 'body' => ''],  // MKCOL clientes/abc/contratos
            ['status' => 201, 'body' => ''],  // PUT
        ]);

        $client->putWebDav('clientes/abc/contratos/2026-001.pdf', 'binary content');
        $this->assertTrue(true, 'putWebDav not throwing == success');
    }

    public function testGetWebDavOkRetornaConteudo(): void
    {
        $client = $this->makeClient([
            ['status' => 200, 'body' => 'PDF binary bytes'],
        ]);

        $bytes = $client->getWebDav('clientes/abc/contratos/2026-001.pdf');
        $this->assertSame('PDF binary bytes', $bytes);
    }

    public function testGetWebDav404LancaNextcloudFileNotFoundException(): void
    {
        $client = $this->makeClient([
            ['status' => 404, 'body' => '<error/>'],
        ]);

        $this->expectException(NextcloudFileNotFoundException::class);
        $client->getWebDav('clientes/abc/missing.pdf');
    }

    public function testExistsWebDav207RetornaTrue(): void
    {
        $client = $this->makeClient([
            ['status' => 207, 'body' => '<d:multistatus xmlns:d="DAV:"></d:multistatus>'],
        ]);

        $this->assertTrue($client->existsWebDav('clientes/abc/contratos/2026-001.pdf'));
    }

    public function testExistsWebDav404RetornaFalseSemException(): void
    {
        $client = $this->makeClient([
            ['status' => 404, 'body' => ''],
        ]);

        $this->assertFalse($client->existsWebDav('clientes/xyz/inexistente.pdf'));
    }

    public function testDeleteWebDav204OkEDelete404Idempotente(): void
    {
        $client1 = $this->makeClient([
            ['status' => 204, 'body' => ''],
        ]);
        $this->assertTrue($client1->deleteWebDav('clientes/abc/contratos/2026-001.pdf'));

        // 2ª chamada com 404 não deve lançar.
        $client2 = $this->makeClient([
            ['status' => 404, 'body' => ''],
        ]);
        $this->assertFalse($client2->deleteWebDav('clientes/abc/contratos/2026-001.pdf'));
    }

    public function testMoveWebDav201OkEMove404SourceLancaFileNotFound(): void
    {
        // moveWebDav chama ensureParentDirs(destination), que faz MKCOL para
        // root togare/ + cada segmento do dirname. Para destination
        // `.purged/abc/clientes/abc/contratos/2026-001.pdf`, dirname é
        // `.purged/abc/clientes/abc/contratos` → root + 5 segmentos = 6 MKCOLs
        // + MOVE = 7 requests no total. (B-NEW-7 fix: root togare/ incluso.)
        $clientOk = $this->makeClient([
            ['status' => 201, 'body' => ''],  // MKCOL togare/ (root)
            ['status' => 201, 'body' => ''],  // MKCOL .purged
            ['status' => 201, 'body' => ''],  // MKCOL .purged/abc
            ['status' => 201, 'body' => ''],  // MKCOL .purged/abc/clientes
            ['status' => 201, 'body' => ''],  // MKCOL .purged/abc/clientes/abc
            ['status' => 201, 'body' => ''],  // MKCOL .purged/abc/clientes/abc/contratos
            ['status' => 201, 'body' => ''],  // MOVE
        ]);
        $clientOk->moveWebDav('clientes/abc/contratos/2026-001.pdf', '.purged/abc/clientes/abc/contratos/2026-001.pdf');

        // Para destination `.purged/abc/clientes/abc/missing.pdf`, dirname é
        // `.purged/abc/clientes/abc` → root + 4 segmentos = 5 MKCOLs + MOVE = 6.
        $client404 = $this->makeClient([
            ['status' => 201, 'body' => ''],  // MKCOL togare/ (root)
            ['status' => 201, 'body' => ''],  // MKCOL .purged
            ['status' => 201, 'body' => ''],  // MKCOL .purged/abc
            ['status' => 201, 'body' => ''],  // MKCOL .purged/abc/clientes
            ['status' => 201, 'body' => ''],  // MKCOL .purged/abc/clientes/abc
            ['status' => 404, 'body' => ''],  // MOVE → source não existe
        ]);
        $this->expectException(NextcloudFileNotFoundException::class);
        $client404->moveWebDav('clientes/abc/missing.pdf', '.purged/abc/clientes/abc/missing.pdf');
    }

    public function testRetryComDuasFalhasESucessoNaTerceiraTentativa(): void
    {
        $client = $this->makeClient([
            ['status' => 503, 'body' => ''],   // attempt 1: retryable
            ['status' => 503, 'body' => ''],   // attempt 2: retryable
            ['status' => 200, 'body' => 'OK'], // attempt 3: success
        ]);

        $bytes = $client->getWebDav('clientes/abc/contratos/2026-001.pdf');
        $this->assertSame('OK', $bytes);
    }

    public function testCircuitBreakerAbreApos5FalhasEDispatchEventoUnico(): void
    {
        // Pré-popula state file com 4 falhas recentes — próxima request com 503
        // dispara recordFailureAndMaybeOpen() na 5ª falha, abrindo o CB.
        $now = 1_715_000_000;
        \file_put_contents($this->stateFile, \json_encode([
            'failures' => [$now - 10, $now - 8, $now - 6, $now - 4],
            'open_until' => 0,
            'opened_at' => 0,
        ]));

        // 1 request que falha 3x consecutivas — após cada falha
        // recordFailureAndMaybeOpen é chamado. Como começamos com 4 falhas
        // pré-existentes na janela, a 1ª falha desta request abre o CB.
        // As 2ª e 3ª tentativas continuam tentando porque o guardCircuitBreaker
        // já passou no início da request — só será bloqueado em request seguinte.
        $client = $this->makeClient([
            ['status' => 503, 'body' => ''],
            ['status' => 503, 'body' => ''],
            ['status' => 503, 'body' => ''],
        ]);

        try {
            $client->getWebDav('clientes/abc/contratos/2026-001.pdf');
            $this->fail('esperava NextcloudUnavailableException');
        } catch (NextcloudUnavailableException) {
            // ok — esgotou tentativas
        }

        // 6ª chamada deve curto-circuitar SEM bater HTTP (CB aberto).
        $clientCbOpen = $this->makeClient([
            // Não deveria ser consumido — guard CB barra antes.
            ['status' => 200, 'body' => 'should not reach'],
        ]);
        $this->expectException(NextcloudUnavailableException::class);
        $clientCbOpen->getWebDav('clientes/abc/contratos/2026-001.pdf');
    }

    public function testResolveWebDavUrlMontaCaminhoComTogareRoot(): void
    {
        $client = $this->makeClient([]);
        $url = $client->resolveWebDavUrl('clientes/abc/contratos/2026-001.pdf');
        $this->assertSame(
            'http://nextcloud-test:80/remote.php/dav/files/admin/togare/clientes/abc/contratos/2026-001.pdf',
            $url,
        );
    }

    public function testResolveWebDavUrlRejeitaTraversalQuandoClientEUsadoDireto(): void
    {
        $client = $this->makeClient([]);

        $this->expectException(InvalidArgumentException::class);
        $client->resolveWebDavUrl('../fora-do-root.pdf');
    }

    public function testPropfindList207RetornaApenasFilhosNaoOProprioDir(): void
    {
        $xmlPath = __DIR__ . '/../../../../../fixtures/webdav-propfind-207-list.xml';
        if (! \is_file($xmlPath)) {
            $this->fail("fixture não encontrada: {$xmlPath}");
        }
        $xml = \file_get_contents($xmlPath);

        $client = $this->makeClient([
            ['status' => 207, 'body' => (string) $xml],
        ]);

        $items = $client->propfindList('.purged/abcdef0123456789abcdef0123456789');
        // Esperado: apenas o file file.pdf relativo ao tombstone dir, NÃO o dir self.
        $this->assertCount(1, $items);
        $this->assertSame('clientes/abc/contratos/2026-001.pdf', $items[0]);
    }

    public function testFileBaseSuportaPutGetExistsMoveDelete(): void
    {
        $root = \sys_get_temp_dir() . '/togare-nextcloud-file-' . \bin2hex(\random_bytes(4));
        $baseUrl = 'file:///' . \str_replace('\\', '/', \ltrim($root, '\\/'));
        if (\preg_match('/^[A-Za-z]:/', $root) === 1) {
            $baseUrl = 'file:///' . \str_replace('\\', '/', $root);
        }

        $client = new OcsApiClient(
            baseUrl: $baseUrl,
            user: 'admin',
            password: 'pass',
            stateFilePath: $this->stateFile,
            sleeper: static fn (int $s) => null,
            eventEmitter: function (string $name, array $payload): void {
                $this->events[] = [$name, $payload];
            },
        );

        try {
            $client->putWebDav('clientes/abc/doc.txt', 'conteudo');
            $this->assertTrue($client->existsWebDav('clientes/abc/doc.txt'));
            $this->assertSame('conteudo', $client->getWebDav('clientes/abc/doc.txt'));

            $client->moveWebDav('clientes/abc/doc.txt', '.purged/abcdef0123456789abcdef0123456789/clientes/abc/doc.txt');
            $this->assertFalse($client->existsWebDav('clientes/abc/doc.txt'));
            $this->assertTrue($client->existsWebDav('.purged/abcdef0123456789abcdef0123456789/clientes/abc/doc.txt'));
            $this->assertTrue($client->deleteWebDav('.purged/abcdef0123456789abcdef0123456789'));
            $this->assertFalse($client->deleteWebDav('.purged/abcdef0123456789abcdef0123456789'));
        } finally {
            $this->deleteDirectory($root);
        }
    }

    private function deleteDirectory(string $path): void
    {
        if (! \file_exists($path)) {
            return;
        }
        if (! \is_dir($path)) {
            @\unlink($path);
            return;
        }
        foreach (\scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->deleteDirectory($path . DIRECTORY_SEPARATOR . $entry);
        }
        @\rmdir($path);
    }
}
