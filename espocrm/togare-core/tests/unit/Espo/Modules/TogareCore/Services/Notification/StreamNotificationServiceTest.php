<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Services\Notification;

use Espo\Modules\TogareCore\Contracts\NotificationContract;
use Espo\Modules\TogareCore\Services\Notification\StreamNotificationService;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

/**
 * Cobre StreamNotificationService (Story 4b.2, AC3).
 */
final class StreamNotificationServiceTest extends TestCase
{
    public function testNotifyCriaNotificationEntityComCamposEsperados(): void
    {
        $captured = null;

        $notif = new \Espo\Core\ORM\Entity();

        $em = $this->createMock(EntityManager::class);
        $em->method('getNewEntity')->with('Notification')->willReturn($notif);
        $em->expects(self::once())->method('saveEntity')
            ->with(self::callback(function ($e) use (&$captured) {
                $captured = $e;
                return true;
            }));

        $service = new StreamNotificationService($em);
        $service->notify('user-001', 'Vence em 3 dias', 'CNJ 0000000-00.2024.8.26.0001');

        self::assertSame($notif, $captured);
        self::assertSame('Message', $notif->get('type'));
        self::assertSame('user-001', $notif->get('userId'));
        self::assertStringContainsString('Vence em 3 dias', (string) $notif->get('message'));
        $data = $notif->get('data');
        self::assertIsArray($data);
        self::assertSame('Vence em 3 dias', $data['message']);
        self::assertSame('togare.prazo.reminder', $data['origin']);
    }

    public function testNotifyPrazoReminderIncluiRelacionamentoEContextoDoPrazo(): void
    {
        $captured = null;

        $notif = new \Espo\Core\ORM\Entity();

        $em = $this->createMock(EntityManager::class);
        $em->method('getNewEntity')->with('Notification')->willReturn($notif);
        $em->expects(self::once())->method('saveEntity')
            ->with(self::callback(function ($e) use (&$captured) {
                $captured = $e;
                return true;
            }));

        $service = new StreamNotificationService($em);
        $service->notifyPrazoReminder('user-001', 'Vence em 3 dias', 'CNJ 000', 'prazo-001', 'D-3');

        self::assertSame($notif, $captured);
        self::assertSame('Prazo', $notif->get('relatedType'));
        self::assertSame('prazo-001', $notif->get('relatedId'));
        $data = $notif->get('data');
        self::assertIsArray($data);
        self::assertSame('prazo-001', $data['prazoId']);
        self::assertSame('D-3', $data['marco']);
    }

    public function testNotifyComCanalErradoLancaInvalidArgument(): void
    {
        $em = $this->createMock(EntityManager::class);
        $service = new StreamNotificationService($em);

        $this->expectException(\InvalidArgumentException::class);
        $service->notify('user-001', 's', 'b', NotificationContract::CHANNEL_EMAIL);
    }

    public function testNotifyComUserIdVazioLancaInvalidArgument(): void
    {
        $em = $this->createMock(EntityManager::class);
        $service = new StreamNotificationService($em);

        $this->expectException(\InvalidArgumentException::class);
        $service->notify('', 's', 'b');
    }

    public function testNotifyEnvelopaFalhaDoEntityManagerComoRuntimeException(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->method('getNewEntity')->willReturn(new \Espo\Core\ORM\Entity());
        $em->method('saveEntity')->willThrowException(new \RuntimeException('DB down'));

        $service = new StreamNotificationService($em);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/StreamNotificationService falhou/');
        $service->notify('user-001', 's', 'b');
    }

    public function testNotifyComNotificationEntityNullLancaRuntimeException(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->method('getNewEntity')->willReturn(null);

        $service = new StreamNotificationService($em);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/getNewEntity.*null/');
        $service->notify('user-001', 's', 'b');
    }
}
