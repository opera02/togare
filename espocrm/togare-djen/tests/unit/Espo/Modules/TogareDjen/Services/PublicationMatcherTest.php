<?php

declare(strict_types=1);

namespace Tests\Unit\Espo\Modules\TogareDjen\Services;

use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareDjen\Services\PublicationMatcher;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRelation;
use Espo\ORM\Repository\RDBRepository;
use PHPUnit\Framework\TestCase;

/**
 * Story 4b.1b — PublicationMatcher (Decisão #2 mãe): match em 2 fases.
 *
 * Cobre os 6 outcomes da tabela D2 + 4 cenários de borda (dedup, dest vazio,
 * Cliente+ParteContraria mesmo Processo, ordering snapshot).
 */
final class PublicationMatcherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TogareLogger::reset();
    }

    private function makeProcesso(string $id, string $cnj = '', ?string $assigned = null): CoreEntity
    {
        $p = new CoreEntity();
        $p->setId($id);
        $p->set('numeroCnj', $cnj);
        if ($assigned !== null) {
            $p->set('assignedUserId', $assigned);
        }
        $p->set('area', 'civel');
        $p->set('fase', 'conhecimento');
        $p->set('dataDistribuicao', '2024-01-15');
        return $p;
    }

    private function makeRepoFindOneOrFind(?array $find = null, ?Entity $findOne = null): RDBRepository
    {
        $repo = $this->createMock(RDBRepository::class);
        $repo->method('where')->willReturn($repo);
        $repo->method('limit')->willReturn($repo);
        $repo->method('distinct')->willReturn($repo);
        $repo->method('join')->willReturn($repo);
        if ($find !== null) {
            $repo->method('find')->willReturn($find);
        } else {
            $repo->method('find')->willReturn([]);
        }
        $repo->method('findOne')->willReturn($findOne);
        return $repo;
    }

    /** @param array<string, RDBRepository|callable> $repos */
    private function makeEm(array $repos, array $entitiesById = [], ?\Throwable $relationThrowable = null): EntityManager
    {
        $em = $this->createMock(EntityManager::class);
        $em->method('getRDBRepository')->willReturnCallback(
            function (string $type) use ($repos) {
                if (isset($repos[$type])) {
                    return $repos[$type];
                }
                return $this->makeRepoFindOneOrFind([]);
            }
        );
        $em->method('getEntityById')->willReturnCallback(
            static function (string $type, string $id) use ($entitiesById): ?Entity {
                return $entitiesById[$id] ?? null;
            }
        );
        if ($relationThrowable !== null) {
            $em->method('getRelation')->willThrowException($relationThrowable);

            return $em;
        }
        $em->method('getRelation')->willReturnCallback(
            function (Entity $entity, string $relationName): RDBRelation {
                return $this->makeRelationFromEntityField($entity, $relationName);
            }
        );
        return $em;
    }

    private function makeRelationFromEntityField(Entity $entity, string $relationName): RDBRelation
    {
        $relation = $this->createMock(RDBRelation::class);
        $collection = $entity->get($relationName);
        $relation->method('find')->willReturn(\is_iterable($collection) ? $collection : []);

        return $relation;
    }

    private function makePayload(string $cnj = '', ?array $destinatarios = null, int $sourcePubId = 999991): array
    {
        return [
            'id' => $sourcePubId,
            'numeroProcesso' => $cnj,
            'destinatarios' => $destinatarios ?? [],
        ];
    }

    public function testFase1CnjExatoUmHitRetornaSingle(): void
    {
        $proc = $this->makeProcesso('proc-A', '00012345620248260001');

        $em = $this->makeEm([
            'Processo' => $this->makeRepoFindOneOrFind(find: [$proc]),
        ]);

        $matcher = new PublicationMatcher($em);
        $r = $matcher->match($this->makePayload(cnj: '0001234-56.2024.8.26.0001'));

        self::assertSame('single', $r->kind);
        self::assertSame($proc, $r->processo);
        self::assertSame('0001234-56.2024.8.26.0001', $r->numeroProcessoOriginal);
    }

    public function testFase1CnjExatoDoisHitsDefensivoRetornaMultipleCnj(): void
    {
        $procA = $this->makeProcesso('proc-A', '00012345620248260001');
        $procB = $this->makeProcesso('proc-B', '00012345620248260001'); // Mesmo CNJ — defensivo

        $em = $this->makeEm([
            'Processo' => $this->makeRepoFindOneOrFind(find: [$procA, $procB]),
        ]);

        $matcher = new PublicationMatcher($em);
        $r = $matcher->match($this->makePayload(cnj: '0001234-56.2024.8.26.0001'));

        self::assertSame('multiple', $r->kind);
        self::assertSame('cnj_multiplos_processos', $r->ambiguityReason);
        self::assertCount(2, $r->candidatos);
    }

    public function testFase1CnjDigitsOnlyDiferenteDe20CaiEmFase2None(): void
    {
        // Sem destinatarios → fase 2 também retorna none.
        $em = $this->makeEm([]);

        $matcher = new PublicationMatcher($em);
        $r = $matcher->match($this->makePayload(cnj: 'cnj-malformado-curto'));

        self::assertSame('none', $r->kind);
    }

    public function testFase2NamematchUmHitClienteRetornaSingleELogResolved(): void
    {
        $proc = $this->makeProcesso('proc-A', '00099999920248260001');

        $cliente = new CoreEntity();
        $cliente->setId('cli-1');
        $cliente->set('name', 'João Silva');
        $cliente->set('processos', [$proc]);

        $em = $this->makeEm(
            [
                'Cliente' => $this->makeRepoFindOneOrFind(find: [$cliente]),
                'ParteContraria' => $this->makeRepoFindOneOrFind(find: []),
            ],
            entitiesById: ['proc-A' => $proc],
        );

        $matcher = new PublicationMatcher($em);
        $r = $matcher->match($this->makePayload(
            cnj: '',
            destinatarios: [['nome' => 'João Silva']],
        ));

        self::assertSame('single', $r->kind);
        self::assertSame($proc, $r->processo);

        $events = \array_column(TogareLogger::getRecorded(), 'event');
        self::assertContains('djen.match.namematch_resolved', $events);
    }

    public function testFase2NamematchUmHitParteContrariaRetornaSingle(): void
    {
        $proc = $this->makeProcesso('proc-X', '00077777720248260001');

        $pc = new CoreEntity();
        $pc->setId('pc-1');
        $pc->set('name', 'Banco X S.A.');
        $pc->set('processos', [$proc]);

        $em = $this->makeEm(
            [
                'Cliente' => $this->makeRepoFindOneOrFind(find: []),
                'ParteContraria' => $this->makeRepoFindOneOrFind(find: [$pc]),
            ],
            entitiesById: ['proc-X' => $proc],
        );

        $matcher = new PublicationMatcher($em);
        $r = $matcher->match($this->makePayload(
            destinatarios: [['nome' => 'Banco X S.A.']],
        ));

        self::assertSame('single', $r->kind);
    }

    public function testFase2NamematchClientePCMesmoProcessoDedupCount1(): void
    {
        // Mesmo Processo aparece via Cliente E via ParteContraria — dedup retorna 1.
        $proc = $this->makeProcesso('proc-shared', '00055555520248260001');

        $cliente = new CoreEntity();
        $cliente->setId('cli-1');
        $cliente->set('name', 'João');
        $cliente->set('processos', [$proc]);

        $pc = new CoreEntity();
        $pc->setId('pc-1');
        $pc->set('name', 'João'); // Mesmo nome — bate em ambos
        $pc->set('processos', [$proc]);

        $em = $this->makeEm(
            [
                'Cliente' => $this->makeRepoFindOneOrFind(find: [$cliente]),
                'ParteContraria' => $this->makeRepoFindOneOrFind(find: [$pc]),
            ],
            entitiesById: ['proc-shared' => $proc],
        );

        $matcher = new PublicationMatcher($em);
        $r = $matcher->match($this->makePayload(
            destinatarios: [['nome' => 'João']],
        ));

        self::assertSame('single', $r->kind, 'Dedup deve resultar em kind=single, não multiple');
    }

    public function testFase2NamematchDoisHitsDistintosRetornaMultipleNameMatch(): void
    {
        $procA = $this->makeProcesso('proc-A', '00011111120248260001');
        $procB = $this->makeProcesso('proc-B', '00099999920248260001');

        $clienteA = new CoreEntity();
        $clienteA->setId('cli-A');
        $clienteA->set('name', 'João');
        $clienteA->set('processos', [$procA]);

        $clienteB = new CoreEntity();
        $clienteB->setId('cli-B');
        $clienteB->set('name', 'Maria');
        $clienteB->set('processos', [$procB]);

        $em = $this->makeEm(
            [
                'Cliente' => new class ($clienteA, $clienteB) extends \stdClass {
                    public function __construct(private CoreEntity $a, private CoreEntity $b) {}
                },
                'ParteContraria' => $this->makeRepoFindOneOrFind(find: []),
            ],
            entitiesById: ['proc-A' => $procA, 'proc-B' => $procB],
        );

        // A versão com class anonymous estraga; voltar para 2 repos chained:
        $clienteRepo = $this->createMock(RDBRepository::class);
        $clienteRepo->method('where')->willReturn($clienteRepo);
        $clienteRepo->method('limit')->willReturn($clienteRepo);
        // Sequência de chamadas where-find: 1ª retorna $clienteA, 2ª retorna $clienteB
        $clienteRepo->method('find')->willReturn([$clienteA], [$clienteB]);

        $em = $this->makeEm(
            [
                'Cliente' => $clienteRepo,
                'ParteContraria' => $this->makeRepoFindOneOrFind(find: []),
            ],
            entitiesById: ['proc-A' => $procA, 'proc-B' => $procB],
        );

        $matcher = new PublicationMatcher($em);
        $r = $matcher->match($this->makePayload(
            destinatarios: [['nome' => 'João'], ['nome' => 'Maria']],
        ));

        self::assertSame('multiple', $r->kind);
        self::assertSame('name_match_multiplos_candidatos', $r->ambiguityReason);
        self::assertCount(2, $r->candidatos);
        // Ordering por numeroCnj ASC — proc-A (0001...) vem antes de proc-B (0009...)
        self::assertSame('proc-A', $r->candidatos[0]['processoId']);
        self::assertSame('azul', $r->candidatos[0]['codigoCor']);
        self::assertSame('proc-B', $r->candidatos[1]['processoId']);
        self::assertSame('laranja', $r->candidatos[1]['codigoCor']);
    }

    public function testFase2NamematchCincoDistintosRetornaMultipleComCincoCodigosCor(): void
    {
        $procs = [];
        $clientes = [];
        for ($i = 1; $i <= 5; $i++) {
            $cnjPad = \str_pad((string) $i, 7, '0', STR_PAD_LEFT);
            $proc = $this->makeProcesso('proc-' . $i, $cnjPad . '20248260001');
            $procs[] = $proc;

            $cli = new CoreEntity();
            $cli->setId('cli-' . $i);
            $cli->set('name', 'Pessoa-' . $i);
            $cli->set('processos', [$proc]);
            $clientes[] = $cli;
        }

        $clienteRepo = $this->createMock(RDBRepository::class);
        $clienteRepo->method('where')->willReturn($clienteRepo);
        $clienteRepo->method('limit')->willReturn($clienteRepo);
        $clienteRepo->method('find')->willReturn(...\array_map(fn ($c) => [$c], $clientes));

        $em = $this->makeEm(
            [
                'Cliente' => $clienteRepo,
                'ParteContraria' => $this->makeRepoFindOneOrFind(find: []),
            ],
            entitiesById: \array_combine(\array_column(\array_map(fn ($p) => ['id' => $p->getId()], $procs), 'id'), $procs),
        );

        $matcher = new PublicationMatcher($em);
        $r = $matcher->match($this->makePayload(
            destinatarios: \array_map(fn ($i) => ['nome' => 'Pessoa-' . $i], [1, 2, 3, 4, 5]),
        ));

        self::assertSame('multiple', $r->kind);
        self::assertCount(5, $r->candidatos);
        $cores = \array_column($r->candidatos, 'codigoCor');
        self::assertSame(['azul', 'laranja', 'verde', 'roxo', 'vermelho'], $cores);
    }

    public function testFase2NamematchSeisOuMaisRetornaTooManyComLogWarning(): void
    {
        $procs = [];
        $clientes = [];
        for ($i = 1; $i <= 6; $i++) {
            $cnjPad = \str_pad((string) $i, 7, '0', STR_PAD_LEFT);
            $proc = $this->makeProcesso('proc-' . $i, $cnjPad . '20248260001');
            $procs[] = $proc;

            $cli = new CoreEntity();
            $cli->setId('cli-' . $i);
            $cli->set('name', 'Pessoa-' . $i);
            $cli->set('processos', [$proc]);
            $clientes[] = $cli;
        }

        $clienteRepo = $this->createMock(RDBRepository::class);
        $clienteRepo->method('where')->willReturn($clienteRepo);
        $clienteRepo->method('limit')->willReturn($clienteRepo);
        $clienteRepo->method('find')->willReturn(...\array_map(fn ($c) => [$c], $clientes));

        $em = $this->makeEm(
            [
                'Cliente' => $clienteRepo,
                'ParteContraria' => $this->makeRepoFindOneOrFind(find: []),
            ],
            entitiesById: [],
        );

        $matcher = new PublicationMatcher($em);
        $r = $matcher->match($this->makePayload(
            destinatarios: \array_map(fn ($i) => ['nome' => 'Pessoa-' . $i], [1, 2, 3, 4, 5, 6]),
        ));

        self::assertSame('too_many', $r->kind);

        $events = \array_column(TogareLogger::getRecorded(), 'event');
        self::assertContains('djen.match.namematch_too_many', $events);
    }

    public function testPayloadDestinatariosVazioRetornaNone(): void
    {
        $em = $this->makeEm([]);

        $matcher = new PublicationMatcher($em);
        $r = $matcher->match($this->makePayload(destinatarios: []));

        self::assertSame('none', $r->kind);
    }

    public function testFase1RetornaZeroEFase2NomesVazioRetornaNone(): void
    {
        $em = $this->makeEm([
            'Cliente' => $this->makeRepoFindOneOrFind(find: []),
            'ParteContraria' => $this->makeRepoFindOneOrFind(find: []),
        ]);

        $matcher = new PublicationMatcher($em);
        $r = $matcher->match($this->makePayload(
            cnj: '0099999-99.2024.8.26.0001', // CNJ válido mas 0 hits
            destinatarios: [['nome' => '   '], ['nome' => '']], // Trim → vazios
        ));

        self::assertSame('none', $r->kind);
    }

    public function testCandidatosSnapshotContemTodos8Fields(): void
    {
        $procA = $this->makeProcesso('proc-A', '00011111120248260001');

        $clienteRel = new CoreEntity();
        $clienteRel->setId('cli-rel-A');
        $clienteRel->set('name', 'Cliente Relacionado A');
        $procA->set('clientes', [$clienteRel]);

        $pcRel = new CoreEntity();
        $pcRel->setId('pc-rel-A');
        $pcRel->set('name', 'PC Relacionada A');
        $procA->set('partesContrarias', [$pcRel]);

        $procB = $this->makeProcesso('proc-B', '00022222220248260001');

        $clienteSearch = new CoreEntity();
        $clienteSearch->setId('cli-A');
        $clienteSearch->set('name', 'João');
        $clienteSearch->set('processos', [$procA, $procB]);

        $em = $this->makeEm(
            [
                'Cliente' => $this->makeRepoFindOneOrFind(find: [$clienteSearch]),
                'ParteContraria' => $this->makeRepoFindOneOrFind(find: []),
            ],
            entitiesById: ['proc-A' => $procA, 'proc-B' => $procB],
        );

        $matcher = new PublicationMatcher($em);
        $r = $matcher->match($this->makePayload(
            destinatarios: [['nome' => 'João']],
        ));

        self::assertSame('multiple', $r->kind);
        $first = $r->candidatos[0];
        self::assertArrayHasKey('processoId', $first);
        self::assertArrayHasKey('numeroCnj', $first);
        self::assertArrayHasKey('clienteNome', $first);
        self::assertArrayHasKey('parteContrariaNome', $first);
        self::assertArrayHasKey('dataDistribuicao', $first);
        self::assertArrayHasKey('area', $first);
        self::assertArrayHasKey('fase', $first);
        self::assertArrayHasKey('codigoCor', $first);
        // procA tem clienteRel + pcRel; procB não tem (defaults).
        self::assertSame('Cliente Relacionado A', $first['clienteNome']);
        self::assertSame('PC Relacionada A', $first['parteContrariaNome']);
        self::assertSame('(sem cliente)', $r->candidatos[1]['clienteNome']);
        self::assertSame('(sem parte contrária)', $r->candidatos[1]['parteContrariaNome']);
    }

    public function testFalhaAoCarregarRelacaoPropagaErroParaRetryDoWorker(): void
    {
        $cliente = new CoreEntity();
        $cliente->setId('cli-1');
        $cliente->set('name', 'Joao Silva');

        $em = $this->makeEm(
            [
                'Cliente' => $this->makeRepoFindOneOrFind(find: [$cliente]),
                'ParteContraria' => $this->makeRepoFindOneOrFind(find: []),
            ],
            relationThrowable: new \RuntimeException('relation DB failure'),
        );

        $matcher = new PublicationMatcher($em);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('relation DB failure');

        $matcher->match($this->makePayload(
            destinatarios: [['nome' => 'Joao Silva']],
        ));
    }

    public function testDigitsOnlyHelperPublicoExtraiSomenteDigitos(): void
    {
        $em = $this->makeEm([]);
        $matcher = new PublicationMatcher($em);

        self::assertSame('00012345620248260001', $matcher->digitsOnly('0001234-56.2024.8.26.0001'));
        self::assertSame('', $matcher->digitsOnly('abc-def'));
        self::assertSame('123', $matcher->digitsOnly('1a2b3'));
    }
}
