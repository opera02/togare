<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Entities;

use Espo\Core\ORM\Entity;
use Espo\Modules\TogareCore\Traits\TenantAwareEntity;

/**
 * Entidade Audiencia — versão MAGRO (Story 3.6-magro, FR16).
 *
 * Tabela SQL: `audiencia` — ORM EspoCRM 9.3 deriva via
 * `Util::toUnderScore('Audiencia') === 'audiencia'` (ADR-02; mesmo padrão
 * Cliente, ParteContraria, Processo). Sem cedilha no identifier técnico
 * (label "Audiência" vive no i18n pt-BR Global.json + Audiencia.json).
 *
 * Hooks (ordem de execução beforeSave/afterSave):
 *  - togare-core/Hooks/Audiencia/EnforceAudienciaAssignmentHook (5)  — auto-titular create
 *  - togare-core/Hooks/Audiencia/ValidateAudienciaFieldsHook    (10) — enums + duracao + datas
 *  - togare-core/Hooks/Audiencia/AuditAudienciaHook              (50, AfterSave) — audit log + eventos cancelled/realized
 *
 * Versão MAGRO (vs. Story 3.6 original) — corte D2 do Party Mode:
 *  - SEM detecção automática de conflito de horário (advogado confirma manual).
 *  - SEM agenda consolidada custom (Calendar nativo EspoCRM cobre via
 *    `scope.calendar=true` + `clientDefs.calendar.dateField=dataHora`).
 *  - SEM colaboradores (visibilidade `read=own` resolve via `assignedUserId`
 *    apenas — diferente do Processo/Story 3.5).
 *  - Participantes é texto livre (não linkMultiple) — Decisão #4.
 *
 * `assignedUser` (advogado responsável) pode ser ≠ `assignedUser` do Processo
 * (Sócio/Admin delega audiência específica para advogado júnior). ACL
 * `read=own` para Advogado filtra por `assignedUserId == user.id`.
 *
 * Trait `TenantAwareEntity` aplicado conforme architecture L650 + Story 1a.9 —
 * `tenant_id` NULL no MVP single-tenant.
 */
class Audiencia extends Entity
{
    use TenantAwareEntity;

    public const ENTITY_TYPE = 'Audiencia';

    public const TIPO_CONCILIACAO = 'conciliacao';
    public const TIPO_INSTRUCAO_JULGAMENTO = 'instrucao_julgamento';
    public const TIPO_JULGAMENTO = 'julgamento';
    public const TIPO_UNA = 'una';
    public const TIPO_CONCILIACAO_MEDIACAO = 'conciliacao_mediacao';
    public const TIPO_OUTRAS = 'outras';

    public const MODALIDADE_PRESENCIAL = 'presencial';
    public const MODALIDADE_VIRTUAL = 'virtual';
    public const MODALIDADE_HIBRIDA = 'hibrida';

    public const STATUS_AGENDADA = 'agendada';
    public const STATUS_REALIZADA = 'realizada';
    public const STATUS_CANCELADA = 'cancelada';
    public const STATUS_ADIADA = 'adiada';

    public const DURACAO_MIN_MINUTOS = 15;
    public const DURACAO_MAX_MINUTOS = 480;
}
