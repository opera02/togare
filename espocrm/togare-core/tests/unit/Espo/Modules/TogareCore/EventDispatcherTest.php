<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore;

use DateTimeImmutable;
use Espo\Modules\TogareCore\Events\EntityPurgedEvent;
use Espo\Modules\TogareCore\Events\EventDispatcher;
use Espo\Modules\TogareCore\Events\IntegrationFailedEvent;
use PHPUnit\Framework\TestCase;

final class EventDispatcherTest extends TestCase
{
    public function testDispatchesToRegisteredListener(): void
    {
        $dispatcher = new EventDispatcher();
        $received = [];

        $dispatcher->subscribe(EntityPurgedEvent::class, static function (EntityPurgedEvent $e) use (&$received): void {
            $received[] = $e;
        });

        $event = new EntityPurgedEvent('Cliente', 'abc123', new DateTimeImmutable('2026-04-23T10:00:00-03:00'));
        $dispatcher->dispatch($event);

        self::assertCount(1, $received);
        self::assertSame('Cliente', $received[0]->entityType);
        self::assertSame('abc123', $received[0]->entityId);
    }

    public function testSilentNoOpWithoutListener(): void
    {
        $dispatcher = new EventDispatcher();

        // Sem subscribe — dispatch não deve lançar nem emitir warning.
        $dispatcher->dispatch(new IntegrationFailedEvent(
            'djen',
            'timeout',
            new DateTimeImmutable(),
        ));

        self::assertTrue(true); // chegou aqui = sem exceção
    }

    public function testSubscribeIsPerEventClassExact(): void
    {
        $dispatcher = new EventDispatcher();
        $receivedPurged = 0;
        $receivedFailed = 0;

        $dispatcher->subscribe(EntityPurgedEvent::class, static function () use (&$receivedPurged): void {
            $receivedPurged++;
        });
        $dispatcher->subscribe(IntegrationFailedEvent::class, static function () use (&$receivedFailed): void {
            $receivedFailed++;
        });

        $dispatcher->dispatch(new EntityPurgedEvent('X', '1', new DateTimeImmutable()));
        $dispatcher->dispatch(new IntegrationFailedEvent('y', 'z', new DateTimeImmutable()));
        $dispatcher->dispatch(new EntityPurgedEvent('X', '2', new DateTimeImmutable()));

        self::assertSame(2, $receivedPurged);
        self::assertSame(1, $receivedFailed);
    }
}
