<?php

declare(strict_types=1);

namespace Espo\Modules\TogareTpu\Exception;

use Espo\Core\Exceptions\BadRequest;

/**
 * Código TPU não encontrado no catálogo local. Mapeia para HTTP 422.
 *
 * Usado pela Story 3.4 (Processo) quando o usuário tenta cadastrar um processo
 * com `classeCodigo`/`assuntoCodigo`/`movimentoCodigo` que não consta na
 * última sync. A mensagem inclui o tipo, o código e a data do último sync
 * (sinaliza ao usuário que pode ser uma classe muito nova ainda não
 * sincronizada).
 */
final class TpuCodeNotFoundException extends BadRequest
{
    public static function create(string $tipo, int $codigo, ?string $lastSyncedAt = null): self
    {
        $msg = "Código TPU {$codigo} não encontrado no catálogo de {$tipo}.";
        if ($lastSyncedAt !== null) {
            $msg .= " Sync mais recente: {$lastSyncedAt}.";
        }
        return new self($msg);
    }
}
