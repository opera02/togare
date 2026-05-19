<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Services;

use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\Modules\TogareCore\Entities\Fatura;
use Espo\Modules\TogareCore\Entities\LancamentoFinanceiro;
use Espo\Modules\TogareCore\Services\FaturaSaldoService;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

/**
 * Story 6.3 — testa FaturaSaldoService recompute idempotente + transitionStatus.
 *
 * Cobre Decisão #4 (status computed), #5 (Hook em LancamentoFinanceiro), #9
 * (cálculo + transições).
 */
final class FaturaSaldoServiceTest extends TestCase
{
    public function testRecomputeSemLancamentosRetornaSaldoIgualAoBruto(): void
    {
        $fatura = $this->makeFatura('fat-001', 1000.0, '2026-12-31', Fatura::STATUS_EMITIDA);
        $em = $this->makeEntityManager($fatura, []);
        $audit = $this->createMock(AuditLogContract::class);
        $service = new FaturaSaldoService($em, $audit);

        $result = $service->recompute('fat-001');

        self::assertNotNull($result);
        self::assertSame(0.0, $result['valorPago']);
        self::assertSame(1000.0, $result['saldo']);
        self::assertSame(Fatura::STATUS_EMITIDA, $result['status']);
    }

    public function testRecomputeUmPagamentoParcialReduzSaldo(): void
    {
        $fatura = $this->makeFatura('fat-002', 1000.0, '2026-12-31', Fatura::STATUS_EMITIDA);
        $lanc = $this->makeLancamento('pagamento_parcial', 400.0);
        $em = $this->makeEntityManager($fatura, [$lanc]);
        $audit = $this->createMock(AuditLogContract::class);
        $service = new FaturaSaldoService($em, $audit);

        $result = $service->recompute('fat-002');

        self::assertSame(400.0, $result['valorPago']);
        self::assertSame(600.0, $result['saldo']);
        self::assertSame(Fatura::STATUS_PARCIALMENTE_PAGA, $result['status']);
    }

    public function testRecomputeMultiplosPagamentosSomamCorreto(): void
    {
        $fatura = $this->makeFatura('fat-003', 1000.0, '2026-12-31', Fatura::STATUS_EMITIDA);
        $lancs = [
            $this->makeLancamento('pagamento_parcial', 300.0),
            $this->makeLancamento('pagamento_parcial', 700.0),
        ];
        $em = $this->makeEntityManager($fatura, $lancs);
        $audit = $this->createMock(AuditLogContract::class);
        $service = new FaturaSaldoService($em, $audit);

        $result = $service->recompute('fat-003');

        self::assertSame(1000.0, $result['valorPago']);
        self::assertSame(0.0, $result['saldo']);
        self::assertSame(Fatura::STATUS_PAGA, $result['status']);
    }

    public function testRecomputeEstornoReduzValorPago(): void
    {
        $fatura = $this->makeFatura('fat-004', 1000.0, '2026-12-31', Fatura::STATUS_PAGA);
        $lancs = [
            $this->makeLancamento('pagamento_total', 1000.0),
            $this->makeLancamento('estorno', 200.0),
        ];
        $em = $this->makeEntityManager($fatura, $lancs);
        $audit = $this->createMock(AuditLogContract::class);
        $service = new FaturaSaldoService($em, $audit);

        $result = $service->recompute('fat-004');

        self::assertSame(800.0, $result['valorPago']);
        self::assertSame(200.0, $result['saldo']);
        self::assertSame(Fatura::STATUS_PARCIALMENTE_PAGA, $result['status']);
    }

    public function testRecomputeVencidaQuandoDataVencimentoPassouComSaldoPositivo(): void
    {
        $ontem = (new \DateTimeImmutable('-1 day'))->format('Y-m-d');
        $fatura = $this->makeFatura('fat-005', 1000.0, $ontem, Fatura::STATUS_EMITIDA);
        $em = $this->makeEntityManager($fatura, []);
        $audit = $this->createMock(AuditLogContract::class);
        $service = new FaturaSaldoService($em, $audit);

        $result = $service->recompute('fat-005');

        self::assertSame(1000.0, $result['saldo']);
        self::assertSame(Fatura::STATUS_VENCIDA, $result['status']);
    }

    public function testRecomputeCanceladaPermaneceTerminal(): void
    {
        $fatura = $this->makeFatura('fat-006', 1000.0, '2026-12-31', Fatura::STATUS_CANCELADA);
        $em = $this->makeEntityManager($fatura, []);
        $audit = $this->createMock(AuditLogContract::class);
        $service = new FaturaSaldoService($em, $audit);

        $result = $service->recompute('fat-006');

        self::assertSame(Fatura::STATUS_CANCELADA, $result['status']);
    }

    public function testRecomputeIdempotenteSegundaChamadaProduzMesmoResultado(): void
    {
        $fatura = $this->makeFatura('fat-007', 500.0, '2026-12-31', Fatura::STATUS_EMITIDA);
        $lancs = [$this->makeLancamento('pagamento_parcial', 200.0)];
        $em = $this->makeEntityManager($fatura, $lancs);
        $audit = $this->createMock(AuditLogContract::class);
        $service = new FaturaSaldoService($em, $audit);

        $first = $service->recompute('fat-007');
        $second = $service->recompute('fat-007');

        self::assertSame($first, $second);
    }

    public function testRecomputeFaturaInexistenteRetornaNull(): void
    {
        $em = $this->makeEntityManager(null, []);
        $audit = $this->createMock(AuditLogContract::class);
        $service = new FaturaSaldoService($em, $audit);

        self::assertNull($service->recompute('fat-inexistente'));
    }

