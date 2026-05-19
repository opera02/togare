<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Controllers;

/**
 * Controller REST para a entidade ParteContraria (Story 3.2, FR6, FR7).
 *
 * Estende `Espo\Core\Controllers\Record` — expõe endpoints CRUD nativos:
 *  - GET    /api/v1/ParteContraria?orderBy=...&maxSize=... — listagem.
 *  - GET    /api/v1/ParteContraria/{id}                     — detalhe.
 *  - POST   /api/v1/ParteContraria                          — criar.
 *  - PUT    /api/v1/ParteContraria/{id}                     — editar.
 *  - DELETE /api/v1/ParteContraria/{id}                     — excluir.
 *
 * ACL aplicada via 8 roles seedados pelo togare-rbac (Story 2.1) + patch
 * 0.6.2 (Story 3.2):
 *  - Sócio/Admin: all
 *  - Advogado:    team
 *  - Assistente:  team (read+edit+create após patch 0.6.2; sem delete — FR7)
 *  - Secretária:  read=team (sem create — FR7 não autoriza)
 *  - Financeiro:  read=team
 *  - Marketing/RH-lite/Cliente-portal: no
 *
 * Hooks beforeSave/afterSave registrados em `Hooks/ParteContraria/`:
 *  - NormalizeBrFieldsHook    (10): só dígitos em CPF/CNPJ/telefone.
 *  - ValidateBrFieldsHook     (20): valida BR + regras PF↔PJ↔desconhecida; lança BadRequest.
 *  - AuditParteContrariaHook  (50): parte_contraria.created / parte_contraria.modified em
 *    togare_audit_log via AuditLogContract.
 *
 * Controller vazio é OBRIGATÓRIO (não pode ser omitido). Sem este arquivo o
 * EspoCRM 9.3 retorna 404 em /api/v1/ParteContraria — confirmado empiricamente
 * na Story 3.1.
 */
class ParteContraria extends \Espo\Core\Controllers\Record
{
}
