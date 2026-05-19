<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareRbac\Metadata;

use PHPUnit\Framework\TestCase;

final class ExtensionMetadataTest extends TestCase
{
    public function testCoreDependencyMatchesFuncionarioRelease(): void
    {
        $path = __DIR__ . '/../../../../../../extension.json';

        self::assertFileExists($path);

        $decoded = \json_decode((string) \file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        // Story 6.5 — V010__patch_roles_add_funcionario referencia
        // Espo\Modules\TogareCore\Contracts\MigrationInterface introduzido/
        // estável no togare-core 0.37.1; o pin precisa acompanhar o fix-pass.
        self::assertSame('>=0.37.1', $decoded['dependencies']['togare-core'] ?? null);
    }
}
