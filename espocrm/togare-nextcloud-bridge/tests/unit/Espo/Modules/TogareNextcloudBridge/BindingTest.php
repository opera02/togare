<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareNextcloudBridge;

use Espo\Core\Binding\Binder;
use Espo\Modules\TogareCore\Contracts\FileStorageContract;
use Espo\Modules\TogareCore\Contracts\PurgeableStorageContract;
use Espo\Modules\TogareNextcloudBridge\Binding;
use Espo\Modules\TogareNextcloudBridge\Contracts\NextcloudClientContract;
use Espo\Modules\TogareNextcloudBridge\Services\NextcloudFileStorage;
use Espo\Modules\TogareNextcloudBridge\Services\NextcloudPurgeableStorage;
use Espo\Modules\TogareNextcloudBridge\Services\OcsApiClient;
use PHPUnit\Framework\TestCase;

/**
 * AC #2 da Story 5.1: Binding registra os 3 contratos cross-module.
 */
final class BindingTest extends TestCase
{
    public function testProcessRegistraOs3ContratosCorretamente(): void
    {
        $binder = new Binder();
        (new Binding())->process($binder);

        $this->assertCount(3, $binder->implementations);

        $this->assertContainsEquals(
            ['interface' => NextcloudClientContract::class, 'implementation' => OcsApiClient::class],
            $binder->implementations,
            'NextcloudClientContract → OcsApiClient não registrado',
        );

        $this->assertContainsEquals(
            ['interface' => FileStorageContract::class, 'implementation' => NextcloudFileStorage::class],
            $binder->implementations,
            'FileStorageContract → NextcloudFileStorage não registrado',
        );

        $this->assertContainsEquals(
            ['interface' => PurgeableStorageContract::class, 'implementation' => NextcloudPurgeableStorage::class],
            $binder->implementations,
            'PurgeableStorageContract → NextcloudPurgeableStorage não registrado',
        );
    }
}