    public function testRecomputeEstornoExcedenteClampValorPagoEm0(): void
    {
        // Estorno excedente (cenário improvável — ValidateHook rejeita;
        // defesa em profundidade no Service): clampa valorPago em 0 para evitar
        // saldo > valorBruto (estado contábil "estranho").
        $fatura = $this->makeFatura('fat-008', 1000.0, '2026-12-31', Fatura::STATUS_PAGA);
        $lancs = [
            $this->makeLancamento('pagamento_total', 1000.0),
            $this->makeLancamento('estorno', 1500.0), // excedente
        ];
        $em = $this->makeEntityManager($fatura, $lancs);
        $audit = $this->createMock(AuditLogContract::class);
        $service = new FaturaSaldoService($em, $audit);

        $result = $service->recompute('fat-008');

        // valorPago clampado em 0 → saldo = valorBruto.
        self::assertSame(0.0, $result['valorPago']);
        self::assertSame(1000.0, $result['saldo']);
    }

    public function testTransitionStatusCanceladaSetaMotivo(): void
    {
        $fatura = $this->makeFatura('fat-009', 1000.0, '2026-12-31', Fatura::STATUS_EMITIDA);
        $em = $this->makeEntityManager($fatura, []);
        $audit = $this->createMock(AuditLogContract::class);
        $service = new FaturaSaldoService($em, $audit);

        $ok = $service->transitionStatus('fat-009', Fatura::STATUS_CANCELADA, 'Cliente desistiu por conta de mudança de escopo.');

        self::assertTrue($ok);
        self::assertSame(Fatura::STATUS_CANCELADA, $fatura->get('status'));
        self::assertSame('Cliente desistiu por conta de mudança de escopo.', $fatura->get('motivoCancelamento'));
    }

    public function testTransitionStatusIdempotenteParaMesmoStatus(): void
    {
        $fatura = $this->makeFatura('fat-010', 1000.0, '2026-12-31', Fatura::STATUS_CANCELADA);
        $em = $this->makeEntityManager($fatura, []);
        $audit = $this->createMock(AuditLogContract::class);
        $service = new FaturaSaldoService($em, $audit);

        $ok = $service->transitionStatus('fat-010', Fatura::STATUS_CANCELADA, 'irrelevante');

        self::assertTrue($ok);
    }

    public function testTransitionStatusNaoPodeDesancelarFatura(): void
    {
        // P5: cancelada é estado terminal — qualquer tentativa de transitar para
        // outro status deve retornar false sem alterar o status da fatura.
        $fatura = $this->makeFatura('fat-011', 1000.0, '2026-12-31', Fatura::STATUS_CANCELADA);
        $em = $this->makeEntityManager($fatura, []);
        $audit = $this->createMock(AuditLogContract::class);
        $service = new FaturaSaldoService($em, $audit);

        $ok = $service->transitionStatus('fat-011', Fatura::STATUS_EMITIDA, 'tentar reabrir');

        self::assertFalse($ok);
        self::assertSame(Fatura::STATUS_CANCELADA, $fatura->get('status'));
    }

    /**
     * Cria EM com 2 repositories: Fatura (getById retorna fatura ou null) +
     * LancamentoFinanceiro (where+find retorna lista).
     *
     * @param list<LancamentoFinanceiro> $lancamentos
     */
    private function makeEntityManager(?Fatura $fatura, array $lancamentos): EntityManager
    {
        $em = $this->createMock(EntityManager::class);

        $faturaRepo = new FakeFaturaRepository($fatura);
        $lancRepo = new FakeLancamentoRepository($lancamentos);

        $em->method('getRDBRepository')->willReturnCallback(
            fn (string $scope) => match ($scope) {
                Fatura::ENTITY_TYPE => $faturaRepo,
                LancamentoFinanceiro::ENTITY_TYPE => $lancRepo,
                default => throw new \InvalidArgumentException('Unknown scope: ' . $scope),
            }
        );

        return $em;
    }

    private function makeFatura(string $id, float $valorBruto, string $dataVencimento, string $status): Fatura
    {
        $f = new Fatura();
        $f->setId($id);
        $f->set([
            'valorBruto' => $valorBruto,
            'valorPago' => 0.0,
            'saldo' => $valorBruto,
            'dataVencimento' => $dataVencimento,
            'status' => $status,
        ]);
        return $f;
    }

    private function makeLancamento(string $tipo, float $valor): LancamentoFinanceiro
    {
        $l = new LancamentoFinanceiro();
        $l->set('tipo', $tipo);
        $l->set('valor', $valor);
        return $l;
    }
}

/**
 * @internal stub Espo RDBRepository<Fatura>.
 */
final class FakeFaturaRepository
{
    public function __construct(private readonly ?Fatura $fatura)
    {
    }

    public function getById(string $id): ?Fatura
    {
        if ($this->fatura === null) {
            return null;
        }
        return ((string) $this->fatura->getId() === $id) ? $this->fatura : null;
    }

    /** @param array<string, mixed> $where */
    public function where(array $where): self
    {
        return $this;
    }

    public function order(string $field, string $direction): self
    {
        return $this;
    }

    /** @return list<Fatura> */
    public function find(): array
    {
        return $this->fatura ? [$this->fatura] : [];
    }

    /** @param array<string, mixed> $options */
    public function save(Fatura $f, array $options = []): void
    {
        // No-op stub — saves in-place via Fatura already set.
    }
}

/**
 * @internal stub Espo RDBRepository<LancamentoFinanceiro>.
 */
final class FakeLancamentoRepository
{
    /** @param list<LancamentoFinanceiro> $lancamentos */
    public function __construct(private readonly array $lancamentos)
    {
    }

    /** @param array<string, mixed> $where */
    public function where(array $where): self
    {
        return $this;
    }

    /** @return list<LancamentoFinanceiro> */
    public function find(): array
    {
        return $this->lancamentos;
    }
}
