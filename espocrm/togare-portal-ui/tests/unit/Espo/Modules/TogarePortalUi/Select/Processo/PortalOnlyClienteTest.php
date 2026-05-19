<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogarePortalUi\Select\Processo;

use PHPUnit\Framework\TestCase;

/**
 * Story 7a.2 — AC5 (isolamento de dados em list/search/related/REST list).
 *
 * Prova determinística (unit, sem container) do filtro
 * `Espo\Modules\TogarePortalUi\Classes\Select\Processo\
 * AccessControlFilters\PortalOnlyCliente`:
 *
 *  - portal user SEM `togareCliente`            → WHERE id = null (nega tudo)
 *  - Cliente inexistente                        → WHERE id = null (nega tudo)
 *  - Cliente sem Processos                       → WHERE id = null (nega tudo)
 *  - Cliente com Processos P1,P2                 → WHERE id IN [P1,P2]
 *
 * Fail-closed por construção (espelha o core `PortalOnlyAccount`). O
 * comportamento em runtime (lista nativa do Portal, related panels, REST)
 * é provado no `docker/smoke-7a-2-cli.php`.
 */
final class PortalOnlyClienteTest extends TestCase
{
    private string $moduleRoot;

    protected function setUp(): void
    {
        $this->moduleRoot = dirname(__DIR__, 7);

        require_once dirname(__DIR__, 2) . '/Stubs/CoreStubs.php';
    }

    private function makeEm(?object $cliente, array $processoIds): \Espo\ORM\EntityManager
    {
        return new class($cliente, $processoIds) extends \Espo\ORM\EntityManager {
            public function __construct(private ?object $cliente, private array $processoIds)
            {
            }

            public function getEntityById(string $entityType, string $id): mixed
            {
                return $this->cliente;
            }

            public function getRDBRepository(string $entityType): mixed
            {
                $ids = $this->processoIds;

                return new class($ids) {
                    public function __construct(private array $ids)
                    {
                    }

                    public function getRelation(mixed $entity, string $link): object
                    {
                        $ids = $this->ids;

                        return new class($ids) {
                            public function __construct(private array $ids)
                            {
                            }

                            public function select(array $fields): self
                            {
                                return $this;
                            }

                            public function find(): array
                            {
                                return array_map(
                                    fn (string $id) => new class($id) {
                                        public function __construct(private string $id)
                                        {
                                        }

                                        public function getId(): string
                                        {
                                            return $this->id;
                                        }
                                    },
                                    $this->ids,
                                );
                            }
                        };
                    }
                };
            }
        };
    }

    private function makeFilter(\Espo\Entities\User $user, \Espo\ORM\EntityManager $em): object
    {
        $class = 'Espo\\Modules\\TogarePortalUi\\Classes\\Select\\Processo\\AccessControlFilters\\PortalOnlyCliente';

        return new $class($user, $em);
    }

    public function testSemClienteVinculadoNegaTudo(): void
    {
        $user = new \Espo\Entities\User(['id' => 'U1']); // sem togareClienteId
        $qb = new \Espo\ORM\Query\SelectBuilder();

        $this->makeFilter($user, $this->makeEm(null, []))->apply($qb);

        self::assertSame([['id' => null]], $qb->whereCalls);
    }

    public function testClienteInexistenteNegaTudo(): void
    {
        $user = new \Espo\Entities\User(['id' => 'U1', 'togareClienteId' => 'C-removido']);
        $qb = new \Espo\ORM\Query\SelectBuilder();

        $this->makeFilter($user, $this->makeEm(null, []))->apply($qb);

        self::assertSame([['id' => null]], $qb->whereCalls);
    }

    public function testClienteSemProcessosNegaTudo(): void
    {
        $user = new \Espo\Entities\User(['id' => 'U1', 'togareClienteId' => 'C1']);
        $qb = new \Espo\ORM\Query\SelectBuilder();

        $this->makeFilter($user, $this->makeEm((object) ['id' => 'C1'], []))->apply($qb);

        self::assertSame([['id' => null]], $qb->whereCalls);
    }

    public function testClienteComProcessosRestringePorIdList(): void
    {
        $user = new \Espo\Entities\User(['id' => 'U1', 'togareClienteId' => 'C1']);
        $qb = new \Espo\ORM\Query\SelectBuilder();

        $this->makeFilter($user, $this->makeEm((object) ['id' => 'C1'], ['P1', 'P2']))->apply($qb);

        self::assertSame([['id' => ['P1', 'P2']]], $qb->whereCalls);
    }
}
