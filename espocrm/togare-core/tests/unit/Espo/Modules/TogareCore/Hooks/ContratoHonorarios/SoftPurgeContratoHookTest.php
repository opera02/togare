<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\ContratoHonorarios;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Modules\TogareCore\Contracts\PurgeableStorageContract;
use Espo\Modules\TogareCore\Entities\ContratoHonorarios;
use Espo\Modules\TogareCore\Hooks\ContratoHonorarios\SoftPurgeContratoHook;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\RemoveOptions;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class SoftPurgeContratoHookTest extends TestCase
{
    public function testSoftPurgeOkComUriNextcloud(): void
    {
        $purgeStorage = $this->createMock(PurgeableStorageContract::class);
        $purgeStorage->expects(self::once())
            ->method('buildUri')
            ->with('clientes/cli-001/contratos/contrato001-foo.pdf')
            ->willReturn('nextcloud://clientes/cli-001/contratos/contrato001-foo.pdf');
        $purgeStorage->expects(self::once())
            ->method('softPurge')
            ->with('clientes/cli-001/contratos/contrato001-foo.pdf', self::isInstanceOf(\DateInterval::class))
            ->willReturn('abcdef0123456789abcdef0123456789');

        $em = $this->createMock(EntityManager::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->expects(self::once())->method('execute')->willReturn(true);
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturn($stmt);
        $em->method('getPDO')->willReturn($pdo);

        $hook = new SoftPurgeContratoHook($purgeStorage, $em);
        $c = $this->makeContrato('contrato001', 'nextcloud://clientes/cli-001/contratos/contrato001-foo.pdf');

        $hook->beforeRemove($c, RemoveOptions::create());
        $hook->afterRemove($c, RemoveOptions::create());

        self::assertTrue(true);
    }

    public function testSoftPurgeOkComUriLocal(): void
    {
        $purgeStorage = $this->createMock(PurgeableStorageContract::class);
        $purgeStorage->expects(self::once())
            ->method('buildUri')
            ->with('clientes/cli-002/contratos/contrato002-foo.pdf')
            ->willReturn('local://clientes/cli-002/contratos/contrato002-foo.pdf');
        $purgeStorage->expects(self::once())
            ->method('softPurge')
            ->with('clientes/cli-002/contratos/contrato002-foo.pdf', self::isInstanceOf(\DateInterval::class))
            ->willReturn('1234567890abcdef1234567890abcdef');

        $em = $this->createMock(EntityManager::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturn($stmt);
        $em->method('getPDO')->willReturn($pdo);

        $hook = new SoftPurgeContratoHook($purgeStorage, $em);
        $c = $this->makeContrato('contrato002', 'local://clientes/cli-002/contratos/contrato002-foo.pdf');

        $hook->beforeRemove($c, RemoveOptions::create());
        $hook->afterRemove($c, RemoveOptions::create());

        self::assertTrue(true);
    }

    public function testSoftPurgeFalhaLancaConflictAbortaDelete(): void
    {
        $purgeStorage = $this->createMock(PurgeableStorageContract::class);
        $purgeStorage->method('buildUri')
            ->willReturn('nextcloud://clientes/cli-003/contratos/contrato003-foo.pdf');
        $purgeStorage->method('softPurge')
            ->willThrowException(new \RuntimeException('Storage indisponivel.'));

        $em = $this->createMock(EntityManager::class);

        $hook = new SoftPurgeContratoHook($purgeStorage, $em);
        $c = $this->makeContrato('contrato003', 'nextcloud://clientes/cli-003/contratos/contrato003-foo.pdf');

        try {
            $hook->beforeRemove($c, RemoveOptions::create());
            self::fail('Conflict nao foi lancada quando softPurge falhou.');
        } catch (Conflict $e) {
            self::assertStringContainsString('lixeira', $e->getMessage());
        }
    }

    public function testUriMalformadaLancaConflict(): void
    {
        $purgeStorage = $this->createMock(PurgeableStorageContract::class);
        $purgeStorage->expects(self::never())->method('softPurge');

        $em = $this->createMock(EntityManager::class);

        $hook = new SoftPurgeContratoHook($purgeStorage, $em);
        $c = $this->makeContrato('contrato004', 's3://wrong-scheme/foo.pdf');

        $this->expectException(Conflict::class);
        $hook->beforeRemove($c, RemoveOptions::create());
    }

    public function testUriAusenteLancaConflict(): void
    {
        $purgeStorage = $this->createMock(PurgeableStorageContract::class);
        $purgeStorage->expects(self::never())->method('softPurge');

        $em = $this->createMock(EntityManager::class);

        $hook = new SoftPurgeContratoHook($purgeStorage, $em);
        $c = $this->makeContrato('contrato005', '');

        $this->expectException(Conflict::class);
        $hook->beforeRemove($c, RemoveOptions::create());
    }

    public function testSchemePersistidoDiferenteDoStorageAtualBloqueiaDelete(): void
    {
        $purgeStorage = $this->createMock(PurgeableStorageContract::class);
        $purgeStorage->expects(self::once())
            ->method('buildUri')
            ->with('clientes/cli-006/contratos/contrato006-foo.pdf')
            ->willReturn('nextcloud://clientes/cli-006/contratos/contrato006-foo.pdf');
        $purgeStorage->expects(self::never())->method('softPurge');

        $em = $this->createMock(EntityManager::class);

        $hook = new SoftPurgeContratoHook($purgeStorage, $em);
        $c = $this->makeContrato('contrato006', 'local://clientes/cli-006/contratos/contrato006-foo.pdf');

        $this->expectException(Conflict::class);
        $hook->beforeRemove($c, RemoveOptions::create());
    }

    public function testFalhaNoTombstoneLogRestauraTombstoneEAbortaDelete(): void
    {
        $purgeStorage = $this->createMock(PurgeableStorageContract::class);
        $purgeStorage->method('buildUri')
            ->willReturn('nextcloud://clientes/cli-007/contratos/contrato007-foo.pdf');
        $purgeStorage->expects(self::once())
            ->method('softPurge')
            ->willReturn('tombstone007');
        $purgeStorage->expects(self::once())
            ->method('restoreFromTombstone')
            ->with('tombstone007');

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(false);
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willReturn($pdo);

        $hook = new SoftPurgeContratoHook($purgeStorage, $em);
        $c = $this->makeContrato('contrato007', 'nextcloud://clientes/cli-007/contratos/contrato007-foo.pdf');

        $this->expectException(Conflict::class);
        $hook->beforeRemove($c, RemoveOptions::create());
    }

    public function testEntidadeNaoContratoIgnora(): void
    {
        $purgeStorage = $this->createMock(PurgeableStorageContract::class);
        $purgeStorage->expects(self::never())->method('softPurge');

        $em = $this->createMock(EntityManager::class);

        $hook = new SoftPurgeContratoHook($purgeStorage, $em);
        $other = new CoreEntity();
        $hook->beforeRemove($other, RemoveOptions::create());
        self::assertTrue(true);
    }

    private function makeContrato(string $id, string $uri): ContratoHonorarios
    {
        $c = new ContratoHonorarios();
        $c->setId($id);
        $c->set('fileStorageUri', $uri);
        return $c;
    }
}
