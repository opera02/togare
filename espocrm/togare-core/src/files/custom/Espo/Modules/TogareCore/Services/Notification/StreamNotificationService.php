<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services\Notification;

use Espo\Modules\TogareCore\Contracts\NotificationContract;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Implementacao de canal `stream` (in-app via Notification nativa do EspoCRM).
 */
class StreamNotificationService implements NotificationContract
{
    public function __construct(
        private readonly EntityManager $entityManager,
    ) {
    }

    public function notify(
        string $userId,
        string $subject,
        string $body,
        string $channel = NotificationContract::CHANNEL_STREAM,
    ): void {
        if ($channel !== NotificationContract::CHANNEL_STREAM) {
            throw new \InvalidArgumentException(
                "StreamNotificationService so aceita canal 'stream', recebido: '{$channel}'"
            );
        }

        if ($userId === '') {
            throw new \InvalidArgumentException('StreamNotificationService.notify: userId nao pode ser vazio.');
        }

        $this->createNotification($userId, $subject, $body);
    }

    public function notifyPrazoReminder(
        string $userId,
        string $subject,
        string $body,
        string $prazoId,
        string $marco,
    ): void {
        if ($userId === '') {
            throw new \InvalidArgumentException('StreamNotificationService.notifyPrazoReminder: userId nao pode ser vazio.');
        }
        if ($prazoId === '') {
            throw new \InvalidArgumentException('StreamNotificationService.notifyPrazoReminder: prazoId nao pode ser vazio.');
        }

        $this->createNotification($userId, $subject, $body, $prazoId, $marco);
    }

    private function createNotification(
        string $userId,
        string $subject,
        string $body,
        ?string $prazoId = null,
        ?string $marco = null,
    ): void {
        try {
            $notification = $this->entityManager->getNewEntity('Notification');
            if ($notification === null) {
                throw new \RuntimeException('EntityManager.getNewEntity(Notification) retornou null.');
            }

            $attributes = [
                'type' => 'Message',
                'userId' => $userId,
                'message' => $subject . "\n" . $body,
                'data' => [
                    'message' => $subject,
                    'body' => $body,
                    'origin' => 'togare.prazo.reminder',
                ],
            ];

            if ($prazoId !== null && $prazoId !== '') {
                $attributes['relatedType'] = 'Prazo';
                $attributes['relatedId'] = $prazoId;
                $attributes['data']['prazoId'] = $prazoId;
            }

            if ($marco !== null && $marco !== '') {
                $attributes['data']['marco'] = $marco;
            }

            $notification->set($attributes);
            $this->entityManager->saveEntity($notification);
        } catch (Throwable $e) {
            TogareLogger::event(
                'warning',
                'notification.stream.failed',
                'StreamNotificationService.notify falhou.',
                ['userId' => $userId, 'error' => $e->getMessage()],
            );
            throw new \RuntimeException(
                'StreamNotificationService falhou: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }
}
