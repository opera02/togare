<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Controllers;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\Modules\TogareCore\Controllers\ContratoHonorarios as ContratoHonorariosController;
use Espo\Modules\TogareCore\Entities\ContratoHonorarios;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Cobre o download proxy de ContratoHonorarios (Story 6.1).
 */
final class ContratoHonorariosControllerTest extends TestCase
{
    private ?string $localRoot = null;

    protected function setUp(): void
    {
        \putenv('TOGARE_NEXTCLOUD_USER=admin');
        \putenv('NEXTCLOUD_ADMIN_USER=admin');
        \putenv('TOGARE_LOCAL_STORAGE_ROOT');

        $stdout = \fopen('php://memory', 'w+');
        $stderr = \fopen('php://memory', 'w+');
        if ($stdout === false || $stderr === false) {
            throw new RuntimeException('Falha ao abrir streams de log em memoria.');
        }
        TogareLogger::init('togare-core-test', null, $stdout, $stderr);
    }

    protected function tearDown(): void
    {
        \putenv('TOGARE_NEXTCLOUD_USER');
        \putenv('NEXTCLOUD_ADMIN_USER');
        \putenv('TOGARE_LOCAL_STORAGE_ROOT');
        TogareLogger::reset();

        if ($this->localRoot !== null && \is_dir($this->localRoot)) {
            @\rmdir($this->localRoot);
        }
    }

    public function testAclDeniedRetorna403EAudita(): void
    {
        $contrato = $this->makeContratoComUri();
        $controller = $this->makeController($contrato, aclAllowed: false);

        $this->expectException(Forbidden::class);

        try {
            $controller->getActionDownload($this->makeRequest('contrato001'), new ContratoSpyResponse());
        } finally {
            self::assertCount(1, $controller->audit->logs);
            self::assertSame('contrato.download_denied', $controller->audit->logs[0]['event']);
            self::assertSame('acl_denied', $controller->audit->logs[0]['context']['reason']);
        }
    }

    public function testContratoNaoExisteRetorna404(): void
    {
        $controller = $this->makeController(null);

        $this->expectException(NotFound::class);

        $controller->getActionDownload($this->makeRequest('missing'), new ContratoSpyResponse());
    }

    public function testUriInvalidaRetornaErroEAudita(): void
    {
        $contrato = $this->makeContrato();
        $contrato->set('fileStorageUri', 'gibberish-sem-prefixo');
        $controller = $this->makeController($contrato);

        $this->expectException(Error::class);

        try {
            $controller->getActionDownload($this->makeRequest('contrato001'), new ContratoSpyResponse());
        } finally {
            self::assertCount(1, $controller->audit->logs);
            self::assertSame('contrato.download_failed', $controller->audit->logs[0]['event']);
            self::assertSame('uri_invalid', $controller->audit->logs[0]['context']['reason']);
        }
    }

    public function testNextcloudBranchEmiteXAccelHeaders(): void
    {
        $contrato = $this->makeContratoComUri();
        $controller = $this->makeController($contrato);
        $response = new ContratoSpyResponse();

        $controller->getActionDownload($this->makeRequest('contrato001'), $response);

        self::assertSame(200, $response->status);
        self::assertSame('application/pdf', $response->headers['Content-Type']);
        self::assertSame('attachment; filename="Contrato.pdf"', $response->headers['Content-Disposition']);
        self::assertArrayHasKey('X-Accel-Redirect', $response->headers);
        self::assertStringStartsWith('/internal-nextcloud/data/admin/files/togare/', $response->headers['X-Accel-Redirect']);
        self::assertStringContainsString('clientes/cli001/contratos/contrato001-Contrato.pdf', $response->headers['X-Accel-Redirect']);
        self::assertSame('', $response->body);
    }

    public function testLocalBranchDespachaParaLocalDisk(): void
    {
        $contrato = $this->makeContratoComUri(scheme: 'local');
        $controller = $this->makeController($contrato);

        $controller->getActionDownload($this->makeRequest('contrato001'), new ContratoSpyResponse());

        self::assertCount(1, $controller->localDiskCalls);
        self::assertSame('clientes/cli001/contratos/contrato001-Contrato.pdf', $controller->localDiskCalls[0]['logicalPath']);
        self::assertSame('contrato001', $controller->localDiskCalls[0]['contratoId']);
        self::assertSame('local', $controller->audit->logs[0]['context']['branch']);
    }

    public function testLocalBranchArquivoAusenteRetorna404EAuditaFalha(): void
    {
        $this->localRoot = \sys_get_temp_dir() . '/togare-contrato-download-' . \bin2hex(\random_bytes(4));
        self::assertTrue(\mkdir($this->localRoot));
        \putenv('TOGARE_LOCAL_STORAGE_ROOT=' . $this->localRoot);

        $contrato = $this->makeContratoComUri(scheme: 'local');
        $controller = $this->makeController($contrato);
        $controller->captureLocalDisk = false;

        $this->expectException(NotFound::class);

        try {
            $controller->getActionDownload($this->makeRequest('contrato001'), new ContratoSpyResponse());
        } finally {
            self::assertCount(2, $controller->audit->logs);
            self::assertSame('contrato.downloaded', $controller->audit->logs[0]['event']);
            self::assertSame('contrato.download_failed', $controller->audit->logs[1]['event']);
            self::assertSame('file_missing', $controller->audit->logs[1]['context']['reason']);
        }
    }

