<?php

declare(strict_types=1);

use Espo\Core\Container;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Modules\TogareCore\Services\MigrationRunner;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareRbac\Service\RoleSeeder;
use Espo\Modules\TogareRbac\Service\SecurityConfigInstaller;
use Espo\ORM\EntityManager;

/**
 * Hook de instalação do togare-rbac.
 *
 * 1. Lê os 8 arquivos JSON em `Resources/seed/roles/` e popula a tabela ORM
 *    `role` do EspoCRM via PDO direto (Story 2.1). Idempotente: se um role
 *    com o mesmo `name` já existe, o seed apenas emite log `rbac.role.skipped`
 *    e NÃO sobrescreve a customização do admin.
 * 2. Aplica via `Espo\Core\Utils\Config\ConfigWriter` 6 chaves de policy de
 *    senha forte + lifetime do invite (Story 2.2). Idempotente: nunca baixa
 *    um valor que o admin tornou mais restritivo.
 *
 * **Contrato pra entidades futuras:** toda nova entidade Togare custom (Cliente,
 * Process, Deadline, Hearing, Invoice, etc.) deve declarar seu scope no seed dos
 * 8 roles via patch incremental deste módulo OU via `aclDefs` do próprio módulo
 * premium. Roles em si NÃO devem ser editados aqui após primeira instalação —
 * admin customiza pelo UI nativo (Admin → Roles).
 */
class AfterInstall
{
    public function run(Container $container): void
    {
        TogareLogger::init('togare-rbac', $container);

        $entityManager = $container->getByClass(EntityManager::class);
        $pdo = $entityManager->getPDO();

        $this->ensureClassLoaded(
            RoleSeeder::class,
            'Service/RoleSeeder.php',
        );
        $this->ensureClassLoaded(
            SecurityConfigInstaller::class,
            'Service/SecurityConfigInstaller.php',
        );

        // Story 2.3: DDL primeiro — rodar migrations antes do seed e config.
        $this->runMigrations($pdo);

        $jsonDir = $this->resolveSeedDir();
        if ($jsonDir === null) {
            TogareLogger::event(
                'error',
                'rbac.seed.dir_not_found',
                'Diretório de seed roles não encontrado.',
                ['candidates_tried' => $this->seedDirCandidates()],
            );

            return;
        }

        $seeder = new RoleSeeder($pdo, TogareLogger::getInstance());
        $seedSummary = $seeder->seedFromDir($jsonDir);

        echo \sprintf(
            "[togare-rbac] Seed concluído: %d seedados, %d skipados, %d inválidos.\n",
            $seedSummary['seeded'],
            $seedSummary['skipped'],
            $seedSummary['invalid'],
        );

        // Story 2.2: aplica policy de senha forte + lifetime do invite.
        // ConfigWriter NÃO é resolvido via getByClass (não tem binding); usar
        // InjectableFactory::create — padrão herdado de Espo\Core\Upgrades\Actions\Base.
        $config = $container->getByClass(Config::class);
        $injectableFactory = $container->getByClass(InjectableFactory::class);
        $configWriter = $injectableFactory->create(ConfigWriter::class);
        $installer = new SecurityConfigInstaller($config, $configWriter);
        $configSummary = $installer->applyDefaults();

        echo \sprintf(
            "[togare-rbac] Configs de segurança: %d aplicadas, %d preservadas.\n",
            \count($configSummary['applied']),
            \count($configSummary['skipped']),
        );
    }

    private function runMigrations(\PDO $pdo): void
    {
        $migrationDir = $this->resolveMigrationDir();
        if ($migrationDir === null) {
            TogareLogger::event(
                'warning',
                'rbac.migration.dir_not_found',
                'Diretório de migrations não encontrado — pulando.',
                [],
            );

            return;
        }

        $runner = new MigrationRunner(
            $pdo,
            TogareLogger::getInstance(),
            'Espo\\Modules\\TogareRbac\\Migration',
        );

        $applied = $runner->runPending($migrationDir);

        echo \sprintf(
            "[togare-rbac] Migrations aplicadas: %s\n",
            $applied === [] ? '(nenhuma pendente)' : \implode(', ', $applied),
        );
    }

    private function resolveMigrationDir(): ?string
    {
        $candidates = [
            'custom/Espo/Modules/TogareRbac/Migration',
            '/var/www/html/custom/Espo/Modules/TogareRbac/Migration',
            __DIR__ . '/../files/custom/Espo/Modules/TogareRbac/Migration',
        ];
        foreach ($candidates as $candidate) {
            if (\is_dir($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function resolveSeedDir(): ?string
    {
        foreach ($this->seedDirCandidates() as $candidate) {
            if (\is_dir($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Garante que uma classe específica esteja carregada mesmo se o autoloader
     * PSR-4 do EspoCRM ainda não tiver indexado o módulo recém-extraído.
     *
     * @param string $relativePath caminho relativo a partir de `custom/Espo/Modules/TogareRbac/`
     */
    private function ensureClassLoaded(string $fqcn, string $relativePath): void
    {
        if (\class_exists($fqcn, true)) {
            return;
        }

        $candidates = [
            'custom/Espo/Modules/TogareRbac/' . $relativePath,
            '/var/www/html/custom/Espo/Modules/TogareRbac/' . $relativePath,
            __DIR__ . '/../files/custom/Espo/Modules/TogareRbac/' . $relativePath,
        ];

        foreach ($candidates as $candidate) {
            if (\is_file($candidate)) {
                require_once $candidate;

                return;
            }
        }

        throw new \RuntimeException(\sprintf('%s não encontrada em nenhum caminho conhecido.', $fqcn));
    }

    /**
     * @return list<string>
     */
    private function seedDirCandidates(): array
    {
        return [
            'custom/Espo/Modules/TogareRbac/Resources/seed/roles',
            '/var/www/html/custom/Espo/Modules/TogareRbac/Resources/seed/roles',
            __DIR__ . '/../files/custom/Espo/Modules/TogareRbac/Resources/seed/roles',
        ];
    }
}
