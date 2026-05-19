<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Traits;

/**
 * Marker trait para entidades de negócio Togare.
 *
 * Toda entidade que armazena dados operacionais do escritório (Cliente,
 * Processo, Prazo, Audiência, Contrato, Fatura, Lead, Funcionário, etc.)
 * deve usar este trait.
 *
 * Regras que devem ser seguidas junto com o trait:
 *   1. Declare `tenant_id` no entityDef JSON da entidade:
 *      { "type": "varchar", "len": 40, "notNull": false }
 *   2. No MVP o campo fica NULL (single-tenant). Fase 2 (SaaS) ativa scoping
 *      via TenantContextResolverContract.
 *
 * Entidades de infraestrutura (QueueItem, AuditLog, MfaBackupCode, etc.)
 * são isentas — veja INFRASTRUCTURE_ENTITIES em TenantAwareEntityTest.
 *
 * O test TenantAwareEntityTest varre o monorepo e falha o build se
 * alguma entidade em Entities/ não usar este trait sem estar na lista
 * de infraestrutura (com justificativa).
 */
trait TenantAwareEntity
{
    // Marker — sem lógica runtime no MVP.
}
