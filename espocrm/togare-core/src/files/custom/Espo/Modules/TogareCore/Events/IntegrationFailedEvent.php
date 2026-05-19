<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Events;

use DateTimeImmutable;

/**
 * Evento emitido quando uma integração externa (DJEN, AASP, TPU, Nextcloud)
 * falha após retries. Aciona HedgeBanner + degradação graciosa do fluxo.
 *
 * Consumidores: togare-core/ui (banner), togare-lgpd (não purgar enquanto
 * indisponível), TogareHealth (marca tile correspondente como degraded).
 */
final readonly class IntegrationFailedEvent
{
    public function __construct(
        public string $integrationName,
        public string $reason,
        public DateTimeImmutable $failedAt,
    ) {
    }
}
