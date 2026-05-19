<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\Fatura;

use Espo\Modules\TogareCore\Entities\Fatura;
use Espo\Modules\TogareCore\Hooks\Fatura\RecomputeFaturaSaldoHook;
use Espo\Modules\TogareCore\Services\FaturaSaldoService;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

/**
 * Story 6.3 — testa RecomputeFaturaSaldoHook (Fatura AfterSave — P11).
 *
 * Cobre:
 *  - valorBruto alterado pós-isNew=false → recompute chamado.
 *  - isNew=true → sem recompute (valorPago/saldo inicializados pelo ValidateHook).
 *  - valorBruto não alterado → sem recompute.
 *  - save vindo do _fromRecompute → sem recompute (anti-loop).
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class RecomputeFaturaSaldoHookTest extends TestCase
{
    public function testValorBrutoAlteradoChamaRecompute(): void
    {
        $service = $this->createMock(FaturaSaldoService::class);
        $service->expects(self::once())
            ->method('recompute')
            ->with('fat-p11');

        $hook = new RecomputeFaturaSaldoHook($service);

        $f = new Fatura();
        $f->setId('fat-p11');
        $f->set('valorBruto', 500.0);
        // Simula "changed" marcando atributo como alterado
        $f->setFetched('valorBruto', 1000.0);

        // not isNew + valorBruto changed + not _fromRecompute
        $hook->afterSave($f, SaveOptions::create());
    }

    public function testIsNewNaoDispararaRecompute(): void
    {
        $service = $this->createMock(FaturaSaldoService::class);
        $service->expects(self::never())->method('recompute');

        $hook = new RecomputeFaturaSaldoHook($service);

        $f = new Fatura();
        $f->setId('fat-new');
        $f->set('valorBruto', 500.0);
        // isNew=true — entidade recém-criada (sem fetchedAttributes)

        $hook->afterSave($f, SaveOptions::create());
    }

    public function testValorBrutoNaoAlteradoNaoDisparaRecompute(): void
    {
        $service = $this->createMock(FaturaSaldoService::class);
        $service->expects(self::never())->method('recompute');

        $hook = new RecomputeFaturaSaldoHook($service);

        $f = new Fatura();
        $f->setId('fat-nochange');
        $f->set('valorBruto', 1000.0);
        $f->setFetched('valorBruto', 1000.0); // mesmo valor → not changed
        $f->set('descricao', 'Nova descricao'); // outra mudança

        $hook->afterSave($f, SaveOptions::create());
    }

    public function testFromRecomputeAntiLoopNaoDisparaRecompute(): void
    {
        $service = $this->createMock(FaturaSaldoService::class);
        $service->expects(self::never())->method('recompute');

        $hook = new RecomputeFaturaSaldoHook($service);

        $f = new Fatura();
        $f->setId('fat-recompute');
        $f->set('valorBruto', 500.0);
        $f->setFetched('valorBruto', 1000.0);

        $opts = SaveOptions::create(['silent' => true, '_fromRecompute' => true]);
        $hook->afterSave($f, $opts);
    }
}
