<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Controllers;

/**
 * Controller REST para a entidade Prazo (Story 4a.3, FR12+FR13+FR14).
 *
 * Estende `Espo\Core\Controllers\Record` — expõe endpoints CRUD nativos:
 *  - GET    /api/v1/Prazo?orderBy=...&maxSize=... — listagem.
 *  - GET    /api/v1/Prazo/{id}                     — detalhe.
 *  - POST   /api/v1/Prazo                          — criar.
 *  - PUT    /api/v1/Prazo/{id}                     — editar.
 *  - DELETE /api/v1/Prazo/{id}                     — excluir.
 *
 * BoolFilters disponíveis (clientDefs.Prazo.boolFilterList + selectDefs):
 *  - onlyMy        — global EspoCRM (auto-resolve via assignedUser).
 *  - naoVinculadas — Sócio/Admin triagem global (status=rascunho_nao_vinculado).
 *  - meusPendentes — Advogado (status=pendente AND assignedUser=self).
 *  - meusRascunhos — Advogado (status=rascunho_nao_vinculado AND assignedUser=self).
 *
 * ACL aplicada via 8 roles seedados pelo togare-rbac 0.8.0:
 *  - Sócio/Admin:        all
 *  - Advogado:           {read:own, edit:own, create:team, delete:no} — by-assignment
 *  - Assistente:         {read:team, edit:team, create:team, delete:no}
 *  - Secretária:         {read:team, edit:no, create:no, delete:no}
 *  - Financeiro/Marketing/RH-lite/Cliente-portal: no
 *
 * Hooks beforeSave/afterSave registrados:
 *  - ValidatePrazoFieldsHook (togare-core, 10, BeforeSave) — enums + datas + status
 *  - AuditPrazoHook          (togare-core, 50, AfterSave)  — audit log + 4 eventos derivados
 *
 * Producer principal: `togareDjenPrazoCreator` (togare-djen 0.3.0) que cria
 * Prazos via `EntityManager::saveEntity` no contexto worker (não via API HTTP).
 * Endpoints REST cobrem o caminho UI manual + Stories 4a.4 (confirmar 1-clique)
 * e 4a.5 (BriefingDoDia rascunhos órfãos).
 *
 * Controller vazio é OBRIGATÓRIO. Sem este arquivo o EspoCRM 9.3 retorna
 * 404 em /api/v1/Prazo (confirmado nas Stories 3.1, 3.2, 3.4 e 3.6-magro).
 */
class Prazo extends \Espo\Core\Controllers\Record
{
}
