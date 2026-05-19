<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\Documento;

use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Entities\User;
use Espo\Modules\TogareCore\Entities\Documento;
use Espo\Modules\TogareCore\Hooks\Documento\DefaultUploadedByHook;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class DefaultUploadedByHookTest extends TestCase
{
    public function testForcaUploadedByParaUsuarioAtualMesmoComPayloadSpoofado(): void
    {
        $em = $this->createMock(EntityManager::class);
        $hook = new DefaultUploadedByHook($this->makeUser('user-current'), $em);
        $doc = $this->makeDocumento([
            'processoId' => 'proc-001',
            'uploadedById' => 'user-spoofed',
        ]);

        $hook->beforeSave($doc, SaveOptions::create());

        self::assertSame('user-current', $doc->get('uploadedById'));
        self::assertSame('user-current', $doc->get('assignedUserId'));
    }

    public function testHerdaAssignedUserDoProcessoPorId(): void
    {
        $processo = $this->makeParent('proc-001', 'adv-proc');
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->with('Processo', 'proc-001')->willReturn($processo);

        $hook = new DefaultUploadedByHook($this->makeUser('user-current'), $em);
        $doc = $this->makeDocumento(['processoId' => 'proc-001']);

        $hook->beforeSave($doc, SaveOptions::create());

        self::assertSame('user-current', $doc->get('uploadedById'));
        self::assertSame('adv-proc', $doc->get('assignedUserId'));
    }

    public function testHerdaAssignedUserDoClientePorId(): void
    {
        $cliente = $this->makeParent('cli-001', 'adv-cli');
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->with('Cliente', 'cli-001')->willReturn($cliente);

        $hook = new DefaultUploadedByHook($this->makeUser('user-current'), $em);
        $doc = $this->makeDocumento(['clienteId' => 'cli-001']);

        $hook->beforeSave($doc, SaveOptions::create());

        self::assertSame('adv-cli', $doc->get('assignedUserId'));
    }

    public function testHerdaAssignedUserDoPrazoPorId(): void
    {
        $prazo = $this->makeParent('prz-001', 'adv-prz');
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->with('Prazo', 'prz-001')->willReturn($prazo);

        $hook = new DefaultUploadedByHook($this->makeUser('user-current'), $em);
        $doc = $this->makeDocumento(['prazoId' => 'prz-001']);

        $hook->beforeSave($doc, SaveOptions::create());

        self::assertSame('user-current', $doc->get('uploadedById'));
        self::assertSame('adv-prz', $doc->get('assignedUserId'));
    }

    public function testFallbackUploadedByQuandoPrazoSemAssignedUser(): void
    {
        $prazo = $this->makeParent('prz-001', '');
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->with('Prazo', 'prz-001')->willReturn($prazo);

        $hook = new DefaultUploadedByHook($this->makeUser('user-current'), $em);
        $doc = $this->makeDocumento(['prazoId' => 'prz-001']);

        $hook->beforeSave($doc, SaveOptions::create());

        self::assertSame('user-current', $doc->get('uploadedById'));
        // Sem assignedUserId no Prazo → fallback = uploadedBy.
        self::assertSame('user-current', $doc->get('assignedUserId'));
    }

    public function testDocumentoExistenteIgnora(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->expects(self::never())->method('getEntityById');

        $hook = new DefaultUploadedByHook($this->makeUser('user-current'), $em);
        $doc = $this->makeDocumento(['uploadedById' => 'user-old']);
        $doc->setId('doc-existing');

        $hook->beforeSave($doc, SaveOptions::create());

        self::assertSame('user-old', $doc->get('uploadedById'));
    }

    /** @param array<string, mixed> $attrs */
    private function makeDocumento(array $attrs): Documento
    {
        $doc = new Documento();
        $doc->set($attrs);
        return $doc;
    }

    private function makeUser(string $id): User
    {
        $user = new User();
        $user->setId($id);
        return $user;
    }

    private function makeParent(string $id, string $assignedUserId): CoreEntity
    {
        $parent = new CoreEntity();
        $parent->setId($id);
        $parent->set('assignedUserId', $assignedUserId);
        return $parent;
    }
}
