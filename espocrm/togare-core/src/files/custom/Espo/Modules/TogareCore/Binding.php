<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore;

use Espo\Core\Binding\Binder;
use Espo\Core\Binding\BindingProcessor;
use Espo\Core\Container;
use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\Modules\TogareCore\Contracts\FileStorageContract;
use Espo\Modules\TogareCore\Contracts\PurgeableStorageContract;
use Espo\Modules\TogareCore\Services\AuditLogService;
use Espo\Modules\TogareCore\Services\Storage\LocalDiskPurgeableStorage;
use Espo\Modules\TogareCore\Services\TogareSettingsService;
use Espo\Tools\App\SettingsService;

/**
 * DI bindings do módulo TogareCore.
 *
 * Carregado automaticamente pelo EspoBindingLoader via convenção de nome
 * `Espo\Modules\<ModuleName>\Binding`. Registra substituições de classe
 * que não podem ser expressas via containerServices.json (que só cobre
 * serviços nomeados acessados via `$container->get('chave')`, não DI por
 * type-hint na interface — vide InjectableFactory:264 que cai em
 * createInternal e quebra com "Class does not exist" pra interfaces).
 *
 * Story 6.0 — Override semântica para FileStorageContract / PurgeableStorageContract:
 *   - TogareCore Binding registra LocalDiskPurgeableStorage SOMENTE quando o
 *     módulo togare-nextcloud-bridge NÃO está instalado (guard via
 *     `class_exists(NextcloudFileStorage::class)`).
 *   - Decisão #3 ATUALIZADA pós-smoke F1 da 6.0: o pressuposto inicial era
 *     que TogareCore carregaria ANTES de TogareNextcloudBridge (ordem
 *     alfabética), permitindo override via "última chamada vence". Smoke
 *     real revelou que `Resources/module.json` define `order` explícito:
 *     bridge=12 (carrega primeiro) e core=50 (carrega depois). Resultado:
 *     core SEMPRE sobrescreve bridge — invertido do esperado. Fix
 *     pragmático: guard `class_exists` cobre o caso bridge-instalado SEM
 *     mexer em ordem de módulos (mais cirúrgico que rebumpar bridge para
 *     order > 50, que afetaria carregamento de hooks/migrations não-binding).
 *   - O guard NÃO viola boundaries arquiteturais (architecture.md L686): o
 *     antipadrão é `require_once` cross-module; aqui é `class_exists`, que é
 *     o pattern idiomático de "feature detection" — togare-core consulta a
 *     EXISTÊNCIA da classe (PSR-4 autoload), sem incluir/instanciar nada.
 *   - rootPath callback registrado INCONDICIONALMENTE — LocalDiskPurgeableStorage
 *     pode ser injetada por type-hint direto em algum serviço futuro (testes
 *     PHPUnit, scripts ops) mesmo com bridge instalado.
 */
final class Binding implements BindingProcessor
{
    public function process(Binder $binder): void
    {
        // Substitui SettingsService pelo decorator de auditoria (Story 2.4 AC9).
        // SettingsService não usa ORM save → ORM hooks nunca disparam.
        // Decorator intercepta setConfigData() e emite config.security.changed.
        $binder->bindImplementation(SettingsService::class, TogareSettingsService::class);

        // Resolve type-hint AuditLogContract → AuditLogService em qualquer
        // construtor (hooks, services, controllers). Story 3.1 — descoberto
        // ao instalar AuditClienteHook: containerServices.json só cobre
        // resolução nomeada (`$container->get('FQCN')`), não DI automática
        // por type-hint. Sem este binding, todos os hooks que recebem
        // AuditLogContract no construtor quebram com:
        // "InjectableFactory: Class 'AuditLogContract' does not exist"
        // (porque é interface, não classe — `class_exists()` retorna false).
        $binder->bindImplementation(AuditLogContract::class, AuditLogService::class);

        // Story 6.0 — LocalDiskStorage fallback. Bind SOMENTE quando bridge
        // NÃO está instalado (feature detection via class_exists). Sem este
        // guard, core sobrescreveria bridge em produção (vide doc acima).
        // UMA classe (LocalDiskPurgeableStorage) cobre AMBOS contratos via
        // LSP (Decisão #2 — extends LocalDiskStorage implements Purgeable).
        if (! \class_exists(
            'Espo\\Modules\\TogareNextcloudBridge\\Services\\NextcloudFileStorage',
            true,
        )) {
            $binder->bindImplementation(FileStorageContract::class, LocalDiskPurgeableStorage::class);
            $binder->bindImplementation(PurgeableStorageContract::class, LocalDiskPurgeableStorage::class);
        }

        // Construtor `LocalDiskPurgeableStorage(string $rootPath)` recebe path
        // resolvido da env var `TOGARE_LOCAL_STORAGE_ROOT` (Decisão #4 — env
        // var em vez de Settings UI no MVP; pattern Story 4b.4
        // TOGARE_DJEN_CB_STATE_PATH). Lazy via callback — `getenv()` é
        // avaliado quando InjectableFactory instancia, não no boot do Binding.
        // Registrado INCONDICIONALMENTE para permitir injeção direta da impl
        // concreta em testes ou scripts ops mesmo com bridge ativo.
        $binder->for(LocalDiskPurgeableStorage::class)
            ->bindCallback(
                '$rootPath',
                static fn (Container $container): string =>
                    (string) (\getenv('TOGARE_LOCAL_STORAGE_ROOT') ?: '/var/togare/local-storage'),
            );
    }
}
