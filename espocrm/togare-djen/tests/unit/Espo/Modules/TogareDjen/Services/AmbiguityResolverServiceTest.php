<?php

declare(strict_types=1);

namespace Tests\Unit\Espo\Modules\TogareDjen\Services;

use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Modules\TogareCore\Entities\Prazo;
use Espo\Modules\TogareCore\Entities\PublicacaoAmbigua;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareDjen\Exception\AlreadyResolvedException;
use Espo\Modules\TogareDjen\Exception\InvalidCandidateException;
use Espo\Modules\TogareDjen\Services\AmbiguityResolverService;
use Espo\Modules\TogareDjen\Services\PrazoCreatorService;
use Espo\Modules\TogareDjen\Services\PublicationMatcher;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\TransactionManager;
use PHPUnit\Framework\TestCase;

/**
 * Story 4b.1b — AmbiguityResolverService (Decisão #4 mãe): 3 ações atômicas.
 *
 * Cobre:
 *  - resolve happy path (cria Prazo + marca pub + 1 row ambiguity_log)
 *  - resolve com chosenProcessoId fora de candidatos → InvalidCandidateException
 *  - resolve em pub status != pendente_revisao → AlreadyResolvedException
 *  - ignore happy path (marca pub + 1 row ambiguity_log; ZERO Prazo)
 *  - bulkIgnoreProcesso afeta N pubs com processoId em candidatos
 *  - bulkIgnoreProcesso processoId vazio → InvalidCandidateException
 *  - Throwable durante save → rollback completo (mock TransactionManager
 *    propaga exception)
 *  - assemble de Prazo via assemblePrazoFromPublicacaoAmbigua usa SOURCE_MANUAL_AMBIGUO
 */
final class AmbiguityResolverServiceTest extends TestCase
{
    private array $insertedRows = [];
    private array $savedEntities = [];

    protected function setUp(): void
    {
        parent::setUp();
        TogareLogger::reset();
        $this->insertedRows = [];
        $this->savedEntities = [];
    }

    private function makePub(string $id = 'pub-001', string $status = PublicacaoAmbigua::STATUS_PENDENTE_REVISAO, array $candidatos = []): PublicacaoAmbigua
    {
        $pub = new PublicacaoAmbigua();
        $pub->setId($id);
        $pub->set([
            'status' => $status,
            'sourcePubId' => 999991,
            'numeroProcessoOriginal' => '0001234-56.2024.8.26.0001',
            'payload' => '{"id":999991}',
            'texto' => 'CONCEDO o prazo de 15 dias para apresentar contestação.',
            'dataDisponibilizacao' => '2026-05-15',
            'dataInicioPrazo' => '2026-05-18',
            'dataFatal' => '2026-06-08',
            'prazoDias' => 15,
            'contagem' => 'uteis',
            'atoCodigo' => 'contestacao',
            'referenciaLegal' => 'CPC art. 335',
            'confidence' => 'high',
            'parserRegraVersao' => '1.0.0',
            'fonteExcerpt' => 'CONCEDO o prazo de 15 dias',
            'candidatos' => \json_encode($candidatos, JSON_UNESCAPED_UNICODE),
            'ambiguityReason' => PublicacaoAmbigua::AMBIGUITY_REASON_NAME_MATCH_MULTIPLE,
            'assignedUserId' => 'user-felipe',
        ]);
        return $pub;
    }

    private function makeProcesso(string $id, ?string $assigned = 'user-titular'): CoreEntity
    {
        $p = new CoreEntity();
        $p->setId($id);
        $p->set('assignedUserId', $assigned);
        return $p;
    }

    /**
     * Cria EntityManager mock com:
     * - getEntityById('PublicacaoAmbigua', $id) → $pub
     * - getEntityById('Processo', $id) → $processo
     * - saveEntity registra em $this->savedEntities
     * - getPDO() → mock PDO que registra INSERT em $this->insertedRows
     * - getTransactionManager()->run($cb) → executa $cb diretamente
     * - getRDBRepository(...)->find() → opcional para bulkIgnore
     */
    private function makeEm(?Entity $pub = null, ?Entity $processo = null, ?array $bulkPubs = null, bool $saveThrowsOnSecondCall = false): EntityManager
    {
        $em = $this->createMock(EntityManager::class);

        $em->method('getEntityById')->willReturnCallback(
            static function (string $type, string $id) use ($pub, $processo): ?Entity {
                if ($type === PublicacaoAmbigua::ENTITY_TYPE && $pub !== null && $pub->getId() === $id) {
                    return $pub;
                }
                if ($type === 'Processo' && $processo !== null && $processo->getId() === $id) {
                    return $processo;
                }
                return null;
            }
        );

        $em->method('getNewEntity')->willReturnCallback(
            static fn (string $type) => $type === 'Prazo' ? new Prazo() : new CoreEntity()
        );

        $callCount = 0;
        $em->method('saveEntity')->willReturnCallback(function (Entity $e) use (&$callCount, $saveThrowsOnSecondCall) {
            $callCount++;
            if ($saveThrowsOnSecondCall && $callCount === 2) {
                throw new \RuntimeException('Simulated DB failure on 2nd save');
            }
            if ($e->getId() === null) {
                if ($e instanceof CoreEntity) {
                    $e->setId('entity-uuid-' . $callCount);
                }
            }
            $this->savedEntities[] = $e;
            return $e;
        });

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturnCallback(function (array $params): bool {
            $this->insertedRows[] = $params;
            return true;
        });

        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $em->method('getPDO')->willReturn($pdo);

        $tm = $this->createMock(TransactionManager::class);
        $tm->method('run')->willReturnCallback(static fn (callable $cb) => $cb());
        $em->method('getTransactionManager')->willReturn($tm);

        if ($bulkPubs !== null) {
            $repo = $this->createMock(RDBRepository::class);
            $repo->method('where')->willReturn($repo);
            $repo->method('find')->willReturn($bulkPubs);
            $em->method('getRDBRepository')->willReturn($repo);
        }

        return $em;
    }

