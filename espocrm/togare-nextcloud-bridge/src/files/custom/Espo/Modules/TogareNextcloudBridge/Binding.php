<?php

declare(strict_types=1);

namespace Espo\Modules\TogareNextcloudBridge;

use Espo\Core\Binding\Binder;
use Espo\Core\Binding\BindingProcessor;
use Espo\Core\Container;
use Espo\Modules\TogareCore\Contracts\EventBusContract;
use Espo\Modules\TogareCore\Contracts\FileStorageContract;
use Espo\Modules\TogareCore\Contracts\PurgeableStorageContract;
use Espo\Modules\TogareNextcloudBridge\Contracts\NextcloudClientContract;
use Espo\Modules\TogareNextcloudBridge\Services\NextcloudFileStorage;
use Espo\Modules\TogareNextcloudBridge\Services\NextcloudPurgeableStorage;
use Espo\Modules\TogareNextcloudBridge\Services\OcsApiClient;

/**
 * DI bindings do módulo TogareNextcloudBridge.
 *
 * Carregado automaticamente pelo EspoBindingLoader via convenção de nome
 * `Espo\Modules\<ModuleName>\Binding`. Resolve 3 contratos cross-module:
 *
 *   1. NextcloudClientContract  → OcsApiClient            (interno, Decisão #2)
 *   2. FileStorageContract      → NextcloudFileStorage     (cross-module, togare-core)
 *   3. PurgeableStorageContract → NextcloudPurgeableStorage (cross-module, togare-core)
 *
 * Sem este Binding, services downstream que recebem
 * `FileStorageContract` no construtor (Story 5.2 entity Documento upload,
 * Story 6.0 LocalDiskStorage swap em ambiente sem Epic 5, etc.) quebram
 * com "Class 'FileStorageContract' does not exist" — interface não pode
 * ser instanciada via createInternal.
 *
 * API canônica do ContextualBinder (EspoCRM 9.x): bindImplementation,
 * bindService, bindValue, bindInstance, bindCallback, bindFactory.
 *
 * B20 endereçada por design (regra retro 4a): EventBusContract é injetado
 * explicitamente via bindCallback, evitando o default null silencioso do
 * construtor e garantindo emissão real de IntegrationFailedEvent.
 */
final class Binding implements BindingProcessor
{
    public function process(Binder $binder): void
    {
        $binder->bindImplementation(NextcloudClientContract::class, OcsApiClient::class);
        $binder->bindImplementation(FileStorageContract::class, NextcloudFileStorage::class);
        $binder->bindImplementation(PurgeableStorageContract::class, NextcloudPurgeableStorage::class);

        $binder->for(OcsApiClient::class)
            ->bindCallback(
                EventBusContract::class,
                static fn (Container $container): EventBusContract =>
                    $container->get('togareCoreEventDispatcher'),
            );
    }
}
