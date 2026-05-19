<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Controllers;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Exceptions\ServiceUnavailable;
use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\Modules\TogareCore\Controllers\Documento as DocumentoController;
use Espo\Modules\TogareCore\Entities\Documento;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareNextcloudBridge\Contracts\NextcloudClientContract;
use Espo\Modules\TogareNextcloudBridge\Exception\NextcloudUnavailableException;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Cobre o download proxy real entregue na Story 5.3 (substitui o stub 501
 * da Story 5.2 Decisão #9).
 *
 * Organização (≥10 cenários, AC #12 da Story 5.3):
 *
 *   Grupo A — Erros e ACL (3 testes)
 *     - testAclDenied_returns403
 *     - testDocumentoNaoExiste_returns404
 *     - testUriInvalida_returns500
 *
 *   Grupo B — Branch X-Accel-Redirect default (3 testes)
 *     - testXAccelHeaderPresenteQuandoEnvFalse
 *     - testContentTypePropagaMimeType
 *     - testContentDispositionFilenameSanitizada
 *
 *   Grupo C — Branch PHP-proxy fallback (2 testes)
 *     - testPhpProxyChamaStreamWebDavQuandoEnvTrue
 *     - testPhpProxyConverteNextcloudUnavailableEm503
 *
 *   Grupo D — Audit log (2 testes)
 *     - testAuditDownloadedRowGravadoComBranchCorreto
 *     - testAuditDownloadFailedRowQuandoNextcloudUnavailable
 *
 * Padrão: subclasse `TestableDocumento` (no fim do arquivo) faz override dos
 * pontos de extensão protected do controller (`resolveEntityManager`, `resolveAcl`,
 * `resolveNextcloudClient`, `resolveAuditLog`, `shouldUsePhpProxy`, `openOutputStream`,
 * `writeStreamHeaders`, `terminateAfterStream`) — sem precisar de mock complexo do
 * InjectableFactory.
 */
final class DocumentoControllerTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset env vars que afetam branch decision entre testes.
        \putenv('TOGARE_DOWNLOAD_USE_PHP_PROXY');
        \putenv('TOGARE_NEXTCLOUD_USER=admin');
        \putenv('NEXTCLOUD_ADMIN_USER=admin');

        $stdout = \fopen('php://memory', 'w+');
        $stderr = \fopen('php://memory', 'w+');
        if ($stdout === false || $stderr === false) {
            throw new RuntimeException('Falha ao abrir streams de log em memória.');
        }
        TogareLogger::init('togare-core-test', null, $stdout, $stderr);
    }

    protected function tearDown(): void
    {
        \putenv('TOGARE_DOWNLOAD_USE_PHP_PROXY');
        \putenv('TOGARE_NEXTCLOUD_USER');
        \putenv('NEXTCLOUD_ADMIN_USER');
        TogareLogger::reset();
    }

    // =========================================================
    // Grupo A — Erros e ACL
    // =========================================================

    public function testAclDenied_returns403(): void
    {
        $doc = $this->makeDocumento();
        $controller = $this->makeController($doc, aclAllowed: false);
        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage('Sem permissão para baixar este Documento.');
        try {
            $controller->getActionDownload($this->makeRequest('doc001'), new SpyResponse());
        } finally {
            // ACL denied DEVE registrar audit antes do throw.
            self::assertCount(1, $controller->audit->logs, 'Audit row deve ser gravada antes do Forbidden.');
            self::assertSame('documento.download_denied', $controller->audit->logs[0]['event']);
            self::assertSame('acl_denied', $controller->audit->logs[0]['context']['reason']);
        }
    }

    public function testDocumentoNaoExiste_returns404(): void
    {
        $controller = $this->makeController(documento: null);
        $this->expectException(NotFound::class);
        $this->expectExceptionMessage('Documento não encontrado.');
        $controller->getActionDownload($this->makeRequest('doc-missing'), new SpyResponse());
    }

    public function testUriInvalida_returns500(): void
    {
        $doc = $this->makeDocumento();
        // Set malformed URI (sem prefixo nextcloud://) — extractLogicalPath lança RuntimeException.
        $doc->set('nextcloudUri', 'gibberish-sem-prefixo');
        $controller = $this->makeController($doc, aclAllowed: true);
        try {
            $controller->getActionDownload($this->makeRequest('doc001'), new SpyResponse());
            self::fail('Esperava Error');
        } catch (Error $e) {
            self::assertStringContainsString(
                'Não foi possível localizar o arquivo deste Documento',
                $e->getMessage(),
            );
        }
        // Audit row DEVE existir com reason=uri_invalid.
        self::assertCount(1, $controller->audit->logs);
        self::assertSame('documento.download_failed', $controller->audit->logs[0]['event']);
        self::assertSame('uri_invalid', $controller->audit->logs[0]['context']['reason']);
    }

    public function testUriComFormatoIncompleto_returns500(): void
    {
        $doc = $this->makeDocumento();
        $doc->set('nextcloudUri', 'nextcloud://malformed');
        $controller = $this->makeController($doc, aclAllowed: true);

        $this->expectException(Error::class);

        try {
            $controller->getActionDownload($this->makeRequest('doc001'), new SpyResponse());
        } finally {
            self::assertCount(1, $controller->audit->logs);
            self::assertSame('documento.download_failed', $controller->audit->logs[0]['event']);
            self::assertSame('uri_invalid', $controller->audit->logs[0]['context']['reason']);
        }
    }

    public function testUriComDotSegment_returns500(): void
    {
        $doc = $this->makeDocumento();
        $doc->set('nextcloudUri', 'nextcloud://processos/../doc001-Peticao.pdf');
        $controller = $this->makeController($doc, aclAllowed: true);

        $this->expectException(Error::class);

        try {
            $controller->getActionDownload($this->makeRequest('doc001'), new SpyResponse());
        } finally {
            self::assertCount(1, $controller->audit->logs);
            self::assertSame('documento.download_failed', $controller->audit->logs[0]['event']);
            self::assertSame('uri_invalid', $controller->audit->logs[0]['context']['reason']);
        }
    }

    public function testUriAusente_returns500ComReasonInvalid(): void
    {
        $doc = $this->makeDocumento();
        $controller = $this->makeController($doc, aclAllowed: true);

        $this->expectException(Error::class);

        try {
            $controller->getActionDownload($this->makeRequest('doc001'), new SpyResponse());
        } finally {
            self::assertCount(1, $controller->audit->logs);
            self::assertSame('documento.download_failed', $controller->audit->logs[0]['event']);
            self::assertSame('uri_invalid', $controller->audit->logs[0]['context']['reason']);
        }
    }

    // =========================================================
    // Grupo B — Branch X-Accel-Redirect default
    // =========================================================

    public function testXAccelHeaderPresenteQuandoEnvFalse(): void
    {
        \putenv('TOGARE_DOWNLOAD_USE_PHP_PROXY=false');
        $doc = $this->makeDocumentoComUri();
        $controller = $this->makeController($doc, aclAllowed: true);
        $response = new SpyResponse();

        $controller->getActionDownload($this->makeRequest('doc001'), $response);

        self::assertSame(200, $response->status);
        self::assertArrayHasKey('X-Accel-Redirect', $response->headers);
        self::assertStringStartsWith('/internal-nextcloud/', $response->headers['X-Accel-Redirect']);
        self::assertStringContainsString('/files/togare/', $response->headers['X-Accel-Redirect']);
        // Body NÃO escrito — Caddy substitui.
        self::assertSame('', $response->body);
    }

    public function testContentTypePropagaMimeType(): void
    {
        \putenv('TOGARE_DOWNLOAD_USE_PHP_PROXY=false');
        $doc = $this->makeDocumentoComUri(mimeType: 'image/png');
        $controller = $this->makeController($doc, aclAllowed: true);
        $response = new SpyResponse();

        $controller->getActionDownload($this->makeRequest('doc001'), $response);

        self::assertSame('image/png', $response->headers['Content-Type']);
        self::assertSame('private, max-age=0, must-revalidate', $response->headers['Cache-Control']);
        self::assertSame('nosniff', $response->headers['X-Content-Type-Options']);
    }

    public function testContentDispositionFilenameSanitizada(): void
    {
        \putenv('TOGARE_DOWNLOAD_USE_PHP_PROXY=false');
        $doc = $this->makeDocumentoComUri(filename: 'Peticao_inicial.pdf');
        $controller = $this->makeController($doc, aclAllowed: true);
        $response = new SpyResponse();

        $controller->getActionDownload($this->makeRequest('doc001'), $response);

        self::assertSame(
            'attachment; filename="Peticao_inicial.pdf"',
            $response->headers['Content-Disposition'],
        );
    }

    // =========================================================
    // Grupo C — Branch PHP-proxy fallback
    // =========================================================

    public function testPhpProxyChamaStreamWebDavQuandoEnvTrue(): void
    {
        \putenv('TOGARE_DOWNLOAD_USE_PHP_PROXY=true');
        $doc = $this->makeDocumentoComUri();
        $controller = $this->makeController($doc, aclAllowed: true);
        $response = new SpyResponse();

        $controller->getActionDownload($this->makeRequest('doc001'), $response);

        self::assertCount(1, $controller->client->streamCalls);
        self::assertSame(
            'processos/proc123/doc001-Peticao.pdf',
            $controller->client->streamCalls[0]['webdavPath'],
        );
        self::assertTrue($controller->terminateCalled, 'terminateAfterStream deve ter sido chamado');
        // X-Accel header NÃO deve ser setado no branch PHP-proxy.
        self::assertArrayNotHasKey('X-Accel-Redirect', $response->headers);
        // Stream headers escritos via writeStreamHeaders (mock captura).
        self::assertSame('application/pdf', $controller->streamHeaders['Content-Type'] ?? null);
        self::assertArrayNotHasKey('Content-Length', $controller->streamHeaders);
    }

    public function testPhpProxyConverteNextcloudUnavailableEm503(): void
    {
        \putenv('TOGARE_DOWNLOAD_USE_PHP_PROXY=true');
        $doc = $this->makeDocumentoComUri();
        $controller = $this->makeController($doc, aclAllowed: true);
        $controller->client->throwOnStream = new NextcloudUnavailableException([
            'cause' => 'cb_open',
            'cbOpenUntil' => 0,
        ]);

        try {
            $controller->getActionDownload($this->makeRequest('doc001'), new SpyResponse());
            self::fail('Esperava ServiceUnavailable');
        } catch (ServiceUnavailable $e) {
            self::assertStringContainsString(
                'Nextcloud indisponível',
                $e->getMessage(),
            );
        }
        // Audit deve ter 2 rows: 1 downloaded (antes do dispatch) + 1 download_failed.
        self::assertCount(2, $controller->audit->logs);
        self::assertSame('documento.downloaded', $controller->audit->logs[0]['event']);
        self::assertSame('documento.download_failed', $controller->audit->logs[1]['event']);
        self::assertSame('nextcloud_unavailable', $controller->audit->logs[1]['context']['reason']);
    }

    // =========================================================
    // Grupo D — Audit log
    // =========================================================

    public function testAuditDownloadedRowGravadoComBranchCorreto(): void
    {
        \putenv('TOGARE_DOWNLOAD_USE_PHP_PROXY=false');
        $doc = $this->makeDocumentoComUri();
        $controller = $this->makeController($doc, aclAllowed: true);

        $controller->getActionDownload($this->makeRequest('doc001'), new SpyResponse());

        self::assertCount(1, $controller->audit->logs);
        $row = $controller->audit->logs[0];
        self::assertSame('documento.downloaded', $row['event']);
        self::assertSame('Documento', $row['entityType']);
        self::assertSame('doc001', $row['entityId']);
        self::assertSame('x_accel', $row['context']['branch']);
        self::assertSame('proc123', $row['context']['processoId']);
        self::assertSame('application/pdf', $row['context']['mimeType']);
    }

    public function testAuditDownloadFailedRowQuandoNextcloudUnavailable(): void
    {
        \putenv('TOGARE_DOWNLOAD_USE_PHP_PROXY=true');
        $doc = $this->makeDocumentoComUri();
        $controller = $this->makeController($doc, aclAllowed: true);
        $controller->client->throwOnStream = new NextcloudUnavailableException(['cause' => 'cb_open']);

        try {
            $controller->getActionDownload($this->makeRequest('doc001'), new SpyResponse());
        } catch (ServiceUnavailable) {
        }

        $events = \array_column($controller->audit->logs, 'event');
        self::assertContains('documento.downloaded', $events);
        self::assertContains('documento.download_failed', $events);
        $failed = $controller->audit->logs[\array_search('documento.download_failed', $events, true)];
        self::assertSame('nextcloud_unavailable', $failed['context']['reason']);
    }

    // =========================================================
    // Helpers
    // =========================================================

    private function makeController(?Documento $documento, bool $aclAllowed = true): TestableDocumento
    {
        $em = $this->createStub(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(
            static fn (string $type, string $id): ?Documento =>
                $type === 'Documento' && $documento !== null && $documento->getId() === $id ? $documento : null,
        );

        $acl = new class ($aclAllowed) extends Acl {
            public function __construct(private readonly bool $allowed) {}
            public function checkEntity(mixed $entity, ?string $action = null): bool
            {
                return $this->allowed;
            }
        };

        $controller = new TestableDocumento();
        $controller->em = $em;
        $controller->acl = $acl;
        $controller->client = new SpyNextcloudClient();
        $controller->audit = new SpyAuditLogService();
        return $controller;
    }

    private function makeDocumento(string $id = 'doc001'): Documento
    {
        $entity = new Documento();
        $entity->setId($id);
        return $entity;
    }

    private function makeDocumentoComUri(
        string $filename = 'Peticao.pdf',
        string $mimeType = 'application/pdf',
        int $sizeBytes = 1024,
    ): Documento {
        $doc = $this->makeDocumento();
        $doc->set('nextcloudUri', 'nextcloud://processos/proc123/doc001-' . $filename);
        $doc->set('filename', $filename);
        $doc->set('mimeType', $mimeType);
        $doc->set('sizeBytes', $sizeBytes);
        $doc->set('processoId', 'proc123');
        return $doc;
    }

    private function makeRequest(string $id): Request
    {
        return new class ($id) implements Request {
            public function __construct(private string $id) {}
            public function getRouteParam(string $name): ?string
            {
                return $name === 'id' ? $this->id : null;
            }
            public function getServerParam(string $name): mixed { return null; }
            public function getHeader(string $name): ?string { return null; }
            public function getMethod(): string { return 'GET'; }
        };
    }
}

// =========================================================
// Test doubles (escopo: este arquivo de teste)
// =========================================================

/**
 * Subclasse testável do controller que substitui os pontos de extensão
 * protected (resolve*, openOutputStream, terminateAfterStream, etc.).
 *
 * Captura side-effects para assert no test body sem precisar de mock do
 * InjectableFactory ou de Response real do Slim.
 */
final class TestableDocumento extends DocumentoController
{
    public ?EntityManager $em = null;
    public ?Acl $acl = null;
    public ?SpyNextcloudClient $client = null;
    public SpyAuditLogService $audit;
    public bool $terminateCalled = false;
    /** @var array<string, mixed> */
    public array $streamHeaders = [];

    public function __construct()
    {
        // Skip parent (RecordBase ctor exige InjectableFactory).
    }

    protected function resolveEntityManager(): EntityManager
    {
        return $this->em ?? throw new RuntimeException('TestableDocumento: $em não setado');
    }

    protected function resolveAcl(): Acl
    {
        return $this->acl ?? throw new RuntimeException('TestableDocumento: $acl não setado');
    }

    protected function resolveNextcloudClient(): NextcloudClientContract
    {
        return $this->client ?? throw new RuntimeException('TestableDocumento: $client não setado');
    }

    protected function resolveAuditLog(): AuditLogContract
    {
        return $this->audit;
    }

    /** Override: não chamar exit em testes. */
    protected function terminateAfterStream(): void
    {
        $this->terminateCalled = true;
    }

    /** Override: stream em memória (não toca php://output / não chama ob_end_clean). */
    protected function openOutputStream()
    {
        $h = \fopen('php://memory', 'w+b');
        if ($h === false) {
            throw new RuntimeException('fopen php://memory falhou');
        }
        return $h;
    }

    /** Override: captura headers em vez de chamar header() nativo. */
    protected function writeStreamHeaders(string $mimeType, string $filename, int $sizeBytes): void
    {
        $this->streamHeaders = [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
            'X-Accel-Buffering' => 'no',
        ];
    }
}

/**
 * Spy de NextcloudClientContract — captura chamadas a streamWebDav.
 */
final class SpyNextcloudClient implements NextcloudClientContract
{
    /** @var list<array{webdavPath: string, hasStream: bool}> */
    public array $streamCalls = [];

    public ?\Throwable $throwOnStream = null;

    public function putWebDav(string $webdavPath, string $binaryContent): void {}
    public function getWebDav(string $webdavPath): string { return ''; }
    public function existsWebDav(string $webdavPath): bool { return true; }
    public function deleteWebDav(string $webdavPath): bool { return true; }
    public function moveWebDav(string $sourceWebDavPath, string $destinationWebDavPath): void {}
    public function propfindList(string $webdavPath): array { return []; }
    public function resolveWebDavUrl(string $logicalPath): string
    {
        return 'http://nextcloud:80/remote.php/dav/files/admin/togare/' . $logicalPath;
    }

    public function streamWebDav(string $webdavPath, $outputStream, ?callable $beforeFirstByte = null): void
    {
        $this->streamCalls[] = [
            'webdavPath' => $webdavPath,
            'hasStream' => \is_resource($outputStream),
        ];
        if ($this->throwOnStream !== null) {
            throw $this->throwOnStream;
        }
        if ($beforeFirstByte !== null) {
            $beforeFirstByte();
        }
    }
}

/**
 * Spy do AuditLogContract — captura `log()` em memória.
 *
 * Implementa o **contrato** (não a classe final `AuditLogService`) — o
 * controller foi redesenhado pra retornar a interface no `resolveAuditLog()`.
 */
final class SpyAuditLogService implements AuditLogContract
{
    /** @var list<array{event: string, entityType: string, entityId: ?string, context: array}> */
    public array $logs = [];

    public function log(string $event, string $entityType, ?string $entityId, array $context = []): void
    {
        $this->logs[] = [
            'event' => $event,
            'entityType' => $entityType,
            'entityId' => $entityId,
            'context' => $context,
        ];
    }
}

/**
 * Spy de Response — extende o stub leve de `Espo\Core\Api\Response` definido
 * em `CoreStubs.php` (classe, não interface — no host de testes unit).
 *
 * Em runtime real (EspoCRM 9.x) Response é uma interface; em tests usamos o
 * stub class que já agrega setStatus/setHeader/writeBody em memória.
 */
final class SpyResponse extends Response
{
}
