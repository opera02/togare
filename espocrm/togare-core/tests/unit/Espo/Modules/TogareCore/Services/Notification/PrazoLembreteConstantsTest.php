<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Services\Notification;

use Espo\Modules\TogareCore\Services\Notification\PrazoLembreteConstants;
use PHPUnit\Framework\TestCase;

/**
 * Cobre as constantes e helpers do subsistema togare-core/Notifications &
 * Reminders. Story 4b.3 (UX-DR10) adiciona marco D-0 + 2 maps de hora/minuto
 * por marco — esta classe valida AC1 + parte de AC4 (subject literal D-0).
 */
final class PrazoLembreteConstantsTest extends TestCase
{
    // ============================================================
    // Decisão #1 — D-0 entra no DEADLINE_OFFSETS (offset = 0)
    // ============================================================

    public function testDeadlineOffsetsIncluiD0ComOffsetZero(): void
    {
        self::assertArrayHasKey(PrazoLembreteConstants::MARCO_D0, PrazoLembreteConstants::DEADLINE_OFFSETS);
        self::assertSame(0, PrazoLembreteConstants::DEADLINE_OFFSETS[PrazoLembreteConstants::MARCO_D0]);
    }

    public function testMarcoD0ConstanteValeStringDZero(): void
    {
        self::assertSame('D-0', PrazoLembreteConstants::MARCO_D0);
    }

    public function testDeadlineOffsetsMantem4MarcosDeProximidade(): void
    {
        // Story 4b.3 estende 3 → 4 marcos. Asserts presença sem assumir ordem.
        $offsets = PrazoLembreteConstants::DEADLINE_OFFSETS;
        self::assertCount(4, $offsets);
        self::assertArrayHasKey(PrazoLembreteConstants::MARCO_D7, $offsets);
        self::assertArrayHasKey(PrazoLembreteConstants::MARCO_D3, $offsets);
        self::assertArrayHasKey(PrazoLembreteConstants::MARCO_D1, $offsets);
        self::assertArrayHasKey(PrazoLembreteConstants::MARCO_D0, $offsets);
        // Não-regressão dos existentes:
        self::assertSame(7, $offsets[PrazoLembreteConstants::MARCO_D7]);
        self::assertSame(3, $offsets[PrazoLembreteConstants::MARCO_D3]);
        self::assertSame(1, $offsets[PrazoLembreteConstants::MARCO_D1]);
    }

    // ============================================================
    // Decisão #2 — Maps HORA_DISPARO_BY_MARCO + MINUTO_DISPARO_BY_MARCO
    //              D-0 dispara 00:05 BRT; demais 09:00 BRT.
    // ============================================================

    public function testHoraDisparoByMarcoMapeiaD0ParaZeroEDemaisParaNove(): void
    {
        $map = PrazoLembreteConstants::HORA_DISPARO_BY_MARCO;
        self::assertSame(0, $map[PrazoLembreteConstants::MARCO_D0]);
        self::assertSame(9, $map[PrazoLembreteConstants::MARCO_D7]);
        self::assertSame(9, $map[PrazoLembreteConstants::MARCO_D3]);
        self::assertSame(9, $map[PrazoLembreteConstants::MARCO_D1]);
    }

    public function testMinutoDisparoByMarcoMapeiaD0ParaCinco(): void
    {
        $map = PrazoLembreteConstants::MINUTO_DISPARO_BY_MARCO;
        self::assertSame(5, $map[PrazoLembreteConstants::MARCO_D0]);
        self::assertSame(0, $map[PrazoLembreteConstants::MARCO_D7]);
        self::assertSame(0, $map[PrazoLembreteConstants::MARCO_D3]);
        self::assertSame(0, $map[PrazoLembreteConstants::MARCO_D1]);
    }

    public function testHoraDisparoLegacyConstanteMantemNoveParaRetroCompat(): void
    {
        // 4b.2 usava `PrazoLembreteConstants::HORA_DISPARO` escalar = 9.
        // 4b.3 mantém a const para retro-compat — código que ainda referencia
        // continua lendo 9 (fallback para D-7/D-3/D-1).
        self::assertSame(9, PrazoLembreteConstants::HORA_DISPARO);
    }

