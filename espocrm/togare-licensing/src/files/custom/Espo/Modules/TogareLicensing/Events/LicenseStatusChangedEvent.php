<?php

declare(strict_types=1);

namespace Espo\Modules\TogareLicensing\Events;

use DateTimeImmutable;

/**
 * Disparado quando um módulo Togare premium transita de status.
 *
 * Transições possíveis:
 *   - never_activated → active   (primeira ativação, reason="key_activated")
 *   - active → read_only         (expiração via RevalidateLicensesJob, reason="expired")
 *   - read_only → active         (renovação de chave, reason="key_refreshed")
 *   - active → active            (renovação antecipada — NÃO emite evento)
 *
 * Consumidores futuros: TogareHealth (UI badge), notification system, audit log.
 */
final readonly class LicenseStatusChangedEvent
{
    public function __construct(
        public string $module,
        public string $oldStatus,
        public string $newStatus,
        public string $reason,
        public DateTimeImmutable $occurredAt,
    ) {
    }
}
