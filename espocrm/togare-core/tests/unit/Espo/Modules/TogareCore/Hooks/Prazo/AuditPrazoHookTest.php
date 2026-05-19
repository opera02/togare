<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\Prazo;

use Espo\Modules\TogareCore\Entities\Prazo;
use Espo\Modules\TogareCore\Hooks\Prazo\AuditPrazoHook;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;
use tests\unit\Espo\Modules\TogareCore\Stubs\AuditLogContractStub;

/**
 * Cobre auditoria implícita (FR37 + NFR10) para Prazo (Story 4a.3 + 4a.3.1).
 *
 * Story 4a.3.1 reescreveu o derivedEventMap para 7 eventos (alinhados ADR-03 v1.1 §1):
 *   PENDENTE              → audit.prazo.bound          (substitui audit.prazo.confirmed)
 *   DESCARTADO            → audit.prazo.descartado     (preservado)
 *   PROTOCOLADO           → audit.prazo.protocolado    (substitui audit.prazo.cumprido)
 *   REAGENDADO            → audit.prazo.reagendado     (NOVO; context inclui motivoReagendamento)
 *   AGUARDANDO_CLIENTE    → audit.prazo.aguardando_cliente (NOVO)
 *   ACOMPANHAMENTO        → audit.prazo.acompanhamento (NOVO)
 *   protocolado→pendente  → audit.prazo.revertido      (transição pura, NÃO mapping)
 *
 * SENSITIVE_FIELDS expandido +3: motivoReagendamento, prioridade, tipoPrazo.
 */
final class AuditPrazoHookTest extends TestCase
{
    public function testNovoPrazoEmiteEventoCreated(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuditPrazoHook($stub);

        $prazo = $this->makeNewPrazo();
        // sem setId() → isNew()=true.

        $hook->afterSave($prazo, SaveOptions::create());

        self::assertCount(1, $stub->calls);
        $call = $stub->calls[0];
        self::assertSame('audit.prazo.created', $call['event']);
        self::assertSame('Prazo', $call['entityType']);
        self::assertSame(Prazo::STATUS_PENDENTE, $call['context']['status']);
        self::assertSame(Prazo::SOURCE_DJEN, $call['context']['source']);
        self::assertSame(12345, $call['context']['sourcePubId']);
        self::assertSame('proc-001', $call['context']['processoId']);
        self::assertSame('contestacao', $call['context']['atoCodigo']);
        self::assertSame('2026-06-08', $call['context']['dataFatal']);
        self::assertSame(Prazo::CONFIDENCE_HIGH, $call['context']['confidence']);
        self::assertSame('1.0.0', $call['context']['parserRegraVersao']);
        // Story 4a.3.1: novos campos em buildCreatedContext.
        self::assertSame(Prazo::PRIORIDADE_NORMAL, $call['context']['prioridade']);
        self::assertArrayHasKey('tipoPrazo', $call['context']);
        self::assertArrayHasKey('clienteId', $call['context']);
        self::assertArrayHasKey('parteContrariaId', $call['context']);
        // Story 4a.5.1: dataCumprimento registrada no context (null aceito —
        // makeNewPrazo não seta; DefaultDataCumprimentoHook=15 setaria em runtime).
        self::assertArrayHasKey('dataCumprimento', $call['context']);
    }

    public function testEdicaoSemMudancaSensivelNaoEmiteEvento(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuditPrazoHook($stub);

        $prazo = new Prazo();
        $prazo->setFetched('fonteExcerpt', 'trecho velho');
        $prazo->setFetched('referenciaLegal', 'CPC art. 335');
        $prazo->set([
            'fonteExcerpt' => 'trecho novo', // não está em SENSITIVE_FIELDS
            'referenciaLegal' => 'CPC art. 335', // não mudou (e nem está em SENSITIVE)
        ]);
        $prazo->setId('00000000000000prz');

        $hook->afterSave($prazo, SaveOptions::create());

        self::assertCount(0, $stub->calls);
    }

