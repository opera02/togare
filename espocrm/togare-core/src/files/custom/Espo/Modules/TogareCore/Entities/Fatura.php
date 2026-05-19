<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Entities;

use Espo\Core\ORM\Entity;
use Espo\Modules\TogareCore\Traits\TenantAwareEntity;

/**
 * Entidade Fatura — Story 6.3 (FR24 + Art. 22 §4º Estatuto OAB).
 *
 * Tabela SQL `fatura` (snake_case via Util::toUnderScore — ADR-02; pattern
 * Cliente/Processo/Documento/ContratoHonorarios).
 *
 * Story 6.3 entrega 2 entities (Fatura + LancamentoFinanceiro) + 7 Hooks + 2
 * Services (FaturaSaldoService + FaturaLookupService) + Migration V021 (2
 * tabelas auxiliares togare_fatura_log + togare_lancamento_financeiro_log) +
 * 2 Controllers (Fatura.getActionCancelar + LancamentoFinanceiro stock) +
 * 5+ views frontend (create-modal + record/detail + record/list + row-actions
 * + registrar-pagamento modal) + togare-rbac V009 (8 roles patch em 2 scopes).
 *
 * Estrutura de relacionamentos (Decisão #2 + Decisão #10 da spec):
 *  - N:1 Cliente OBRIGATÓRIO (herdado de contrato.cliente no ValidateHook)
 *  - N:1 Processo OPCIONAL (quando informado, deve pertencer ao mesmo cliente)
 *  - N:1 ContratoHonorarios OBRIGATÓRIO (gate FR23 backend — defesa em
 *    profundidade da regra OAB; Story 6.2 entrega banner UX inline)
 *
 * Numero auto-gerado (Decisão #3) — formato <ANO>-<seqAno>, ex.: `2026-0001`:
 *  - Sequência reinicia em 1º de janeiro (numeração contábil tradicional BR).
 *  - UNIQUE index + retry no Hook para race condition simultânea.
 *  - Imutável após save (etiqueta humana).
 *
 * Status (Decisão #4 + Decisão #9) — computed + persisted via FaturaSaldoService:
 *  - emitida           — saldo == valorBruto AND today <= dataVencimento
 *  - parcialmente_paga — 0 < saldo < valorBruto AND today <= dataVencimento
 *  - paga              — saldo == 0
 *  - vencida           — today > dataVencimento AND saldo > 0
 *  - cancelada         — TERMINAL (não transita; recompute pula transição)
 *
 * valorPago / saldo são COMPUTED + PERSISTED (Decisão #4):
 *  - Reports nativo EspoCRM (FR25 descopo D2) precisa de SQL direto.
 *  - RecomputeFaturaSaldoHook em LancamentoFinanceiro recalcula após cada
 *    INSERT/UPDATE/DELETE de pagamento ou estorno vinculado.
 *  - Idempotente; reentrant-safe; silent save anti-loop com AuditFaturaHook.
 *
 * Hooks (ordem):
 *  - ValidateFaturaFieldsHook         (BeforeSave, order=10) — gate FR23 backend + numero + cross-cliente + dataVencimento + valorBruto + imutabilidade
 *  - DefaultFaturaAssignmentHook      (BeforeSave, order=15) — assignedUser current default
 *  - AuditFaturaHook                  (AfterSave + AfterRemove, order=50) — eventos canônicos + togare_fatura_log V021
 *
 * RBAC (Decisão #10) — togare-rbac V009:
 *  - Sócio/Admin + Financeiro: all
 *  - Advogado + Assistente/Estagiário: read own (assignedUser = current)
 *  - Secretária + Marketing + RH-lite: no (blindagem cruzada FR3)
 *  - Cliente-portal: no (aclPortal=false)
 *
 * Trait `TenantAwareEntity` aplicado conforme architecture L650 + Story 1a.9.
 */
class Fatura extends Entity
{
    use TenantAwareEntity;

    public const ENTITY_TYPE = 'Fatura';

    /**
     * Status canônicos (Decisão #4 + Decisão #9).
     * Labels pt-BR vivem em i18n/pt_BR/Fatura.json::options.status.
     */
    public const STATUS_EMITIDA = 'emitida';
    public const STATUS_PARCIALMENTE_PAGA = 'parcialmente_paga';
    public const STATUS_PAGA = 'paga';
    public const STATUS_VENCIDA = 'vencida';
    public const STATUS_CANCELADA = 'cancelada';

    public const STATUSES = [
        self::STATUS_EMITIDA,
        self::STATUS_PARCIALMENTE_PAGA,
        self::STATUS_PAGA,
        self::STATUS_VENCIDA,
        self::STATUS_CANCELADA,
    ];

    /** Status que ainda admitem pagamentos (não terminal). */
    public const STATUSES_OPEN = [
        self::STATUS_EMITIDA,
        self::STATUS_PARCIALMENTE_PAGA,
        self::STATUS_VENCIDA,
    ];

    /**
     * Verifica se a fatura é vencida em uma data de referência.
     * Vencida = today > dataVencimento AND saldo > 0 AND status != cancelada.
     */
    public function isVencida(?\DateTimeImmutable $reference = null): bool
    {
        if ($this->get('status') === self::STATUS_CANCELADA) {
            return false;
        }

        $reference = $reference ?? new \DateTimeImmutable('today');
        $venc = (string) ($this->get('dataVencimento') ?? '');
        if ($venc === '') {
            return false;
        }

        $saldo = (float) ($this->get('saldo') ?? 0.0);
        if ($saldo <= 0) {
            return false;
        }

        return $venc < $reference->format('Y-m-d');
    }

    public function isPaga(): bool
    {
        return $this->get('status') === self::STATUS_PAGA;
    }

    public function isCancelada(): bool
    {
        return $this->get('status') === self::STATUS_CANCELADA;
    }
}
