<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\ContratoHonorarios;

use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Modules\TogareCore\Contracts\FileStorageContract;
use Espo\Modules\TogareCore\Entities\ContratoHonorarios;
use Espo\Modules\TogareCore\Hooks\ContratoHonorarios\MoveAttachmentToFileStorageHook;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

/**
 * Story 6.1 — cobre o hook BeforeSave order=30 que (a) move bytes do Attachment
 * temporário, (b) chama FileStorageContract::buildUri() AGNÓSTICO para
 * persistir URI dinâmica em fileStorageUri.
 *
 * Esta é a primeira entity Togare a usar buildUri() — testamos
 * AMBOS os schemes (nextcloud:// e local://) via mocks.
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class MoveAttachmentToFileStorageHookTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (\is_file($path)) {
                @\unlink($path);
            }
        }
        $this->tempFiles = [];
    }

    public function testUriUsaBuildUriNextcloudQuandoBridgeInstalado(): void
    {
        $attachment = $this->makeAttachment('att-001', 'contrato.pdf', 'application/pdf', 'BYTES_DO_CONTRATO');
        $this->writeAttachmentBytes('att-001', 'BYTES_DO_CONTRATO');

        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn($attachment);
        $repo = $this->createMock(\Espo\ORM\Repository\RDBRepository::class);
        $em->method('getRepository')->willReturn($repo);

        $captured = ['put_path' => null, 'put_bytes' => null, 'build_path' => null];
        $fileStorage = $this->createMock(FileStorageContract::class);
        $fileStorage->expects(self::once())
            ->method('put')
            ->willReturnCallback(function (string $p, string $b) use (&$captured): void {
                $captured['put_path'] = $p;
                $captured['put_bytes'] = $b;
            });
        $fileStorage->expects(self::once())
            ->method('buildUri')
            ->willReturnCallback(function (string $p) use (&$captured): string {
                $captured['build_path'] = $p;
                return 'nextcloud://' . $p;
            });

        $hook = new MoveAttachmentToFileStorageHook($em, $fileStorage);
        $c = $this->makeNewContrato('contrato-001', [
            'clienteId' => 'cli-001',
            'modalidade' => 'fixo',
            'uploadedAttachmentId' => 'att-001',
        ]);

        $hook->beforeSave($c, SaveOptions::create());

        self::assertSame('clientes/cli-001/contratos/contrato-001-contrato.pdf', $captured['put_path']);
        self::assertSame('BYTES_DO_CONTRATO', $captured['put_bytes']);
        self::assertSame('clientes/cli-001/contratos/contrato-001-contrato.pdf', $captured['build_path']);
        self::assertSame(
            'nextcloud://clientes/cli-001/contratos/contrato-001-contrato.pdf',
            $c->get('fileStorageUri'),
        );
        self::assertSame(\strlen('BYTES_DO_CONTRATO'), $c->get('sizeBytes'));
        MoveAttachmentToFileStorageHook::markUploadCommitted('contrato-001');
    }

    public function testUriUsaBuildUriLocalQuandoBridgeAusente(): void
    {
        // Mesmo cenário, FileStorageContract::buildUri retorna 'local://...'
        // simulando LocalDiskPurgeableStorage (fallback sem bridge).
        $attachment = $this->makeAttachment('att-002', 'contrato.pdf', 'application/pdf', 'X');
        $this->writeAttachmentBytes('att-002', 'X');

        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn($attachment);
        $repo = $this->createMock(\Espo\ORM\Repository\RDBRepository::class);
        $em->method('getRepository')->willReturn($repo);

        $fileStorage = $this->createMock(FileStorageContract::class);
        $fileStorage->method('put');
        $fileStorage->method('buildUri')->willReturnCallback(
            fn (string $p): string => 'local://' . $p,
        );

        $hook = new MoveAttachmentToFileStorageHook($em, $fileStorage);
        $c = $this->makeNewContrato('contrato-002', [
            'clienteId' => 'cli-002',
            'modalidade' => 'fixo',
            'uploadedAttachmentId' => 'att-002',
        ]);

        $hook->beforeSave($c, SaveOptions::create());

        self::assertSame(
            'local://clientes/cli-002/contratos/contrato-002-contrato.pdf',
            $c->get('fileStorageUri'),
        );
        MoveAttachmentToFileStorageHook::markUploadCommitted('contrato-002');
    }

    public function testNaoIsNewSkipa(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->expects(self::never())->method('getEntityById');

        $fileStorage = $this->createMock(FileStorageContract::class);
        $fileStorage->expects(self::never())->method('put');

        $hook = new MoveAttachmentToFileStorageHook($em, $fileStorage);
        $c = new ContratoHonorarios();
        $c->set('clienteId', 'cli-001');
        $c->set('uploadedAttachmentId', 'att-001');
        $c->set('fileStorageUri', 'nextcloud://clientes/cli-001/contratos/old-foo.pdf');
        $c->setId('contrato-existente'); // setId marca new=false em Espo Entity

        $hook->beforeSave($c, SaveOptions::create());

        self::assertSame(
            'nextcloud://clientes/cli-001/contratos/old-foo.pdf',
            $c->get('fileStorageUri'),
        );
    }

    public function testEntidadeNaoContratoIgnora(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->expects(self::never())->method('getEntityById');

        $fileStorage = $this->createMock(FileStorageContract::class);
        $fileStorage->expects(self::never())->method('put');

        $hook = new MoveAttachmentToFileStorageHook($em, $fileStorage);
        $other = new CoreEntity();
        $hook->beforeSave($other, SaveOptions::create());
        self::assertTrue(true);
    }

    /** @param array<string, mixed> $attrs */
    private function makeNewContrato(string $id, array $attrs): ContratoHonorarios
    {
        $c = new ContratoHonorarios();
        $c->set($attrs);
        self::injectId($c, $id);
        return $c;
    }

    private function makeAttachment(string $id, string $name, string $type, string $contents): CoreEntity
    {
        $attachment = new CoreEntity();
        $attachment->set([
            'name' => $name,
            'type' => $type,
            'size' => \strlen($contents),
        ]);
        $attachment->setId($id);
        return $attachment;
    }

    private function writeAttachmentBytes(string $attachmentId, string $contents): void
    {
        $dir = 'data/upload';
        if (! \is_dir($dir)) {
            \mkdir($dir, 0777, true);
        }
        $path = $dir . '/' . $attachmentId;
        \file_put_contents($path, $contents);
        $this->tempFiles[] = $path;
    }

    private static function injectId(\Espo\ORM\Entity $entity, string $id): void
    {
        $ref = new \ReflectionClass($entity);
        while ($ref !== false && ! $ref->hasProperty('id')) {
            $ref = $ref->getParentClass();
        }
        if ($ref === false) {
            return;
        }
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($entity, $id);
    }
}
