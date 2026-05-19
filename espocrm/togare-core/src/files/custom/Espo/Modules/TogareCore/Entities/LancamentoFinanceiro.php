<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Entities;

use Espo\Core\ORM\Entity;
use Espo\Modules\TogareCore\Traits\TenantAwareEntity;

/**
 * Entidade LancamentoFinanceiro — Story 6.3 (FR24).
 *
 * Tabela SQL `lancamento_financeiro` (snake_case via Util::toUnderScore).
 *
 * Modela:
 *  - Pagamentos vinculados a uma Fatura (pagamento_total | pagamento_parcial)
 *  - Estornos vinculados a uma Fatura (reduzem valorPago)
 *  - Lançamentos avulsos sem Fatura (despesa_interna | receita_avulsa | acerto)
 *    — Flow F6 UX-DR3 L427: "Este lançamento não emite documento de cobrança formal — ok".
 *
 * Validação tipo×fatura (Decisão #7):
 *
 *   | tipo               | fatura      | regra de valor                         |
 *   |--------------------|-------------|----------------------------------------|
 *   | pagamento_total    | obrigatória | valor == fatura.saldo no momento       |
 *   | pagamento_parcial  | obrigatória | 0 < valor < fatura.saldo               |
 *   | estorno            | obrigatória | valor <= fatura.valorPago              |
 *   | despesa_interna    | proibida    | valor > 0 (saída de caixa)             |
 *   | receita_avulsa     | proibida    | valor > 0 (entrada de caixa avulsa)    |
 *   | acerto             | proibida    | valor > 0 (ajuste neutro contábil)     |
 *
 * formaPagamento (Decisão #8):
 *  - Obrigatória quando tipo ∈ {pagamento_total, pagamento_parcial, estorno}.
 *  - Proibida quando tipo ∈ {despesa_interna, receita_avulsa, acerto}.
 *
 * Recompute da Fatura é disparado por RecomputeFaturaSaldoHook (AfterSave +
 * AfterRemove order=20) via FaturaSaldoService::recompute idempotente quando
 * faturaId presente.
 *
 * RBAC (Decisão #10) — togare-rbac V009: mesma política da Fatura.
 *
 * Trait `TenantAwareEntity` aplicado.
 */
class LancamentoFinanceiro extends Entity
{
    use TenantAwareEntity;

    public const ENTITY_TYPE = 'LancamentoFinanceiro';

    /** Tipos canônicos (Decisão #7). */
    public const TIPO_PAGAMENTO_TOTAL = 'pagamento_total';
    public const TIPO_PAGAMENTO_PARCIAL = 'pagamento_parcial';
    public const TIPO_DESPESA_INTERNA = 'despesa_interna';
    public const TIPO_RECEITA_AVULSA = 'receita_avulsa';
    public const TIPO_ACERTO = 'acerto';
    public const TIPO_ESTORNO = 'estorno';

    public const TIPOS = [
        self::TIPO_PAGAMENTO_TOTAL,
        self::TIPO_PAGAMENTO_PARCIAL,
        self::TIPO_DESPESA_INTERNA,
        self::TIPO_RECEITA_AVULSA,
        self::TIPO_ACERTO,
        self::TIPO_ESTORNO,
    ];

    /** Tipos que exigem fatura (vinculados à cobrança formal). */
    public const TIPOS_COM_FATURA = [
        self::TIPO_PAGAMENTO_TOTAL,
        self::TIPO_PAGAMENTO_PARCIAL,
        self::TIPO_ESTORNO,
    ];

    /** Tipos que proíbem fatura (lançamentos avulsos). */
    public const TIPOS_AVULSOS = [
        self::TIPO_DESPESA_INTERNA,
        self::TIPO_RECEITA_AVULSA,
        self::TIPO_ACERTO,
    ];

    /** Tipos que somam em valorPago da Fatura (entradas). */
    public const TIPOS_DE_PAGAMENTO = [
        self::TIPO_PAGAMENTO_TOTAL,
        self::TIPO_PAGAMENTO_PARCIAL,
    ];

    /**
     * Formas de pagamento canônicas (Decisão #8).
     * Labels pt-BR vivem em i18n/pt_BR/LancamentoFinanceiro.json::options.formaPagamento.
     */
    public const FORMA_DINHEIRO = 'dinheiro';
    public const FORMA_PIX = 'pix';
    public const FORMA_BOLETO = 'boleto';
    public const FORMA_TRANSFERENCIA_BANCARIA = 'transferencia_bancaria';
    public const FORMA_CARTAO_CREDITO = 'cartao_credito';
    public const FORMA_CARTAO_DEBITO = 'cartao_debito';
    public const FORMA_CHEQUE = 'cheque';
    public const FORMA_OUTRO = 'outro';

    public const FORMAS_PAGAMENTO = [
        self::FORMA_DINHEIRO,
        self::FORMA_PIX,
        self::FORMA_BOLETO,
        self::FORMA_TRANSFERENCIA_BANCARIA,
        self::FORMA_CARTAO_CREDITO,
        self::FORMA_CARTAO_DEBITO,
        self::FORMA_CHEQUE,
        self::FORMA_OUTRO,
    ];

    public function isAvulso(): bool
    {
        return \in_array($this->get('tipo'), self::TIPOS_AVULSOS, true);
    }

    public function isPagamento(): bool
    {
        return \in_array($this->get('tipo'), self::TIPOS_DE_PAGAMENTO, true);
    }

    public function isEstorno(): bool
    {
        return $this->get('tipo') === self::TIPO_ESTORNO;
    }

    public function exigeFatura(): bool
    {
        return \in_array($this->get('tipo'), self::TIPOS_COM_FATURA, true);
    }

    public function exigeFormaPagamento(): bool
    {
        return $this->exigeFatura();
    }
}
