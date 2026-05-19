<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Services;

use Espo\Modules\TogareCore\Entities\ContratoHonorarios;
use Espo\Modules\TogareCore\Services\ContratoLogicalPathBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Story 6.1 — testa sanitização + build + extract (ambos schemes
 * nextcloud:// e local:// — Decisão #2 da spec).
 */
final class ContratoLogicalPathBuilderTest extends TestCase
{
    public function testSanitizeFilenamePreservaExtensao(): void
    {
        // Espaço vira underscore (regex `[^a-zA-Z0-9._-]` → `_`); colapso de `_+` mantém um só.
        self::assertSame(
            'contrato_2026.pdf',
            ContratoLogicalPathBuilder::sanitizeFilename('contrato 2026.pdf'),
        );
    }

    public function testSanitizeFilenamePreservaHifenOriginal(): void
    {
        // Hífen no nome original é PRESERVADO (faz parte da allowlist [a-zA-Z0-9._-]).
        self::assertSame(
            'contrato-2026.pdf',
            ContratoLogicalPathBuilder::sanitizeFilename('contrato-2026.pdf'),
        );
    }

    public function testSanitizeFilenameTransliteraAcentos(): void
    {
        self::assertSame(
            'contrato-honorarios.pdf',
            ContratoLogicalPathBuilder::sanitizeFilename('contrato-honorários.pdf'),
        );
    }

    public function testSanitizeFilenameSubstituiCaracteresEspeciais(): void
    {
        self::assertSame(
            'contrato_com_espacos.pdf',
            ContratoLogicalPathBuilder::sanitizeFilename('contrato com espaços.pdf'),
        );
    }

    public function testSanitizeFilenameSemExtensao(): void
    {
        self::assertSame('contrato', ContratoLogicalPathBuilder::sanitizeFilename('contrato'));
    }

    public function testSanitizeFilenameVazioFallback(): void
    {
        self::assertSame(
            ContratoHonorarios::FILENAME_FALLBACK,
            ContratoLogicalPathBuilder::sanitizeFilename(''),
        );
    }

    public function testBuildLogicalPath(): void
    {
        // IDs Espo nativos são alfanuméricos 17 chars sem dashes (pattern Util::generateId).
        $entity = new ContratoHonorarios();
        $entity->setId('contrato001abc');
        $entity->set('clienteId', 'cliente-acme');

        self::assertSame(
            'clientes/cliente-acme/contratos/contrato001abc-contrato.pdf',
            ContratoLogicalPathBuilder::build($entity, 'contrato.pdf'),
        );
    }

    public function testBuildSemClienteIdLancaLogicException(): void
    {
        $entity = new ContratoHonorarios();
        $entity->setId('contrato001abc');

        $this->expectException(\LogicException::class);
        ContratoLogicalPathBuilder::build($entity, 'contrato.pdf');
    }

    public function testBuildSemIdLancaLogicException(): void
    {
        $entity = new ContratoHonorarios();
        $entity->set('clienteId', 'cliente-acme');

        $this->expectException(\LogicException::class);
        ContratoLogicalPathBuilder::build($entity, 'contrato.pdf');
    }

    public function testExtractLogicalPathNextcloudScheme(): void
    {
        self::assertSame(
            'clientes/abc/contratos/xyz-arquivo.pdf',
            ContratoLogicalPathBuilder::extractLogicalPath('nextcloud://clientes/abc/contratos/xyz-arquivo.pdf'),
        );
    }

    public function testExtractLogicalPathLocalScheme(): void
    {
        self::assertSame(
            'clientes/abc/contratos/xyz-arquivo.pdf',
            ContratoLogicalPathBuilder::extractLogicalPath('local://clientes/abc/contratos/xyz-arquivo.pdf'),
        );
    }

    public function testExtractLogicalPathSchemeDesconhecidoLancaException(): void
    {
        $this->expectException(\RuntimeException::class);
        ContratoLogicalPathBuilder::extractLogicalPath('s3://bucket/key');
    }

    public function testParseFromUriNextcloud(): void
    {
        // contratoId Espo nativo = alfanumérico sem dashes (split em primeira `-` no tail).
        $parsed = ContratoLogicalPathBuilder::parseFromUri(
            'nextcloud://clientes/cliente-acme/contratos/contrato001abc-contrato.pdf',
        );

        self::assertSame('nextcloud', $parsed['scheme']);
        self::assertSame('clientes', $parsed['bucket']);
        self::assertSame('cliente-acme', $parsed['clienteId']);
        self::assertSame('contratos', $parsed['subdir']);
        self::assertSame('contrato001abc', $parsed['contratoId']);
        self::assertSame('contrato.pdf', $parsed['filename']);
    }

    public function testParseFromUriLocal(): void
    {
        $parsed = ContratoLogicalPathBuilder::parseFromUri(
            'local://clientes/cliente-acme/contratos/contrato001abc-contrato.pdf',
        );

        self::assertSame('local', $parsed['scheme']);
        self::assertSame('cliente-acme', $parsed['clienteId']);
        self::assertSame('contrato001abc', $parsed['contratoId']);
    }

    public function testParseFromUriBucketInvalidoLancaException(): void
    {
        $this->expectException(\RuntimeException::class);
        ContratoLogicalPathBuilder::parseFromUri(
            'nextcloud://processos/abc/contratos/xyz-arquivo.pdf',
        );
    }
}
