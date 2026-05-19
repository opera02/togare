<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Entities;

use Espo\Core\ORM\Entity;
use Espo\Modules\TogareCore\Traits\TenantAwareEntity;

/**
 * Entidade Cliente — Pessoa Física ou Jurídica do escritório (Story 3.1, FR6).
 *
 * Tabela SQL: `cliente` — ORM EspoCRM 9.3 deriva o nome diretamente do entity type
 * em snake_case (`Util::toUnderScore('Cliente') === 'cliente'`), ignorando
 * `additionalParams.tableName`. Documentado em ADR-02
 * (`docs/ADR-02-entity-naming-business.md`).
 *
 * Campos comuns + grupo PF (cpf, rg, dataNascimento, estadoCivil, nacionalidade,
 * profissao) + grupo PJ (cnpj, razaoSocial, nomeFantasia, inscricaoEstadual)
 * controlados via `tipoPessoa` enum + dynamic logic em clientDefs.
 *
 * Storage CPF/CNPJ/CEP/telefone sempre em SÓ DÍGITOS — normalização via
 * `Hooks\Cliente\NormalizeBrFieldsHook::beforeSave` (architecture L457).
 *
 * Validação BR dupla — `validation-br` no cliente + `BrValidator` no servidor
 * via `Hooks\Cliente\ValidateBrFieldsHook::beforeSave` (UX-DR12).
 *
 * Trait `TenantAwareEntity` aplicado conforme architecture L650 + Story 1a.9 —
 * `tenant_id` NULL no MVP single-tenant; scope ativo na Fase 2 SaaS.
 */
class Cliente extends Entity
{
    use TenantAwareEntity;

    public const ENTITY_TYPE = 'Cliente';

    public const TIPO_PF = 'pf';
    public const TIPO_PJ = 'pj';
}