    private function makeService(EntityManager $em): AmbiguityResolverService
    {
        $matcher = $this->createMock(PublicationMatcher::class);
        $creator = new PrazoCreatorService($em, $matcher);
        return new AmbiguityResolverService($em, $creator);
    }

    public function testResolveHappyPathCriaPrazoMarcaPubAppendaLog(): void
    {
        $candidatos = [
            ['processoId' => 'proc-A', 'numeroCnj' => '00011111120248260001'],
            ['processoId' => 'proc-B', 'numeroCnj' => '00099999920248260001'],
        ];
        $pub = $this->makePub(candidatos: $candidatos);
        $processoA = $this->makeProcesso('proc-A');

        $em = $this->makeEm(pub: $pub, processo: $processoA);
        $svc = $this->makeService($em);

        $prazo = $svc->resolve('pub-001', 'proc-A', 'user-advogado');

        // 1. Prazo criado
        self::assertInstanceOf(Prazo::class, $prazo);
        self::assertSame(Prazo::STATUS_PENDENTE, $prazo->get('status'));
        self::assertSame(Prazo::SOURCE_MANUAL_AMBIGUO, $prazo->get('source'));
        self::assertSame('proc-A', $prazo->get('processoId'));
        self::assertSame('user-titular', $prazo->get('assignedUserId'));

        // 2. Pub marcada
        self::assertSame(PublicacaoAmbigua::STATUS_RESOLVIDO, $pub->get('status'));
        self::assertSame(PublicacaoAmbigua::DECISION_CONFIRMAR_CANDIDATO, $pub->get('decisionType'));
        self::assertSame('proc-A', $pub->get('decisionProcessoId'));
        self::assertSame((string) $prazo->getId(), $pub->get('prazoCriadoId'));
        self::assertSame('user-advogado', $pub->get('decidedById'));
        self::assertNotEmpty($pub->get('decidedAt'));

        // 3. Ambiguity log appended
        self::assertCount(1, $this->insertedRows);
        $row = $this->insertedRows[0];
        self::assertSame('pub-001', $row[':publicacao_ambigua_id']);
        self::assertSame('confirmar_candidato', $row[':decision_type']);
        self::assertSame('proc-A', $row[':chosen_processo_id']);
        self::assertSame((string) $prazo->getId(), $row[':prazo_criado_id']);
        self::assertSame('user-advogado', $row[':decided_by_user_id']);

        $events = \array_column(TogareLogger::getRecorded(), 'event');
        self::assertContains('djen.ambiguity.resolved', $events);
    }

    public function testResolveChosenProcessoIdForaDeCandidatosThrowsInvalidCandidate(): void
    {
        $candidatos = [
            ['processoId' => 'proc-A'],
            ['processoId' => 'proc-B'],
        ];
        $pub = $this->makePub(candidatos: $candidatos);

        $em = $this->makeEm(pub: $pub);
        $svc = $this->makeService($em);

        $this->expectException(InvalidCandidateException::class);

        try {
            $svc->resolve('pub-001', 'proc-INEXISTENTE', 'user-advogado');
        } finally {
            // ZERO mudanças no banco
            self::assertSame([], $this->insertedRows, 'Nenhum log deve ser inserido');
            self::assertSame(PublicacaoAmbigua::STATUS_PENDENTE_REVISAO, $pub->get('status'), 'Pub permanece pendente');
        }
    }

    public function testResolveStatusJaResolvidoThrowsAlreadyResolved(): void
    {
        $pub = $this->makePub(status: PublicacaoAmbigua::STATUS_RESOLVIDO, candidatos: [['processoId' => 'proc-A']]);
        $pub->set('decidedAt', '2026-05-08 10:00:00');

        $em = $this->makeEm(pub: $pub);
        $svc = $this->makeService($em);

        $this->expectException(AlreadyResolvedException::class);

        try {
            $svc->resolve('pub-001', 'proc-A', 'user-advogado');
        } finally {
            self::assertSame([], $this->insertedRows);
        }
    }

