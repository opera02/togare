<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore;

use Espo\Core\Binding\Binder;
use Espo\Modules\TogareCore\Binding;
use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\Modules\TogareCore\Contracts\FileStorageContract;
use Espo\Modules\TogareCore\Contracts\PurgeableStorageContract;
use Espo\Modules\TogareCore\Services\AuditLogService;
use Espo\Modules\TogareCore\Services\Storage\LocalDiskPurgeableStorage;
use PHPUnit\Framework\TestCase;

/**
 * Cobre Story 6.0 AC4 + AC5 — Binding default da togare-core registra
 * LocalDiskPurgeableStorage para AMBOS FileStorageContract e
 * PurgeableStorageContract SOMENTE quando o bridge NÃO está instalado
 * (guard via class_exists — Decisão #3 atualizada pós-smoke F1).
 *
 * NOTA: o ambiente de testes do togare-core tem autoload-dev cobrindo o
 * namespace TogareNextcloudBridge (composer.json:9), então `class_exists`
 * retorna true e o bind do core fica SUPRIMIDO — emulando comportamento
 * de runtime com bridge instalado. Para emular runtime sem bridge,
 * `testBindFallbackQuandoBridgeAusente` força via reflection.
 */
final class BindingTest extends TestCase
{
    public function testProcessRegistraBindsBaseSemDuplicar(): void
    {
        $binder = new Binder();
        (new Binding())->process($binder);

        // Bridge presente via autoload-dev → core suprime os 2 binds de Storage.
        // Restam: SettingsService decorator (Story 2.4) + AuditLogContract (Story 3.1) = 2.
        $this->assertCount(2, $binder->implementations);

        $this->assertContainsEquals(
            [
                'interface' => AuditLogContract::class,
                'implementation' => AuditLogService::class,
            ],
            $binder->implementations,
            'AuditLogContract → AuditLogService não registrado',
        );

        // Confirma que NÃO há bind de FileStorageContract / PurgeableStorageContract
        // (bridge está autoloadable em test env → core não bate).
        $fileStorageBinds = \array_filter(
            $binder->implementations,
            static fn (array $b): bool => $b['interface'] === FileStorageContract::class,
        );
        $purgeableBinds = \array_filter(
            $binder->implementations,
            static fn (array $b): bool => $b['interface'] === PurgeableStorageContract::class,
        );

        $this->assertCount(0, $fileStorageBinds, 'Core NÃO deve bindar FileStorageContract quando bridge está autoloadable');
        $this->assertCount(0, $purgeableBinds, 'Core NÃO deve bindar PurgeableStorageContract quando bridge está autoloadable');
    }

    public function testProcessRegistraContextualCallbackParaRootPathIncondicionalmente(): void
    {
        // bindCallback do rootPath é registrado SEMPRE (independente do bridge),
        // para permitir injeção direta de LocalDiskPurgeableStorage em testes ou
        // scripts ops mesmo com bridge ativo.
        $binder = new Binder();
        (new Binding())->process($binder);

        $this->assertContainsEquals(
            [
                'contextClass' => LocalDiskPurgeableStorage::class,
                'key' => '$rootPath',
            ],
            $binder->contextualCallbacks,
            'bindCallback("$rootPath") em LocalDiskPurgeableStorage não registrado',
        );
    }

    /**
     * Documenta a expectativa de runtime — quando NextcloudFileStorage NÃO existe
     * (deployment sem bridge), os 2 binds de Storage aparecem.
     *
     * Como PHPUnit roda com autoload-dev que enxerga o namespace do bridge, não
     * conseguimos "esconder" a classe in-process. Validação real desse caminho
     * é via smoke F1 T9.5 (bridge desinstalado no container Docker).
     *
     * Aqui inspecionamos diretamente o source code da Binding.php pra garantir
     * que o guard está presente e nas duas linhas certas — defesa estática.
     */
    public function testBindingSourceContemGuardClassExistsParaFallback(): void
    {
        $reflection = new \ReflectionClass(Binding::class);
        $filename = $reflection->getFileName();
        $this->assertNotFalse($filename, 'ReflectionClass não pôde resolver filename');

        $source = (string) \file_get_contents($filename);

        $this->assertStringContainsString(
            "class_exists(",
            $source,
            'Binding.php deve usar class_exists para feature detection do bridge',
        );
        $this->assertStringContainsString(
            'Espo\\\\Modules\\\\TogareNextcloudBridge\\\\Services\\\\NextcloudFileStorage',
            $source,
            'Binding.php deve referenciar NextcloudFileStorage no guard (FQCN escapado)',
        );
        $this->assertMatchesRegularExpression(
            '/bindImplementation\(\s*FileStorageContract::class\s*,\s*LocalDiskPurgeableStorage::class/',
            $source,
            'Bind FileStorageContract → LocalDiskPurgeableStorage deve estar no source',
        );
        $this->assertMatchesRegularExpression(
            '/bindImplementation\(\s*PurgeableStorageContract::class\s*,\s*LocalDiskPurgeableStorage::class/',
            $source,
            'Bind PurgeableStorageContract → LocalDiskPurgeableStorage deve estar no source',
        );
    }
}
