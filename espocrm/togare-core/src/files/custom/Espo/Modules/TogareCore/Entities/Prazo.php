<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Entities;

use Espo\Core\ORM\Entity;
use Espo\Modules\TogareCore\Traits\TenantAwareEntity;

/**
 * Entidade Prazo (Story 4a.3 + 4a.3.1 — FR12+FR13+FR14+FR15+FR37 PRD v1.3).
 *
 * Tabela SQL: `prazo` — derivada via `Util::toUnderScore('Prazo') === 'prazo'`
 * (ADR-02 togare-core; mesmo pattern Cliente/ParteContraria/Processo/Audiencia).
 *
 * Materializa o pipeline "publicação DJEN → Prazo persistido" (Story 4a.3
 * fechou o "aha moment" da jornada Ricardo do PRD). Story 4a.3.1 expande
 * o modelo de dados para refletir o vocabulário jurídico real (8 status
 * canônicos + 5 campos novos + auto-vínculo Cliente/ParteContraria via
 * AutoLinkClientHook em togare-core).
 *
 * Hooks (ordem beforeSave/afterSave):
 *  - togare-core/Hooks/Prazo/ValidatePrazoFieldsHook  (10) — enums + datas + integridade status×campos + motivoReagendamento ≥10 chars
 *  - togare-core/Hooks/Prazo/AutoLinkClientHook       (20) — auto-vínculo Cliente/ParteContraria N:N defensivo (Story 4a.3.1)
 *  - togare-core/Hooks/Prazo/AuditPrazoHook           (50, AfterSave) — audit log + 7 eventos derivados (bound/descartado/protocolado/reagendado/aguardando_cliente/acompanhamento + revertido transição)
 *
 * Status enum 9 valores (8 visíveis + 1 técnico oculto — Decisão #1 da 4a.3.1
 * fechando D7 do sprint change proposal 2026-05-04 como opção (b)):
 *  - rascunho             — não vinculado OU vinculado-mas-não-validado (caminho sem match DJEN)
 *  - pendente             — confirmado pelo advogado, aguardando ação (caminho match DJEN)
 *  - atrasado_reagendado  — passou da data fatal OU foi reagendado (EXIGE motivoReagendamento ≥10 chars)
 *  - aguardando_cliente   — aguardando retorno do cliente
 *  - aguardando_correcao  — aguardando correção do tribunal/sistema
 *  - protocolado          — concluído (peça protocolada)
 *  - ciencia_renuncia     — concluído (ciência com renúncia ao prazo)
 *  - acompanhamento       — informativo, sem ação requerida
 *  - descartado           — TÉCNICO oculto do dropdown UI (preservado para queries audit
 *                           e audit.prazo.descartado; clientDefs.dynamicLogic.options.status
 *                           filtra do dropdown — D7 (b) drafting da 4a.3.1)
 *
 * Mapping legacy V009 (Story 4a.3.1, ADR-03 v1.1 §1):
 *  - rascunho_nao_vinculado → rascunho
 *  - confirmado             → pendente   (D2 Felipe)
 *  - cumprido               → protocolado
 *  - revertido              → pendente   (vira evento puro audit.prazo.revertido)
 *  - pendente, descartado   → preservados
 *
 * Source enum:
 *  - djen           — caminho automático
 *  - manual         — futuro (cadastro UI manual)
 *  - manual_ambiguo — Story 4b.1 ComparadorCandidatos
 *
 * Prioridade enum 4 valores (Story 4a.3.1 — F1.11):
 *  - baixa, normal (default), alta, urgente
 *
 * Trait `TenantAwareEntity` aplicado conforme architecture L650 + Story 1a.9 —
 * `tenant_id` NULL no MVP single-tenant.
 */
class Prazo extends Entity
{
    use TenantAwareEntity;

    public const ENTITY_TYPE = 'Prazo';

    /** Story 4a.3.1 — 9 status canônicos (8 visíveis + 1 técnico oculto). */
    public const STATUS_RASCUNHO = 'rascunho';
    public const STATUS_PENDENTE = 'pendente';
    public const STATUS_REAGENDADO = 'atrasado_reagendado';
    public const STATUS_AGUARDANDO_CLIENTE = 'aguardando_cliente';
    public const STATUS_AGUARDANDO_CORRECAO = 'aguardando_correcao';
    public const STATUS_PROTOCOLADO = 'protocolado';
    public const STATUS_CIENCIA_RENUNCIA = 'ciencia_renuncia';
    public const STATUS_ACOMPANHAMENTO = 'acompanhamento';
    public const STATUS_DESCARTADO = 'descartado';

    public const SOURCE_DJEN = 'djen';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_MANUAL_AMBIGUO = 'manual_ambiguo';

    public const CONTAGEM_UTEIS = 'uteis';
    public const CONTAGEM_CORRIDOS = 'corridos';

    public const CONFIDENCE_HIGH = 'high';
    public const CONFIDENCE_MEDIUM = 'medium';
    public const CONFIDENCE_LOW = 'low';

    /** Story 4a.3.1 — 4 prioridades canônicas (F1.11). */
    public const PRIORIDADE_BAIXA = 'baixa';
    public const PRIORIDADE_NORMAL = 'normal';
    public const PRIORIDADE_ALTA = 'alta';
    public const PRIORIDADE_URGENTE = 'urgente';

    public const PRAZO_DIAS_MIN = 1;
    public const PRAZO_DIAS_MAX = 365;

    /** Story 4a.3.1 — limite mínimo do motivoReagendamento (Decisão #2). */
    public const MOTIVO_REAGENDAMENTO_MIN_LEN = 10;
}
