<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\LancamentoFinanceiro;

use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\TogareCore\Entities\Fatura;
use Espo\Modules\TogareCore\Entities\LancamentoFinanceiro;
use Espo\Modules\TogareCore\Hooks\LancamentoFinanceiro\ValidateLancamentoFieldsHook;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

/**
 * Story 6.3 — testa ValidateLancamentoFieldsHook matriz Decisão #7 (tipo×fatura)
 * + Decisão #8 (formaPagamento) + valor×saldo.
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class ValidateLancamentoFieldsHookTest extends TestCase
{
    public function testPagamentoTotalSemFaturaLancaBadRequest(): void
    {
        $hook = $this->makeHook(null);
        $l = $this->makeNew([
            'descricao' => 'Pagamento',
            'tipo' => 'pagamento_total',
            'valor' => 100.0,
            'dataMovimento' => '2026-05-15',
            'formaPagamento' => 'pix',
            'clienteId' => 'cli-001',
            // sem faturaId
        ]);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessageMatches('/exigem fatura/');
        $hook->beforeSave($l, SaveOptions::create());
    }

    public function testDespesaInternaComFaturaLancaBadRequest(): void
    {
        $hook = $this->makeHook(null);
        $l = $this->makeNew([
            'descricao' => 'Custas',
            'tipo' => 'despesa_interna',
            'valor' => 100.0,
            'dataMovimento' => '2026-05-15',
            'clienteId' => 'cli-001',
            'faturaId' => 'fat-001',
        ]);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessageMatches('/não admite fatura/');
        $hook->beforeSave($l, SaveOptions::create());
    }

    public function testPagamentoSemFormaPagamentoLancaBadRequest(): void
    {
        $fatura = $this->makeFatura('fat-001', 1000.0, 1000.0, 'emitida');
        $hook = $this->makeHook($fatura);
        $l = $this->makeNew([
            'descricao' => 'Pagamento',
            'tipo' => 'pagamento_parcial',
            'valor' => 100.0,
            'dataMovimento' => '2026-05-15',
            'faturaId' => 'fat-001',
            'clienteId' => 'cli-001',
            // sem formaPagamento
        ]);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessageMatches('/Forma de pagamento/');
        $hook->beforeSave($l, SaveOptions::create());
    }

    public function testDespesaComFormaPagamentoLancaBadRequest(): void
    {
        $hook = $this->makeHook(null);
        $l = $this->makeNew([
            'descricao' => 'Custas',
            'tipo' => 'despesa_interna',
            'valor' => 100.0,
            'dataMovimento' => '2026-05-15',
            'clienteId' => 'cli-001',
            'formaPagamento' => 'pix',
        ]);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessageMatches('/não se aplica/');
        $hook->beforeSave($l, SaveOptions::create());
    }

    public function testFaturaCanceladaProibido(): void
    {
        $fatura = $this->makeFatura('fat-001', 1000.0, 1000.0, 'cancelada');
        $hook = $this->makeHook($fatura);
        $l = $this->makeNew([
            'descricao' => 'Pagamento',
            'tipo' => 'pagamento_total',
            'valor' => 1000.0,
            'dataMovimento' => '2026-05-15',
            'formaPagamento' => 'pix',
            'faturaId' => 'fat-001',
            'clienteId' => 'cli-001',
        ]);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessageMatches('/cancelada/');
        $hook->beforeSave($l, SaveOptions::create());
    }

    public function testPagamentoTotalValorDiferenteSaldoLancaBadRequest(): void
    {
        $fatura = $this->makeFatura('fat-001', 1000.0, 500.0, 'parcialmente_paga');
        $hook = $this->makeHook($fatura);
        $l = $this->makeNew([
            'descricao' => 'Pagamento total errado',
            'tipo' => 'pagamento_total',
            'valor' => 600.0, // saldo é 500
            'dataMovimento' => '2026-05-15',
            'formaPagamento' => 'pix',
            'faturaId' => 'fat-001',
            'clienteId' => 'cli-001',
        ]);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessageMatches('/exatamente igual ao saldo/');
        $hook->beforeSave($l, SaveOptions::create());
    }

    public function testPagamentoParcialMaiorQueSaldoLancaBadRequest(): void
    {
        $fatura = $this->makeFatura('fat-001', 1000.0, 500.0, 'parcialmente_paga');
        $hook = $this->makeHook($fatura);
        $l = $this->makeNew([
            'descricao' => 'Pagamento parcial excedente',
            'tipo' => 'pagamento_parcial',
            'valor' => 600.0, // saldo é 500
            'dataMovimento' => '2026-05-15',
            'formaPagamento' => 'pix',
            'faturaId' => 'fat-001',
            'clienteId' => 'cli-001',
        ]);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessageMatches('/excede o saldo/');
        $hook->beforeSave($l, SaveOptions::create());
    }

    public function testEstornoMaiorQueValorPagoLancaBadRequest(): void
    {
        $fatura = $this->makeFatura('fat-001', 1000.0, 800.0, 'parcialmente_paga');
        // valorPago = valorBruto - saldo = 200
        $fatura->set('valorPago', 200.0);
        $hook = $this->makeHook($fatura);
        $l = $this->makeNew([
            'descricao' => 'Estorno excedente',
            'tipo' => 'estorno',
            'valor' => 500.0, // valorPago é 200
            'dataMovimento' => '2026-05-15',
            'formaPagamento' => 'pix',
            'faturaId' => 'fat-001',
            'clienteId' => 'cli-001',
        ]);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessageMatches('/estornar mais do que já foi pago/');
        $hook->beforeSave($l, SaveOptions::create());
    }

    public function testValorZeroLancaBadRequest(): void
    {
        $hook = $this->makeHook(null);
        $l = $this->makeNew([
            'descricao' => 'Vazio',
            'tipo' => 'despesa_interna',
            'valor' => 0,
            'dataMovimento' => '2026-05-15',
            'clienteId' => 'cli-001',
        ]);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessageMatches('/maior que zero/');
        $hook->beforeSave($l, SaveOptions::create());
    }

    public function testClienteHerdadoDaFaturaQuandoVazio(): void
    {
        $fatura = $this->makeFatura('fat-001', 1000.0, 1000.0, 'emitida');
        $fatura->set('clienteId', 'cli-002');
        $hook = $this->makeHook($fatura);
        $l = $this->makeNew([
            'descricao' => 'Pagamento',
            'tipo' => 'pagamento_total',
            'valor' => 1000.0,
            'dataMovimento' => '2026-05-15',
            'formaPagamento' => 'pix',
            'faturaId' => 'fat-001',
            // sem clienteId — deve herdar de fatura
        ]);

        $hook->beforeSave($l, SaveOptions::create());
        self::assertSame('cli-002', $l->get('clienteId'));
    }

    private function makeHook(?Fatura $fatura): ValidateLancamentoFieldsHook
    {
        $em = $this->createMock(EntityManager::class);
        $repo = new FakeLancValidateRepository($fatura);
        $em->method('getRDBRepository')->willReturn($repo);
        // queryExecutor + queryBuilder ficam fora — testes não exercem
        // validateProcessoCrossCliente (cobertura via smoke F1).
        return new ValidateLancamentoFieldsHook($em);
    }

    private function makeFatura(string $id, float $valorBruto, float $saldo, string $status): Fatura
    {
        $f = new Fatura();
        $f->setId($id);
        $f->set([
            'valorBruto' => $valorBruto,
            'saldo' => $saldo,
            'valorPago' => $valorBruto - $saldo,
            'status' => $status,
            'clienteId' => 'cli-001',
        ]);
        return $f;
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function makeNew(array $attrs): LancamentoFinanceiro
    {
        $l = new LancamentoFinanceiro();
        $l->set($attrs);
        // Sem setId → isNew=true por default.
        return $l;
    }
}

/**
 * @internal stub for ValidateLancamentoFieldsHook EM mock.
 */
final class FakeLancValidateRepository
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
}
