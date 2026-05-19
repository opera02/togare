<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Entities;

use Espo\Core\ORM\Entity;
use Espo\Modules\TogareCore\Traits\TenantAwareEntity;

/**
 * Entidade Processo — núcleo operacional do escritório (Story 3.4, FR7, FR8).
 *
 * Tabela SQL: `processo` — ORM EspoCRM 9.3 deriva via
 * `Util::toUnderScore('Processo') === 'processo'` (ADR-02; mesmo padrão Cliente
 * e ParteContraria). NÃO usar `additionalParams.tableName`.
 *
 * Storage CNJ: 20 dígitos puros em `numero_cnj VARCHAR(25)` — `maxLength=25`
 * acomoda input mascarado (`'0001234-56.2023.8.26.0100'` = 25 chars), hook
 * `NormalizeCnjNumberHook` reduz para 20 dígitos antes do persist (architecture
 * L457 + Decisão #2 da story). UNIQUE em `numeroCnj`: número CNJ é globalmente
 * único no Brasil (Res. CNJ 65/2008).
 *
 * Validação CNJ dupla — `brValidators.js::isValidCnj` no cliente +
 * `CnjNumberValidator::isValid` no servidor via hook (UX-DR12).
 *
 * Hooks (ordem de execução beforeSave/afterSave):
 *  - togare-core/Hooks/Processo/NormalizeCnjNumberHook    (10) — strip mask + valida CNJ
 *  - togare-core/Hooks/Processo/ValidateProcessoFieldsHook (20) — enums + valor + datas
 *  - togare-tpu/Hooks/Processo/ResolveTpuFieldsHook        (30) — lookup TPU + denormaliza nomes
 *  - togare-core/Hooks/Processo/AuditProcessoHook          (50, AfterSave) — audit log
 *
 * Hook split entre togare-core (dona da entity + 3 hooks intrínsecos) e
 * togare-tpu (dona do hook lookup TPU). EspoCRM 9.3 hook scanner aceita
 * `Hooks/Processo/` em qualquer módulo (Decisão #3 da story; sem ciclo de dep).
 *
 * Links N:N:
 *  - clientes (hasMany Cliente, foreign processos, relationshipName ClienteProcesso)
 *  - partesContrarias (hasMany ParteContraria, foreign processos, relationshipName ParteContrariaProcesso)
 * Join tables `cliente_processo` e `parte_contraria_processo` criadas no
 * rebuild quando entityDefs dos 2 lados existirem (Decisão #5 da story).
 *
 * `assignedUser` (titular) — link belongsTo User. ACL by-assignment (FR11
 * restringir visibilidade aos atribuídos) é Story 3.5; por ora ACL `team`
 * para Advogado e `all` para Sócio/Admin.
 *
 * Trait `TenantAwareEntity` aplicado conforme architecture L650 + Story 1a.9 —
 * `tenant_id` NULL no MVP single-tenant.
 */
class Processo extends Entity
{
    use TenantAwareEntity;

    public const ENTITY_TYPE = 'Processo';

    public const AREA_CIVEL = 'civel';
    public const AREA_CRIMINAL = 'criminal';
    public const AREA_TRABALHISTA = 'trabalhista';
    public const AREA_TRIBUTARIO = 'tributario';
    public const AREA_ADMINISTRATIVO = 'administrativo';
    public const AREA_PREVIDENCIARIO = 'previdenciario';
    public const AREA_CONSUMIDOR = 'consumidor';
    public const AREA_FAMILIA = 'familia';
    public const AREA_EMPRESARIAL = 'empresarial';
    public const AREA_AMBIENTAL = 'ambiental';
    public const AREA_OUTRAS = 'outras';

    public const INSTANCIA_PRIMEIRA = 'primeira';
    public const INSTANCIA_SEGUNDA = 'segunda';
    public const INSTANCIA_SUPERIOR = 'superior';

    public const FASE_CONHECIMENTO = 'conhecimento';
    public const FASE_CUMPRIMENTO_SENTENCA = 'cumprimento_sentenca';
    public const FASE_EXECUCAO = 'execucao';
    public const FASE_RECURSAL = 'recursal';
    public const FASE_ARQUIVADO = 'arquivado';

    public const STATUS_ATIVO = 'ativo';
    public const STATUS_SUSPENSO = 'suspenso';
    public const STATUS_ARQUIVADO = 'arquivado';
    public const STATUS_BAIXADO = 'baixado';

    public const POLO_ATIVO = 'ativo';
    public const POLO_PASSIVO = 'passivo';
    public const POLO_TERCEIRO = 'terceiro';
}
