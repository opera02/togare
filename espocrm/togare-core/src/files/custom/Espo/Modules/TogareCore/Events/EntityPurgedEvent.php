<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Events;

use DateTimeImmutable;

/**
 * Evento emitido quando uma entidade de negócio é purgada
 * (soft-delete após expirar política de retenção LGPD, FR44/FR45).
 *
 * Consumidores: togare-lgpd (registra prova ed25519), togare-nextcloud-bridge
 * (dispara soft-delete de binários vinculados).
 */
final readonly class EntityPurgedEvent
{
    public function __construct(
        public string $entityType,
        public string $entityId,
        public DateTimeImmutable $purgedAt,
    ) {
    }
}
