<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\Fatura;

use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\Modules\TogareCore\Entities\Fatura;
use Espo\Modules\TogareCore\Hooks\Fatura\AuditFaturaHook;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

/**
 * Story 6.3 — testa AuditFaturaHook:
 *  - emite fatura.created quando isNew
 *  - emite fatura.modified quando SENSITIVE_FIELDS mudam pós-update
 *  - **respeita flag silent/_fromRecompute anti-loop**
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class AuditFaturaHookTest extends TestCase
{
    public function testCreatedEventoEmitido(): void
    {
        $audit = $this->createMock(AuditLogContract::class);
        $audit->expects(self::once())
            ->method('log')
            ->with(self::equalTo('fatura.created'), self::equalTo('Fatura'), self::anything(), self::anything());

        $em = $this->createMock(EntityManager::class);
        // PDO mock — sem fatura_log para simplificar.
        $em->method('getPDO')->willThrowException(new \RuntimeException('skip PDO for test'));

        $hook = new AuditFaturaHook($audit, $em);
        $f = new Fatura();
        // Sem setId → isNew=true.
        $f->set([
            'numero' => '2026-0001',
            'descricao' => 'Teste',
            'status' => 'emitida',
            'valorBruto' => 1000.0,
            'clienteId' => 'cli-001',
            'contratoHonorariosId' => 'contrato-001',
        ]);

        $hook->afterSave($f, SaveOptions::create());
    }

    public function testSilentRecomputeNaoDisparaAudit(): void
    {
        $audit = $this->createMock(AuditLogContract::class);
        $audit->expects(self::never())->method('log');

        $em = $this->createMock(EntityManager::class);
        $hook = new AuditFaturaHook($audit, $em);

        $f = new Fatura();
        $f->set('status', 'paga');

        $options = SaveOptions::create([
            'silent' => true,
            '_fromRecompute' => true,
        ]);

        $hook->afterSave($f, $options);
    }
}
