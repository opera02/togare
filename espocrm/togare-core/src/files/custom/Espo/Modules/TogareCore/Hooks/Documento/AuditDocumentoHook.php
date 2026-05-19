<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\Documento;

use Espo\Core\Hook\Hook\AfterRemove;
use Espo\Core\Hook\Hook\AfterSave;
use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\Modules\TogareCore\Entities\Documento;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\RemoveOptions;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Audit AfterSave + AfterRemove em Documento (Story 5.2 — FR37, NFR10).
 *
 * Eventos:
 *  - documento.created   — isNew
 *  - documento.modified  — campos sensíveis mudaram
 *  - documento.removed   — AfterRemove (após o soft-purge da Decisão #10).
 *
 * Persiste em `togare_audit_log` via AuditLogContract (Story 2.4).
 * Try/catch \Throwable — audit nunca pode bloquear save/remove (regra FR37).
 *
 * @implements AfterSave<Documento>
 * @implements AfterRemove<Documento>
 */
final class AuditDocumentoHook implements AfterSave, AfterRemove
{
    public static int $order = 50;

    /** @var list<string> */
    private const SENSITIVE_FIELDS = [
        'filename',
        'mimeType',
        'sizeBytes',
        'processoId',
        'clienteId',
        'assignedUserId',
        'uploadedById',
    ];

    public function __construct(
        private readonly AuditLogContract $auditLog,
    ) {
    }

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (! $entity instanceof Documento) {
            return;
        }

        $documentoId = (string) ($entity->getId() ?? '');

        if ($entity->isNew()) {
            $this->safeLog(
                'documento.created',
                $documentoId,
                $this->buildCreatedContext($entity),
            );
            return;
        }

        $changed = [];
        foreach (self::SENSITIVE_FIELDS as $field) {
            if ($entity->isAttributeChanged($field)) {
                $changed[] = $field;
            }
        }

        if ($changed === []) {
            return;
        }

        $this->safeLog(
            'documento.modified',
            $documentoId,
            [
                'processoId' => (string) ($entity->get('processoId') ?? ''),
                'clienteId' => (string) ($entity->get('clienteId') ?? ''),
                'changedFields' => $changed,
            ],
        );
    }

    public function afterRemove(Entity $entity, RemoveOptions $options): void
    {
        if (! $entity instanceof Documento) {
            return;
        }

        $documentoId = (string) ($entity->getId() ?? '');

        $this->safeLog(
            'documento.removed',
            $documentoId,
            [
                'processoId' => (string) ($entity->get('processoId') ?? ''),
                'clienteId' => (string) ($entity->get('clienteId') ?? ''),
                'filename' => (string) $entity->get('filename'),
                'mimeType' => (string) $entity->get('mimeType'),
            ],
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function safeLog(string $event, string $documentoId, array $context): void
    {
        try {
            $this->auditLog->log($event, 'Documento', $documentoId, $context);
        } catch (\Throwable $e) {
            TogareLogger::event(
                'warning',
                'documento.audit_log_failed',
                'AuditDocumentoHook: falha ao gravar audit log (não-bloqueante)',
                ['event' => $event, 'documentoId' => $documentoId, 'error' => $e->getMessage()],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCreatedContext(Documento $entity): array
    {
        return [
            'filename' => (string) $entity->get('filename'),
            'mimeType' => (string) $entity->get('mimeType'),
            'sizeBytes' => (int) $entity->get('sizeBytes'),
            'processoId' => (string) ($entity->get('processoId') ?? ''),
            'clienteId' => (string) ($entity->get('clienteId') ?? ''),
            'uploadedById' => (string) ($entity->get('uploadedById') ?? ''),
            'assignedUserId' => (string) ($entity->get('assignedUserId') ?? ''),
            'nextcloudUri' => (string) $entity->get('nextcloudUri'),
        ];
    }
}
