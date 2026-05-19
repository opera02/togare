<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\Documento;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Modules\TogareCore\Entities\Documento;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Hard-delete do Attachment temporário após save Documento OK (Story 5.2).
 *
 * Roda em AfterSave order=10 — só é chamado se o BeforeSave inteiro
 * (Validate=10 + DefaultUploadedBy=15 + Move=30) passou e save persistiu
 * o Documento.
 *
 * Idempotente — silent se Attachment já foi removido OU file já não existe.
 *
 * @implements AfterSave<Documento>
 */
final class MoveAttachmentToNextcloudHookCleanup implements AfterSave
{
    public static int $order = 10;

    public function __construct(
        private readonly EntityManager $entityManager,
    ) {
    }

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (! $entity instanceof Documento) {
            return;
        }

        if (! $entity->isNew()) {
            return;
        }

        $attachmentId = (string) ($entity->get('uploadedAttachmentId') ?? '');
        if ($attachmentId === '') {
            return;
        }

        $documentoId = (string) ($entity->getId() ?? 'unknown');
        MoveAttachmentToNextcloudHook::markUploadCommitted($documentoId);

        try {
            $attachment = $this->entityManager->getEntityById('Attachment', $attachmentId);
            if ($attachment !== null) {
                $this->entityManager->removeEntity($attachment);
            }
        } catch (\Throwable $e) {
            TogareLogger::event(
                'warning',
                'documento.attachment_cleanup_failed',
                'MoveAttachmentToNextcloudHookCleanup: removeEntity falhou (não-bloqueante)',
                [
                    'attachmentId' => $attachmentId,
                    'documentoId' => $documentoId,
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
            'documento.attachment_cleaned',
            'MoveAttachmentToNextcloudHookCleanup: Attachment temporário removido',
            ['attachmentId' => $attachmentId, 'documentoId' => $documentoId],
        );
    }
}
