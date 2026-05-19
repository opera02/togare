<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareTpu\Metadata;

use PHPUnit\Framework\TestCase;

final class TogareTpuCatalogScopeMetadataTest extends TestCase
{
    public function testCatalogoTpuDeclaraScopeNaoEntidadeSemPortalAcl(): void
    {
        $path = __DIR__ . '/../../../../../../src/files/custom/Espo/Modules/TogareTpu/Resources/metadata/scopes/TogareTpuCatalog.json';

        self::assertFileExists($path);

        /** @var array<string, mixed> $scope */
        $scope = \json_decode((string) \file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($scope['object'] ?? true);
        self::assertFalse($scope['entity'] ?? true);
        self::assertSame('TogareTpu', $scope['module'] ?? null);
        self::assertFalse($scope['aclPortal'] ?? true);
    }
}
