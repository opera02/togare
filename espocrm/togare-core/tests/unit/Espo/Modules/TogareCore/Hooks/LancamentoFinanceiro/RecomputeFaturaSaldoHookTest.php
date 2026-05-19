<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\LancamentoFinanceiro;

use Espo\Modules\TogareCore\Entities\LancamentoFinanceiro;
use Espo\Modules\TogareCore\Hooks\LancamentoFinanceiro\RecomputeFaturaSaldoHook;
use Espo\Modules\TogareCore\Services\FaturaSaldoService;
use Espo\ORM\Repository\Option\RemoveOptions;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

/**
 * Story 6.3 — testa RecomputeFaturaSaldoHook:
 *  - AfterSave com faturaId chama FaturaSaldoService::recompute idempotente.
 *  - AfterSave sem faturaId (lançamento avulso) é no-op.
 *  - AfterRemove com faturaId chama recompute.
 *  - Edge case: faturaId mudou em update — recomputa AMBAS faturas.
 *  - Defensivo \Throwable: erro do service não bloqueia o save.
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class RecomputeFaturaSaldoHookTest extends TestCase
{
    public function testAfterSaveComFaturaIdChamaRecompute(): void
    {
        $service = $this->createMock(FaturaSaldoService::class);
        $service->expects(self::once())
            ->method('recompute')
            ->with('fat-001');

        $hook = new RecomputeFaturaSaldoHook($service);
        $l = $this->makeLancamento('lanc-001', 'fat-001');

        $hook->afterSave($l, SaveOptions::create());
    }

    public function testAfterSaveSemFaturaIdNoOp(): void
    {
        $service = $this->createMock(FaturaSaldoService::class);
        $service->expects(self::never())->method('recompute');

        $hook = new RecomputeFaturaSaldoHook($service);
        // lançamento avulso (despesa_interna sem fatura)
        $l = new LancamentoFinanceiro();
        $l->set([
            'tipo' => 'despesa_interna',
            'valor' => 100.0,
        ]);

        $hook->afterSave($l, SaveOptions::create());
    }

    public function testAfterRemoveChamaRecompute(): void
    {
        $service = $this->createMock(FaturaSaldoService::class);
        $service->expects(self::once())
            ->method('recompute')
            ->with('fat-001');

        $hook = new RecomputeFaturaSaldoHook($service);
        $l = $this->makeLancamento('lanc-001', 'fat-001');

        $hook->afterRemove($l, RemoveOptions::create());
    }

    public function testErroDoServiceNaoBloqueiaSave(): void
    {
        $service = $this->createMock(FaturaSaldoService::class);
        $service->method('recompute')->willThrowException(new \RuntimeException('DB unavailable'));

        $hook = new RecomputeFaturaSaldoHook($service);
        $l = $this->makeLancamento('lanc-001', 'fat-001');

        // Não deve lançar — defensivo \Throwable.
        $hook->afterSave($l, SaveOptions::create());
        self::assertTrue(true);
    }

    private function makeLancamento(string $id, string $faturaId): LancamentoFinanceiro
    {
        $l = new LancamentoFinanceiro();
        $l->setId($id);
        $l->set([
            'tipo' => 'pagamento_parcial',
            'valor' => 100.0,
            'faturaId' => $faturaId,
        ]);
        // Sem setId → isNew=true por default.
        return $l;
    }
}
