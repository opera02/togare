<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Services;

use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Modules\TogareCore\Entities\ContratoHonorarios;
use Espo\Modules\TogareCore\Services\ContratoHonorariosLookupService;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class ContratoHonorariosLookupServiceTest extends TestCase
{
    public function testContratoSemProcessosContaComoGenericoDoCliente(): void
    {
        $contrato = $this->makeContrato('contrato-001');
        $repo = new FakeContratoRepository([$contrato], [
            'contrato-001' => [],
        ]);

        $service = new ContratoHonorariosLookupService($this->makeEntityManager($repo));

        self::assertSame([$contrato], $service->findContratosVigentes('cli-001', 'proc-001'));
    }

    public function testContratoComProcessoAlvoContaComoVigente(): void
    {
        $contrato = $this->makeContrato('contrato-002');
        $repo = new FakeContratoRepository([$contrato], [
            'contrato-002' => [$this->makeEntity('proc-001')],
        ]);

        $service = new ContratoHonorariosLookupService($this->makeEntityManager($repo));

        self::assertSame([$contrato], $service->findContratosVigentes('cli-001', 'proc-001'));
    }

    public function testFalhaAoLerRelacaoNaoIncluiContratoComoGenerico(): void
    {
        $contrato = $this->makeContrato('contrato-003');
        $repo = new FakeContratoRepository([$contrato], [
            'contrato-003' => new \RuntimeException('relation unavailable'),
        ]);

        $service = new ContratoHonorariosLookupService($this->makeEntityManager($repo));

        self::assertSame([], $service->findContratosVigentes('cli-001', 'proc-001'));
    }

    private function makeEntityManager(FakeContratoRepository $repo): EntityManager
    {
        $em = $this->createMock(EntityManager::class);
        $em->method('getRDBRepository')->willReturn($repo);
        return $em;
    }

    private function makeContrato(string $id): ContratoHonorarios
    {
        $contrato = new ContratoHonorarios();
        $contrato->setId($id);
        $contrato->set([
            'clienteId' => 'cli-001',
            'deleted' => false,
            'vigenciaInicio' => '2026-01-01',
            'vigenciaFim' => null,
        ]);
        return $contrato;
    }

    private function makeEntity(string $id): CoreEntity
    {
        $entity = new CoreEntity();
        $entity->setId($id);
        return $entity;
    }
}

/**
 * @internal test double for Espo RDBRepository + relation API.
 */
final class FakeContratoRepository
{
    /**
     * @param list<ContratoHonorarios> $contratos
     * @param array<string, list<CoreEntity>|\Throwable> $relations
     */
    public function __construct(
        private readonly array $contratos,
        private readonly array $relations,
    ) {
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

    /** @return list<ContratoHonorarios> */
    public function find(): array
    {
        return $this->contratos;
    }

    public function getRelation(ContratoHonorarios $contrato, string $relationName): FakeProcessosRelation
    {
        $value = $this->relations[(string) $contrato->getId()] ?? [];
        if ($value instanceof \Throwable) {
            throw $value;
        }
        return new FakeProcessosRelation($value);
    }
}

/**
 * @internal test double for Espo relation repository.
 */
final class FakeProcessosRelation
{
    /** @param list<CoreEntity> $processos */
    public function __construct(private readonly array $processos)
    {
    }

    /** @return list<CoreEntity> */
    public function find(): array
    {
        return $this->processos;
    }
}
