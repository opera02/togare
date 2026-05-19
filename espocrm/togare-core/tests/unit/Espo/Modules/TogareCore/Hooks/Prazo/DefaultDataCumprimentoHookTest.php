<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\Prazo;

use Espo\Modules\TogareCore\Entities\Prazo;
use Espo\Modules\TogareCore\Hooks\Prazo\DefaultDataCumprimentoHook;
use Espo\Modules\TogareCore\Services\Calendar\BrazilianBusinessCalendar;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

/**
 * Cobre o default `dataCumprimento = dataFatal − 2 dias úteis` em criação
 * de Prazo (Story 4a.5.1, AC3 + Decisão #2).
 *
 * Calendar é instanciado real (sem mock) — service puro sem deps; testa o
 * comportamento end-to-end com feriados nacionais BR reais.
 *
 * Convenção: Prazo é considerado "novo" enquanto não tiver setId() chamado
 * (isNew() retorna true). Para simular edit, basta setId() qualquer.
 */
final class DefaultDataCumprimentoHookTest extends TestCase
{
    private DefaultDataCumprimentoHook $hook;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hook = new DefaultDataCumprimentoHook(new BrazilianBusinessCalendar());
    }

    public function testCriacaoComDataFatalSegSetaDataCumprimentoQuiAnterior(): void
    {
        // dataFatal = seg 25/05/2026.
        // -1 útil = sex 22/05; -2 úteis = qui 21/05.
        $prazo = $this->makeNewPrazo('2026-05-25');

        $this->hook->beforeSave($prazo, SaveOptions::create());

        self::assertSame('2026-05-21', $prazo->get('dataCumprimento'));
    }

    public function testCriacaoComDataFatalTerSetaSexAnterior(): void
    {
        // dataFatal = ter 26/05/2026.
        // -1 útil = seg 25/05; -2 úteis = sex 22/05.
        $prazo = $this->makeNewPrazo('2026-05-26');

        $this->hook->beforeSave($prazo, SaveOptions::create());

        self::assertSame('2026-05-22', $prazo->get('dataCumprimento'));
    }

    public function testCriacaoAtravessaCorpusChristi2026(): void
    {
        // Corpus Christi 2026 = qui 04/06.
        // dataFatal = ter 09/06/2026 (típico cálculo do parser DJEN para 15
        // úteis a partir de seg 18/05 — ver BrazilianBusinessCalendarTest).
        // -1 útil = seg 08/06; -2 úteis = sex 05/06 (qui 04/06 é Corpus Christi pulado).
        $prazo = $this->makeNewPrazo('2026-06-09');

        $this->hook->beforeSave($prazo, SaveOptions::create());

        self::assertSame('2026-06-05', $prazo->get('dataCumprimento'));
    }

    public function testCriacaoAtravessaCarnaval2026(): void
    {
        // Carnaval 2026: seg 16/02 + ter 17/02. Quarta de cinzas = 18/02.
        // dataFatal = qui 19/02/2026.
        // -1 útil = qua 18/02; -2 úteis = sex 13/02 (carnaval seg+ter pulados).
        $prazo = $this->makeNewPrazo('2026-02-19');

        $this->hook->beforeSave($prazo, SaveOptions::create());

        self::assertSame('2026-02-13', $prazo->get('dataCumprimento'));
    }

    public function testCriacaoComDataCumprimentoJaSetadoNaoSobrescreve(): void
    {
        // Decisão #2 — respeita override manual.
        $prazo = $this->makeNewPrazo('2026-05-25');
        $prazo->set('dataCumprimento', '2026-05-15'); // user pré-setou via form.

        $this->hook->beforeSave($prazo, SaveOptions::create());

        self::assertSame('2026-05-15', $prazo->get('dataCumprimento'), 'Hook NÃO sobrescreve override manual');
    }

    public function testEditNaoSobrescreveDataCumprimento(): void
    {
        // Decisão #2 — só dispara em isNew. Edits são no-op.
        $prazo = $this->makeNewPrazo('2026-05-25');
        $prazo->setId('00000000000000prz'); // simula entity já persistida → isNew() = false.
        // dataCumprimento começa null no edit (sem persisted value carregado em test).

        $this->hook->beforeSave($prazo, SaveOptions::create());

        self::assertNull($prazo->get('dataCumprimento'), 'Edit NÃO deve setar default — só criação');
    }

    public function testCriacaoComDataFatalVazioOuNullEhNoOp(): void
    {
        // ValidatePrazoFieldsHook=10 já bloqueia esse caso; defensivo.
        $prazo = new Prazo();
        // sem dataFatal.

        $this->hook->beforeSave($prazo, SaveOptions::create());

        self::assertNull($prazo->get('dataCumprimento'));
    }

    public function testEntidadeNaoPrazoEhNoOp(): void
    {
        $other = new \Espo\Core\ORM\Entity();
        $other->set('dataFatal', '2026-05-25');

        $this->hook->beforeSave($other, SaveOptions::create());

        self::assertNull($other->get('dataCumprimento'));
    }

    public function testDataFatalInvalidaNaoBloqueiaSave(): void
    {
        // Date string inválida (parser DateTimeImmutable lança exception).
        // Hook deve capturar, logar warning, NÃO relançar — não bloqueia save.
        $prazo = $this->makeNewPrazo('not-a-date');

        // Sem expectException — hook é defensivo via try/catch \Throwable.
        $this->hook->beforeSave($prazo, SaveOptions::create());

        self::assertNull($prazo->get('dataCumprimento'), 'Date inválida cai no catch; dataCumprimento permanece null');
    }

    public function testHookOrderEh15(): void
    {
        // Vinculante para Decisão #2 — entre Validate/PrioridadeWeight (10) e
        // AutoLink (20) e Audit (50).
        self::assertSame(15, DefaultDataCumprimentoHook::$order);
    }

    private function makeNewPrazo(string $dataFatal): Prazo
    {
        $prazo = new Prazo();
        $prazo->set([
            'dataDisponibilizacao' => '2026-05-04',
            'dataInicioPrazo' => '2026-05-05',
            'dataFatal' => $dataFatal,
            'prazoDias' => 15,
            'contagem' => Prazo::CONTAGEM_UTEIS,
            'atoCodigo' => 'contestacao',
            'referenciaLegal' => 'CPC art. 335',
            'confidence' => Prazo::CONFIDENCE_HIGH,
            'parserRegraVersao' => '1.0.0',
            'source' => Prazo::SOURCE_DJEN,
            'status' => Prazo::STATUS_RASCUNHO,
            'numeroProcessoOriginal' => '0001234-56.2024.8.26.0001',
            'prioridade' => Prazo::PRIORIDADE_NORMAL,
        ]);
        // sem setId() → isNew() = true.
        return $prazo;
    }
}
