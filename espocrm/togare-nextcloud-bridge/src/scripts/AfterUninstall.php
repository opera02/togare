<?php

declare(strict_types=1);

use Espo\Core\Container;

/**
 * Hook de desinstalação do togare-nextcloud-bridge.
 *
 * No-op deliberado: a Story 5.1 não cria schema próprio e não remove arquivos
 * do Nextcloud automaticamente. Tombstones e hard-delete ficam para a Story 5.5.
 */
class AfterUninstall
{
    public function run(Container $container): void
    {
    }
}
