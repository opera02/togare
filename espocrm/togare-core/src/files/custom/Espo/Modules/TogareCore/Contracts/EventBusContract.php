<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Contracts;

/**
 * Barramento de eventos interno do Togare.
 *
 * Desacopla emissores e consumidores de eventos cross-módulo. Usado por
 * togare-core (emite LicenseExpiredEvent via togare-licensing; emite
 * EntityPurgedEvent via togare-lgpd; emite IntegrationFailedEvent via
 * togare-djen/tpu/nextcloud-bridge).
 *
 * Contrato: dispatch é síncrono, in-process, sem hierarquia de tipos (listener
 * registrado para X só recebe eventos instanceof X, não de subclasses). Sem
 * priority, sem async, sem error isolation — listeners controlados pelos
 * módulos Togare. Em Fase 2 (marketplace de terceiros), rever.
 */
interface EventBusContract
{
    /**
     * Dispara o evento para todos os listeners registrados para sua classe
     * exata. Silent no-op se não houver listeners. Propaga exceções lançadas
     * por listeners — caller decide tratar.
     */
    public function dispatch(object $event): void;

    /**
     * Registra um listener para uma classe de evento específica.
     * Múltiplos listeners podem ser registrados; são invocados na ordem
     * de registro (FIFO).
     *
     * @param class-string $eventClass
     * @param callable(object): void $listener
     */
    public function subscribe(string $eventClass, callable $listener): void;
}
