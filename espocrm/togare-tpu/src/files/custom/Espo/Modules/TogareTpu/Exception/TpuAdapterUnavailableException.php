<?php

declare(strict_types=1);

namespace Espo\Modules\TogareTpu\Exception;

use RuntimeException;

/**
 * Adapter de fonte TPU está indisponível (timeout, 5xx repetido, circuit
 * breaker aberto, payload malformado). Exception interna — nunca chega ao
 * usuário, sempre vira fila/banner via Job/Health Panel.
 */
final class TpuAdapterUnavailableException extends RuntimeException
{
}
