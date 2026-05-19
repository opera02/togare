<?php

declare(strict_types=1);

namespace Tests\Unit\Espo\Modules\TogareDjen\Services;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Modules\TogareCore\Entities\Prazo;
use Espo\Modules\TogareCore\Services\Calendar\BrazilianBusinessCalendar;
use Espo\Modules\TogareCore\Services\QueueService;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareDjen\Contracts\PublicationSourceAdapterContract;
use Espo\Modules\TogareDjen\Exception\DjenAdapterUnavailableException;
use Espo\Modules\TogareDjen\Services\DjenAtoClassifier;
use Espo\Modules\TogareDjen\Services\DjenParserService;
use Espo\Modules\TogareDjen\Services\DjenPrazoRules;
use Espo\Modules\TogareDjen\Services\DjenWorkerService;
use Espo\Modules\TogareDjen\Services\PrazoCalculado;
use Espo\Modules\TogareDjen\Services\CreationResult;
use Espo\Modules\TogareDjen\Services\PrazoCreatorService;
use PHPUnit\Framework\TestCase;

/**
 * Story 4a.1 + 4a.2 — DjenWorkerService.
 *
 * Cobre (Story 4a.1):
 *  - Dispatch type=sync_window → adapter.fetchPublicacoes + enqueue de N type=publication.
 *  - Captura DjenAdapterUnavailableException → markFailed customDelay=3600 (AC3 4a.1).
 *  - Captura Forbidden license_expired → markFailed customDelay=3600 (AC5.1 4a.1).
 *  - Outros Throwable → markFailed delay padrão.
 *  - Fila vazia retorna false sem efeitos colaterais.
 *  - Payload type desconhecido → markFailed delay padrão.
 *
 * Cobre (Story 4a.2 — handler real substitui stub):
 *  - Dispatch type=publication → chama parser, loga `djen.publication.parsed` (AC10).
 *  - Confidence=low → log adicional `djen.parser.classifier_lowconfidence` (AC10.1).
 *  - Parser retorna null (ato certificatório) → log `djen.publication.unparsed` (AC9).
 */
