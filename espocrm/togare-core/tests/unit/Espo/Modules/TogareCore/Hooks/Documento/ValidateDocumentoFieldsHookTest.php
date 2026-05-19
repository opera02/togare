<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\Documento;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Entities\User;
use Espo\Modules\TogareCore\Entities\Documento;
use Espo\Modules\TogareCore\Hooks\Documento\ValidateDocumentoFieldsHook;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

/**
 * Story 5.2 + Story 5.6 — Cobre XOR triplo Processo/Cliente/Prazo + MIME
 * allowlist + max size em ValidateDocumentoFieldsHook (BeforeSave order=10).
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class ValidateDocumentoFieldsHookTest extends TestCase
{
    public function testXorTriploTodosNullLancaBadRequestPtBr(): void
    {
        $em = $this->createMock(EntityManager::class);
        $hook = $this->makeHook($em);
        $doc = $this->makeNewDocumento([
            'uploadedAttachmentId' => 'att-001',
        ]);

        try {
            $hook->beforeSave($doc, SaveOptions::create());
            self::fail('BadRequest XOR todos null não foi lançada');
        } catch (BadRequest $e) {
            $body = $e->getBody();
            self::assertNotNull($body);
            $decoded = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('xor_all_null', $decoded['reason']);
            self::assertStringContainsString('Processo, Cliente OU Prazo', $decoded['message']);
        }
    }

    public function testXorTriploProcessoEClienteLancaBadRequestPtBr(): void
    {
        $em = $this->createMock(EntityManager::class);
        $hook = $this->makeHook($em);
        $doc = $this->makeNewDocumento([
            'processoId' => 'proc-001',
            'clienteId' => 'cli-001',
            'uploadedAttachmentId' => 'att-001',
        ]);

        try {
            $hook->beforeSave($doc, SaveOptions::create());
            self::fail('BadRequest XOR processo+cliente não foi lançada');
        } catch (BadRequest $e) {
            $body = $e->getBody();
            self::assertNotNull($body);
            $decoded = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('xor_multiple_set', $decoded['reason']);
            self::assertSame(2, $decoded['countSetados']);
            self::assertStringContainsString('mais de um registro', $decoded['message']);
        }
    }

    public function testXorTriploProcessoEPrazoLancaBadRequest(): void
    {
        $em = $this->createMock(EntityManager::class);
        $hook = $this->makeHook($em);
        $doc = $this->makeNewDocumento([
            'processoId' => 'proc-001',
            'prazoId' => 'prz-001',
            'uploadedAttachmentId' => 'att-001',
        ]);

        try {
            $hook->beforeSave($doc, SaveOptions::create());
            self::fail('BadRequest XOR processo+prazo não foi lançada');
        } catch (BadRequest $e) {
            $decoded = json_decode((string) $e->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('xor_multiple_set', $decoded['reason']);
            self::assertSame(2, $decoded['countSetados']);
        }
    }

    public function testXorTriploClienteEPrazoLancaBadRequest(): void
    {
        $em = $this->createMock(EntityManager::class);
        $hook = $this->makeHook($em);
        $doc = $this->makeNewDocumento([
            'clienteId' => 'cli-001',
            'prazoId' => 'prz-001',
            'uploadedAttachmentId' => 'att-001',
        ]);

        try {
            $hook->beforeSave($doc, SaveOptions::create());
            self::fail('BadRequest XOR cliente+prazo não foi lançada');
        } catch (BadRequest $e) {
            $decoded = json_decode((string) $e->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('xor_multiple_set', $decoded['reason']);
            self::assertSame(2, $decoded['countSetados']);
        }
    }

    public function testXorTriploTodosTresLancaBadRequest(): void
    {
        $em = $this->createMock(EntityManager::class);
        $hook = $this->makeHook($em);
        $doc = $this->makeNewDocumento([
            'processoId' => 'proc-001',
            'clienteId' => 'cli-001',
            'prazoId' => 'prz-001',
            'uploadedAttachmentId' => 'att-001',
        ]);

        try {
            $hook->beforeSave($doc, SaveOptions::create());
            self::fail('BadRequest XOR todos os 3 não foi lançada');
        } catch (BadRequest $e) {
            $decoded = json_decode((string) $e->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('xor_multiple_set', $decoded['reason']);
            self::assertSame(3, $decoded['countSetados']);
        }
    }

    public function testSoPrazoIdValidoComMimePermitidoPassa(): void
    {
        $attachment = $this->makeAttachment('peticao_cumprimento.pdf', 'application/pdf', 2048);
        $parent = $this->makeParent('prz-001');
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(
            fn (string $entityType, string $id): ?CoreEntity => $entityType === 'Prazo' ? $parent : $attachment,
        );

        $hook = $this->makeHook($em);
        $doc = $this->makeNewDocumento([
            'prazoId' => 'prz-001',
            'uploadedAttachmentId' => 'att-001',
        ]);

        $hook->beforeSave($doc, SaveOptions::create());

        self::assertSame('peticao_cumprimento.pdf', $doc->get('filename'));
        self::assertSame('application/pdf', $doc->get('mimeType'));
        self::assertSame(2048, $doc->get('sizeBytes'));
    }

    public function testReatribuirPrazoIdLancaBadRequest(): void
    {
        $em = $this->createMock(EntityManager::class);
        $hook = $this->makeHook($em);
        $doc = new Documento();
        $doc->setId('doc-001');
        $doc->set('prazoId', 'prz-new');
        $doc->setFetched('prazoId', 'prz-old');

        try {
            $hook->beforeSave($doc, SaveOptions::create());
            self::fail('BadRequest documento_link_immutable em prazoId não foi lançada');
        } catch (BadRequest $e) {
            $decoded = json_decode((string) $e->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('documento_link_immutable', $decoded['reason']);
        }
    }

    public function testSoProcessoIdValidoComMimePermitidoPassa(): void
    {
        $attachment = $this->makeAttachment('peticao.pdf', 'application/pdf', 1024);
        $parent = $this->makeParent('proc-001');
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(
            fn (string $entityType, string $id): ?CoreEntity => $entityType === 'Processo' ? $parent : $attachment,
        );

        $hook = $this->makeHook($em);
        $doc = $this->makeNewDocumento([
            'processoId' => 'proc-001',
            'uploadedAttachmentId' => 'att-001',
        ]);

        $hook->beforeSave($doc, SaveOptions::create());

        self::assertSame('peticao.pdf', $doc->get('filename'));
        self::assertSame('application/pdf', $doc->get('mimeType'));
        self::assertSame(1024, $doc->get('sizeBytes'));
    }

    public function testSoClienteIdValidoComMimePermitidoPassa(): void
    {
        $attachment = $this->makeAttachment('contrato.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 50000);
        $parent = $this->makeParent('cli-001');
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(
            fn (string $entityType, string $id): ?CoreEntity => $entityType === 'Cliente' ? $parent : $attachment,
        );

        $hook = $this->makeHook($em);
        $doc = $this->makeNewDocumento([
            'clienteId' => 'cli-001',
            'uploadedAttachmentId' => 'att-001',
        ]);

        $hook->beforeSave($doc, SaveOptions::create());

        self::assertSame('contrato.docx', $doc->get('filename'));
        self::assertSame(50000, $doc->get('sizeBytes'));
    }

    public function testMimeNaoPermitidoLancaBadRequest(): void
    {
        $attachment = $this->makeAttachment('virus.exe', 'application/x-msdownload', 10000);
        $parent = $this->makeParent('proc-001');
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(
            fn (string $entityType, string $id): ?CoreEntity => $entityType === 'Processo' ? $parent : $attachment,
        );

        $hook = $this->makeHook($em);
        $doc = $this->makeNewDocumento([
            'processoId' => 'proc-001',
            'uploadedAttachmentId' => 'att-001',
        ]);

        try {
            $hook->beforeSave($doc, SaveOptions::create());
            self::fail('BadRequest MIME inválido não foi lançada');
        } catch (BadRequest $e) {
            $body = $e->getBody();
            self::assertNotNull($body);
            $decoded = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('mime_rejected', $decoded['reason']);
            self::assertSame('application/x-msdownload', $decoded['mimeType']);
            self::assertStringContainsString('Tipo de arquivo não permitido', $decoded['message']);
        }
    }

    public function testTamanhoExcedidoLancaBadRequest(): void
    {
        $attachment = $this->makeAttachment('big.pdf', 'application/pdf', 250 * 1024 * 1024);
        $parent = $this->makeParent('proc-001');
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(
            fn (string $entityType, string $id): ?CoreEntity => $entityType === 'Processo' ? $parent : $attachment,
        );

        $hook = $this->makeHook($em);
        $doc = $this->makeNewDocumento([
            'processoId' => 'proc-001',
            'uploadedAttachmentId' => 'att-001',
        ]);

        try {
            $hook->beforeSave($doc, SaveOptions::create());
            self::fail('BadRequest size_exceeded não foi lançada');
        } catch (BadRequest $e) {
            $body = $e->getBody();
            self::assertNotNull($body);
            $decoded = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('size_exceeded', $decoded['reason']);
            self::assertSame(250, $decoded['sizeMb']);
            self::assertSame(200, $decoded['limitMb']);
        }
    }

    public function testAttachmentIdAusenteLancaBadRequest(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn($this->makeParent('proc-001'));
        $hook = $this->makeHook($em);
        $doc = $this->makeNewDocumento([
            'processoId' => 'proc-001',
        ]);

        try {
            $hook->beforeSave($doc, SaveOptions::create());
            self::fail('BadRequest missing_attachment não foi lançada');
        } catch (BadRequest $e) {
            $body = $e->getBody();
            self::assertNotNull($body);
            $decoded = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('missing_attachment', $decoded['reason']);
        }
    }

    public function testEntidadeNaoDocumentoIgnora(): void
    {
        $em = $this->createMock(EntityManager::class);
        $hook = $this->makeHook($em);
        $other = new CoreEntity();
        $other->set('processoId', null);
        $other->set('clienteId', null);

        // Não deve lançar — não é Documento.
        $hook->beforeSave($other, SaveOptions::create());

        self::assertNull($other->get('processoId'));
    }

    public function testReatribuirDocumentoExistenteLancaBadRequest(): void
    {
        $em = $this->createMock(EntityManager::class);
        $hook = $this->makeHook($em);
        $doc = new Documento();
        $doc->setId('doc-001');
        $doc->set('processoId', 'proc-new');
        $doc->setFetched('processoId', 'proc-old');

        try {
            $hook->beforeSave($doc, SaveOptions::create());
            self::fail('BadRequest documento_link_immutable não foi lançada');
        } catch (BadRequest $e) {
            $body = $e->getBody();
            self::assertNotNull($body);
            $decoded = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('documento_link_immutable', $decoded['reason']);
        }
    }

    public function testProcessoInexistenteLancaBadRequest(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(
            fn (string $entityType, string $id): ?CoreEntity => $entityType === 'Processo' ? null : $this->makeAttachment('a.pdf', 'application/pdf', 10),
        );
        $hook = $this->makeHook($em);
        $doc = $this->makeNewDocumento([
            'processoId' => 'proc-missing',
            'uploadedAttachmentId' => 'att-001',
        ]);

        $this->expectException(BadRequest::class);
        $hook->beforeSave($doc, SaveOptions::create());
    }

    public function testAclDoParentFalhaLancaForbidden(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn($this->makeParent('proc-001'));
        $hook = $this->makeHook($em, $this->makeAcl(false));
        $doc = $this->makeNewDocumento([
            'processoId' => 'proc-001',
            'uploadedAttachmentId' => 'att-001',
        ]);

        $this->expectException(Forbidden::class);
        $hook->beforeSave($doc, SaveOptions::create());
    }

    public function testAttachmentComRoleInvalidoLancaBadRequest(): void
    {
        $attachment = $this->makeAttachment('foo.pdf', 'application/pdf', 100, ['role' => 'Inline Attachment']);
        $parent = $this->makeParent('proc-001');
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(
            fn (string $entityType, string $id): ?CoreEntity => $entityType === 'Processo' ? $parent : $attachment,
        );
        $hook = $this->makeHook($em);
        $doc = $this->makeNewDocumento([
            'processoId' => 'proc-001',
            'uploadedAttachmentId' => 'att-001',
        ]);

        $this->expectException(BadRequest::class);
        $hook->beforeSave($doc, SaveOptions::create());
    }

    public function testAttachmentDeOutroUsuarioLancaForbidden(): void
    {
        $attachment = $this->makeAttachment('foo.pdf', 'application/pdf', 100, ['createdById' => 'user-other']);
        $parent = $this->makeParent('proc-001');
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(
            fn (string $entityType, string $id): ?CoreEntity => $entityType === 'Processo' ? $parent : $attachment,
        );
        $hook = $this->makeHook($em);
        $doc = $this->makeNewDocumento([
            'processoId' => 'proc-001',
            'uploadedAttachmentId' => 'att-001',
        ]);

        $this->expectException(Forbidden::class);
        $hook->beforeSave($doc, SaveOptions::create());
    }

    /**
     * Cria Documento "novo" com atributos iniciais. Espo Entity::isNew()
     * default = true em construção sem id.
     *
     * @param array<string, mixed> $data
     */
    private function makeNewDocumento(array $data): Documento
    {
        $doc = new Documento();
        $doc->set($data);
        return $doc;
    }

    /** @param array<string, mixed> $overrides */
    private function makeAttachment(string $name, string $type, int $size, array $overrides = []): CoreEntity
    {
        $attachment = new CoreEntity();
        $attachment->set(array_merge([
            'name' => $name,
            'type' => $type,
            'size' => $size,
            'role' => Documento::ATTACHMENT_ROLE,
            'parentType' => Documento::ENTITY_TYPE,
            'field' => 'uploadedAttachment',
            'createdById' => 'user-001',
        ], $overrides));
        $attachment->setId('att-001');
        return $attachment;
    }

    private function makeParent(string $id): CoreEntity
    {
        $parent = new CoreEntity();
        $parent->setId($id);
        return $parent;
    }

    private function makeUser(): User
    {
        $user = new User();
        $user->setId('user-001');
        return $user;
    }

    private function makeAcl(bool $allowed = true): Acl
    {
        return new class ($allowed) extends Acl {
            public function __construct(private bool $allowed)
            {
            }

            public function checkEntity(mixed $entity, ?string $action = null): bool
            {
                return $this->allowed;
            }
        };
    }

    private function makeHook(EntityManager $em, ?Acl $acl = null): ValidateDocumentoFieldsHook
    {
        return new ValidateDocumentoFieldsHook($em, $acl ?? $this->makeAcl(), $this->makeUser());
    }
}