    public function testAuditDownloadedIncluiMetadadosPrincipais(): void
    {
        $contrato = $this->makeContratoComUri();
        $controller = $this->makeController($contrato);

        $controller->getActionDownload($this->makeRequest('contrato001'), new ContratoSpyResponse());

        self::assertCount(1, $controller->audit->logs);
        $row = $controller->audit->logs[0];
        self::assertSame('contrato.downloaded', $row['event']);
        self::assertSame('ContratoHonorarios', $row['entityType']);
        self::assertSame('contrato001', $row['entityId']);
        self::assertSame('nextcloud', $row['context']['branch']);
        self::assertSame('cli001', $row['context']['clienteId']);
        self::assertSame('application/pdf', $row['context']['mimeType']);
    }

    private function makeController(?ContratoHonorarios $contrato, bool $aclAllowed = true): TestableContratoHonorarios
    {
        $em = $this->createStub(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(
            static fn (string $type, string $id): ?ContratoHonorarios =>
                $type === 'ContratoHonorarios' && $contrato !== null && $contrato->getId() === $id ? $contrato : null,
        );

        $acl = new class ($aclAllowed) extends Acl {
            public function __construct(private readonly bool $allowed) {}
            public function checkEntity(mixed $entity, ?string $action = null): bool
            {
                return $this->allowed;
            }
        };

        $controller = new TestableContratoHonorarios();
        $controller->em = $em;
        $controller->acl = $acl;
        $controller->audit = new ContratoSpyAuditLogService();

        return $controller;
    }

    private function makeContrato(string $id = 'contrato001'): ContratoHonorarios
    {
        $entity = new ContratoHonorarios();
        $entity->setId($id);

        return $entity;
    }

    private function makeContratoComUri(
        string $scheme = 'nextcloud',
        string $filename = 'Contrato.pdf',
        string $mimeType = 'application/pdf',
        int $sizeBytes = 1024,
    ): ContratoHonorarios {
        $contrato = $this->makeContrato();
        $contrato->set('clienteId', 'cli001');
        $contrato->set('modalidade', 'fixo');
        $contrato->set('filename', $filename);
        $contrato->set('mimeType', $mimeType);
        $contrato->set('sizeBytes', $sizeBytes);
        $contrato->set('fileStorageUri', $scheme . '://clientes/cli001/contratos/contrato001-' . $filename);

        return $contrato;
    }

    private function makeRequest(string $id): Request
    {
        return new class ($id) implements Request {
            public function __construct(private string $id) {}
            public function getRouteParam(string $name): ?string
            {
                return $name === 'id' ? $this->id : null;
            }
            public function getQueryParam(string $name): ?string
            {
                return null;
            }
            public function getServerParam(string $name): mixed { return null; }
            public function getHeader(string $name): ?string { return null; }
            public function getMethod(): string { return 'GET'; }
        };
    }
}

final class TestableContratoHonorarios extends ContratoHonorariosController
{
    public ?EntityManager $em = null;
    public ?Acl $acl = null;
    public ContratoSpyAuditLogService $audit;
    public bool $captureLocalDisk = true;
    public bool $terminateCalled = false;
    /** @var list<array{logicalPath: string, contratoId: string, userId: ?string}> */
    public array $localDiskCalls = [];

    public function __construct()
    {
    }

    protected function resolveEntityManager(): EntityManager
    {
        return $this->em ?? throw new RuntimeException('TestableContratoHonorarios: $em nao setado');
    }

    protected function resolveAcl(): Acl
    {
        return $this->acl ?? throw new RuntimeException('TestableContratoHonorarios: $acl nao setado');
    }

    protected function resolveAuditLog(): AuditLogContract
    {
        return $this->audit;
    }

    protected function emitStreamLocalDisk(
        ContratoHonorarios $entity,
        string $logicalPath,
        string $contratoId,
        ?string $userId,
    ): void {
        if (! $this->captureLocalDisk) {
            parent::emitStreamLocalDisk($entity, $logicalPath, $contratoId, $userId);
            return;
        }

        $this->localDiskCalls[] = [
            'logicalPath' => $logicalPath,
            'contratoId' => $contratoId,
            'userId' => $userId,
        ];
    }

    protected function terminateAfterStream(): void
    {
        $this->terminateCalled = true;
    }
}

final class ContratoSpyAuditLogService implements AuditLogContract
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

final class ContratoSpyResponse extends Response
{
}
