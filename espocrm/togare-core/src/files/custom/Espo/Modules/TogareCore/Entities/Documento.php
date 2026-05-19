<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Entities;

use Espo\Core\ORM\Entity;
use Espo\Modules\TogareCore\Traits\TenantAwareEntity;

/**
 * Entidade Documento — Story 5.2 (FR19, FR20; FR21 listagem; NFR26).
 *
 * Tabela SQL `documento` (snake_case via Util::toUnderScore — ADR-02;
 * pattern Cliente/ParteContraria/Processo/Audiencia/Prazo/PublicacaoAmbigua).
 *
 * Nome em pt-BR sem prefixo Togare evita colisão com a entity nativa
 * `Document` (Knowledge Base) do EspoCRM. Convive com a tabela `document`
 * stock — nomes distintos.
 *
 * Story 5.2 entrega entity + 6 Hooks + Migration V018 (audit log auxiliar)
 * + Controller stub download (501 — Story 5.3 substitui pelo proxy real)
 * + 3 views frontend + RBAC patch 8 roles + integração com FileStorageContract
 * (bound a NextcloudFileStorage da Story 5.1).
 *
 * XOR rígido triplo entre processo, cliente e prazo (Story 5.6 — Decisão #1):
 *  - EXATAMENTE UM dos três deve estar setado.
 *  - Todos null → BadRequest pt-BR.
 *  - Dois ou mais preenchidos → BadRequest pt-BR.
 *
 * Hooks (ordem):
 *  - ValidateDocumentoFieldsHook  (BeforeSave, order=10) — XOR triplo + MIME + size
 *  - DefaultUploadedByHook        (BeforeSave, order=15) — uploadedBy = user atual
 *  - MoveAttachmentToNextcloudHook(BeforeSave, order=30) — lê Attachment + put no Nextcloud
 *  - MoveAttachmentToNextcloudHookCleanup (AfterSave, order=10) — hard-delete Attachment
 *  - SoftPurgeDocumentoHook       (BeforeRemove, order=20) — softPurge bridge + log V018
 *  - AuditDocumentoHook           (AfterSave + AfterRemove, order=50) — audit log
 *
 * URI lógica: `nextcloud://<bucket>/<entityId>/<documentoId>-<filename>`
 *  - bucket = 'processos', 'clientes' ou 'prazos' (Story 5.6 — BUCKET_PRAZOS).
 *  - entityId = processoId, clienteId ou prazoId.
 *  - documentoId = Espo Entity ID (gerado em beforeSave).
 *  - filename = sanitização de DocumentoLogicalPathBuilder::sanitizeFilename().
 *
 * Trait `TenantAwareEntity` aplicado conforme architecture L650 + Story 1a.9.
 */
class Documento extends Entity
{
    use TenantAwareEntity;

    public const ENTITY_TYPE = 'Documento';

    /** Story 5.2 — MIME types permitidos (Decisão #5; allowlist server-side). */
    public const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // DOCX
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',       // XLSX
        'application/msword',                                                       // DOC legacy
        'application/vnd.ms-excel',                                                 // XLS legacy
        'image/png',
        'image/jpeg',
        'image/tiff',
        'text/plain',
    ];

    /** Story 5.2 — máximo de filename sanitizado (Decisão #7). */
    public const MAX_FILENAME_LENGTH = 100;

    /** Story 5.2 — fallback de basename quando sanitização zera tudo. */
    public const FILENAME_FALLBACK = 'arquivo';

    /** Story 5.2 — buckets do logical path (Decisão #4); Story 5.6 adiciona PRAZOS. */
    public const BUCKET_PROCESSOS = 'processos';
    public const BUCKET_CLIENTES = 'clientes';
    public const BUCKET_PRAZOS = 'prazos';

    /** Story 5.2 — prefixo da URI lógica (Decisão #4 — sem host, sem user). */
    public const URI_SCHEME = 'nextcloud://';

    /** Story 5.2 — role do Attachment temporário (deve bater com Attachment::ROLE_ATTACHMENT). */
    public const ATTACHMENT_ROLE = 'Attachment';
}
