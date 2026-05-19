<?php

declare(strict_types=1);

use Espo\Core\Container;
use Espo\Modules\TogareCore\Services\MigrationRunner;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\EntityManager;

/**
 * Hook de instalação do ext-template para togare-tpu (Story 3.3).
 *
 * Aplica as migrations V001-V003 que criam as 3 tabelas de catálogo TPU
 * (togare_tpu_classe, togare_tpu_assunto, togare_tpu_movimento).
 *
 * Reutiliza o `MigrationRunner` do togare-core (≥0.9.2) com namespace explícito
 * `Espo\Modules\TogareTpu\Migration` (Dev Notes §3 — sem isso, classes não
 * resolvem). Reusa também a tabela `togare_migrations_applied` (Dev Notes §4 —
 * fonte única do estado de schema entre módulos; togare-core deve estar
 * instalado antes).
 */
class AfterInstall
{
    public function run(Container $container): void
    {
        TogareLogger::init('togare-tpu', $container);

        $entityManager = $container->getByClass(EntityManager::class);
        $pdo = $entityManager->getPDO();

        // Caminho absoluto derivado de __DIR__ — independente do cwd do instalador.
        // Fallback para /var/www/html se o realpath falhar (container sem symlinks).
        $migrationDir = realpath(__DIR__ . '/../../files/custom/Espo/Modules/TogareTpu/Migration')
            ?: '/var/www/html/custom/Espo/Modules/TogareTpu/Migration';

        $runner = new MigrationRunner(
            $pdo,
            TogareLogger::getInstance(),
            'Espo\\Modules\\TogareTpu\\Migration',
        );
        $applied = $runner->runPending($migrationDir);

        if ($applied === []) {
            echo "[togare-tpu] Nenhuma migration pendente.\n";
            return;
        }

        echo "[togare-tpu] Migrations aplicadas: " . implode(', ', $applied) . "\n";
    }
}
