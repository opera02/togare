<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Controllers;

/**
 * Controller REST para a entidade Audiencia (Story 3.6-magro, FR16).
 *
 * Estende `Espo\Core\Controllers\Record` — expõe endpoints CRUD nativos:
 *  - GET    /api/v1/Audiencia?orderBy=...&maxSize=... — listagem.
 *  - GET    /api/v1/Audiencia/{id}                     — detalhe.
 *  - POST   /api/v1/Audiencia                          — criar.
 *  - PUT    /api/v1/Audiencia/{id}                     — editar.
 *  - DELETE /api/v1/Audiencia/{id}                     — excluir.
 *
 * Calendar nativo: como `scopes.Audiencia.calendar=true` +
 * `clientDefs.Audiencia.calendar.dateField=dataHora`, EspoCRM agrega
 * Audiencia em `#Calendar` automaticamente lado a lado com Meeting/Call/Task
 * — zero código de UI custom (Decisão #3 da story; Story 3.7 cortada D2).
 *
 * ACL aplicada via 8 roles seedados pelo togare-rbac (já presentes desde 0.7.0):
 *  - Sócio/Admin: all
 *  - Advogado:    own  — visibilidade by-assignment via `assignedUser`
 *  - Assistente:  own  — apoia advogado responsável
 *  - Secretária:  {read=team, edit=no, create=no, delete=no} — agenda consolidada
 *  - Financeiro/Marketing/RH-lite/Cliente-portal: no
 *
 * Hooks beforeSave/afterSave registrados:
 *  - EnforceAudienciaAssignmentHook (togare-core,  5, BeforeSave) — auto-titular create
 *  - ValidateAudienciaFieldsHook    (togare-core, 10, BeforeSave) — enums + duracao
 *  - AuditAudienciaHook             (togare-core, 50, AfterSave)  — audit log + cancelled/realized
 *
 * Diferente do Processo/Story 3.5: NÃO bloqueia mudança de assignment por
 * terceiros (admins delegando audiência é fluxo legítimo). Versão LIGHT
 * intencional — Audiencia é menos sensível que Processo.
 *
 * Controller vazio é OBRIGATÓRIO. Sem este arquivo o EspoCRM 9.3 retorna
 * 404 em /api/v1/Audiencia (confirmado nas Stories 3.1, 3.2 e 3.4).
 */
class Audiencia extends \Espo\Core\Controllers\Record
{
}
