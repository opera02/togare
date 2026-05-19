<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Entities;

use Espo\Core\ORM\Entity;
use Espo\Modules\TogareCore\Traits\TenantAwareEntity;

/**
 * Entidade ParteContraria — contraparte processual do escritório (Story 3.2, FR6, FR7).
 *
 * Tabela SQL: `parte_contraria` — ORM EspoCRM 9.3 deriva o nome diretamente do
 * entity type em snake_case (`Util::toUnderScore('ParteContraria') === 'parte_contraria'`),
 * ignorando `additionalParams.tableName`. Documentado em ADR-02.
 *
 * Campos comuns + grupo PF (cpf) + grupo PJ (cnpj) controlados via `tipoPessoa`
 * enum (pf | pj | desconhecida) + dynamic logic em clientDefs. CPF e CNPJ são
 * OPCIONAIS em todos os tipos — diferença crítica em relação a Cliente (3.1),
 * onde são obrigatórios. O tipo `desconhecida` permite cadastrar parte sem
 * documento (raça desconhecida em ações de massa, partes anônimas, etc.).
 *
 * Storage CPF/CNPJ/telefone sempre em SÓ DÍGITOS — normalização via
 * `Hooks\ParteContraria\NormalizeBrFieldsHook::beforeSave` (architecture L457).
 *
 * Validação BR dupla — `validation-br` no cliente + `BrValidator` no servidor
 * via `Hooks\ParteContraria\ValidateBrFieldsHook::beforeSave` (UX-DR12). Quando
 * informados, CPF/CNPJ devem ter DV válido. Regras de exclusividade:
 *  - tipoPessoa=pf  → CNPJ vazio
 *  - tipoPessoa=pj  → CPF vazio
 *  - tipoPessoa=desconhecida → CPF e CNPJ vazios
 *
 * Link N:N → Processo declarado em entityDef. Join table `parte_contraria_processo`
 * é criada pelo ORM no rebuild quando Processo existir (Story 3.4). Painel
 * "Processos" na detail view aparece vazio até lá — comportamento esperado.
 *
 * Trait `TenantAwareEntity` aplicado conforme architecture L650 + Story 1a.9 —
 * `tenant_id` NULL no MVP single-tenant; scope ativo na Fase 2 SaaS.
 *
 * Sem UNIQUE em CPF/CNPJ — a mesma pessoa pode ser parte em múltiplos processos
 * e o usuário pode duplicar registros intencionalmente (partes homônimas).
 * Contraste com Cliente (3.1 P9) que tem UNIQUE.
 */
class ParteContraria extends Entity
{
    use TenantAwareEntity;

    public const ENTITY_TYPE = 'ParteContraria';

    public const TIPO_PF = 'pf';
    public const TIPO_PJ = 'pj';
    public const TIPO_DESCONHECIDA = 'desconhecida';
}