    public function testEdicaoEmiteModifiedComChangedFields(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuditPrazoHook($stub);

        $prazo = new Prazo();
        $prazo->setFetched('processoId', 'proc-001');
        $prazo->setFetched('dataFatal', '2026-06-08');
        $prazo->setFetched('atoCodigo', 'manifestacao_generica');
        $prazo->setFetched('parserRegraVersao', '1.0.0');

        $prazo->set([
            'processoId' => 'proc-001',         // não mudou
            'dataFatal' => '2026-06-09',        // mudou (correção retroativa)
            'atoCodigo' => 'contestacao',        // mudou (re-classificação manual)
            'parserRegraVersao' => '1.0.0',      // não mudou
        ]);
        $prazo->setId('00000000000000prz');

        $hook->afterSave($prazo, SaveOptions::create());

        self::assertCount(1, $stub->calls);
        self::assertSame('audit.prazo.modified', $stub->calls[0]['event']);
        $changed = $stub->calls[0]['context']['changedFields'];
        self::assertContains('dataFatal', $changed);
        self::assertContains('atoCodigo', $changed);
        self::assertNotContains('processoId', $changed);
        self::assertNotContains('parserRegraVersao', $changed);
        self::assertSame('proc-001', $stub->calls[0]['context']['processoId']);
    }

    /** Story 4a.3.1 — substitui testStatusConfirmadoEmiteAuditPrazoConfirmedAlemDoModified.
     *  Transição rascunho → pendente (vinculação) emite audit.prazo.bound. */
    public function testStatusPendenteEmiteAuditPrazoBoundAlemDoModified(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuditPrazoHook($stub);

        $prazo = new Prazo();
        $prazo->setFetched('status', Prazo::STATUS_RASCUNHO);
        $prazo->setFetched('processoId', null);
        $prazo->setFetched('dataFatal', '2026-06-08');
        $prazo->setFetched('atoCodigo', 'contestacao');

        $prazo->set([
            'status' => Prazo::STATUS_PENDENTE,
            'processoId' => 'proc-001',
            'dataFatal' => '2026-06-08',
            'atoCodigo' => 'contestacao',
        ]);
        $prazo->setId('00000000000000prz');

        $hook->afterSave($prazo, SaveOptions::create());

        self::assertCount(2, $stub->calls);
        self::assertSame('audit.prazo.modified', $stub->calls[0]['event']);
        self::assertSame('audit.prazo.bound', $stub->calls[1]['event']);
        self::assertSame(Prazo::STATUS_RASCUNHO, $stub->calls[1]['context']['previousStatus']);
        self::assertSame('proc-001', $stub->calls[1]['context']['processoId']);
        self::assertSame('2026-06-08', $stub->calls[1]['context']['dataFatal']);
    }

    public function testStatusDescartadoEmiteAuditPrazoDescartado(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuditPrazoHook($stub);

        $prazo = new Prazo();
        $prazo->setFetched('status', Prazo::STATUS_RASCUNHO);
        $prazo->setFetched('processoId', null);
        $prazo->setFetched('dataFatal', '2026-06-08');
        $prazo->setFetched('atoCodigo', 'manifestacao_generica');

        $prazo->set([
            'status' => Prazo::STATUS_DESCARTADO,
            'processoId' => null,
            'dataFatal' => '2026-06-08',
            'atoCodigo' => 'manifestacao_generica',
        ]);
        $prazo->setId('00000000000000prz');

        $hook->afterSave($prazo, SaveOptions::create());

        self::assertCount(2, $stub->calls);
        self::assertSame('audit.prazo.modified', $stub->calls[0]['event']);
        self::assertSame('audit.prazo.descartado', $stub->calls[1]['event']);
        self::assertSame(Prazo::STATUS_RASCUNHO, $stub->calls[1]['context']['previousStatus']);
    }

    /** Story 4a.3.1 — AC5: protocolado emite audit.prazo.protocolado (substitui cumprido). */
    public function testStatusProtocoladoEmiteAuditPrazoProtocolado(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuditPrazoHook($stub);

        $prazo = new Prazo();
        $prazo->setFetched('status', Prazo::STATUS_PENDENTE);
        $prazo->setFetched('processoId', 'proc-001');
        $prazo->setFetched('dataFatal', '2026-06-08');
        $prazo->setFetched('atoCodigo', 'contestacao');

        $prazo->set([
            'status' => Prazo::STATUS_PROTOCOLADO,
            'processoId' => 'proc-001',
            'dataFatal' => '2026-06-08',
            'atoCodigo' => 'contestacao',
        ]);
        $prazo->setId('00000000000000prz');

        $hook->afterSave($prazo, SaveOptions::create());

        self::assertCount(2, $stub->calls);
        self::assertSame('audit.prazo.protocolado', $stub->calls[1]['event']);
    }

