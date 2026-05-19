<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\Documento;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Modules\TogareCore\Contracts\FileStorageContract;
use Espo\Modules\TogareCore\Entities\Documento;
use Espo\Modules\TogareCore\Hooks\Documento\MoveAttachmentToNextcloudHook;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

/**
 * Story 5.2 — cobre o hook BeforeSave order=30 que move bytes do Attachment
 * temporário pro Nextcloud via FileStorageContract::put().
 *
 * Pegadinha B-NEW-A1: usa BeforeSave (não AfterSave) para que exception em
 * put() aborte o save naturalmente — AfterSave roda pós-COMMIT em Espo 9.x.
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class MoveAttachmentToNextcloudHookTest extends TestCase
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

    public function testHappyPathDelegaPutEsetaNextcloudUri(): void
    {
        $attachment = $this->makeAttachment('att-001', 'peticao.pdf', 'application/pdf', 'BYTES_DA_PETICAO');
        $this->writeAttachmentBytes('att-001', 'BYTES_DA_PETICAO');

        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn($attachment);
        $repo = $this->createMock(\Espo\ORM\Repository\RDBRepository::class);
        $em->method('getRepository')->willReturn($repo);

        $captured = ['path' => null, 'bytes' => null];
        $fileStorage = $this->createMock(FileStorageContract::class);
        $fileStorage->expects(self::once())
            ->method('put')
            ->willReturnCallback(function (string $path, string $bytes) use (&$captured): void {
                $captured['path'] = $path;
                $captured['bytes'] = $bytes;
            });

        $hook = new MoveAttachmentToNextcloudHook($em, $fileStorage);
        $doc = $this->makeNewDocumento('doc-id-001', [
            'processoId' => 'proc-001',
            'uploadedAttachmentId' => 'att-001',
        ]);

        $hook->beforeSave($doc, SaveOptions::create());

        self::assertNotNull($captured['path']);
        self::assertStringStartsWith('processos/proc-001/doc-id-001-', $captured['path']);
        self::assertStringEndsWith('peticao.pdf', $captured['path']);
        self::assertSame(strlen('BYTES_DA_PETICAO'), $doc->get('sizeBytes'));
        self::assertSame('nextcloud://' . $captured['path'], $doc->get('nextcloudUri'));
        MoveAttachmentToNextcloudHook::markUploadCommitted('doc-id-001');
    }

    public function testAttachmentNaoEncontradoLancaError(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn(null);

        $fileStorage = $this->createMock(FileStorageContract::class);
        $fileStorage->expects(self::never())->method('put');

        $hook = new MoveAttachmentToNextcloudHook($em, $fileStorage);
        $doc = $this->makeNewDocumento('doc-id-002', [
            'processoId' => 'proc-001',
            'uploadedAttachmentId' => 'att-missing',
        ]);

        $this->expectException(Error::class);
        $hook->beforeSave($doc, SaveOptions::create());
    }

    public function testPutFalhaRelancaErrorComMensagemPtBr(): void
    {
        $attachment = $this->makeAttachment('att-001', 'foo.pdf', 'application/pdf', 'B');
        $this->writeAttachmentBytes('att-001', 'B');

        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn($attachment);
        $em->method('getRepository')->willReturn($this->createMock(\Espo\ORM\Repository\RDBRepository::class));

        $fileStorage = $this->createMock(FileStorageContract::class);
        $fileStorage->method('put')->willThrowException(new \RuntimeException('Nextcloud indisponível.'));
        $fileStorage->expects(self::once())->method('delete');

        $hook = new MoveAttachmentToNextcloudHook($em, $fileStorage);
        $doc = $this->makeNewDocumento('doc-id-003', [
            'processoId' => 'proc-001',
            'uploadedAttachmentId' => 'att-001',
        ]);

        try {
            $hook->beforeSave($doc, SaveOptions::create());
            self::fail('Error não foi relançada quando put() falhou.');
        } catch (Error $e) {
            self::assertStringContainsString('Nextcloud', $e->getMessage());
            self::assertStringContainsString('Tente novamente', $e->getMessage());
            // Documento NÃO deve ter ficado com nextcloudUri setada.
            self::assertNull($doc->get('nextcloudUri'));
        }
    }

    public function testTamanhoRealExcedidoNaoChamaPut(): void
    {
        $oldLimit = getenv('TOGARE_MAX_DOCUMENT_SIZE_MB');
        putenv('TOGARE_MAX_DOCUMENT_SIZE_MB=1');

        try {
            $contents = str_repeat('A', 1024 * 1024 + 1);
            $attachment = $this->makeAttachment('att-001', 'big.pdf', 'application/pdf', $contents);
            $this->writeAttachmentBytes('att-001', $contents);

            $em = $this->createMock(EntityManager::class);
            $em->method('getEntityById')->willReturn($attachment);
            $em->method('getRepository')->willReturn($this->createMock(\Espo\ORM\Repository\RDBRepository::class));

            $fileStorage = $this->createMock(FileStorageContract::class);
            $fileStorage->expects(self::never())->method('put');

            $hook = new MoveAttachmentToNextcloudHook($em, $fileStorage);
            $doc = $this->makeNewDocumento('doc-id-big', [
                'processoId' => 'proc-001',
                'uploadedAttachmentId' => 'att-001',
            ]);

            $this->expectException(BadRequest::class);
            $hook->beforeSave($doc, SaveOptions::create());
        } finally {
            if ($oldLimit === false) {
                putenv('TOGARE_MAX_DOCUMENT_SIZE_MB');
            } else {
                putenv('TOGARE_MAX_DOCUMENT_SIZE_MB=' . $oldLimit);
            }
        }
    }

    public function testNaoIsNewSkipa(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->expects(self::never())->method('getEntityById');

        $fileStorage = $this->createMock(FileStorageContract::class);
        $fileStorage->expects(self::never())->method('put');

        $hook = new MoveAttachmentToNextcloudHook($em, $fileStorage);
        $doc = $this->makeExistingDocumento('doc-existente', [
            'processoId' => 'proc-001',
            'nextcloudUri' => 'nextcloud://processos/proc-001/doc-existente-foo.pdf',
        ]);

        $hook->beforeSave($doc, SaveOptions::create());

        // Sem mudança — entity existente preserva URI.
        self::assertSame('nextcloud://processos/proc-001/doc-existente-foo.pdf', $doc->get('nextcloudUri'));
    }

    public function testEntidadeNaoDocumentoIgnora(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->expects(self::never())->method('getEntityById');

        $fileStorage = $this->createMock(FileStorageContract::class);
        $fileStorage->expects(self::never())->method('put');

        $hook = new MoveAttachmentToNextcloudHook($em, $fileStorage);
        $other = new CoreEntity();
        $other->set('uploadedAttachmentId', 'att-001');

        $hook->beforeSave($other, SaveOptions::create());

        self::assertSame('att-001', $other->get('uploadedAttachmentId'));
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function makeNewDocumento(string $id, array $attrs): Documento
    {
        $doc = new Documento();
        $doc->set($attrs);
        // Espo Entity stub: setId() seta também `$this->new = false`. Como precisamos
        // que isNew() continue retornando true (entity acabou de ser criada na request
        // de save), usamos reflection pra setar id sem mudar new flag.
        self::injectId($doc, $id);
        return $doc;
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function makeExistingDocumento(string $id, array $attrs): Documento
    {
        $doc = new Documento();
        $doc->set($attrs);
        // Entity persisted: setId já seta new=false (idêntico fetch from DB).
        $doc->setId($id);
        return $doc;
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

    /**
     * Cria arquivo temporário em data/upload/<id> (relativo ao cwd) para o
     * fallback file_get_contents do hook conseguir ler bytes em testes
     * unit standalone (sem repo Espo real).
     */
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
        // Sobe na hierarquia até achar a property `id` declarada em Espo\Core\ORM\Entity stub.
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
