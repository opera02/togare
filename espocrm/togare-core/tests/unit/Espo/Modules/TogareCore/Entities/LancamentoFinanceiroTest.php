<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Entities;

use Espo\Modules\TogareCore\Entities\LancamentoFinanceiro;
use PHPUnit\Framework\TestCase;

/**
 * Story 6.3 — testa constantes + getters de LancamentoFinanceiro.
 */
final class LancamentoFinanceiroTest extends TestCase
{
    public function testEntityTypeConstant(): void
    {
        self::assertSame('LancamentoFinanceiro', LancamentoFinanceiro::ENTITY_TYPE);
    }

    public function testTiposCanonicos(): void
    {
        self::assertSame(
            ['pagamento_total', 'pagamento_parcial', 'despesa_interna', 'receita_avulsa', 'acerto', 'estorno'],
            LancamentoFinanceiro::TIPOS,
        );
    }

    public function testTiposComFaturaCobreSomentePagamentosEEstorno(): void
    {
        self::assertContains('pagamento_total', LancamentoFinanceiro::TIPOS_COM_FATURA);
        self::assertContains('pagamento_parcial', LancamentoFinanceiro::TIPOS_COM_FATURA);
        self::assertContains('estorno', LancamentoFinanceiro::TIPOS_COM_FATURA);
        self::assertNotContains('despesa_interna', LancamentoFinanceiro::TIPOS_COM_FATURA);
        self::assertNotContains('receita_avulsa', LancamentoFinanceiro::TIPOS_COM_FATURA);
        self::assertNotContains('acerto', LancamentoFinanceiro::TIPOS_COM_FATURA);
    }

    public function testTiposAvulsosCobreDespesaReceitaAcerto(): void
    {
        self::assertContains('despesa_interna', LancamentoFinanceiro::TIPOS_AVULSOS);
        self::assertContains('receita_avulsa', LancamentoFinanceiro::TIPOS_AVULSOS);
        self::assertContains('acerto', LancamentoFinanceiro::TIPOS_AVULSOS);
        self::assertNotContains('pagamento_total', LancamentoFinanceiro::TIPOS_AVULSOS);
        self::assertNotContains('estorno', LancamentoFinanceiro::TIPOS_AVULSOS);
    }

    public function testTiposDePagamentoExcluiEstorno(): void
    {
        self::assertContains('pagamento_total', LancamentoFinanceiro::TIPOS_DE_PAGAMENTO);
        self::assertContains('pagamento_parcial', LancamentoFinanceiro::TIPOS_DE_PAGAMENTO);
        self::assertNotContains('estorno', LancamentoFinanceiro::TIPOS_DE_PAGAMENTO);
    }

    public function testFormasPagamentoCanonicas(): void
    {
        self::assertSame(
            ['dinheiro', 'pix', 'boleto', 'transferencia_bancaria', 'cartao_credito', 'cartao_debito', 'cheque', 'outro'],
            LancamentoFinanceiro::FORMAS_PAGAMENTO,
        );
    }

    public function testIsAvulso(): void
    {
        $l = new LancamentoFinanceiro();
        $l->set('tipo', 'despesa_interna');
        self::assertTrue($l->isAvulso());

        $l->set('tipo', 'pagamento_total');
        self::assertFalse($l->isAvulso());
    }

    public function testIsPagamento(): void
    {
        $l = new LancamentoFinanceiro();
        $l->set('tipo', 'pagamento_parcial');
        self::assertTrue($l->isPagamento());

        $l->set('tipo', 'estorno');
        self::assertFalse($l->isPagamento());

        $l->set('tipo', 'despesa_interna');
        self::assertFalse($l->isPagamento());
    }

    public function testIsEstorno(): void
    {
        $l = new LancamentoFinanceiro();
        $l->set('tipo', 'estorno');
        self::assertTrue($l->isEstorno());

        $l->set('tipo', 'pagamento_total');
        self::assertFalse($l->isEstorno());
    }

    public function testExigeFatura(): void
    {
        $l = new LancamentoFinanceiro();
        $l->set('tipo', 'pagamento_total');
        self::assertTrue($l->exigeFatura());

        $l->set('tipo', 'estorno');
        self::assertTrue($l->exigeFatura());

        $l->set('tipo', 'despesa_interna');
        self::assertFalse($l->exigeFatura());
    }
}
