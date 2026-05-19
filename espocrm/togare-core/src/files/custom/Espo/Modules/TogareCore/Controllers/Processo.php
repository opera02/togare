<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Controllers;

/**
 * Controller REST para a entidade Processo (Story 3.4, FR7, FR8).
 *
 * Estende `Espo\Core\Controllers\Record` — expõe endpoints CRUD nativos:
 *  - GET    /api/v1/Processo?orderBy=...&maxSize=... — listagem.
 *  - GET    /api/v1/Processo/{id}                     — detalhe.
 *  - POST   /api/v1/Processo                          — criar.
 *  - PUT    /api/v1/Processo/{id}                     — editar.
 *  - DELETE /api/v1/Processo/{id}                     — excluir.
 *  - POST   /api/v1/Processo/{id}/clientes            — vincular cliente N:N.
 *  - POST   /api/v1/Processo/{id}/partesContrarias    — vincular parte contrária N:N.
 *
 * ACL aplicada via 8 roles seedados pelo togare-rbac (Story 2.1) + patch
 * 0.6.3 (Story 3.4 — rename "Process" → "Processo"):
 *  - Sócio/Admin: all
 *  - Advogado:    {read=own, edit=own, create=team} — ACL by-assignment (FR11, Story 3.5)
 *  - Assistente:  {read=team, edit=team, create=team} — FR7
 *  - Secretária:  {read=team} (read-only para apoio operacional)
 *  - Financeiro:  {read=team} (vincula cobrança)
 *  - Marketing/RH-lite/Cliente-portal: no
 *
 * Hooks beforeSave/afterSave registrados:
 *  - EnforceAssignmentPolicyHook  (togare-core,  5, BeforeSave): ACL by-assignment FR11 + auto-titular
 *  - NormalizeCnjNumberHook       (togare-core, 10): strip mask + valida CNJ DV
 *  - ValidateProcessoFieldsHook   (togare-core, 20): enums + valor causa + datas
 *  - ResolveTpuFieldsHook         (togare-tpu,  30): lookup classe/assunto/movimento + denormaliza
 *  - AuditProcessoHook            (togare-core, 50, AfterSave): audit log via AuditLogContract
 *
 * Hook em togare-tpu (cross-module via hook scanner) — sem ciclo de dep
 * (Decisão #3 da story). Se togare-tpu não estiver instalado, hook não
 * existe → save passa sem validação TPU; campos *Nome ficam null
 * (degradação graciosa documentada em README).
 *
 * Controller vazio é OBRIGATÓRIO. Sem este arquivo o EspoCRM 9.3 retorna
 * 404 em /api/v1/Processo (confirmado nas Stories 3.1 e 3.2).
 */
class Processo extends \Espo\Core\Controllers\Record
{
}
