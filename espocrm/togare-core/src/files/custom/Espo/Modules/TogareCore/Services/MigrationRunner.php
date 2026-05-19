<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services;

use DateTimeImmutable;
use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Aplica migrations pendentes versionadas e registra em togare_migrations_applied.
 *
 * Estratégia de bootstrap paradox: a primeira migration (V001) cria a própria
 * tabela de controle. Runner detecta "tabela não existe" via SQLSTATE 42S02 e
 * aplica V001 mesmo assim; após V001, a tabela existe e o fluxo normal segue.
 *
 * Ordem: lexicográfica por version() (strcmp). Mais simples e robusto que
 * parsear número da string V<N>__.
 *
 * Checksum: SHA-256 do arquivo .php da migration. Alteração em migration já
 * aplicada produz warning (não re-aplica; migrations são imutáveis).
 */
final class MigrationRunner
{
    /**
     * @param string $migrationNamespace namespace PHP esperado das classes de
     *                                   migration. Default = togare-core.
     *                                   Outros módulos passam o seu próprio
     *                                   (ex.: 'Espo\\Modules\\TogareLicensing\\Migration').
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?TogareLogger $logger = null,
        private readonly string $migrationNamespace = 'Espo\\Modules\\TogareCore\\Migration',
    ) {
    }

    /**
     * Varre $migrationDir, compara com togare_migrations_applied, aplica pendentes.
     *
     * @return list<string> versões aplicadas nesta chamada, em ordem
     */
    public function runPending(string $migrationDir): array
    {
        $applied = [];
        $files = $this->discoverMigrationFiles($migrationDir);

        $alreadyApplied = $this->loadAppliedVersions();

        foreach ($files as $version => $filePath) {
            if (isset($alreadyApplied[$version])) {
                $recordedChecksum = $alreadyApplied[$version];
                $currentChecksum = hash_file('sha256', $filePath);
                if ($recordedChecksum !== $currentChecksum) {
                    // Migrations são imutáveis após aplicadas — só avisa, não re-aplica.
                    $warnMsg = \sprintf(
                        'Migration %s alterada após aplicada (checksum diverge). Se for mudança intencional, crie nova V<N+1>.',
                        $version,
                    );
                    if ($this->logger !== null) {
                        TogareLogger::event(
                            'warning',
                            'migration.checksum.diverged',
                            $warnMsg,
                            ['version' => $version],
                        );
                    } else {
                        error_log('[togare-core] ' . $warnMsg); // escape hatch: bootstrap
                    }
                }
                continue;
            }

            $migration = $this->loadMigration($filePath, $version);
            $migration->up($this->pdo);
            $this->recordApplied($version, hash_file('sha256', $filePath));
            $applied[] = $version;
        }

        return $applied;
    }

    /**
     * Rollback de uma migration específica. Idempotente.
     *
     * @return bool true se reverteu; false se não estava aplicada (noop)
     */
    public function rollback(string $version, string $migrationDir): bool
    {
        $alreadyApplied = $this->loadAppliedVersions();
        if (! isset($alreadyApplied[$version])) {
            return false;
        }

        $filePath = $migrationDir . DIRECTORY_SEPARATOR . $version . '.php';
        if (! is_file($filePath)) {
            throw new RuntimeException(\sprintf(
                'Migration %s consta como aplicada mas o arquivo %s não existe.',
                $version,
                $filePath,
            ));
        }

        $migration = $this->loadMigration($filePath, $version);
        $migration->down($this->pdo);
        $this->removeAppliedRecord($version);
        return true;
    }

    /**
     * @return array<string, string> path absoluto por version(), ordenado
     */
    private function discoverMigrationFiles(string $dir): array
    {
        if (! is_dir($dir)) {
            throw new RuntimeException("Diretório de migrations não existe: {$dir}");
        }

        $found = [];
        foreach (scandir($dir) ?: [] as $name) {
            if (! preg_match('/^(V\d+__[A-Za-z0-9_]+)\.php$/', $name, $m)) {
                continue;
            }
            $found[$m[1]] = $dir . DIRECTORY_SEPARATOR . $name;
        }

        ksort($found, SORT_STRING);
        return $found;
    }

    /**
     * @return array<string, string> checksum por version já aplicada
     */
    private function loadAppliedVersions(): array
    {
        try {
            $stmt = $this->pdo->query('SELECT schema_version, checksum FROM togare_migrations_applied');
            if ($stmt === false) {
                return [];
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $out = [];
            foreach ($rows as $row) {
                $out[(string) $row['schema_version']] = (string) $row['checksum'];
            }
            return $out;
        } catch (PDOException $e) {
            // Bootstrap paradox: tabela pode não existir ainda. SQLSTATE:
            //   - MariaDB/MySQL: 42S02 ('Base table or view not found')
            //   - SQLite (para testes unit): HY000 com "no such table"
            $sqlState = $e->getCode();
            if ($sqlState === '42S02' || str_contains($e->getMessage(), 'no such table')) {
                return [];
            }
            throw $e;
        }
    }

    private function recordApplied(string $version, string $checksum): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO togare_migrations_applied (schema_version, applied_at, checksum) VALUES (:v, :a, :c)',
        );
        $stmt->execute([
            ':v' => $version,
            ':a' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            ':c' => $checksum,
        ]);
    }

    private function removeAppliedRecord(string $version): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM togare_migrations_applied WHERE schema_version = :v',
        );
        $stmt->execute([':v' => $version]);
    }

    private function loadMigration(string $filePath, string $expectedVersion): MigrationInterface
    {
        require_once $filePath;

        // Convenção: classe da migration tem o mesmo nome do arquivo sem extensão,
        // dentro do namespace configurado no construtor.
        $className = \rtrim($this->migrationNamespace, '\\') . '\\' . $expectedVersion;

        if (! class_exists($className)) {
            throw new RuntimeException(\sprintf(
                "Migration file %s não define a classe esperada %s.",
                $filePath,
                $className,
            ));
        }

        $instance = new $className();
        if (! $instance instanceof MigrationInterface) {
            throw new RuntimeException(\sprintf(
                'Classe %s não implementa MigrationInterface.',
                $className,
            ));
        }

        if ($instance->version() !== $expectedVersion) {
            throw new RuntimeException(\sprintf(
                'Migration %s declara version() = %s (esperado %s).',
                $className,
                $instance->version(),
                $expectedVersion,
            ));
        }

        return $instance;
    }
}
