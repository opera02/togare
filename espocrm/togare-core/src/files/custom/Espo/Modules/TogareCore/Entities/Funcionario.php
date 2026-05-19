<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Entities;

use Espo\Core\ORM\Entity;
use Espo\Modules\TogareCore\Traits\TenantAwareEntity;

/**
 * Entidade Funcionario — registro básico de equipe do escritório
 * (Story 6.5, FR32 — Epic 9 "RH-lite" fundido em Epic 6).
 *
 * Tabela SQL: `funcionario` — ORM EspoCRM 9.3 deriva o nome diretamente do
 * entity type em snake_case (`Util::toUnderScore('Funcionario') === 'funcionario'`),
 * ignorando `additionalParams.tableName`. Documentado em ADR-02
 * (`docs/ADR-02-entity-naming-business.md`). NÃO há migration CREATE TABLE —
 * o `rebuild` do EspoCRM cria a tabela a partir do entityDefs.
 *
 * Escopo enxuto FR32: cadastro básico de equipe (nome, CPF, cargo, salário,
 * data de admissão) como ponte com RH/contador externo. SEM folha de
 * pagamento, ponto, férias ou CLT avançada. SEM stream social.
 *
 * Storage CPF sempre em SÓ DÍGITOS — normalização via
 * `Hooks\Funcionario\NormalizeFuncionarioCpfHook::beforeSave` (order 10).
 *
 * Validação CPF dupla (UX-DR12 / defesa em profundidade) — field view
 * `togare-core:views/fields/cpf-br` no cliente + `BrValidator` no servidor
 * via `Hooks\Funcionario\ValidateFuncionarioCpfHook::beforeSave` (order 20).
 *
 * Trait `TenantAwareEntity` aplicado conforme architecture L650 + Story 1a.9 —
 * `tenant_id` NULL no MVP single-tenant; scope ativo na Fase 2 SaaS.
 */
class Funcionario extends Entity
{
    use TenantAwareEntity;

    public const ENTITY_TYPE = 'Funcionario';
}
