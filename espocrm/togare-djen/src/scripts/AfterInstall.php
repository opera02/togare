<?php

declare(strict_types=1);

use Espo\Core\Container;
use Espo\Modules\TogareCore\Services\MigrationRunner;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\EntityManager;

/**
 * Hook de instalação do ext-template para togare-djen (Story 4a.1).
 *
 * Aplica a migration V001 que cria a tabela auxiliar
 * `togare_djen_user_state` (rastreia last_synced_at por advogado).
 *
 * Reutiliza o `MigrationRunner` do togare-core (≥0.15.0) com namespace explícito
 * `Espo\Modules\TogareDjen\Migration`. Reusa também a tabela
 * `togare_migrations_applied` (fonte única do estado de schema entre módulos;
 * togare-core deve estar instalado antes).
 */
class AfterInstall
{
    public function run(Container $container): void
    {
        TogareLogger::init('togare-djen', $container);

        $entityManager = $container->getByClass(EntityManager::class);
        $pdo = $entityManager->getPDO();

        // Caminho absoluto derivado de __DIR__ — independente do cwd do instalador.
        // Fallback para /var/www/html se o realpath falhar (container sem symlinks).
        $migrationDir = realpath(__DIR__ . '/../../files/custom/Espo/Modules/TogareDjen/Migration')
            ?: '/var/www/html/custom/Espo/Modules/TogareDjen/Migration';

        $runner = new MigrationRunner(
            $pdo,
            TogareLogger::getInstance(),
            'Espo\\Modules\\TogareDjen\\Migration',
        );
        $applied = $runner->runPending($migrationDir);

        if ($applied === []) {
            echo "[togare-djen] Nenhuma migration pendente.\n";
            return;
        }

        echo "[togare-djen] Migrations aplicadas: " . implode(', ', $applied) . "\n";
    }
}
