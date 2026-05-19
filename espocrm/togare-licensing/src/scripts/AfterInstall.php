<?php

declare(strict_types=1);

use Espo\Core\Container;
use Espo\Modules\TogareCore\Services\MigrationRunner;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\EntityManager;

/**
 * Hook de instalação do togare-licensing.
 *
 * Reusa o MigrationRunner do togare-core (dependência declarada no extension.json).
 * Aplica V001 (cria togare_module_status). Idempotente — runPending pula migrations
 * já aplicadas via togare_migrations_applied.
 */
class AfterInstall
{
    public function run(Container $container): void
    {
        // Init incondicional — TogareLogger é singleton; reinit substitui pelo
        // serviço deste módulo só durante o AfterInstall. Logs subsequentes em
        // runtime usam o init feito pelo togare-core no boot do EspoCRM.
        TogareLogger::init('togare-licensing', $container);

        $entityManager = $container->getByClass(EntityManager::class);
        $pdo = $entityManager->getPDO();

        $migrationDir = 'custom/Espo/Modules/TogareLicensing/Migration';
        if (! is_dir($migrationDir)) {
            $migrationDir = '/var/www/html/custom/Espo/Modules/TogareLicensing/Migration';
        }

        $runner = new MigrationRunner(
            $pdo,
            TogareLogger::getInstance(),
            'Espo\\Modules\\TogareLicensing\\Migration',
        );
        $applied = $runner->runPending($migrationDir);

        if ($applied === []) {
            echo "[togare-licensing] Nenhuma migration pendente.\n";

            return;
        }

        echo "[togare-licensing] Migrations aplicadas: " . implode(', ', $applied) . "\n";
    }
}
