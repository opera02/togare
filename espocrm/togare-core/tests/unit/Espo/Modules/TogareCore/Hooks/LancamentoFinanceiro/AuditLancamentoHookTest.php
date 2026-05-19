<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\LancamentoFinanceiro;

use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\Modules\TogareCore\Entities\LancamentoFinanceiro;
use Espo\Modules\TogareCore\Hooks\LancamentoFinanceiro\AuditLancamentoHook;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

/**
 * Story 6.3 — testa AuditLancamentoHook:
 *  - emite lancamento.created quando isNew + tipo regular.
 *  - emite lancamento.estorno_aplicado quando isNew + tipo=estorno.
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class AuditLancamentoHookTest extends TestCase
{
    public function testCreatedEventoEmitido(): void
    {
        $audit = $this->createMock(AuditLogContract::class);
        $audit->expects(self::once())
            ->method('log')
            ->with('lancamento.created', 'LancamentoFinanceiro', self::anything(), self::anything());

        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willThrowException(new \RuntimeException('skip PDO'));

        $hook = new AuditLancamentoHook($audit, $em);
        $l = new LancamentoFinanceiro();
        // **NÃO chamar setId** — stub do CoreStubs marca new=false (isNew=false)
        // ao setId, e queremos testar o path isNew=true → emite 'lancamento.created'.
        $l->set([
            'tipo' => 'pagamento_parcial',
            'valor' => 100.0,
            'faturaId' => 'fat-001',
        ]);

        $hook->afterSave($l, SaveOptions::create());
    }

    public function testEstornoEventoRefinado(): void
    {
        $audit = $this->createMock(AuditLogContract::class);
        $audit->expects(self::once())
            ->method('log')
            ->with('lancamento.estorno_aplicado', 'LancamentoFinanceiro', self::anything(), self::anything());

        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willThrowException(new \RuntimeException('skip PDO'));

        $hook = new AuditLancamentoHook($audit, $em);
        $l = new LancamentoFinanceiro();
        // NÃO chamar setId — pelo mesmo motivo do testCreatedEventoEmitido.
        $l->set([
            'tipo' => 'estorno',
            'valor' => 50.0,
            'faturaId' => 'fat-001',
        ]);

        $hook->afterSave($l, SaveOptions::create());
    }
}
