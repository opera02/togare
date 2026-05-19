<?php

declare(strict_types=1);

namespace Espo\Modules\TogareNextcloudBridge\Exception;

use RuntimeException;
use Throwable;

/**
 * Sinaliza que o arquivo solicitado não existe no Nextcloud (HTTP 404).
 *
 * `getMessage()` retorna pt-BR fixo seguro para usuário final.
 * `getLogicalPath()` expõe o caminho lógico para logs estruturados.
 */
final class NextcloudFileNotFoundException extends RuntimeException
{
    public function __construct(
        private readonly string $logicalPath,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            'Arquivo não encontrado no Nextcloud.',
            0,
            $previous,
        );
    }

    public function getLogicalPath(): string
    {
        return $this->logicalPath;
    }
}
