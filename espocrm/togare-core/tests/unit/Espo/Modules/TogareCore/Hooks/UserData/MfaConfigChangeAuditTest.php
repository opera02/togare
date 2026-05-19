<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\UserData;

use Espo\Entities\User;
use Espo\Entities\UserData;
use Espo\Modules\TogareCore\Hooks\UserData\MfaConfigChangeAudit;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;
use tests\unit\Espo\Modules\TogareCore\Stubs\AuditLogContractStub;

/**
 * Cobre AC10 — MfaConfigChangeAudit (afterSave UserData distingue
 * enable vs disable via isAttributeChanged('auth2FA')).
 */
final class MfaConfigChangeAuditTest extends TestCase
{
    private function makeEm(?User $user): EntityManager
    {
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn($user);
        return $em;
    }

    public function testDistingueEnableVsDisable(): void
    {
        $stub = new AuditLogContractStub();
        $user = new User();
        $user->setId('user-01');
        $user->set('userName', 'advogado_smoke');

        $hook = new MfaConfigChangeAudit($stub, $this->makeEm($user));
        $opts = SaveOptions::create();

        // Enable: fetched=false, current=true.
        $ud = new UserData();
        $ud->setId('ud-01');
        $ud->setFetched('auth2FA', false);
        $ud->set(['userId' => 'user-01', 'auth2FA' => true, 'auth2FAMethod' => 'Totp']);
        $hook->afterSave($ud, $opts);

        // Disable: fetched=true+Totp, current=false (method limpo no mesmo save).
        $ud2 = new UserData();
        $ud2->setId('ud-02');
        $ud2->setFetched('auth2FA', true);
        $ud2->setFetched('auth2FAMethod', 'Totp'); // método ativo antes do disable
        $ud2->set(['userId' => 'user-01', 'auth2FA' => false]);
        $hook->afterSave($ud2, $opts);

        self::assertCount(2, $stub->calls);
        self::assertSame('user.mfa.enabled', $stub->calls[0]['event']);
        self::assertSame('user.mfa.disabled', $stub->calls[1]['event']);
        self::assertSame('UserData', $stub->calls[0]['entityType']);
        self::assertSame('advogado_smoke', $stub->calls[0]['context']['userName']);
        self::assertSame('user-01', $stub->calls[0]['context']['userId']);
        // Enable: método vem de get('auth2FAMethod')
        self::assertSame('Totp', $stub->calls[0]['context']['method']);
        // Disable: método vem de getFetched('auth2FAMethod') — captura o que estava ativo
        self::assertSame('Totp', $stub->calls[1]['context']['method']);
    }

    public function testIgnoraQuandoAuth2FaNaoMudou(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new MfaConfigChangeAudit($stub, $this->makeEm(null));

        $ud = new UserData();
        $ud->setId('ud-03');
        $ud->setFetched('auth2FA', true);
        $ud->set(['userId' => 'user-01', 'auth2FA' => true]); // mesmo valor
        $ud->set(['firstName' => 'João']); // mudança em outro campo

        $hook->afterSave($ud, SaveOptions::create());

        self::assertCount(0, $stub->calls);
    }
}
