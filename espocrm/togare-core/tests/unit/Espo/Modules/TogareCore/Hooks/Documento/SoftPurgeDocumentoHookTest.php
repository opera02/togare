<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\Documento;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Modules\TogareCore\Contracts\PurgeableStorageContract;
use Espo\Modules\TogareCore\Entities\Documento;
use Espo\Modules\TogareCore\Hooks\Documento\SoftPurgeDocumentoHook;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\RemoveOptions;
use PHPUnit\Framework\TestCase;

/**
 * Story 5.2 — Cobre BeforeRemove order=20: softPurge + log V018 + falha bloqueia delete.
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class SoftPurgeDocumentoHookTest extends TestCase
{
    public function testSoftPurgeOkLogV018(): void
    {
        $purgeStorage = $this->createMock(PurgeableStorageContract::class);
        $purgeStorage->expects(self::once())
            ->method('softPurge')
            ->with('processos/proc-001/doc-001-foo.pdf', self::isInstanceOf(\DateInterval::class))
            ->willReturn('abcdef0123456789abcdef0123456789');

        $em = $this->createMock(EntityManager::class);
        // PDO mock: prepare retorna stmt; stmt->execute consumido sem throw.
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->expects(self::once())->method('execute');
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturn($stmt);
        $em->method('getPDO')->willReturn($pdo);

        $hook = new SoftPurgeDocumentoHook($purgeStorage, $em);
        $doc = $this->makeDocumento('doc-001', 'nextcloud://processos/proc-001/doc-001-foo.pdf');

        $hook->beforeRemove($doc, RemoveOptions::create());
        $hook->afterRemove($doc, RemoveOptions::create());

        // Asserções implícitas via expects.
        self::assertTrue(true);
    }

    public function testSoftPurgeFalhaLancaConflictAbortaDelete(): void
    {
        $purgeStorage = $this->createMock(PurgeableStorageContract::class);
        $purgeStorage->method('softPurge')
            ->willThrowException(new \RuntimeException('Nextcloud indisponível.'));

        $em = $this->createMock(EntityManager::class);

        $hook = new SoftPurgeDocumentoHook($purgeStorage, $em);
        $doc = $this->makeDocumento('doc-002', 'nextcloud://processos/proc-001/doc-002-bar.pdf');

        try {
            $hook->beforeRemove($doc, RemoveOptions::create());
            self::fail('Conflict não foi lançada quando softPurge falhou.');
        } catch (Conflict $e) {
            self::assertStringContainsString('lixeira do Nextcloud', $e->getMessage());
        }
    }

    public function testUriMalformadaLancaRuntimeException(): void
    {
        $purgeStorage = $this->createMock(PurgeableStorageContract::class);
        $purgeStorage->expects(self::never())->method('softPurge');

        $em = $this->createMock(EntityManager::class);

        $hook = new SoftPurgeDocumentoHook($purgeStorage, $em);
        $doc = $this->makeDocumento('doc-003', 'http://invalid/foo.pdf');

        $this->expectException(\RuntimeException::class);
        $hook->beforeRemove($doc, RemoveOptions::create());
    }

    public function testUriVaziaLancaConflictParaEvitarOrfao(): void
    {
        $purgeStorage = $this->createMock(PurgeableStorageContract::class);
        $purgeStorage->expects(self::never())->method('softPurge');

        $em = $this->createMock(EntityManager::class);

        $hook = new SoftPurgeDocumentoHook($purgeStorage, $em);
        $doc = $this->makeDocumento('doc-004', '');

        $this->expectException(Conflict::class);
        $hook->beforeRemove($doc, RemoveOptions::create());
    }

    public function testEntidadeNaoDocumentoIgnora(): void
    {
        $purgeStorage = $this->createMock(PurgeableStorageContract::class);
        $purgeStorage->expects(self::never())->method('softPurge');

        $em = $this->createMock(EntityManager::class);

        $hook = new SoftPurgeDocumentoHook($purgeStorage, $em);
        $other = new CoreEntity();
        $other->set('nextcloudUri', 'nextcloud://foo/bar/baz-x.pdf');

        $hook->beforeRemove($other, RemoveOptions::create());

        self::assertSame('nextcloud://foo/bar/baz-x.pdf', $other->get('nextcloudUri'));
    }

    private function makeDocumento(string $id, string $uri): Documento
    {
        $doc = new Documento();
        $doc->set([
            'nextcloudUri' => $uri,
        ]);
        $doc->setId($id);
        return $doc;
    }
}