final class DjenWorkerServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TogareLogger::reset();
    }

    /**
     * Helper: cria DjenParserService real com deps puras (calendar/classifier/rules
     * são standalone — barato instanciar). Evita stubbing da árvore inteira nos
     * testes que não focam no parser.
     */
    private function makeParser(): DjenParserService
    {
        return new DjenParserService(
            new BrazilianBusinessCalendar(),
            new DjenAtoClassifier(),
            new DjenPrazoRules(),
        );
    }

    /**
     * Helper Story 4a.3 + 4b.1b: cria PrazoCreatorService mock que retorna
     * CreationResult (assinatura mudou na 4b.1b — Entity → CreationResult).
     * Worker descarta o retorno; mantemos default `prazoBound` por simplicidade.
     */
    private function makeCreator(): PrazoCreatorService
    {
        $creator = $this->createMock(PrazoCreatorService::class);
        $defaultEntity = new Prazo();
        $defaultEntity->setId('prazo-stub-default');
        $creator->method('create')->willReturn(CreationResult::prazoBound($defaultEntity));
        return $creator;
    }

    /**
     * Helper Story 4a.3 + 4b.1b: cria PrazoCreatorService mock retornando
     * `CreationResult::prazoBound($entity)` (compat com testes pré-4b.1b
     * que esperam Prazo bound).
     */
    private function makeCreatorReturning(CoreEntity $entity): PrazoCreatorService
    {
        $creator = $this->createMock(PrazoCreatorService::class);
        $creator->method('create')->willReturn(CreationResult::prazoBound($entity));
        return $creator;
    }

    /**
     * Helper Story 4a.3: cria PrazoCreatorService mock que LANÇA exception
     * em create() — usado para validar try/catch hierárquico (AC7.1).
     */
    private function makeCreatorThrowing(\Throwable $exception): PrazoCreatorService
    {
        $creator = $this->createMock(PrazoCreatorService::class);
        $creator->method('create')->willThrowException($exception);
        return $creator;
    }

    public function testFilaVaziaRetornaFalseSemEfeitosColaterais(): void
    {
        $queue = $this->createMock(QueueService::class);
        $queue->method('claim')->with('djen', 1)->willReturn([]);
        $queue->expects($this->never())->method('markDone');
        $queue->expects($this->never())->method('markFailed');

        $adapter = $this->createMock(PublicationSourceAdapterContract::class);

        $worker = new DjenWorkerService($queue, $adapter, $this->makeParser(), $this->makeCreator());
        $this->assertFalse($worker->processOne());
    }

    public function testDispatchSyncWindowEnfileiraPublicacoesViaAdapter(): void
    {
        $payload = [
            'type' => 'sync_window',
            'userId' => 'felipe-id',
            'oab' => '462034',
            'uf' => 'SP',
            'dataInicio' => '2026-04-30',
            'dataFim' => '2026-04-30',
        ];
        $queue = $this->createMock(QueueService::class);
        $queue->method('claim')->willReturn([
            ['id' => 'parent-1', 'queue_name' => 'djen', 'payload' => $payload, 'correlation_id' => null, 'retry_count' => 0],
        ]);

        $enqueueCalls = [];
        $queue->method('enqueue')->willReturnCallback(
            function (string $q, array $p, string $k) use (&$enqueueCalls): string {
                $enqueueCalls[] = ['queueName' => $q, 'payload' => $p, 'key' => $k];
                return 'pub-' . \count($enqueueCalls);
            }
        );
        $queue->expects($this->once())->method('markDone')->with('parent-1');

        $adapter = $this->createMock(PublicationSourceAdapterContract::class);
        $adapter->method('fetchPublicacoes')->willReturnCallback(
            static function () {
                yield ['id' => 100, 'numeroProcesso' => '00012345-67.2025.8.26.0100', 'siglaTribunal' => 'TJSP', 'texto' => 'pub1'];
                yield ['id' => 200, 'numeroProcesso' => '00098765-43.2025.8.26.0100', 'siglaTribunal' => 'TJSP', 'texto' => 'pub2'];
            }
        );

        $worker = new DjenWorkerService($queue, $adapter, $this->makeParser(), $this->makeCreator());
        $this->assertTrue($worker->processOne());

        $this->assertCount(2, $enqueueCalls);
        $this->assertSame('djen.pub.100', $enqueueCalls[0]['key']);
        $this->assertSame('publication', $enqueueCalls[0]['payload']['type']);
        $this->assertSame('felipe-id', $enqueueCalls[0]['payload']['userId']);
        $this->assertSame('parent-1', $enqueueCalls[0]['payload']['parentSyncWindowItemId']);
        $this->assertSame('djen.pub.200', $enqueueCalls[1]['key']);
    }

    /**
     * Story 4a.2 (substitui o teste do stub da 4a.1):
     * Handler real chama parser, loga `djen.publication.parsed` com PrazoCalculado,
     * NÃO loga mais `djen.publication.received` (evento descontinuado).
     */
    public function testDispatchPublicationChamaParserELogaDjenPublicationParsed(): void
    {
        $payload = [
            'type' => 'publication',
            'id' => 999,
            'numeroProcesso' => '00012345672025826010',
            'siglaTribunal' => 'TJSP',
            'tipoComunicacao' => 'Intimação',
            'tipoDocumento' => 'Decisão',
            'dataDisponibilizacao' => '2026-05-15', // sex
            'texto' => 'CONCEDO o prazo de 15 dias para apresentar contestação.',
            'userId' => 'felipe-id',
        ];
        $queue = $this->createMock(QueueService::class);
        $queue->method('claim')->willReturn([
            ['id' => 'pub-item-1', 'queue_name' => 'djen', 'payload' => $payload, 'correlation_id' => null, 'retry_count' => 0],
        ]);
        $queue->expects($this->once())->method('markDone')->with('pub-item-1');
        $queue->expects($this->never())->method('enqueue');

        $adapter = $this->createMock(PublicationSourceAdapterContract::class);
        $adapter->expects($this->never())->method('fetchPublicacoes');

        $worker = new DjenWorkerService($queue, $adapter, $this->makeParser(), $this->makeCreator());
        $this->assertTrue($worker->processOne());

        $events = TogareLogger::getRecorded();
        $parsed = \array_filter($events, static fn ($e) => $e['event'] === 'djen.publication.parsed');
        $this->assertCount(1, $parsed, 'Handler 4a.2 deve logar djen.publication.parsed');
        $first = \array_values($parsed)[0];
        $this->assertSame(999, $first['context']['pubId']);
        $this->assertSame('contestacao', $first['context']['atoCodigo']);
        $this->assertSame(15, $first['context']['prazoDias']);
        $this->assertSame('uteis', $first['context']['contagem']);
        $this->assertSame('CPC art. 335', $first['context']['referenciaLegal']);
        $this->assertSame('high', $first['context']['confidence']);
        $this->assertSame('1.0.0', $first['context']['regraVersao']);
        $this->assertSame('2026-05-18', $first['context']['dataInicioPrazo']);
        $this->assertSame('2026-06-08', $first['context']['dataFatal'], 'AC6 — dataFatal=08/06: 1º dia em 18/05 (inclusive); 15º útil cai em 08/06 com Corpus Christi 04/06 pulado');

        // Handler 4a.2 NÃO loga mais o evento descontinuado da 4a.1.
        $received = \array_filter($events, static fn ($e) => $e['event'] === 'djen.publication.received');
        $this->assertCount(0, $received, 'Handler 4a.2 NÃO deve logar djen.publication.received (descontinuado)');
    }

    /**
     * Story 4a.2 AC10.1 — confidence=low loga warning adicional
     * `djen.parser.classifier_lowconfidence` E ainda assim segue o pipeline
     * (NÃO joga exception — confidence=low é estado válido).
     */
    public function testDispatchPublicationLogaWarningEmConfidenceLow(): void
    {
        $payload = [
            'type' => 'publication',
            'id' => 1234,
            'numeroProcesso' => '00012345672025826010',
            'tipoComunicacao' => 'Intimação',
            'tipoDocumento' => 'Despacho',
            'dataDisponibilizacao' => '2026-05-15',
            'texto' => 'Intimem-se as partes do conteúdo da decisão proferida nos autos.',
            'userId' => 'felipe-id',
        ];
        $queue = $this->createMock(QueueService::class);
        $queue->method('claim')->willReturn([
            ['id' => 'pub-low-1', 'queue_name' => 'djen', 'payload' => $payload, 'correlation_id' => null, 'retry_count' => 0],
        ]);
        $queue->expects($this->once())->method('markDone')->with('pub-low-1');

        $adapter = $this->createMock(PublicationSourceAdapterContract::class);

        $worker = new DjenWorkerService($queue, $adapter, $this->makeParser(), $this->makeCreator());
        $this->assertTrue($worker->processOne());

        $eventNames = \array_column(TogareLogger::getRecorded(), 'event');
        $this->assertContains('djen.parser.classifier_lowconfidence', $eventNames);
        $this->assertContains('djen.publication.parsed', $eventNames, 'Mesmo com low confidence, parsed log dispara');
    }

    /**
     * Story 4a.2 AC9 — parser retorna null para ato puramente certificatório
     * → handler loga `djen.publication.unparsed` (info) e markDone normal.
     * Pipeline NÃO falha; zero perda silenciosa.
     */
    public function testDispatchPublicationLogaUnparsedQuandoParserRetornaNull(): void
    {
        $payload = [
            'type' => 'publication',
            'id' => 5555,
            'numeroProcesso' => '00012345672025826010',
            'tipoComunicacao' => 'Intimação',
            'tipoDocumento' => 'Ato ordinatório',
            'dataDisponibilizacao' => '2026-05-15',
            'texto' => 'Junte-se a petição. Cumpra-se. Publique-se.',
            'userId' => 'felipe-id',
        ];
        $queue = $this->createMock(QueueService::class);
        $queue->method('claim')->willReturn([
            ['id' => 'pub-unparsed-1', 'queue_name' => 'djen', 'payload' => $payload, 'correlation_id' => null, 'retry_count' => 0],
        ]);
        $queue->expects($this->once())->method('markDone')->with('pub-unparsed-1');
        $queue->expects($this->never())->method('markFailed');

        $adapter = $this->createMock(PublicationSourceAdapterContract::class);

        $worker = new DjenWorkerService($queue, $adapter, $this->makeParser(), $this->makeCreator());
        $this->assertTrue($worker->processOne());

        $eventNames = \array_column(TogareLogger::getRecorded(), 'event');
        $this->assertContains('djen.publication.unparsed', $eventNames);
        $this->assertNotContains(
            'djen.publication.parsed',
            $eventNames,
            'Quando parser retorna null, parsed NÃO deve aparecer',
        );
    }

    public function testCapturaDjenAdapterUnavailableEMarkFailedCustomDelay3600(): void
    {
        $payload = [
            'type' => 'sync_window',
            'userId' => 'felipe-id', 'oab' => '462034', 'uf' => 'SP',
            'dataInicio' => '2026-04-30', 'dataFim' => '2026-04-30',
        ];
        $queue = $this->createMock(QueueService::class);
        $queue->method('claim')->willReturn([
            ['id' => 'failing-1', 'queue_name' => 'djen', 'payload' => $payload, 'correlation_id' => null, 'retry_count' => 0],
        ]);
        $queue->expects($this->never())->method('markDone');
        $queue->expects($this->once())
            ->method('markFailed')
            ->with(
                'failing-1',
                $this->stringContains('djen sync window failed:'),
                false,
                3600,
            );

        $adapter = $this->createMock(PublicationSourceAdapterContract::class);
        $adapter->method('fetchPublicacoes')
            ->willThrowException(new DjenAdapterUnavailableException('HTTP 502'));

        $worker = new DjenWorkerService($queue, $adapter, $this->makeParser(), $this->makeCreator());
        $this->assertTrue($worker->processOne());

        $events = \array_column(TogareLogger::getRecorded(), 'event');
        $this->assertContains('djen.worker.adapter_unavailable_retry', $events);
    }

    public function testCapturaForbiddenLicenseExpiredEMarkFailedCustomDelay3600(): void
    {
        $adapter = $this->createMock(PublicationSourceAdapterContract::class);
        $adapter->method('fetchPublicacoes')->willThrowException(
            new Forbidden('Module togare-djen is in read-only state (license_expired)')
        );
        $payloadSync = [
            'type' => 'sync_window',
            'userId' => 'felipe-id', 'oab' => '462034', 'uf' => 'SP',
            'dataInicio' => '2026-04-30', 'dataFim' => '2026-04-30',
        ];
        $queue = $this->createMock(QueueService::class);
        $queue->method('claim')->willReturn([
            ['id' => 'license-blocked-1', 'queue_name' => 'djen', 'payload' => $payloadSync, 'correlation_id' => null, 'retry_count' => 0],
        ]);
        $queue->expects($this->never())->method('markDone');
        $queue->expects($this->once())
            ->method('markFailed')
            ->with(
                'license-blocked-1',
                'license_expired',
                false,
                3600,
            );

        $worker = new DjenWorkerService($queue, $adapter, $this->makeParser(), $this->makeCreator());
        $this->assertTrue($worker->processOne());

        $events = \array_column(TogareLogger::getRecorded(), 'event');
        $this->assertContains('djen.worker.license_expired_retry', $events);
    }

    public function testForbiddenGenericoNaoLicenseEhClassificadoComoForbiddenSemDeadLetter(): void
    {
        $payloadSync = [
            'type' => 'sync_window',
            'userId' => 'felipe-id', 'oab' => '462034', 'uf' => 'SP',
            'dataInicio' => '2026-04-30', 'dataFim' => '2026-04-30',
        ];
        $queue = $this->createMock(QueueService::class);
        $queue->method('claim')->willReturn([
            ['id' => 'forbidden-other-1', 'queue_name' => 'djen', 'payload' => $payloadSync, 'correlation_id' => null, 'retry_count' => 0],
        ]);
        $queue->expects($this->once())
            ->method('markFailed')
            ->with(
                'forbidden-other-1',
                'forbidden',
                false,
                3600,
            );

        $adapter = $this->createMock(PublicationSourceAdapterContract::class);
        $adapter->method('fetchPublicacoes')
            ->willThrowException(new Forbidden('ACL denied for entity X'));

        $worker = new DjenWorkerService($queue, $adapter, $this->makeParser(), $this->makeCreator());
        $worker->processOne();
    }

    public function testThrowableGenericoUsaDelayPadraoNaoCustom(): void
    {
        $payloadSync = [
            'type' => 'sync_window',
            'userId' => 'felipe-id', 'oab' => '462034', 'uf' => 'SP',
            'dataInicio' => '2026-04-30', 'dataFim' => '2026-04-30',
        ];
        $queue = $this->createMock(QueueService::class);
        $queue->method('claim')->willReturn([
            ['id' => 'unexpected-1', 'queue_name' => 'djen', 'payload' => $payloadSync, 'correlation_id' => null, 'retry_count' => 0],
        ]);
        $queue->expects($this->once())
            ->method('markFailed')
            ->with(
                'unexpected-1',
                $this->stringContains('boom'),
                false,
                null, // delay padrão — backoff exponencial
            );

        $adapter = $this->createMock(PublicationSourceAdapterContract::class);
        $adapter->method('fetchPublicacoes')->willThrowException(new \RuntimeException('boom'));

        $worker = new DjenWorkerService($queue, $adapter, $this->makeParser(), $this->makeCreator());
        $worker->processOne();

        $events = \array_column(TogareLogger::getRecorded(), 'event');
        $this->assertContains('djen.worker.unexpected_error', $events);
    }

    public function testPayloadTypeDesconhecidoMarkFailedDelayPadrao(): void
    {
        $queue = $this->createMock(QueueService::class);
        $queue->method('claim')->willReturn([
            ['id' => 'unknown-1', 'queue_name' => 'djen', 'payload' => ['type' => 'wat', 'foo' => 'bar'], 'correlation_id' => null, 'retry_count' => 0],
        ]);
        $queue->expects($this->never())->method('markDone');
        $queue->expects($this->once())
            ->method('markFailed')
            ->with(
                'unknown-1',
                $this->stringContains('Payload type desconhecido'),
                false,
                null,
            );

        $adapter = $this->createMock(PublicationSourceAdapterContract::class);
        $worker = new DjenWorkerService($queue, $adapter, $this->makeParser(), $this->makeCreator());
        $worker->processOne();

        $events = \array_column(TogareLogger::getRecorded(), 'event');
        $this->assertContains('djen.worker.unknown_payload_type', $events);
    }

    /**
     * Story 4a.3 AC7 — handler chama PrazoCreatorService quando parser
     * retorna PrazoCalculado válido (caminho match → status=pendente).
     */
    public function testDispatchPublicationCriaPrazoQuandoMatch(): void
    {
        $payload = [
            'type' => 'publication',
            'id' => 999,
            'numeroProcesso' => '00012345672025826010',
            'tipoComunicacao' => 'Intimação',
            'tipoDocumento' => 'Decisão',
            'dataDisponibilizacao' => '2026-05-15',
            'texto' => 'CONCEDO o prazo de 15 dias para apresentar contestação.',
            'userId' => 'felipe-id',
        ];
        $queue = $this->createMock(QueueService::class);
        $queue->method('claim')->willReturn([
            ['id' => 'pub-bound-1', 'queue_name' => 'djen', 'payload' => $payload, 'correlation_id' => null, 'retry_count' => 0],
        ]);
        $queue->expects($this->once())->method('markDone')->with('pub-bound-1');
        $queue->expects($this->never())->method('markFailed');

        $adapter = $this->createMock(PublicationSourceAdapterContract::class);

        $boundPrazo = new Prazo();
        $boundPrazo->setId('prazo-bound-001');
        $boundPrazo->set('status', Prazo::STATUS_PENDENTE);
        $boundPrazo->set('processoId', 'proc-001');

        $creator = $this->createMock(PrazoCreatorService::class);
        $creator->expects($this->once())
            ->method('create')
            ->with(
                $this->isType('array'),
                $this->isInstanceOf(PrazoCalculado::class),
            )
            ->willReturn(CreationResult::prazoBound($boundPrazo));

        $worker = new DjenWorkerService($queue, $adapter, $this->makeParser(), $creator);
        $this->assertTrue($worker->processOne());

        // Parsed log dispara, creator é chamado, markDone normal.
        $events = \array_column(TogareLogger::getRecorded(), 'event');
        $this->assertContains('djen.publication.parsed', $events);
    }

    /**
     * Story 4a.3 AC7 — handler chama creator e ele retorna rascunho
     * quando sem match. Worker continua sem distinguir o caminho.
     */
    public function testDispatchPublicationCriaPrazoRascunhoQuandoSemMatch(): void
    {
        $payload = [
            'type' => 'publication',
            'id' => 998,
            'numeroProcesso' => '99999999992025826099',
            'tipoComunicacao' => 'Intimação',
            'tipoDocumento' => 'Decisão',
            'dataDisponibilizacao' => '2026-05-15',
            'texto' => 'CONCEDO o prazo de 15 dias para apresentar contestação.',
            'userId' => 'felipe-id',
        ];
        $queue = $this->createMock(QueueService::class);
        $queue->method('claim')->willReturn([
            ['id' => 'pub-rascunho-1', 'queue_name' => 'djen', 'payload' => $payload, 'correlation_id' => null, 'retry_count' => 0],
        ]);
        $queue->expects($this->once())->method('markDone')->with('pub-rascunho-1');

        $adapter = $this->createMock(PublicationSourceAdapterContract::class);

        $rascunho = new Prazo();
        $rascunho->setId('prazo-rascunho-001');
        $rascunho->set('status', Prazo::STATUS_RASCUNHO);
        $rascunho->set('processoId', null);

        $creator = $this->makeCreatorReturning($rascunho);

        $worker = new DjenWorkerService($queue, $adapter, $this->makeParser(), $creator);
        $this->assertTrue($worker->processOne());

        $events = \array_column(TogareLogger::getRecorded(), 'event');
        $this->assertContains('djen.publication.parsed', $events);
    }

    /**
     * Story 4a.3 AC5 — quando creator detecta sourcePubId já existente,
     * retorna a entity pré-existente (deduped). Worker NÃO falha — markDone
     * normal porque o item da fila já foi processado em rodada anterior.
     */
    public function testDispatchPublicationDedupedQuandoSourcePubIdJaExiste(): void
    {
        $payload = [
            'type' => 'publication',
            'id' => 997,
            'numeroProcesso' => '00012345672025826010',
            'tipoComunicacao' => 'Intimação',
            'tipoDocumento' => 'Decisão',
            'dataDisponibilizacao' => '2026-05-15',
            'texto' => 'CONCEDO o prazo de 15 dias para apresentar contestação.',
            'userId' => 'felipe-id',
        ];
        $queue = $this->createMock(QueueService::class);
        $queue->method('claim')->willReturn([
            ['id' => 'pub-dedup-1', 'queue_name' => 'djen', 'payload' => $payload, 'correlation_id' => null, 'retry_count' => 0],
        ]);
        $queue->expects($this->once())->method('markDone')->with('pub-dedup-1');
        $queue->expects($this->never())->method('markFailed');

        $adapter = $this->createMock(PublicationSourceAdapterContract::class);

        $existing = new Prazo();
        $existing->setId('prazo-existing-001');
        $existing->set('status', Prazo::STATUS_PENDENTE);

        $creator = $this->makeCreatorReturning($existing);

        $worker = new DjenWorkerService($queue, $adapter, $this->makeParser(), $creator);
        $this->assertTrue($worker->processOne());

        // Mesmo dedup, parsed log dispara (handler loga ANTES de chamar creator).
        $events = \array_column(TogareLogger::getRecorded(), 'event');
        $this->assertContains('djen.publication.parsed', $events);
    }

    /**
     * Story 4a.3 AC7.1 — Throwable do creator (ex.: PDO disconnected, hook
     * BadRequest, etc.) sobe para o catch hierárquico do processOne →
     * markFailed delay padrão (vai para retry; não dead_letter).
     * Preserva invariante Spike 1b.S3.
     */
    public function testDispatchPublicationCreatorThrowableEhCapturadoNoCatchHierarquico(): void
    {
        $payload = [
            'type' => 'publication',
            'id' => 996,
            'numeroProcesso' => '00012345672025826010',
            'tipoComunicacao' => 'Intimação',
            'tipoDocumento' => 'Decisão',
            'dataDisponibilizacao' => '2026-05-15',
            'texto' => 'CONCEDO o prazo de 15 dias para apresentar contestação.',
            'userId' => 'felipe-id',
        ];
        $queue = $this->createMock(QueueService::class);
        $queue->method('claim')->willReturn([
            ['id' => 'pub-creator-fail', 'queue_name' => 'djen', 'payload' => $payload, 'correlation_id' => null, 'retry_count' => 0],
        ]);
        $queue->expects($this->never())->method('markDone');
        $queue->expects($this->once())
            ->method('markFailed')
            ->with(
                'pub-creator-fail',
                $this->stringContains('PDO disconnected'),
                false,
                null, // delay padrão — backoff exponencial
            );

        $adapter = $this->createMock(PublicationSourceAdapterContract::class);

        $creator = $this->makeCreatorThrowing(new \RuntimeException('PDO disconnected'));

        $worker = new DjenWorkerService($queue, $adapter, $this->makeParser(), $creator);
        $worker->processOne();

        $events = \array_column(TogareLogger::getRecorded(), 'event');
        // Parser foi chamado e logou parsed ANTES da exception do creator.
        $this->assertContains('djen.publication.parsed', $events);
        // Try/catch hierárquico do worker captura.
        $this->assertContains('djen.worker.unexpected_error', $events);
    }

    /**
     * Story 4b.1b T4.7 — handler chama creator que retorna kind=publicacao_ambigua;
     * worker descarta retorno (backward-compat preservada) e markDone() é chamado
     * normalmente. Sem distinção de path no worker — invariante Decisão #3 mãe.
     */
    public function testDispatchPublicationAmbiguidadeEndToEndWorkerDescartaRetornoEMarcaDone(): void
    {
        $payload = [
            'type' => 'publication',
            'id' => 999,
            'numeroProcesso' => '',
            'tipoComunicacao' => 'Intimação',
            'tipoDocumento' => 'Decisão',
            'dataDisponibilizacao' => '2026-05-15',
            'texto' => 'CONCEDO o prazo de 15 dias para apresentar contestação.',
            'userId' => 'felipe-id',
            'destinatarios' => [['nome' => 'João Silva'], ['nome' => 'Maria Souza']],
        ];
        $queue = $this->createMock(QueueService::class);
        $queue->method('claim')->willReturn([
            ['id' => 'pub-ambigua-1', 'queue_name' => 'djen', 'payload' => $payload, 'correlation_id' => null, 'retry_count' => 0],
        ]);
        $queue->expects($this->once())->method('markDone')->with('pub-ambigua-1');
        $queue->expects($this->never())->method('markFailed');

        $adapter = $this->createMock(PublicationSourceAdapterContract::class);

        $pubAmbigua = new \Espo\Modules\TogareCore\Entities\PublicacaoAmbigua();
        $pubAmbigua->setId('pub-ambigua-uuid-001');

        $creator = $this->createMock(PrazoCreatorService::class);
        $creator->expects($this->once())
            ->method('create')
            ->willReturn(CreationResult::publicacaoAmbigua($pubAmbigua));

        $worker = new DjenWorkerService($queue, $adapter, $this->makeParser(), $creator);
        $this->assertTrue($worker->processOne());

        $events = \array_column(TogareLogger::getRecorded(), 'event');
        $this->assertContains('djen.publication.parsed', $events);
    }

    // ============================================================
    // Story 4b.4 / ADR 0009 — tick check + failure_category
    // ============================================================

    /**
     * Helper: cria adapter fake com `getCircuitBreakerState` controlado +
     * `clearCircuitBreakerOpenFlag` rastreado. Implementa o contract via
     * delegação para `createMock(PublicationSourceAdapterContract::class)`.
     *
     * @param array{failures:list<int>, open_until:int, opened_at:int} $cbState
     * @return object{adapter: PublicationSourceAdapterContract, clearedCount: \stdClass}
     */
    private function makeAdapterWithCbState(array $cbState): object
    {
        $tracker = new \stdClass();
        $tracker->clearedCount = 0;

        // Anonymous class implementa contract + adiciona métodos extras.
        $adapter = new class ($cbState, $tracker) implements PublicationSourceAdapterContract {
            public function __construct(
                private array $cbState,
                private \stdClass $tracker,
            ) {
            }

            public function fetchPublicacoes(string $oab, string $uf, \DateTimeImmutable $dataInicio, \DateTimeImmutable $dataFim): \Generator
            {
                if (false) {
                    yield;
                }
            }

            public function getCircuitBreakerState(): array
            {
                return $this->cbState;
            }

            public function clearCircuitBreakerOpenFlag(): void
            {
                $this->tracker->clearedCount++;
                $this->cbState['open_until'] = 0;
                $this->cbState['opened_at'] = 0;
            }
        };

        return (object) ['adapter' => $adapter, 'tracker' => $tracker];
    }

    /**
     * AC5: tick check detecta open→closed E dispara reschedule.
     */
    public function testProcessOneDetectaCbCloseEDispararReschedule(): void
    {
        $now = 1717000000;
        $clock = static fn (): int => $now;

        // CB fechou há 30s — open_until = now-30, opened_at = now-1830 (30min atrás).
        $bundle = $this->makeAdapterWithCbState([
            'failures' => [$now - 1830, $now - 1820, $now - 1810, $now - 1800, $now - 1790],
            'open_until' => $now - 30,
            'opened_at' => $now - 1830,
        ]);

        $queue = $this->createMock(QueueService::class);
        // claim depois do tick check — fila vazia (foco do teste é só o tick).
        $queue->method('claim')->willReturn([]);
        // rescheduleAfter é chamado 1 vez com queue=djen + cat=adapter_unavailable.
        $queue->expects($this->once())
            ->method('rescheduleAfterCircuitBreakerClose')
            ->with('djen', DjenWorkerService::FAILURE_CATEGORY_ADAPTER_UNAVAILABLE)
            ->willReturn(3);

        $worker = new DjenWorkerService(
            $queue,
            $bundle->adapter,
            $this->makeParser(),
            $this->makeCreator(),
            $clock,
        );
        $worker->processOne();

        $this->assertSame(1, $bundle->tracker->clearedCount, 'clearCircuitBreakerOpenFlag deve ter sido chamado 1×');

        $events = \array_column(TogareLogger::getRecorded(), 'event');
        $this->assertContains('djen.queue.rescheduled_after_cb_close', $events);
    }

    /**
     * AC5: tick check NÃO faz reschedule quando CB ainda está aberto.
     */
    public function testProcessOneTickCheckEhNoOpQuandoCbAindaAberto(): void
    {
        $now = 1717000000;
        $clock = static fn (): int => $now;

        $bundle = $this->makeAdapterWithCbState([
            'failures' => [],
            'open_until' => $now + 600, // CB abre por mais 10min
            'opened_at' => $now - 5,
        ]);

        $queue = $this->createMock(QueueService::class);
        $queue->method('claim')->willReturn([]);
        $queue->expects($this->never())->method('rescheduleAfterCircuitBreakerClose');

        $worker = new DjenWorkerService(
            $queue,
            $bundle->adapter,
            $this->makeParser(),
            $this->makeCreator(),
            $clock,
        );
        $worker->processOne();

        $this->assertSame(0, $bundle->tracker->clearedCount, 'clearCircuitBreakerOpenFlag NÃO deve ser chamado');
    }

    /**
     * AC5: tick check NÃO faz reschedule quando CB nunca abriu (state vazio).
     */
    public function testProcessOneTickCheckEhNoOpQuandoCbNuncaAbriu(): void
    {
        $bundle = $this->makeAdapterWithCbState([
            'failures' => [],
            'open_until' => 0,
            'opened_at' => 0,
        ]);

        $queue = $this->createMock(QueueService::class);
        $queue->method('claim')->willReturn([]);
        $queue->expects($this->never())->method('rescheduleAfterCircuitBreakerClose');

        $worker = new DjenWorkerService(
            $queue,
            $bundle->adapter,
            $this->makeParser(),
            $this->makeCreator(),
        );
        $worker->processOne();

        $this->assertSame(0, $bundle->tracker->clearedCount);
    }

    /**
     * AC6: catch DjenAdapterUnavailableException passa categoria 'adapter_unavailable'.
     */
    public function testCatchAdapterUnavailablePassaCategoriaAdapterUnavailable(): void
    {
        $payload = ['type' => 'sync_window', 'userId' => 'u1', 'oab' => '462034', 'uf' => 'SP',
                    'dataInicio' => '2026-05-09', 'dataFim' => '2026-05-09'];

        $queue = $this->createMock(QueueService::class);
        $queue->method('claim')->willReturn([
            ['id' => 'item-1', 'queue_name' => 'djen', 'payload' => $payload, 'correlation_id' => null, 'retry_count' => 0],
        ]);
        $queue->expects($this->once())
            ->method('markFailed')
            ->with(
                'item-1',
                $this->stringContains('djen sync window failed'),
                false,
                3600,
                DjenWorkerService::FAILURE_CATEGORY_ADAPTER_UNAVAILABLE,
            );

        $adapter = $this->createMock(PublicationSourceAdapterContract::class);
        $adapter->method('fetchPublicacoes')
            ->willThrowException(new DjenAdapterUnavailableException('502 Bad Gateway'));

        $worker = new DjenWorkerService($queue, $adapter, $this->makeParser(), $this->makeCreator());
        $worker->processOne();
    }

    /**
     * AC6: catch Forbidden (license_expired) passa categoria 'license_expired'.
     */
    public function testCatchForbiddenLicenseExpiredPassaCategoriaLicenseExpired(): void
    {
        $payload = ['type' => 'sync_window', 'userId' => 'u1', 'oab' => '462034', 'uf' => 'SP',
                    'dataInicio' => '2026-05-09', 'dataFim' => '2026-05-09'];

        $queue = $this->createMock(QueueService::class);
        $queue->method('claim')->willReturn([
            ['id' => 'item-2', 'queue_name' => 'djen', 'payload' => $payload, 'correlation_id' => null, 'retry_count' => 0],
        ]);
        $queue->expects($this->once())
            ->method('markFailed')
            ->with(
                'item-2',
                'license_expired',
                false,
                3600,
                DjenWorkerService::FAILURE_CATEGORY_LICENSE_EXPIRED,
            );

        $adapter = $this->createMock(PublicationSourceAdapterContract::class);
        $adapter->method('fetchPublicacoes')
            ->willThrowException(new Forbidden('Modulo togare-djen está em read-only — license expired'));

        $worker = new DjenWorkerService($queue, $adapter, $this->makeParser(), $this->makeCreator());
        $worker->processOne();
    }

    /**
     * AC6: catch Throwable genérico passa categoria NULL e delay NULL (default backoff).
     */
    public function testCatchThrowableGenericoPassaCategoriaNullEDelayNull(): void
    {
        $payload = ['type' => 'sync_window', 'userId' => 'u1', 'oab' => '462034', 'uf' => 'SP',
                    'dataInicio' => '2026-05-09', 'dataFim' => '2026-05-09'];

        $queue = $this->createMock(QueueService::class);
        $queue->method('claim')->willReturn([
            ['id' => 'item-3', 'queue_name' => 'djen', 'payload' => $payload, 'correlation_id' => null, 'retry_count' => 0],
        ]);
        $queue->expects($this->once())
            ->method('markFailed')
            ->with('item-3', $this->anything(), false, null, null);

        $adapter = $this->createMock(PublicationSourceAdapterContract::class);
        $adapter->method('fetchPublicacoes')
            ->willThrowException(new \RuntimeException('erro inesperado totalmente desconhecido'));

        $worker = new DjenWorkerService($queue, $adapter, $this->makeParser(), $this->makeCreator());
        $worker->processOne();
    }
}
