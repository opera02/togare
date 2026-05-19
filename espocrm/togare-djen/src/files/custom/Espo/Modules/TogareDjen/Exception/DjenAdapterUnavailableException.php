<?php

declare(strict_types=1);

namespace Espo\Modules\TogareDjen\Exception;

use RuntimeException;

/**
 * Adapter Comunica API DJEN está indisponível (timeout, 5xx repetido, circuit
 * breaker aberto, payload malformado). Exception interna — nunca chega ao
 * usuário, sempre vira fila (failed_retry com next_retry_at=now+1h via
 * QueueService::markFailed customDelay) ou banner via Job/Health Panel.
 */
final class DjenAdapterUnavailableException extends RuntimeException
{
}
