<?php

declare(strict_types=1);

namespace Espo\Modules\TogareTpu\Exception;

use Espo\Core\Exceptions\Error;

/**
 * Falha durante o sync TPU. Mapeia para HTTP 500.
 *
 * Usado quando uma operação de sync de uma das 3 tabelas (classes/assuntos/
 * movimentos) falha de forma inesperada (ex.: erro de DDL, falha de DB
 * intransponível). Falhas previstas (adapter indisponível, payload malformado
 * etc.) usam exceptions específicas.
 */
final class TpuSyncFailedException extends Error
{
}
