<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\Prazo;

use Espo\Modules\TogareCore\Entities\Prazo;
use Espo\Modules\TogareCore\Hooks\Prazo\PrioridadeWeightHook;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

/**
 * Cobre o mapeamento `prioridade` (string enum) → `prioridade_weight` (int)
 * no PrioridadeWeightHook (Story 4a.5, AC4 + Decisão #2 Plano C).
 *
 * Mapping canônico:
 *   urgente → 4
 *   alta    → 3
 *   normal  → 2  (default; também aplicado a null/empty/inválido)
 *   baixa   → 1
 */
final class PrioridadeWeightHookTest extends TestCase
{
    public function testUrgenteMapeiaPara4(): void
    {
        $prazo = $this->makePrazoComPrioridade(Prazo::PRIORIDADE_URGENTE);

        (new PrioridadeWeightHook())->beforeSave($prazo, SaveOptions::create());

        self::assertSame(4, $prazo->get('prioridadeWeight'));
    }

    public function testAltaMapeiaPara3(): void
    {
        $prazo = $this->makePrazoComPrioridade(Prazo::PRIORIDADE_ALTA);

        (new PrioridadeWeightHook())->beforeSave($prazo, SaveOptions::create());

        self::assertSame(3, $prazo->get('prioridadeWeight'));
    }

    public function testNormalMapeiaPara2(): void
    {
        $prazo = $this->makePrazoComPrioridade(Prazo::PRIORIDADE_NORMAL);

        (new PrioridadeWeightHook())->beforeSave($prazo, SaveOptions::create());

        self::assertSame(2, $prazo->get('prioridadeWeight'));
    }

    public function testBaixaMapeiaPara1(): void
    {
        $prazo = $this->makePrazoComPrioridade(Prazo::PRIORIDADE_BAIXA);

        (new PrioridadeWeightHook())->beforeSave($prazo, SaveOptions::create());

        self::assertSame(1, $prazo->get('prioridadeWeight'));
    }

    public function testNullMapeiaParaDefault2(): void
    {
        $prazo = new Prazo();
        $prazo->set('prioridade', null);

        (new PrioridadeWeightHook())->beforeSave($prazo, SaveOptions::create());

        self::assertSame(2, $prazo->get('prioridadeWeight'));
    }

    public function testEmptyStringMapeiaParaDefault2(): void
    {
        $prazo = new Prazo();
        $prazo->set('prioridade', '');

        (new PrioridadeWeightHook())->beforeSave($prazo, SaveOptions::create());

        self::assertSame(2, $prazo->get('prioridadeWeight'));
    }

    public function testValorInvalidoMapeiaParaDefault2(): void
    {
        $prazo = $this->makePrazoComPrioridade('foo_bar_invalida');

        (new PrioridadeWeightHook())->beforeSave($prazo, SaveOptions::create());

        self::assertSame(2, $prazo->get('prioridadeWeight'));
    }

    public function testNaoStringMapeiaParaDefault2(): void
    {
        $prazo = new Prazo();
        $prazo->set('prioridade', 99);

        (new PrioridadeWeightHook())->beforeSave($prazo, SaveOptions::create());

        self::assertSame(2, $prazo->get('prioridadeWeight'));
    }

    public function testNaoFazNadaQuandoEntidadeNaoEhPrazo(): void
    {
        $other = new \Espo\Core\ORM\Entity();
        $other->set('prioridade', Prazo::PRIORIDADE_URGENTE);

        (new PrioridadeWeightHook())->beforeSave($other, SaveOptions::create());

        // No-op: hook não toca em entities que não são Prazo.
        self::assertNull($other->get('prioridadeWeight'));
    }

    public function testHookOrderEh10(): void
    {
        // Vinculante para Story 4a.5 §Decisão #2 Plano C — antes de
        // AutoLinkClientHook (20) e AuditPrazoHook (50).
        self::assertSame(10, PrioridadeWeightHook::$order);
    }

    private function makePrazoComPrioridade(string $prioridade): Prazo
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
            'status' => Prazo::STATUS_PENDENTE,
            'prioridade' => $prioridade,
        ]);

        return $prazo;
    }
}
