<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareRbac\Service;

use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareRbac\Service\SecurityConfigInstaller;
use PHPUnit\Framework\TestCase;

/**
 * Cobre AC1 — 6 configs aplicadas idempotentemente.
 */
final class SecurityConfigInstallerTest extends TestCase
{
    protected function setUp(): void
    {
        $stdout = \fopen('php://memory', 'w+');
        $stderr = \fopen('php://memory', 'w+');
        TogareLogger::init('test-rbac-config', null, $stdout, $stderr);
    }

    public function testAplicaTodasAs13ConfigsEmInstanciaLimpa(): void
    {
        $config = new Config();
        $writer = new ConfigWriter();

        $installer = new SecurityConfigInstaller($config, $writer);
        $summary = $installer->applyDefaults();

        $this->assertCount(13, $summary['applied'], 'Story 2.5 adicionou 4 chaves → total 13.');
        $this->assertCount(0, $summary['skipped']);
        $this->assertTrue($writer->saved);

        $expected = SecurityConfigInstaller::DEFAULTS;
        foreach ($expected as $k => $v) {
            $this->assertSame($v, $writer->applied[$k] ?? null, "Config '{$k}' não foi aplicada.");
        }
    }

    public function testIdempotente_segundaExecucaoSkipaTodas(): void
    {
        $config = new Config(SecurityConfigInstaller::DEFAULTS);
        $writer = new ConfigWriter();

        $installer = new SecurityConfigInstaller($config, $writer);
        $summary = $installer->applyDefaults();

        $this->assertSame(0, \count($summary['applied']));
        $this->assertSame(13, \count($summary['skipped']), 'Story 2.5: 13 configs totais (6 senha + 3 MFA + 4 sessão).');
        $this->assertFalse($writer->saved, 'Writer NÃO deve salvar quando todas as configs já estão no valor esperado.');
    }

    public function testV2NovasChavesMfaAplicadas(): void
    {
        $config = new Config();
        $writer = new ConfigWriter();

        $installer = new SecurityConfigInstaller($config, $writer);
        $installer->applyDefaults();

        $this->assertSame(true, $writer->applied['auth2FA'] ?? null, 'auth2FA deve ser true.');
        $this->assertSame(['Totp'], $writer->applied['auth2FAMethodList'] ?? null, 'auth2FAMethodList deve ser [Totp].');
        $this->assertSame(false, $writer->applied['auth2FAInPortal'] ?? null, 'auth2FAInPortal deve ser false.');
    }

    public function testAuth2FaSkipQuandoJaAtivo(): void
    {
        $config = new Config(['auth2FA' => true, 'auth2FAInPortal' => false, 'auth2FAMethodList' => ['Totp']]);
        $writer = new ConfigWriter();

        $installer = new SecurityConfigInstaller($config, $writer);
        $summary = $installer->applyDefaults();

        $this->assertContains('auth2FA', $summary['skipped']);
        $this->assertContains('auth2FAInPortal', $summary['skipped']);
        $this->assertContains('auth2FAMethodList', $summary['skipped']);
        $this->assertArrayNotHasKey('auth2FA', $writer->applied);
    }

    public function testPreservaConfigCustomMaisRestritivo(): void
    {
        // Admin pôs length=14 (mais restritivo que default 10) — não pode baixar.
        $config = new Config([
            'passwordStrengthLength' => 14,
            'passwordStrengthBothCases' => true,
        ]);
        $writer = new ConfigWriter();

        $installer = new SecurityConfigInstaller($config, $writer);
        $summary = $installer->applyDefaults();

        $this->assertContains('passwordStrengthLength', $summary['skipped']);
        $this->assertContains('passwordStrengthBothCases', $summary['skipped']);

        // Mas as 4 outras configs ausentes foram aplicadas.
        $this->assertContains('passwordStrengthNumberCount', $summary['applied']);

        // Valor 14 NÃO foi sobrescrito.
        $this->assertArrayNotHasKey('passwordStrengthLength', $writer->applied);
    }

