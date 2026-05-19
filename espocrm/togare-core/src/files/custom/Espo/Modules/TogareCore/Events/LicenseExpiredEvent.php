<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Events;

use DateTimeImmutable;

/**
 * Evento emitido quando a licença JWT de um módulo premium expira.
 *
 * Consumidores: togare-licensing (aciona ReadOnlyGate), togare-core
 * (loga via TogareLogger; exibe GateBanner na UI).
 */
final readonly class LicenseExpiredEvent
{
    public function __construct(
        public string $moduleName,
        public DateTimeImmutable $expiredAt,
    ) {
    }
}