    // ============================================================
    // Decisão #5 — D-0 está no defaultConfig.marcos como true (fail-safe).
    // ============================================================

    public function testDefaultConfigIncluiD0True(): void
    {
        $config = PrazoLembreteConstants::defaultConfig();
        self::assertTrue($config['marcos'][PrazoLembreteConstants::MARCO_D0]);
        // Não-regressão dos existentes:
        self::assertTrue($config['marcos'][PrazoLembreteConstants::MARCO_D7]);
        self::assertTrue($config['marcos'][PrazoLembreteConstants::MARCO_D3]);
        self::assertTrue($config['marcos'][PrazoLembreteConstants::MARCO_D1]);
        self::assertTrue($config['marcos']['status_dirigido']);
    }

    // ============================================================
    // mergeWithDefaults preserva escolha do user E aplica default em key ausente
    // ============================================================

    public function testMergeWithDefaultsRespeitaD0FalseExplicito(): void
    {
        $userConfig = ['marcos' => ['D-0' => false]];
        $merged = PrazoLembreteConstants::mergeWithDefaults($userConfig);
        self::assertFalse($merged['marcos']['D-0']);
    }

    public function testMergeWithDefaultsAplicaD0DefaultQuandoAusente(): void
    {
        $userConfig = ['marcos' => ['D-7' => false]]; // user só desligou D-7
        $merged = PrazoLembreteConstants::mergeWithDefaults($userConfig);
        self::assertTrue($merged['marcos']['D-0']); // default true
        self::assertFalse($merged['marcos']['D-7']);
    }

    // ============================================================
    // resolveCanal honra preferences do user para marco D-0
    // ============================================================

    public function testResolveCanalRetornaNullQuandoUserDesativaD0(): void
    {
        $userConfig = ['marcos' => ['D-0' => false]];
        $canal = PrazoLembreteConstants::resolveCanal($userConfig, 'D-0');
        self::assertNull($canal);
    }

    public function testResolveCanalRetornaBothComDefaultsParaD0(): void
    {
        $canal = PrazoLembreteConstants::resolveCanal([], 'D-0');
        self::assertSame(PrazoLembreteConstants::CANAL_BOTH, $canal);
    }

    public function testResolveCanalRetornaPopupSeUserDesligaEmail(): void
    {
        $userConfig = ['channels' => ['popup' => true, 'email' => false]];
        $canal = PrazoLembreteConstants::resolveCanal($userConfig, 'D-0');
        self::assertSame(PrazoLembreteConstants::CANAL_POPUP, $canal);
    }

    // ============================================================
    // labelsForMarco — D-0 retorna subject especial "VENCE HOJE"
    // ============================================================

    public function testLabelsForMarcoD0RetornaSubjectVenceHojeSemPrefixoPrazo(): void
    {
        $cnj = '0001234-56.2026.8.26.0001';
        $labels = PrazoLembreteConstants::labelsForMarco('D-0', $cnj);
        // Subject literal AC5 da Story 4b.3:
        // "[Togare] VENCE HOJE — 0001234-56.2026.8.26.0001"
        // Sem o "Prazo " prefixado por contraste com os outros marcos
        // (urgência > formalidade — Decisão #7 da Story 4b.3).
        self::assertSame('[Togare] VENCE HOJE — 0001234-56.2026.8.26.0001', $labels['subject']);
        self::assertSame('VENCE HOJE', $labels['title']);
    }

    public function testLabelsForMarcoD7MantemSubjectAntigoNaoRegrediu(): void
    {
        // Não-regressão — D-7 continua com pattern "Prazo vence em 7 dias úteis"
        // e prefixo "[Togare] {title} — {cnj}".
        $cnj = '0001234-56.2026.8.26.0001';
        $labels = PrazoLembreteConstants::labelsForMarco('D-7', $cnj);
        self::assertSame('Prazo vence em 7 dias úteis', $labels['title']);
        self::assertSame('[Togare] Prazo vence em 7 dias úteis — 0001234-56.2026.8.26.0001', $labels['subject']);
    }
}
