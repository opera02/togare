<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Controllers;

/**
 * Controller REST para a entidade Cliente (Story 3.1, FR6).
 *
 * Estende `Espo\Core\Controllers\Record` — expõe endpoints CRUD nativos:
 *  - GET    /api/v1/Cliente?orderBy=...&maxSize=... — listagem.
 *  - GET    /api/v1/Cliente/{id}                     — detalhe.
 *  - POST   /api/v1/Cliente                          — criar.
 *  - PUT    /api/v1/Cliente/{id}                     — editar.
 *  - DELETE /api/v1/Cliente/{id}                     — excluir.
 *
 * ACL aplicada via 8 roles seedados pelo togare-rbac (Story 2.1):
 *  - Sócio/Admin: all
 *  - Advogado:    team
 *  - Assistente:  team (read+edit+create — patch 0.6.1, FR6)
 *  - Secretária:  read=team (sem create — FR6 não autoriza, ver OQ1)
 *  - Financeiro:  read=team
 *  - Marketing/RH-lite/Cliente-portal: no
 *
 * Hooks beforeSave/afterSave registrados em `Hooks/Cliente/`:
 *  - NormalizeBrFieldsHook (10): só dígitos em CPF/CNPJ/CEP/telefone.
 *  - ValidateBrFieldsHook  (20): valida BR + regras PF↔PJ; lança BadRequest.
 *  - AuditClienteHook      (50): cliente.created / cliente.updated em
 *    togare_audit_log via AuditLogContract.
 */
class Cliente extends \Espo\Core\Controllers\Record
{
}
