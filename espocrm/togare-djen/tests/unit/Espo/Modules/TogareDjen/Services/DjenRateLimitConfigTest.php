<?php

declare(strict_types=1);

namespace Tests\Unit\Espo\Modules\TogareDjen\Services;

use Espo\Modules\TogareDjen\Services\DjenRateLimitConfig;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Story 4a.6 — guard contra drift acidental dos valores do rate-limit.
 *
 * Constantes vinculantes documentadas no AC1 da story:
 *   RATE_KEY        = 'djen:comunica-api'
 *   LIMIT           = 30
 *   WINDOW_SECONDS  = 60
 *   CAP_SECONDS     = 90
 *
 * Se este teste falhar, é porque alguém mudou os valores sem ADR — abrir
 * discussão antes de "corrigir" o teste.
 */
final class DjenRateLimitConfigTest extends TestCase
{
    public function testConstantesVinculantes(): void
    {
        self::assertSame('djen:comunica-api', DjenRateLimitConfig::RATE_KEY);
        self::assertSame(30, DjenRateLimitConfig::LIMIT);
        self::assertSame(60, DjenRateLimitConfig::WINDOW_SECONDS);
        self::assertSame(90, DjenRateLimitConfig::CAP_SECONDS);
    }

    public function testClasseEFinalEConstrutorEPrivate(): void
    {
        $reflection = new ReflectionClass(DjenRateLimitConfig::class);
        self::assertTrue($reflection->isFinal(), 'DjenRateLimitConfig deve ser final');

        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);
        self::assertTrue(
            $constructor->isPrivate(),
            'Construtor deve ser private (classe não-instanciável)',
        );
    }

    public function testCapEhMaiorQueWindowSeconds(): void
    {
        // Invariante semântica: cap precisa permitir ≥1 reset completo da janela.
        self::assertGreaterThan(
            DjenRateLimitConfig::WINDOW_SECONDS,
            DjenRateLimitConfig::CAP_SECONDS,
            'CAP_SECONDS deve ser > WINDOW_SECONDS para permitir reset da janela durante a espera',
        );
    }
}
