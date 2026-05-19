<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\ContratoHonorarios;

use Espo\Core\Hook\Hook\AfterRemove;
use Espo\Core\Hook\Hook\AfterSave;
use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\Modules\TogareCore\Entities\ContratoHonorarios;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\RemoveOptions;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Audit AfterSave + AfterRemove em ContratoHonorarios (Story 6.1 — FR37, NFR10).
 *
 * Eventos:
 *  - contrato.created   — isNew
 *  - contrato.modified  — campos sensíveis mudaram (modalidade, valor,
 *    percentual, vigência, clienteId, assignedUserId)
 *  - contrato.removed   — AfterRemove (após o soft-purge da Decisão #5).
 *
 * Persiste em `togare_audit_log` via AuditLogContract (Story 2.4).
 * Try/catch \Throwable — audit nunca pode bloquear save/remove (regra FR37).
 *
 * Pattern literal de AuditDocumentoHook.
 *
 * @implements AfterSave<ContratoHonorarios>
 * @implements AfterRemove<ContratoHonorarios>
 */
final class AuditContratoHook implements AfterSave, AfterRemove
{
    public static int $order = 50;

    /** @var list<string> */
    private const SENSITIVE_FIELDS = [
        'modalidade',
        'valor',
        'percentual',
        'parcelamentoJson',
        'dataAssinatura',
        'vigenciaInicio',
        'vigenciaFim',
        'clienteId',
        'processosIds',
        'assignedUserId',
        'uploadedById',
    ];

    public function __construct(
        private readonly AuditLogContract $auditLog,
    ) {
    }

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (! $entity instanceof ContratoHonorarios) {
            return;
        }

        $contratoId = (string) ($entity->getId() ?? '');

        if ($entity->isNew()) {
            $this->safeLog(
                'contrato.created',
                $contratoId,
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
            'contrato.modified',
            $contratoId,
            [
                'clienteId' => (string) ($entity->get('clienteId') ?? ''),
                'modalidade' => (string) ($entity->get('modalidade') ?? ''),
                'changedFields' => $changed,
            ],
        );
    }

    public function afterRemove(Entity $entity, RemoveOptions $options): void
    {
        if (! $entity instanceof ContratoHonorarios) {
            return;
        }

        $contratoId = (string) ($entity->getId() ?? '');

        $this->safeLog(
            'contrato.removed',
            $contratoId,
            [
                'clienteId' => (string) ($entity->get('clienteId') ?? ''),
                'modalidade' => (string) $entity->get('modalidade'),
                'filename' => (string) $entity->get('filename'),
            ],
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function safeLog(string $event, string $contratoId, array $context): void
    {
        try {
            $this->auditLog->log($event, 'ContratoHonorarios', $contratoId, $context);
        } catch (\Throwable $e) {
            TogareLogger::event(
                'warning',
                'contrato.audit_log_failed',
                'AuditContratoHook: falha ao gravar audit log (não-bloqueante)',
                ['event' => $event, 'contratoId' => $contratoId, 'error' => $e->getMessage()],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCreatedContext(ContratoHonorarios $entity): array
    {
        return [
            'modalidade' => (string) $entity->get('modalidade'),
            'valor' => $entity->get('valor'),
            'percentual' => $entity->get('percentual'),
            'dataAssinatura' => (string) $entity->get('dataAssinatura'),
            'vigenciaInicio' => (string) $entity->get('vigenciaInicio'),
            'vigenciaFim' => (string) ($entity->get('vigenciaFim') ?? ''),
            'clienteId' => (string) ($entity->get('clienteId') ?? ''),
            'uploadedById' => (string) ($entity->get('uploadedById') ?? ''),
            'assignedUserId' => (string) ($entity->get('assignedUserId') ?? ''),
            'filename' => (string) $entity->get('filename'),
            'mimeType' => (string) $entity->get('mimeType'),
            'sizeBytes' => (int) $entity->get('sizeBytes'),
            'fileStorageUri' => (string) $entity->get('fileStorageUri'),
        ];
    }
}
