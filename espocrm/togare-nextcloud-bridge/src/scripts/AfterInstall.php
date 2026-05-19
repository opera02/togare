<?php

declare(strict_types=1);

use Espo\Core\Container;

/**
 * Hook de instalação do togare-nextcloud-bridge.
 *
 * Story 5.1 não cria migrations nem entidades. O bootstrap operacional do
 * Nextcloud (`trusted_domains` incluindo `nextcloud`) vive no docker-compose
 * e está documentado em docker/README.md para stacks já existentes.
 */
class AfterInstall
{
    public function run(Container $container): void
    {
        echo "[togare-nextcloud-bridge] Nenhuma migration pendente.\n";
    }
}
