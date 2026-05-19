<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Entities;

use Espo\Core\ORM\Entity;
use Espo\Modules\TogareCore\Traits\TenantAwareEntity;

/**
 * Entidade ContratoHonorarios — Story 6.1 (FR22 + Art. 22 §4º Estatuto OAB).
 *
 * Tabela SQL `contrato_honorarios` (snake_case via Util::toUnderScore — ADR-02;
 * pattern Cliente/Processo/Documento).
 *
 * Nome em PascalCase sem prefixo Togare segue pattern interno togare-core
 * (Cliente/Processo/Documento/ParteContraria/Prazo/Audiencia/PublicacaoAmbigua).
 *
 * Story 6.1 entrega entity + 6 Hooks + Migration V020 (audit log auxiliar
 * togare_contrato_honorarios_log — pattern V018 togare_documento_log) +
 * Controller stub download (X-Accel-Redirect via mesma lógica Documento 5.3 —
 * duplicação aceita por simplicidade; extração futura) + 4 views frontend
 * (upload-modal + record/detail + record/list + row-actions) + RBAC patch
 * via togare-rbac V008 + integração com FileStorageContract::buildUri()
 * polimórfico (Decisão #2 da spec — primeira entity Togare a consumir buildUri
 * agnóstico desde D0; Documento Epic 5 permanece com URI_SCHEME 'nextcloud://'
 * hardcoded por escopo OUT da 6.0 Decisão #7).
 *
 * Estrutura de relacionamentos (Decisão #3 / Discovery #1 retro Epic 5):
 *  - N:1 Cliente OBRIGATÓRIO (semântica jurídica — contrato é sempre de UM cliente)
 *  - N:N Processos via relationship `ContratoHonorariosProcesso` (0..N — contrato
 *    cobre ZERO ou mais processos do mesmo cliente; um Processo pode ter múltiplos
 *    contratos ao longo do tempo)
 *  - **NÃO é XOR** (contraposição explícita ao Documento da 5.6)
 *
 * Modalidade (Decisão #4) enum 6 valores snake_case sem acentos:
 *  - fixo            — valor absoluto único
 *  - exito           — percentual sobre êxito
 *  - sucumbencia     — verba sucumbencial (percentual)
 *  - mensal          — valor mensal recorrente
 *  - hora_trabalhada — valor por hora
 *  - misto           — combinação (fixo + percentual)
 *
 * Validação valor vs percentual (Decisão #5):
 *  - modalidade ∈ {fixo, mensal, hora_trabalhada}     → valor obrigatório, percentual NULL
 *  - modalidade ∈ {exito, sucumbencia}                → percentual obrigatório (0 < x ≤ 100), valor NULL
 *  - modalidade = misto                               → AMBOS obrigatórios
 *
 * Vigência (Decisão #8):
 *  - vigenciaInicio obrigatória
 *  - vigenciaFim NULLABLE (contratos abertos sem prazo definido — comum em
 *    modalidade=exito ou mensal de prazo indefinido)
 *  - dataAssinatura obrigatória; pode ser ANTES de vigenciaInicio (contrato
 *    assinado em jan/2026 vigora a partir de fev/2026 — caso comum)
 *
 * Logical path (Decisão #9): `clientes/<clienteId>/contratos/<contratoId>-<filename>`.
 * NÃO usa buckets {processos,clientes,prazos} do Documento — contrato é SEMPRE de
 * Cliente (single-context), sem alternativa.
 *
 * URI persistida em `fileStorageUri` (NÃO `nextcloudUri` — Decisão #2 reforça
 * semântica agnóstica):
 *  - bridge instalado → `nextcloud://clientes/<id>/contratos/<contratoId>-<file>`
 *  - bridge ausente   → `local://clientes/<id>/contratos/<contratoId>-<file>`
 *
 * Hooks (ordem):
 *  - ValidateContratoFieldsHook       (BeforeSave, order=10) — modalidade/valor/percentual + vigência + processos cross-cliente + popular meta
 *  - DefaultUploadedByHook            (BeforeSave, order=15) — uploadedBy + assignedUser herdado de cliente.assignedUser
 *  - MoveAttachmentToFileStorageHook  (BeforeSave, order=30) — FileStorageContract::put + buildUri agnóstico + compensation
 *  - MoveAttachmentCleanupHook        (AfterSave, order=10) — hard-delete Attachment temp
 *  - SoftPurgeContratoHook            (BeforeRemove + AfterRemove, order=20) — softPurge + log V020
 *  - AuditContratoHook                (AfterSave + AfterRemove, order=50) — audit log via AuditLogContract
 *
 * Trait `TenantAwareEntity` aplicado conforme architecture L650 + Story 1a.9.
 *
 * RBAC (Decisão #10) — togare-rbac V008:
 *  - Sócio/Admin + Financeiro: all
 *  - Advogado + Assistente/Estagiário: own (assignedUser = current; populado
 *    pelo DefaultUploadedByHook via cliente.assignedUserId)
 *  - Secretária + Marketing + RH-lite: no (blindagem cruzada FR3)
 *  - Cliente-portal: no (aclPortal=false)
 */
class ContratoHonorarios extends Entity
{
    use TenantAwareEntity;

    public const ENTITY_TYPE = 'ContratoHonorarios';

    /**
     * Modalidades canônicas (Decisão #4 da Story 6.1).
     * Labels pt-BR vivem em i18n/pt_BR/ContratoHonorarios.json::options.modalidade.
     */
    public const MODALIDADES = [
        'fixo',
        'exito',
        'sucumbencia',
        'mensal',
        'hora_trabalhada',
        'misto',
    ];

    /**
     * Modalidades que exigem `valor` (Decisão #5).
     */
    public const MODALIDADES_COM_VALOR = [
        'fixo',
        'mensal',
        'hora_trabalhada',
        'misto',
    ];

    /**
     * Modalidades que exigem `percentual` (Decisão #5).
     */
    public const MODALIDADES_COM_PERCENTUAL = [
        'exito',
        'sucumbencia',
        'misto',
    ];

    /** PDF obrigatório — Decisão #11 da spec. */
    public const ALLOWED_MIME_TYPES = [
        'application/pdf',
    ];

    /** Pattern Documento — Decisão #7 da 5.2. */
    public const MAX_FILENAME_LENGTH = 100;

    /** Pattern Documento — Decisão #7 da 5.2. */
    public const FILENAME_FALLBACK = 'arquivo';

    /** Role do Attachment temporário (mesmo pattern Documento). */
    public const ATTACHMENT_ROLE = 'Attachment';

    /**
     * Verifica se um contrato é vigente em uma data de referência.
     *
     * Regra (Decisão #8): `vigenciaInicio <= reference AND (vigenciaFim IS NULL OR vigenciaFim >= reference)`.
     *
     * NOTA: este é um getter puro auxiliar — usado por ContratoHonorariosLookupService
     * e por testes. Não persiste o status.
     */
    public function isVigente(?\DateTimeImmutable $reference = null): bool
    {
        $reference = $reference ?? new \DateTimeImmutable('today');
        $refDate = $reference->format('Y-m-d');

        $inicio = (string) ($this->get('vigenciaInicio') ?? '');
        if ($inicio === '') {
            return false;
        }
        if ($inicio > $refDate) {
            return false;
        }

        $fim = (string) ($this->get('vigenciaFim') ?? '');
        if ($fim === '') {
            return true; // contrato aberto sem prazo
        }

        return $fim >= $refDate;
    }
}
