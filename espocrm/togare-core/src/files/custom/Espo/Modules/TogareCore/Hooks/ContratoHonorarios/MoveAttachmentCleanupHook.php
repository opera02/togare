<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\ContratoHonorarios;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Modules\TogareCore\Entities\ContratoHonorarios;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Hard-delete do Attachment temporário após save ContratoHonorarios OK (Story 6.1).
 *
 * Roda em AfterSave order=10 — só é chamado se o BeforeSave inteiro
 * (Validate=10 + DefaultUploadedBy=15 + Move=30) passou e save persistiu
 * o ContratoHonorarios.
 *
 * Idempotente — silent se Attachment já foi removido OU file já não existe.
 *
 * Pattern literal de MoveAttachmentToNextcloudHookCleanup (Documento).
 *
 * @implements AfterSave<ContratoHonorarios>
 */
final class MoveAttachmentCleanupHook implements AfterSave
{
    public static int $order = 10;

    public function __construct(
        private readonly EntityManager $entityManager,
    ) {
    }

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (! $entity instanceof ContratoHonorarios) {
            return;
        }

        if (! $entity->isNew()) {
            return;
        }

        $attachmentId = (string) ($entity->get('uploadedAttachmentId') ?? '');
        if ($attachmentId === '') {
            return;
        }

        $contratoId = (string) ($entity->getId() ?? 'unknown');
        MoveAttachmentToFileStorageHook::markUploadCommitted($contratoId);

        try {
            $attachment = $this->entityManager->getEntityById('Attachment', $attachmentId);
            if ($attachment !== null) {
                $this->entityManager->removeEntity($attachment);
            }
        } catch (\Throwable $e) {
            TogareLogger::event(
                'warning',
                'contrato.attachment_cleanup_failed',
                'MoveAttachmentCleanupHook: removeEntity falhou (não-bloqueante)',
                [
                    'attachmentId' => $attachmentId,
                    'contratoId' => $contratoId,
                    'error' => $e->getMessage(),
                ],
            );
        }

        // Também remove arquivo físico em data/upload/ (defensivo).
        $candidates = [
            'data/upload/' . $attachmentId,
            '/var/www/html/data/upload/' . $attachmentId,
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        TogareLogger::event(
            'info',
            'contrato.attachment_cleaned',
            'MoveAttachmentCleanupHook: Attachment temporário removido',
            ['attachmentId' => $attachmentId, 'contratoId' => $contratoId],
        );
    }
}
