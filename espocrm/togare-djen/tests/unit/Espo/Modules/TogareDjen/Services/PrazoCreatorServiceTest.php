<?php

declare(strict_types=1);

namespace Tests\Unit\Espo\Modules\TogareDjen\Services;

use DateTimeImmutable;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Modules\TogareCore\Entities\Prazo;
use Espo\Modules\TogareCore\Entities\PublicacaoAmbigua;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareDjen\Services\CreationResult;
use Espo\Modules\TogareDjen\Services\MatchResult;
use Espo\Modules\TogareDjen\Services\PrazoCalculado;
use Espo\Modules\TogareDjen\Services\PrazoCreatorService;
use Espo\Modules\TogareDjen\Services\PublicationMatcher;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use PDOException;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Story 4a.3 + Story 4b.1b — PrazoCreatorService.
 *
 * Pipeline coberto:
 *  - AC2 4b.1b: matcher.kind=single → CreationResult::prazoBound + Prazo `pendente` vinculado.
 *  - AC2 4b.1b: matcher.kind=none → CreationResult::prazoRascunho + Prazo `rascunho`.
 *  - AC2 4b.1b: matcher.kind=multiple → CreationResult::publicacaoAmbigua + PublicacaoAmbigua persistida.
 *  - AC2 4b.1b: matcher.kind=too_many → CreationResult::prazoRascunho (escala manual).
 *  - AC5 4a.3 + idempotência cross-table 4b.1b: re-fetch sourcePubId em prazo OR publicacao_ambigua.
 *  - AC5.1 4a.3: race condition saveEntity PDOException duplicate-key → refetch concorrente.
 *  - AC6 4a.3: heurística assignedUser cascade — payload.userId / Sócio-Admin / NULL.
 *  - digitsOnly + matchProcessoByNumeroCnj helpers: preservados como public para retrocompat.
 *  - AC8 4a.3: publicacaoOrigemRaw JSON serializado roundtrip.
 *
 * Story 4b.1b: PublicationMatcher é param OBRIGATÓRIO (B20 endereçada).
 * Testes mockam PublicationMatcher com MatchResult específico.
 */
