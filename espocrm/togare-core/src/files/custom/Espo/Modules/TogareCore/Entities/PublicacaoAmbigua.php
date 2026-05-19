<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Entities;

use Espo\Core\ORM\Entity;
use Espo\Modules\TogareCore\Traits\TenantAwareEntity;

/**
 * Entidade PublicacaoAmbigua — Story 4b.1a (FR14 fila de revisão, NFR18 zero perda silenciosa).
 *
 * Tabela SQL `publicacao_ambigua` (snake_case via Util::toUnderScore — ADR-02;
 * mesmo padrão Cliente/ParteContraria/Processo/Audiencia/Prazo).
 *
 * Materializa a fila "Precisa sua leitura" do flow F3 (jornada Beatriz):
 * quando o worker DJEN identifica 2+ processos candidatos para 1 publicação,
 * cria 1 PublicacaoAmbigua com snapshot de candidatos denormalizados em vez
 * de criar 1 Prazo erroneamente. Decisão = ato humano, audit-trail completo
 * em `togare_ambiguity_log` (D5 da spec-mãe 4b.1).
 *
 * Story 4b.1a entrega APENAS o schema + entity + Hook + Controller stub +
 * boolFilter "PrecisaSuaLeitura" + RBAC scope. Gerador (PublicationMatcher
 * + refactor PrazoCreatorService) vem na 4b.1b. UX custom (QueueNavegavel +
 * ComparadorCandidatos) vem na 4b.1c.
 *
 * Hooks (ordem beforeSave/afterSave):
 *  - togare-core/Hooks/PublicacaoAmbigua/AuditPublicacaoAmbiguaHook (50, AfterSave)
 *
 * Status enum 4 valores:
 *  - pendente_revisao  — default; advogado precisa decidir.
 *  - resolvido         — advogado escolheu candidato → Prazo criado (4b.1b cria).
 *  - ignorado          — advogado disse "Nenhum dos candidatos serve" (4b.1b cria).
 *  - bulk_ignorado     — advogado bulk-ignorou todas pubs daquele Processo (4b.1b cria).
 *
 * decisionType enum 3 valores (preenchido junto com decidedBy/decidedAt na 4b.1b):
 *  - confirmar_candidato        — escolheu 1 candidato; gera Prazo manual_ambiguo.
 *  - ignorar                    — descartou pub.
 *  - bulk_ignorar_processo      — bulk operation.
 *
 * ambiguityReason enum 2 valores (preenchido pelo PublicationMatcher na 4b.1b):
 *  - cnj_multiplos_processos       — defensivo; UNIQUE em numero_cnj deveria impedir.
 *  - name_match_multiplos_candidatos — caso principal (CNJ 0 hits + name-match 2+ hits).
 *
 * Trait `TenantAwareEntity` aplicado conforme architecture L650 + Story 1a.9 —
 * `tenant_id` NULL no MVP single-tenant.
 */
class PublicacaoAmbigua extends Entity
{
    use TenantAwareEntity;

    public const ENTITY_TYPE = 'PublicacaoAmbigua';

    /** Story 4b.1a — 4 status canônicos. */
    public const STATUS_PENDENTE_REVISAO = 'pendente_revisao';
    public const STATUS_RESOLVIDO = 'resolvido';
    public const STATUS_IGNORADO = 'ignorado';
    public const STATUS_BULK_IGNORADO = 'bulk_ignorado';

    /** Story 4b.1b — preenchidos na decisão. */
    public const DECISION_CONFIRMAR_CANDIDATO = 'confirmar_candidato';
    public const DECISION_IGNORAR = 'ignorar';
    public const DECISION_BULK_IGNORAR_PROCESSO = 'bulk_ignorar_processo';

    /** Story 4b.1b — preenchido pelo PublicationMatcher. */
    public const AMBIGUITY_REASON_CNJ_MULTIPLE = 'cnj_multiplos_processos';
    public const AMBIGUITY_REASON_NAME_MATCH_MULTIPLE = 'name_match_multiplos_candidatos';

    /** Limite de candidatos exibidos no ComparadorCandidatos (Decisão #1 mãe). */
    public const MAX_CANDIDATOS = 5;
}
