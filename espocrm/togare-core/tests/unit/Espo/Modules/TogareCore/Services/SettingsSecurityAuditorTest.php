<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Services;

use Espo\Modules\TogareCore\Services\SettingsSecurityAuditor;
use PHPUnit\Framework\TestCase;
use stdClass;
use tests\unit\Espo\Modules\TogareCore\Stubs\AuditLogContractStub;

/**
 * Cobre AC9 — SettingsSecurityAuditor (allowlist de 10 chaves).
 * Anti-pattern alvo: NÃO emitir audit para chaves cosméticas.
 */
final class SettingsSecurityAuditorTest extends TestCase
{
    public function testIgnoraChaveForaDaAllowlist(): void
    {
        $stub = new AuditLogContractStub();
        $auditor = new SettingsSecurityAuditor($stub);

        $data = new stdClass();
        $data->theme = 'Sakura';

        $auditor->auditChanges([], $data);

        self::assertCount(0, $stub->calls);
    }

    public function testNaoEmiteQuandoOldEqualsNew(): void
    {
        $stub = new AuditLogContractStub();
        $auditor = new SettingsSecurityAuditor($stub);

        $data = new stdClass();
        $data->passwordStrengthLength = 10;

        $auditor->auditChanges(['passwordStrengthLength' => 10], $data);

        self::assertCount(0, $stub->calls);
    }

    public function testCapturaPasswordStrengthLengthChange(): void
    {
        $stub = new AuditLogContractStub();
        $auditor = new SettingsSecurityAuditor($stub);

        $data = new stdClass();
        $data->passwordStrengthLength = 12;

        $auditor->auditChanges(['passwordStrengthLength' => 10], $data);

        self::assertCount(1, $stub->calls);
        self::assertSame('config.security.changed', $stub->calls[0]['event']);
        self::assertSame('Settings', $stub->calls[0]['entityType']);
        self::assertNull($stub->calls[0]['entityId']);
        self::assertSame('passwordStrengthLength', $stub->calls[0]['context']['key']);
        self::assertSame(10, $stub->calls[0]['context']['oldValue']);
        self::assertSame(12, $stub->calls[0]['context']['newValue']);
    }

    public function testCapturaMultiplasChavesNaMesmaOperacao(): void
    {
        $stub = new AuditLogContractStub();
        $auditor = new SettingsSecurityAuditor($stub);

        $data = new stdClass();
        $data->passwordStrengthLength = 12;
        $data->auth2FA = true;

        $auditor->auditChanges(
            ['passwordStrengthLength' => 10, 'auth2FA' => false],
            $data,
        );

        self::assertCount(2, $stub->calls);
        $keys = \array_column(\array_column($stub->calls, 'context'), 'key');
        self::assertContains('passwordStrengthLength', $keys);
        self::assertContains('auth2FA', $keys);
    }

    public function testAllowlistContemTodasAs10Chaves(): void
    {
        $expected = [
            'passwordStrengthLength', 'passwordStrengthLetterCount',
            'passwordStrengthNumberCount', 'passwordStrengthSpecialCharacterCount',
            'passwordStrengthBothCases', 'passwordChangeRequestNewUserLifetime',
            'auth2FA', 'auth2FAMethodList', 'auth2FAInPortal', 'auth2FAForced',
        ];
        // Canonicalizing: só o conjunto importa, não a ordem de declaração.
        self::assertEqualsCanonicalizing($expected, SettingsSecurityAuditor::SECURITY_KEYS);
    }

    public function testLooseEqualityEvitaFalsoAuditDeConversaoDeTipo(): void
    {
        // EspoCRM armazena booleans como 0/1 no banco. Payload de form pode
        // devolver int ou bool. Loose equality (==) é intencional — evita
        // audit-spam em mudanças de tipo sem mudança semântica.
        $stub = new AuditLogContractStub();
        $auditor = new SettingsSecurityAuditor($stub);

        $data = new stdClass();
        $data->auth2FA = false; // payload bool

        $auditor->auditChanges(['auth2FA' => 0], $data); // config armazena int 0

        // 0 == false → sem evento de audit (equivalência semântica)
        self::assertCount(0, $stub->calls);
    }

    public function testChaveAusenteNoPayloadEmiteComNewValueNull(): void
    {
        // Se $oldValues tem uma chave mas $newData não (payload parcial ou chave
        // removida da config), emite evento com newValue=null. Comportamento
        // documentado aqui para evitar regressão silenciosa.
        $stub = new AuditLogContractStub();
        $auditor = new SettingsSecurityAuditor($stub);

        $data = new stdClass(); // sem propriedade auth2FA

        $auditor->auditChanges(['auth2FA' => true], $data);

        self::assertCount(1, $stub->calls);
        self::assertSame('auth2FA', $stub->calls[0]['context']['key']);
        self::assertTrue($stub->calls[0]['context']['oldValue']);
        self::assertNull($stub->calls[0]['context']['newValue']);
    }
}