final class PrazoCreatorServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TogareLogger::reset();
    }

    private function makePrazoCalculado(int $sourcePubId = 12345): PrazoCalculado
    {
        return new PrazoCalculado(
            dataInicioPrazo: new DateTimeImmutable('2026-05-18'),
            dataFatal: new DateTimeImmutable('2026-06-08'),
            prazoDias: 15,
            contagem: 'uteis',
            atoCodigo: 'contestacao',
            referenciaLegal: 'CPC art. 335',
            confidence: 'high',
            fonteExcerpt: 'CONCEDO o prazo de 15 dias para apresentar contestação',
            regraVersao: '1.0.0',
            sourcePubId: $sourcePubId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function makePayload(string $cnj = '0001234-56.2024.8.26.0001', int $sourcePubId = 12345, ?string $userId = 'user-felipe'): array
    {
        return [
            'type' => 'publication',
            'id' => $sourcePubId,
            'numeroProcesso' => $cnj,
            'tipoComunicacao' => 'Intimação',
            'tipoDocumento' => 'Decisão',
            'dataDisponibilizacao' => '2026-05-15',
            'texto' => 'CONCEDO o prazo de 15 dias para apresentar contestação.',
            'userId' => $userId,
        ];
    }

    /**
     * @param list<?CoreEntity> $findOneReturns
     */
    private function makeRepoMock(array $findOneReturns): RDBRepository
    {
        $repo = $this->createMock(RDBRepository::class);
        $repo->method('where')->willReturn($repo);
        $repo->method('limit')->willReturn($repo);
        $repo->method('distinct')->willReturn($repo);
        $repo->method('join')->willReturn($repo);

        if (\count($findOneReturns) === 1) {
            $repo->method('findOne')->willReturn($findOneReturns[0]);
        } else {
            $repo->method('findOne')->willReturn(...$findOneReturns);
        }

        return $repo;
    }

    /**
     * @param array<string, RDBRepository> $reposByEntityType
     */
    private function makeEntityManagerMock(
        array $reposByEntityType,
        ?CoreEntity $newPrazoEntity = null,
        ?CoreEntity $newPubAmbiguaEntity = null,
        bool $saveThrows = false,
        ?Throwable $saveThrowable = null,
    ): EntityManager
    {
        $em = $this->createMock(EntityManager::class);

        $em->method('getRDBRepository')->willReturnCallback(
            function (string $entityType) use ($reposByEntityType) {
                if (isset($reposByEntityType[$entityType])) {
                    return $reposByEntityType[$entityType];
                }
                return $this->makeRepoMock([null]);
            }
        );

        $em->method('getNewEntity')->willReturnCallback(
            static function (string $entityType) use ($newPrazoEntity, $newPubAmbiguaEntity): CoreEntity {
                if ($entityType === 'Prazo') {
                    return $newPrazoEntity ?? new Prazo();
                }
                if ($entityType === PublicacaoAmbigua::ENTITY_TYPE) {
                    return $newPubAmbiguaEntity ?? new PublicacaoAmbigua();
                }
                return new CoreEntity();
            }
        );

        if ($saveThrows || $saveThrowable !== null) {
            $em->method('saveEntity')->willThrowException($saveThrowable ??
                new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '12345' for key 'prazo_source_pub_id_unique'", 23000)
            );
        } else {
            $em->method('saveEntity')->willReturnCallback(static function (CoreEntity $e): CoreEntity {
                if ($e->getId() === null) {
                    $e->setId('entity-uuid-new');
                }
                return $e;
            });
        }

        return $em;
    }

    /**
     * Helper: cria PublicationMatcher mock que sempre retorna o MatchResult dado.
     */
    private function matcherReturning(MatchResult $result): PublicationMatcher
    {
        $matcher = $this->createMock(PublicationMatcher::class);
        $matcher->method('match')->willReturn($result);
        return $matcher;
    }

    public function testCreateMatcherSingleVinculaProcessoEStatusPendente(): void
    {
        $matchedProcesso = new CoreEntity();
        $matchedProcesso->setId('proc-uuid-001');
        $matchedProcesso->set('numeroCnj', '00012345620248260001');
        $matchedProcesso->set('assignedUserId', 'user-titular');

        $em = $this->makeEntityManagerMock([
            'Prazo' => $this->makeRepoMock([null]),
            'PublicacaoAmbigua' => $this->makeRepoMock([null]),
        ]);

        $matcher = $this->matcherReturning(MatchResult::single($matchedProcesso, '0001234-56.2024.8.26.0001'));

        $svc = new PrazoCreatorService($em, $matcher);
        $result = $svc->create($this->makePayload(), $this->makePrazoCalculado());

        self::assertInstanceOf(CreationResult::class, $result);
        self::assertSame('prazo_bound', $result->kind);

        $prazo = $result->entity;
        self::assertSame(Prazo::STATUS_PENDENTE, $prazo->get('status'));
        self::assertSame('proc-uuid-001', $prazo->get('processoId'));
        self::assertSame('user-titular', $prazo->get('assignedUserId'));
        self::assertSame(Prazo::SOURCE_DJEN, $prazo->get('source'));
        self::assertSame(12345, $prazo->get('sourcePubId'));
        self::assertSame('0001234-56.2024.8.26.0001', $prazo->get('numeroProcessoOriginal'));
        self::assertSame('contestacao', $prazo->get('atoCodigo'));
        self::assertSame('CPC art. 335', $prazo->get('referenciaLegal'));
        self::assertSame('1.0.0', $prazo->get('parserRegraVersao'));

        $events = \array_column(TogareLogger::getRecorded(), 'event');
        self::assertContains('djen.prazo.created_bound', $events);
    }

    public function testCreateMatcherNoneCriaRascunhoComPayloadPreservado(): void
    {
        $em = $this->makeEntityManagerMock([
            'Prazo' => $this->makeRepoMock([null]),
            'PublicacaoAmbigua' => $this->makeRepoMock([null]),
        ]);

        $matcher = $this->matcherReturning(MatchResult::none('9999999-99.2024.8.26.9999'));

        $svc = new PrazoCreatorService($em, $matcher);
        $payload = $this->makePayload(cnj: '9999999-99.2024.8.26.9999');
        $result = $svc->create($payload, $this->makePrazoCalculado());

        self::assertSame('prazo_rascunho', $result->kind);
        $prazo = $result->entity;
        self::assertSame(Prazo::STATUS_RASCUNHO, $prazo->get('status'));
        self::assertNull($prazo->get('processoId'));
        self::assertNull($prazo->get('assignedUserId')); // User não mockado → cascade exaure → null

        $raw = $prazo->get('publicacaoOrigemRaw');
        self::assertIsString($raw);
        $decoded = \json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($payload, $decoded);

        $events = \array_column(TogareLogger::getRecorded(), 'event');
        self::assertContains('djen.prazo.created_unbound', $events);
    }

    public function testCreateIdempotenciaSourcePubIdJaExisteEmPrazoNaoCriaNovo(): void
    {
        $existing = new CoreEntity();
        $existing->setId('prazo-existing-001');
        $existing->set('sourcePubId', 12345);

        $em = $this->makeEntityManagerMock([
            'Prazo' => $this->makeRepoMock([$existing]),
        ]);
        $em->expects($this->never())->method('saveEntity');

        $matcher = $this->matcherReturning(MatchResult::none('whatever'));

        $svc = new PrazoCreatorService($em, $matcher);
        $result = $svc->create($this->makePayload(), $this->makePrazoCalculado());

        self::assertSame('deduped', $result->kind);
        self::assertSame($existing, $result->entity);

        $events = \array_column(TogareLogger::getRecorded(), 'event');
        self::assertContains('djen.prazo.deduped', $events);
        self::assertNotContains('djen.prazo.created_bound', $events);
        self::assertNotContains('djen.prazo.created_unbound', $events);
    }

    public function testCreateIdempotenciaCrossTableSourcePubIdJaExisteEmPubAmbigua(): void
    {
        // Story 4b.1b — NEW: idempotência cross-table.
        $existingPub = new CoreEntity();
        $existingPub->setId('pub-ambigua-existing-001');
        $existingPub->set('sourcePubId', 12345);

        $em = $this->makeEntityManagerMock([
            'Prazo' => $this->makeRepoMock([null]),
            'PublicacaoAmbigua' => $this->makeRepoMock([$existingPub]),
        ]);
        $em->expects($this->never())->method('saveEntity');

        $matcher = $this->matcherReturning(MatchResult::none('whatever'));

        $svc = new PrazoCreatorService($em, $matcher);
        $result = $svc->create($this->makePayload(), $this->makePrazoCalculado());

        self::assertSame('deduped', $result->kind);
        self::assertSame($existingPub, $result->entity);

        $events = \array_column(TogareLogger::getRecorded(), 'event');
        self::assertContains('djen.publication.deduped_via_ambigua_existing', $events);
        self::assertNotContains('djen.prazo.created_bound', $events);
    }

    public function testCreateRaceConditionPdoExceptionDuplicateKeyEhTratadoComoDeduped(): void
    {
        $matchedProcesso = new CoreEntity();
        $matchedProcesso->setId('proc-uuid-001');
        $matchedProcesso->set('assignedUserId', 'user-titular');

        $concurrent = new CoreEntity();
        $concurrent->setId('prazo-concurrent-001');
        $concurrent->set('sourcePubId', 12345);

        // 1ª findOne em Prazo → null (dedup check OK)
        // 2ª findOne em Prazo (após PDOException) → retorna concurrent
        $prazoRepo = $this->createMock(RDBRepository::class);
        $prazoRepo->method('where')->willReturn($prazoRepo);
        $prazoRepo->method('limit')->willReturn($prazoRepo);
        $prazoRepo->method('distinct')->willReturn($prazoRepo);
        $prazoRepo->method('join')->willReturn($prazoRepo);
        $prazoRepo->method('findOne')->willReturn(null, $concurrent);

        $em = $this->createMock(EntityManager::class);
        $em->method('getRDBRepository')->willReturnCallback(
            function (string $entityType) use ($prazoRepo) {
                if ($entityType === 'Prazo') {
                    return $prazoRepo;
                }
                return $this->makeRepoMock([null]);
            }
        );
        $em->method('getNewEntity')->willReturn(new Prazo());
        $em->method('saveEntity')->willThrowException(
            new PDOException("SQLSTATE[23000]: Duplicate entry '12345' for key 'prazo_source_pub_id_unique'", 23000)
        );

        $matcher = $this->matcherReturning(MatchResult::single($matchedProcesso, '0001234-56.2024.8.26.0001'));

        $svc = new PrazoCreatorService($em, $matcher);
        $result = $svc->create($this->makePayload(), $this->makePrazoCalculado());

        self::assertSame('deduped', $result->kind);
        self::assertSame($concurrent, $result->entity);

        $events = \array_column(TogareLogger::getRecorded(), 'event');
        self::assertContains('djen.prazo.deduped_via_constraint', $events);
    }

    public function testCreateRaceConditionDuplicateKeyEmbrulhadoEhTratadoComoDeduped(): void
    {
        $matchedProcesso = new CoreEntity();
        $matchedProcesso->setId('proc-uuid-001');

        $concurrent = new CoreEntity();
        $concurrent->setId('prazo-concurrent-wrapped');
        $concurrent->set('sourcePubId', 12345);

        $prazoRepo = $this->createMock(RDBRepository::class);
        $prazoRepo->method('where')->willReturn($prazoRepo);
        $prazoRepo->method('limit')->willReturn($prazoRepo);
        $prazoRepo->method('distinct')->willReturn($prazoRepo);
        $prazoRepo->method('join')->willReturn($prazoRepo);
        $prazoRepo->method('findOne')->willReturn(null, $concurrent);

        $em = $this->makeEntityManagerMock(
            [
                'Prazo' => $prazoRepo,
                'PublicacaoAmbigua' => $this->makeRepoMock([null]),
            ],
            saveThrowable: new \RuntimeException(
                'ORM wrapped duplicate key',
                0,
                new PDOException("SQLSTATE[23000]: Duplicate entry '12345' for key 'prazo_source_pub_id_unique'", 23000),
            ),
        );

        $svc = new PrazoCreatorService(
            $em,
            $this->matcherReturning(MatchResult::single($matchedProcesso, '0001234-56.2024.8.26.0001')),
        );

        $result = $svc->create($this->makePayload(), $this->makePrazoCalculado());

        self::assertSame('deduped', $result->kind);
        self::assertSame($concurrent, $result->entity);
    }

    public function testCreatePublicacaoAmbiguaRaceDuplicateKeyEmbrulhadoEhTratadoComoDeduped(): void
    {
        $concurrent = new CoreEntity();
        $concurrent->setId('pub-ambigua-concurrent-wrapped');
        $concurrent->set('sourcePubId', 12345);

        $pubRepo = $this->createMock(RDBRepository::class);
        $pubRepo->method('where')->willReturn($pubRepo);
        $pubRepo->method('limit')->willReturn($pubRepo);
        $pubRepo->method('distinct')->willReturn($pubRepo);
        $pubRepo->method('join')->willReturn($pubRepo);
        $pubRepo->method('findOne')->willReturn(null, $concurrent);

        $em = $this->makeEntityManagerMock(
            [
                'Prazo' => $this->makeRepoMock([null]),
                'PublicacaoAmbigua' => $pubRepo,
            ],
            saveThrowable: new \RuntimeException(
                'ORM wrapped duplicate key',
                0,
                new PDOException("SQLSTATE[23000]: Duplicate entry '12345' for key 'publicacao_ambigua_source_pub_id_unique'", 23000),
            ),
        );

        $candidatos = [
            ['processoId' => 'proc-A', 'numeroCnj' => '00011111120248260001', 'clienteNome' => 'Joao', 'parteContrariaNome' => 'Banco X', 'dataDistribuicao' => null, 'area' => null, 'fase' => null, 'codigoCor' => 'azul'],
            ['processoId' => 'proc-B', 'numeroCnj' => '00022222220248260001', 'clienteNome' => 'Maria', 'parteContrariaNome' => 'Banco Y', 'dataDistribuicao' => null, 'area' => null, 'fase' => null, 'codigoCor' => 'laranja'],
        ];

        $svc = new PrazoCreatorService(
            $em,
            $this->matcherReturning(MatchResult::multiple($candidatos, 'name_match_multiplos_candidatos', 'cnj-original')),
        );

        $result = $svc->create($this->makePayload(), $this->makePrazoCalculado());

        self::assertSame('deduped', $result->kind);
        self::assertSame($concurrent, $result->entity);
    }

    public function testHeuristicaAssignedUserUsaPayloadUserIdQuandoUserAtivo(): void
    {
        $userActive = new CoreEntity();
        $userActive->setId('user-felipe');
        $userActive->set('isActive', true);

        $em = $this->createMock(EntityManager::class);
        $em->method('getRDBRepository')->willReturnCallback(
            function (string $entityType) use ($userActive) {
                if ($entityType === 'Prazo') {
                    return $this->makeRepoMock([null]);
                }
                if ($entityType === 'User') {
                    return $this->makeRepoMock([$userActive]);
                }
                return $this->makeRepoMock([null]);
            }
        );
        $em->method('getNewEntity')->willReturn(new Prazo());
        $em->method('saveEntity')->willReturnCallback(static function (CoreEntity $e): CoreEntity {
            $e->setId('prazo-new');
            return $e;
        });

        $matcher = $this->matcherReturning(MatchResult::none('cnj'));

        $svc = new PrazoCreatorService($em, $matcher);
        $result = $svc->create($this->makePayload(userId: 'user-felipe'), $this->makePrazoCalculado());

        self::assertSame('user-felipe', $result->entity->get('assignedUserId'));

        $events = \array_column(TogareLogger::getRecorded(), 'event');
        self::assertNotContains('djen.prazo.assignee_fallback_socio_admin', $events);
        self::assertNotContains('djen.prazo.no_assignee_fallback', $events);
    }

    public function testHeuristicaAssignedUserFallbackSocioAdminQuandoUserIdAusente(): void
    {
        $socioAdmin = new CoreEntity();
        $socioAdmin->setId('user-socio');

        $em = $this->createMock(EntityManager::class);
        $callCounter = ['User' => 0];
        $em->method('getRDBRepository')->willReturnCallback(
            function (string $entityType) use ($socioAdmin, &$callCounter) {
                if ($entityType === 'Prazo') {
                    return $this->makeRepoMock([null]);
                }
                if ($entityType === 'User') {
                    $callCounter['User']++;
                    return $callCounter['User'] === 1
                        ? $this->makeRepoMock([null])
                        : $this->makeRepoMock([$socioAdmin]);
                }
                return $this->makeRepoMock([null]);
            }
        );
        $em->method('getNewEntity')->willReturn(new Prazo());
        $em->method('saveEntity')->willReturnCallback(static function (CoreEntity $e): CoreEntity {
            $e->setId('prazo-new');
            return $e;
        });

        $matcher = $this->matcherReturning(MatchResult::none('cnj'));

        $svc = new PrazoCreatorService($em, $matcher);
        $result = $svc->create($this->makePayload(userId: 'user-stale'), $this->makePrazoCalculado());

        self::assertSame('user-socio', $result->entity->get('assignedUserId'));

        $events = \array_column(TogareLogger::getRecorded(), 'event');
        self::assertContains('djen.prazo.assignee_fallback_socio_admin', $events);
    }

    public function testHeuristicaAssignedUserNullQuandoSemSocioAdmin(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->method('getRDBRepository')->willReturnCallback(
            function (string $entityType) {
                return $this->makeRepoMock([null]);
            }
        );
        $em->method('getNewEntity')->willReturn(new Prazo());
        $em->method('saveEntity')->willReturnCallback(static function (CoreEntity $e): CoreEntity {
            $e->setId('prazo-new');
            return $e;
        });

        $payload = $this->makePayload(userId: null);
        $payload['userId'] = null;

        $matcher = $this->matcherReturning(MatchResult::none('cnj'));

        $svc = new PrazoCreatorService($em, $matcher);
        $result = $svc->create($payload, $this->makePrazoCalculado());

        self::assertNull($result->entity->get('assignedUserId'));

        $events = \array_column(TogareLogger::getRecorded(), 'event');
        self::assertContains('djen.prazo.no_assignee_fallback', $events);
    }

    public function testDigitsOnlyHelperPublico(): void
    {
        $svc = new PrazoCreatorService(
            $this->createMock(EntityManager::class),
            $this->createMock(PublicationMatcher::class),
        );
        self::assertSame('00012345620248260001', $svc->digitsOnly('0001234-56.2024.8.26.0001'));
        self::assertSame('00012345620248260001', $svc->digitsOnly('00012345620248260001'));
        self::assertSame('123', $svc->digitsOnly('abc123'));
        self::assertSame('', $svc->digitsOnly(''));
    }

    public function testMatchProcessoByNumeroCnjPreservadoComoHelperPublico(): void
    {
        $matchedProcesso = new CoreEntity();
        $matchedProcesso->setId('proc-001');

        $em = $this->makeEntityManagerMock([
            'Prazo' => $this->makeRepoMock([null]),
            'Processo' => $this->makeRepoMock([$matchedProcesso]),
        ]);

        $svc = new PrazoCreatorService($em, $this->createMock(PublicationMatcher::class));
        $matched = $svc->matchProcessoByNumeroCnj('0001234-56.2024.8.26.0001', 12345);

        self::assertSame($matchedProcesso, $matched);
    }

    public function testCnjFormatoInvalidoLogaWarningEMatchProcessoByNumeroCnjRetornaNull(): void
    {
        $em = $this->makeEntityManagerMock([
            'Prazo' => $this->makeRepoMock([null]),
        ]);

        $svc = new PrazoCreatorService($em, $this->createMock(PublicationMatcher::class));
        $matched = $svc->matchProcessoByNumeroCnj('123', 999);

        self::assertNull($matched);

        $events = \array_column(TogareLogger::getRecorded(), 'event');
        self::assertContains('djen.prazo.invalid_cnj_format', $events);
    }

    public function testPublicacaoOrigemRawSerializaPayloadCompletoEParseaRoundtrip(): void
    {
        $em = $this->makeEntityManagerMock([
            'Prazo' => $this->makeRepoMock([null]),
            'PublicacaoAmbigua' => $this->makeRepoMock([null]),
        ]);

        $payload = $this->makePayload();
        $payload['extraField'] = 'valor com acentuação ção';
        $payload['linkOriginal'] = 'https://comunicaapi.pje.jus.br/comunicacao/12345';

        $matcher = $this->matcherReturning(MatchResult::none('cnj'));

        $svc = new PrazoCreatorService($em, $matcher);
        $result = $svc->create($payload, $this->makePrazoCalculado());

        $raw = $result->entity->get('publicacaoOrigemRaw');
        self::assertIsString($raw);
        self::assertStringContainsString('valor com acentuação ção', $raw);

        $decoded = \json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($payload, $decoded);
    }

    // ---------- NOVOS CENÁRIOS Story 4b.1b ----------

    public function testCreateMatcherMultipleCriaPublicacaoAmbiguaPersistida(): void
    {
        $candidatos = [
            ['processoId' => 'proc-A', 'numeroCnj' => '00011111120248260001', 'clienteNome' => 'João', 'parteContrariaNome' => 'Banco X', 'dataDistribuicao' => '2024-01-15', 'area' => 'civel', 'fase' => 'conhecimento', 'codigoCor' => 'azul'],
            ['processoId' => 'proc-B', 'numeroCnj' => '00099999920248260001', 'clienteNome' => 'Maria', 'parteContrariaNome' => 'Banco Y', 'dataDistribuicao' => '2024-03-20', 'area' => 'trabalhista', 'fase' => 'recurso', 'codigoCor' => 'laranja'],
        ];

        $em = $this->makeEntityManagerMock([
            'Prazo' => $this->makeRepoMock([null]),
            'PublicacaoAmbigua' => $this->makeRepoMock([null]),
        ]);

        $matcher = $this->matcherReturning(MatchResult::multiple($candidatos, 'name_match_multiplos_candidatos', '0099999-99.2024.8.26.0001'));

        $svc = new PrazoCreatorService($em, $matcher);
        $result = $svc->create($this->makePayload(cnj: '0099999-99.2024.8.26.0001'), $this->makePrazoCalculado());

        self::assertSame('publicacao_ambigua', $result->kind);
        self::assertInstanceOf(PublicacaoAmbigua::class, $result->entity);

        $pub = $result->entity;
        self::assertSame(PublicacaoAmbigua::STATUS_PENDENTE_REVISAO, $pub->get('status'));
        self::assertSame(12345, $pub->get('sourcePubId'));
        self::assertSame('name_match_multiplos_candidatos', $pub->get('ambiguityReason'));
        self::assertSame('0099999-99.2024.8.26.0001', $pub->get('numeroProcessoOriginal'));
        self::assertSame('contestacao', $pub->get('atoCodigo'));
        self::assertSame('CPC art. 335', $pub->get('referenciaLegal'));

        $candidatosJson = $pub->get('candidatos');
        self::assertIsString($candidatosJson);
        $decoded = \json_decode($candidatosJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(2, $decoded);
        self::assertSame('proc-A', $decoded[0]['processoId']);

        $events = \array_column(TogareLogger::getRecorded(), 'event');
        self::assertContains('djen.publication.ambiguous_queued', $events);
    }

    public function testCreateMatcherTooManyCriaPrazoRascunhoComLogWarning(): void
    {
        $em = $this->makeEntityManagerMock([
            'Prazo' => $this->makeRepoMock([null]),
            'PublicacaoAmbigua' => $this->makeRepoMock([null]),
        ]);

        $matcher = $this->matcherReturning(MatchResult::tooMany('cnj-original'));

        $svc = new PrazoCreatorService($em, $matcher);
        $result = $svc->create($this->makePayload(), $this->makePrazoCalculado());

        self::assertSame('prazo_rascunho', $result->kind);
        self::assertSame(Prazo::STATUS_RASCUNHO, $result->entity->get('status'));

        // O log de namematch_too_many vem do PublicationMatcher (não testado aqui — testado em PublicationMatcherTest).
        // Mas o creator deve criar Prazo rascunho, NÃO PublicacaoAmbigua.
        $events = \array_column(TogareLogger::getRecorded(), 'event');
        self::assertContains('djen.prazo.created_unbound', $events);
        self::assertNotContains('djen.publication.ambiguous_queued', $events);
    }

    public function testCreateMatcherSingleViaNamematchAlemDeCnjExatoCriaPrazoBound(): void
    {
        // Cenário: matcher.kind=single mas processo veio via Fase 2 (name-match) — comportamento idêntico ao path CNJ exato.
        $matchedProcesso = new CoreEntity();
        $matchedProcesso->setId('proc-namematch-A');
        $matchedProcesso->set('assignedUserId', 'user-namematch');

        $em = $this->makeEntityManagerMock([
            'Prazo' => $this->makeRepoMock([null]),
            'PublicacaoAmbigua' => $this->makeRepoMock([null]),
        ]);

        $matcher = $this->matcherReturning(MatchResult::single($matchedProcesso, ''));

        $svc = new PrazoCreatorService($em, $matcher);
        $result = $svc->create($this->makePayload(cnj: ''), $this->makePrazoCalculado());

        self::assertSame('prazo_bound', $result->kind);
        self::assertSame('proc-namematch-A', $result->entity->get('processoId'));
        self::assertSame('user-namematch', $result->entity->get('assignedUserId'));
    }
}
