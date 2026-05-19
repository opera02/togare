<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Events;

use Espo\Modules\TogareCore\Contracts\EventBusContract;

/**
 * Implementação padrão do EventBusContract.
 *
 * Design mínimo — síncrono, in-process, sem hierarquia. Ver docblock do
 * contrato para trade-offs aceitos.
 */
final class EventDispatcher implements EventBusContract
{
    /**
     * @var array<class-string, list<callable>>
     */
    private array $listeners = [];

    public function dispatch(object $event): void
    {
        $eventClass = $event::class;
        if (! isset($this->listeners[$eventClass])) {
            return;
        }

        foreach ($this->listeners[$eventClass] as $listener) {
            $listener($event);
        }
    }

    public function subscribe(string $eventClass, callable $listener): void
    {
        if (! isset($this->listeners[$eventClass])) {
            $this->listeners[$eventClass] = [];
        }
        $this->listeners[$eventClass][] = $listener;
    }
}