    /** Story 4a.3.1 — AC5: reagendado inclui motivoReagendamento no context. */
    public function testStatusReagendadoEmiteAuditComMotivoReagendamentoNoContext(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuditPrazoHook($stub);

        $prazo = new Prazo();
        $prazo->setFetched('status', Prazo::STATUS_PENDENTE);
        $prazo->setFetched('processoId', 'proc-001');
        $prazo->setFetched('dataFatal', '2026-06-08');
        $prazo->setFetched('atoCodigo', 'contestacao');
        $prazo->setFetched('motivoReagendamento', null);

        $prazo->set([
            'status' => Prazo::STATUS_REAGENDADO,
            'processoId' => 'proc-001',
            'dataFatal' => '2026-06-08',
            'atoCodigo' => 'contestacao',
            'motivoReagendamento' => 'Cliente solicitou prazo extra para revisar provas.',
        ]);
        $prazo->setId('00000000000000prz');

        $hook->afterSave($prazo, SaveOptions::create());

        self::assertCount(2, $stub->calls);
        self::assertSame('audit.prazo.reagendado', $stub->calls[1]['event']);
        self::assertSame(
            'Cliente solicitou prazo extra para revisar provas.',
            $stub->calls[1]['context']['motivoReagendamento'],
        );
    }

    /** Story 4a.3.1 — AC5: aguardando_cliente emite evento dedicado. */
    public function testStatusAguardandoClienteEmiteAuditDedicado(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuditPrazoHook($stub);

        $prazo = new Prazo();
        $prazo->setFetched('status', Prazo::STATUS_PENDENTE);
        $prazo->setFetched('processoId', 'proc-001');
        $prazo->setFetched('dataFatal', '2026-06-08');
        $prazo->setFetched('atoCodigo', 'contestacao');

        $prazo->set([
            'status' => Prazo::STATUS_AGUARDANDO_CLIENTE,
            'processoId' => 'proc-001',
            'dataFatal' => '2026-06-08',
            'atoCodigo' => 'contestacao',
        ]);
        $prazo->setId('00000000000000prz');

        $hook->afterSave($prazo, SaveOptions::create());

        self::assertCount(2, $stub->calls);
        self::assertSame('audit.prazo.aguardando_cliente', $stub->calls[1]['event']);
    }

    /** Story 4a.3.1 — AC5: transição protocolado→pendente vira evento puro audit.prazo.revertido. */
    public function testTransicaoProtocoladoParaPendenteEmiteAuditPrazoRevertido(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuditPrazoHook($stub);

        $prazo = new Prazo();
        $prazo->setFetched('status', Prazo::STATUS_PROTOCOLADO);
        $prazo->setFetched('processoId', 'proc-001');
        $prazo->setFetched('dataFatal', '2026-06-08');

        $prazo->set([
            'status' => Prazo::STATUS_PENDENTE,
            'processoId' => 'proc-001',
            'dataFatal' => '2026-06-08',
        ]);
        $prazo->setId('00000000000000prz');

        $hook->afterSave($prazo, SaveOptions::create());

        self::assertCount(2, $stub->calls);
        self::assertSame('audit.prazo.modified', $stub->calls[0]['event']);
        self::assertSame('audit.prazo.revertido', $stub->calls[1]['event']);
        self::assertSame(Prazo::STATUS_PROTOCOLADO, $stub->calls[1]['context']['previousStatus']);
    }

    /** Story 4a.3.1 — aguardando_correcao não tem evento dedicado, só audit.prazo.modified. */
    public function testStatusAguardandoCorrecaoSoEmiteModified(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuditPrazoHook($stub);

        $prazo = new Prazo();
        $prazo->setFetched('status', Prazo::STATUS_PENDENTE);
        $prazo->setFetched('processoId', 'proc-001');
        $prazo->setFetched('dataFatal', '2026-06-08');

        $prazo->set([
            'status' => Prazo::STATUS_AGUARDANDO_CORRECAO,
            'processoId' => 'proc-001',
            'dataFatal' => '2026-06-08',
        ]);
        $prazo->setId('00000000000000prz');

        $hook->afterSave($prazo, SaveOptions::create());

        self::assertCount(1, $stub->calls);
        self::assertSame('audit.prazo.modified', $stub->calls[0]['event']);
    }