    public function testAplicaQuandoAdminPosValorMenosRestritivo(): void
    {
        // Admin pôs length=6 (menos restritivo que default 10) — eleva pra 10.
        $config = new Config([
            'passwordStrengthLength' => 6,
        ]);
        $writer = new ConfigWriter();

        $installer = new SecurityConfigInstaller($config, $writer);
        $summary = $installer->applyDefaults();

        $this->assertContains('passwordStrengthLength', $summary['applied']);
        $this->assertSame(10, $writer->applied['passwordStrengthLength'] ?? null);
    }

    public function testLifetimeMenor_AdminPreservadoPorqueMaisRestritivo(): void
    {
        // TTL menor = mais restritivo. Admin pôs 24h, default é 168h (7 dias).
        // Nosso default exige ≥168 mas se admin quer ≤24h, manter (mais restritivo).
        $config = new Config(['passwordChangeRequestNewUserLifetime' => 24]);
        $writer = new ConfigWriter();

        $installer = new SecurityConfigInstaller($config, $writer);
        $summary = $installer->applyDefaults();

        $this->assertContains('passwordChangeRequestNewUserLifetime', $summary['skipped']);
    }

    public function testLifetimeMaior_AdminBaixadoPraDefault(): void
    {
        // Admin pôs 720h (30 dias) — default 168h é mais restritivo. Aplica 168.
        $config = new Config(['passwordChangeRequestNewUserLifetime' => 720]);
        $writer = new ConfigWriter();

        $installer = new SecurityConfigInstaller($config, $writer);
        $summary = $installer->applyDefaults();

        $this->assertContains('passwordChangeRequestNewUserLifetime', $summary['applied']);
        $this->assertSame(168, $writer->applied['passwordChangeRequestNewUserLifetime']);
    }

    // ---- Story 2.5 — authTokenMaxIdleTime + sessão ----

    public function testApplyDefaultsAdicionaAuthTokenMaxIdleTimeQuandoNull(): void
    {
        $config = new Config();
        $writer = new ConfigWriter();

        $installer = new SecurityConfigInstaller($config, $writer);
        $summary = $installer->applyDefaults();

        $this->assertContains('authTokenMaxIdleTime', $summary['applied']);
        $this->assertSame(0.5, $writer->applied['authTokenMaxIdleTime'] ?? null);
    }

    public function testApplyDefaultsPreservaAuthTokenMaxIdleTimeQuandoMaisRestritivo(): void
    {
        // Admin pôs 0.25 (15 min) — mais restritivo que 0.5 (30 min). Preservar.
        $config = new Config(['authTokenMaxIdleTime' => 0.25]);
        $writer = new ConfigWriter();

        $installer = new SecurityConfigInstaller($config, $writer);
        $summary = $installer->applyDefaults();

        $this->assertContains('authTokenMaxIdleTime', $summary['skipped']);
        $this->assertArrayNotHasKey('authTokenMaxIdleTime', $writer->applied);
    }

    public function testApplyDefaultsAplicaAuthTokenMaxIdleTimeQuandoMaisFrouxo(): void
    {
        // Admin pôs 2.0 (2h) — menos restritivo que 0.5. Eleva para 0.5.
        $config = new Config(['authTokenMaxIdleTime' => 2.0]);
        $writer = new ConfigWriter();

        $installer = new SecurityConfigInstaller($config, $writer);
        $summary = $installer->applyDefaults();

        $this->assertContains('authTokenMaxIdleTime', $summary['applied']);
        $this->assertSame(0.5, $writer->applied['authTokenMaxIdleTime']);
    }

    public function testApplyDefaultsPreservaAuthMaxFailedAttemptNumberQuandoMaisRestritivo(): void
    {
        // Admin pôs 5 (mais restritivo que default 10). Preservar.
        $config = new Config(['authMaxFailedAttemptNumber' => 5]);
        $writer = new ConfigWriter();

        $installer = new SecurityConfigInstaller($config, $writer);
        $summary = $installer->applyDefaults();

        $this->assertContains('authMaxFailedAttemptNumber', $summary['skipped']);
        $this->assertArrayNotHasKey('authMaxFailedAttemptNumber', $writer->applied);
    }
}
