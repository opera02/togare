<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Services;

use Espo\Modules\TogareCore\Entities\Fatura;
use Espo\Modules\TogareCore\Services\FaturaLookupService;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

/**
 * Story 6.3 — testa FaturaLookupService API estável (consumida por 6.2 + 10.1).
 */
final class FaturaLookupServiceTest extends TestCase
{
    public function testFindEmAbertoByClienteRetornaListaOrdenada(): void
    {
        $f1 = $this->makeFatura('fat-001', Fatura::STATUS_EMITIDA);
        $f2 = $this->makeFatura('fat-002', Fatura::STATUS_PARCIALMENTE_PAGA);
        $em = $this->makeEntityManager([$f1, $f2]);
        $service = new FaturaLookupService($em);

        $result = $service->findEmAbertoByCliente('cli-001');

        self::assertCount(2, $result);
        self::assertSame('fat-001', (string) $result[0]->getId());
    }

    public function testFindEmAbertoByClienteVazioRetornaVazio(): void
    {
        $em = $this->makeEntityManager([]);
        $service = new FaturaLookupService($em);

        self::assertSame([], $service->findEmAbertoByCliente('cli-001'));
    }

    public function testFindEmAbertoByClienteClienteVazioRetornaVazio(): void
    {
        $em = $this->makeEntityManager([]);
        $service = new FaturaLookupService($em);

        self::assertSame([], $service->findEmAbertoByCliente(''));
    }

    public function testFindVencidas(): void
    {
        $vencida = $this->makeFatura('fat-vencida', Fatura::STATUS_VENCIDA);
        $em = $this->makeEntityManager([$vencida]);
        $service = new FaturaLookupService($em);

        $result = $service->findVencidas();

        self::assertCount(1, $result);
        self::assertSame(Fatura::STATUS_VENCIDA, $result[0]->get('status'));
    }

    public function testHasFaturasEmAbertoTrue(): void
    {
        $f = $this->makeFatura('fat-001', Fatura::STATUS_EMITIDA);
        $em = $this->makeEntityManager([$f]);
        $service = new FaturaLookupService($em);

        self::assertTrue($service->hasFaturasEmAberto('cli-001'));
    }

    public function testHasFaturasEmAbertoFalse(): void
    {
        $em = $this->makeEntityManager([]);
        $service = new FaturaLookupService($em);

        self::assertFalse($service->hasFaturasEmAberto('cli-001'));
    }

    /**
     * @param list<Fatura> $faturas
     */
    private function makeEntityManager(array $faturas): EntityManager
    {
        $em = $this->createMock(EntityManager::class);
        $repo = new FakeFaturaLookupRepository($faturas);
        $em->method('getRDBRepository')->willReturn($repo);
        return $em;
    }

    private function makeFatura(string $id, string $status): Fatura
    {
        $f = new Fatura();
        $f->setId($id);
        $f->set('status', $status);
        $f->set('dataVencimento', '2026-12-31');
        return $f;
    }
}

/**
 * @internal stub Espo RDBRepository<Fatura>.
 */
final class FakeFaturaLookupRepository
{
    /** @param list<Fatura> $faturas */
    public function __construct(private readonly array $faturas)
    {
    }

    /** @param array<string, mixed> $where */
    public function where(array $where): self
    {
        return $this;
    }

    public function order(string $field, string $direction): self
    {
        return $this;
    }

    /** @return list<Fatura> */
    public function find(): array
    {
        return $this->faturas;
    }
}