    /** Story 4a.5.1 — dataCumprimento incluído em buildCreatedContext (default aplicado por DefaultDataCumprimentoHook). */
    public function testCreateRegistraDataCumprimentoNoContext(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuditPrazoHook($stub);

        $prazo = $this->makeNewPrazo();
        // Simula DefaultDataCumprimentoHook=15 já tendo setado dataCumprimento
        // (BeforeSave roda antes do AfterSave deste hook).
        $prazo->set('dataCumprimento', '2026-06-04');

        $hook->afterSave($prazo, SaveOptions::create());

        self::assertCount(1, $stub->calls);
        self::assertSame('audit.prazo.created', $stub->calls[0]['event']);
        self::assertArrayHasKey('dataCumprimento', $stub->calls[0]['context']);
        self::assertSame('2026-06-04', $stub->calls[0]['context']['dataCumprimento']);
    }

    /** Story 4a.5.1 — mudança em dataCumprimento dispara audit.prazo.modified (SENSITIVE_FIELDS +1). */
    public function testMudancaEmDataCumprimentoDisparaAuditModified(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuditPrazoHook($stub);

        $prazo = new Prazo();
        $prazo->setFetched('dataCumprimento', '2026-05-21');
        $prazo->setFetched('processoId', 'proc-001');

        $prazo->set([
            'dataCumprimento' => '2026-05-23', // mudou — advogado replanejou
            'processoId' => 'proc-001',
        ]);
        $prazo->setId('00000000000000prz');

        $hook->afterSave($prazo, SaveOptions::create());

        self::assertCount(1, $stub->calls);
        self::assertSame('audit.prazo.modified', $stub->calls[0]['event']);
        self::assertContains('dataCumprimento', $stub->calls[0]['context']['changedFields']);
    }

    /** Story 4a.3.1 — mudança em prioridade dispara audit.prazo.modified (SENSITIVE_FIELDS +3). */
    public function testMudancaEmPrioridadeDisparaAuditModified(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuditPrazoHook($stub);

        $prazo = new Prazo();
        $prazo->setFetched('prioridade', Prazo::PRIORIDADE_NORMAL);
        $prazo->setFetched('processoId', 'proc-001');

        $prazo->set([
            'prioridade' => Prazo::PRIORIDADE_URGENTE,
            'processoId' => 'proc-001',
        ]);
        $prazo->setId('00000000000000prz');

        $hook->afterSave($prazo, SaveOptions::create());

        self::assertCount(1, $stub->calls);
        self::assertSame('audit.prazo.modified', $stub->calls[0]['event']);
        self::assertContains('prioridade', $stub->calls[0]['context']['changedFields']);
    }

    public function testTryCatchThrowableNaoBloqueia(): void
    {
        $stub = new AuditLogContractStub();
        $stub->shouldThrow = true;
        $hook = new AuditPrazoHook($stub);

        $prazo = $this->makeNewPrazo();

        // Não deve relançar — try/catch interno + log fallback.
        $hook->afterSave($prazo, SaveOptions::create());

        self::assertSame(1, $stub->throwCount);
    }

    public function testEntidadeNaoPrazoIgnora(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuditPrazoHook($stub);

        $other = new \Espo\Core\ORM\Entity();
        $other->set('status', Prazo::STATUS_PENDENTE);

        $hook->afterSave($other, SaveOptions::create());

        self::assertCount(0, $stub->calls);
    }

    private function makeNewPrazo(): Prazo
    {
        $prazo = new Prazo();
        $prazo->set([
            'dataDisponibilizacao' => '2026-05-15',
            'dataInicioPrazo' => '2026-05-18',
            'dataFatal' => '2026-06-08',
            'prazoDias' => 15,
            'contagem' => Prazo::CONTAGEM_UTEIS,
            'atoCodigo' => 'contestacao',
            'referenciaLegal' => 'CPC art. 335',
            'confidence' => Prazo::CONFIDENCE_HIGH,
            'parserRegraVersao' => '1.0.0',
            'source' => Prazo::SOURCE_DJEN,
            'sourcePubId' => 12345,
            'numeroProcessoOriginal' => '0001234-56.2024.8.26.0001',
            'status' => Prazo::STATUS_PENDENTE,
            'prioridade' => Prazo::PRIORIDADE_NORMAL,
            'processoId' => 'proc-001',
            'assignedUserId' => 'user-001',
        ]);

        return $prazo;
    }
}