    public function testIgnoreHappyPathMarcaPubAppendaLogZeroPrazo(): void
    {
        $pub = $this->makePub(candidatos: [['processoId' => 'proc-A']]);

        $em = $this->makeEm(pub: $pub);
        $svc = $this->makeService($em);

        $svc->ignore('pub-001', 'user-advogado');

        self::assertSame(PublicacaoAmbigua::STATUS_IGNORADO, $pub->get('status'));
        self::assertSame(PublicacaoAmbigua::DECISION_IGNORAR, $pub->get('decisionType'));
        self::assertNull($pub->get('decisionProcessoId'));
        self::assertNull($pub->get('prazoCriadoId'));

        // Zero Prazo criado (apenas pub salva)
        self::assertCount(1, $this->savedEntities);
        self::assertSame($pub, $this->savedEntities[0]);

        self::assertCount(1, $this->insertedRows);
        self::assertSame('ignorar', $this->insertedRows[0][':decision_type']);
        self::assertNull($this->insertedRows[0][':chosen_processo_id']);
        self::assertNull($this->insertedRows[0][':prazo_criado_id']);

        $events = \array_column(TogareLogger::getRecorded(), 'event');
        self::assertContains('djen.ambiguity.ignored', $events);
    }

    public function testIgnoreStatusJaResolvidoThrowsAlreadyResolved(): void
    {
        $pub = $this->makePub(status: PublicacaoAmbigua::STATUS_IGNORADO, candidatos: [['processoId' => 'proc-A']]);

        $em = $this->makeEm(pub: $pub);
        $svc = $this->makeService($em);

        $this->expectException(AlreadyResolvedException::class);
        $svc->ignore('pub-001', 'user-advogado');
    }

    public function testBulkIgnoreProcessoAfetaNPubsPendentes(): void
    {
        $pubA = $this->makePub('pub-A', candidatos: [['processoId' => 'P_X']]);
        $pubB = $this->makePub('pub-B', candidatos: [['processoId' => 'P_X'], ['processoId' => 'P_Y']]);
        $pubC = $this->makePub('pub-C', candidatos: [['processoId' => 'P_X']]);

        $em = $this->makeEm(bulkPubs: [$pubA, $pubB, $pubC]);
        $svc = $this->makeService($em);

        $count = $svc->bulkIgnoreProcesso('P_X', 'user-advogado');

        self::assertSame(3, $count);

        self::assertSame(PublicacaoAmbigua::STATUS_BULK_IGNORADO, $pubA->get('status'));
        self::assertSame(PublicacaoAmbigua::STATUS_BULK_IGNORADO, $pubB->get('status'));
        self::assertSame(PublicacaoAmbigua::STATUS_BULK_IGNORADO, $pubC->get('status'));

        self::assertCount(3, $this->insertedRows);
        self::assertSame('bulk_ignorar_processo', $this->insertedRows[0][':decision_type']);
        self::assertSame('P_X', $this->insertedRows[0][':chosen_processo_id']);

        $events = \array_column(TogareLogger::getRecorded(), 'event');
        self::assertContains('djen.ambiguity.bulk_ignored', $events);
    }

    public function testBulkIgnoreProcessoIdVazioThrowsInvalidCandidate(): void
    {
        $em = $this->makeEm();
        $svc = $this->makeService($em);

        $this->expectException(InvalidCandidateException::class);
        $svc->bulkIgnoreProcesso('   ', 'user-advogado');
    }

    public function testRollbackQuandoSegundoSavePrazoFalha(): void
    {
        // Cenário: save Prazo OK (call 1), save Pub falha (call 2) → Throwable
        // sobe para o callback do TransactionManager e rollback acontece.
        // Verificamos via expectException + estado intermediário.
        $candidatos = [['processoId' => 'proc-A']];
        $pub = $this->makePub(candidatos: $candidatos);
        $processoA = $this->makeProcesso('proc-A');

        $em = $this->makeEm(pub: $pub, processo: $processoA, saveThrowsOnSecondCall: true);
        $svc = $this->makeService($em);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Simulated DB failure');

        try {
            $svc->resolve('pub-001', 'proc-A', 'user-advogado');
        } finally {
            // Mock TransactionManager simplificado executa $cb diretamente — não há
            // rollback efetivo no mock. A evidência de "rollback teria acontecido"
            // é que o ambiguity log NÃO foi appended (passo 6 nunca rodou pois
            // passo 5 falhou), confirmando ordem do callback.
            self::assertSame([], $this->insertedRows, 'Log NÃO appended porque save Pub falhou antes');
        }
    }
}
