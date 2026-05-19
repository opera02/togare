<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareRbac\Authentication\Mfa;

use Espo\Core\Api\Request;
use Espo\Core\Authentication\HeaderKey;
use Espo\Core\Authentication\Result;
use Espo\Core\Authentication\Result\FailReason;
use Espo\Core\Authentication\TwoFactor\Totp\Util;
use Espo\Entities\User;
use Espo\Entities\UserData;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareRbac\Authentication\Mfa\TogareTotpLogin;
use Espo\Modules\TogareRbac\Service\Mfa\BackupCodeService;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

/**
 * Cobre AC5, AC6 — TogareTotpLogin (TOTP sucesso, backup code fallback, ambos falha).
 */
final class TogareTotpLoginTest extends TestCase
{
    protected function setUp(): void
    {
        $stdout = \fopen('php://memory', 'w+');
        $stderr = \fopen('php://memory', 'w+');
        TogareLogger::init('test-totp-login', null, $stdout, $stderr);
    }

    private function makeUserWithMfa(string $id = 'user-01', string $secret = 'TESTSECRET'): User
    {
        $user = new User();
        $user->setId($id);
        $user->set('userName', 'socio_smoke');
        return $user;
    }

    private function makeUserDataWithMfa(string $secret = 'TESTSECRET'): UserData
    {
        $ud = new UserData();
        $ud->set([
            'auth2FA' => true,
            'auth2FAMethod' => 'Totp',
            'auth2FATotpSecret' => $secret,
        ]);
        return $ud;
    }

    private function makeEm(UserData $userData): EntityManager
    {
        $repo = new class($userData) {
            public function __construct(private UserData $ud) {}
            public function getByUserId(string $id): ?UserData { return $this->ud; }
        };

        $em = $this->createMock(EntityManager::class);
        $em->method('getRepository')->willReturn($repo);
        return $em;
    }

    private function makeRequest(?string $code): Request
    {
        $req = $this->createMock(Request::class);
        $req->method('getHeader')
            ->with(HeaderKey::AUTHORIZATION_CODE)
            ->willReturn($code);
        return $req;
    }

    public function testLoginSemCodigoRetornaSecondStep(): void
    {
        $user = $this->makeUserWithMfa();
        $em = $this->createStub(EntityManager::class);
        $totp = $this->createStub(Util::class);
        $backupSvc = $this->createStub(BackupCodeService::class);

        $login = new TogareTotpLogin($em, $totp, $backupSvc, new \tests\unit\Espo\Modules\TogareRbac\Stubs\AuditLogContractStub());
        $result = Result::success($user);
        $request = $this->makeRequest(null);

        $output = $login->login($result, $request);

        $this->assertTrue($output->isSecondStepRequired());
    }

    public function testLoginComTotpValidoSucesso(): void
    {
        $secret = 'TESTSECRET123456';
        $user = $this->makeUserWithMfa('user-01', $secret);
        $userData = $this->makeUserDataWithMfa($secret);
        $em = $this->makeEm($userData);

        $totp = $this->createMock(Util::class);
        $totp->method('verifyCode')
            ->with($secret, '123456')
            ->willReturn(true);

        $backupSvc = $this->createMock(BackupCodeService::class);
        $backupSvc->expects($this->never())->method('consume');

        $login = new TogareTotpLogin($em, $totp, $backupSvc, new \tests\unit\Espo\Modules\TogareRbac\Stubs\AuditLogContractStub());
        $initialResult = Result::success($user);
        $request = $this->makeRequest('123456');

        $output = $login->login($initialResult, $request);

        $this->assertFalse($output->isFail());
        $this->assertFalse($output->isSecondStepRequired());
    }

    public function testLoginComBackupCodeFallbackSucesso(): void
    {
        $secret = 'TESTSECRET123456';
        $user = $this->makeUserWithMfa('user-01', $secret);
        $userData = $this->makeUserDataWithMfa($secret);
        $em = $this->makeEm($userData);

        $totp = $this->createMock(Util::class);
        $totp->method('verifyCode')->willReturn(false);

        $backupSvc = $this->createMock(BackupCodeService::class);
        $backupSvc->method('consume')
            ->with($user, 'abcdef12')
            ->willReturn(true);

        $login = new TogareTotpLogin($em, $totp, $backupSvc, new \tests\unit\Espo\Modules\TogareRbac\Stubs\AuditLogContractStub());
        $initialResult = Result::success($user);
        $request = $this->makeRequest('abcd-ef12');

        $output = $login->login($initialResult, $request);

        $this->assertFalse($output->isFail());
        $this->assertFalse($output->isSecondStepRequired());
    }

    public function testLoginAmbosFalhaRetornaFail(): void
    {
        $secret = 'TESTSECRET123456';
        $user = $this->makeUserWithMfa('user-01', $secret);
        $userData = $this->makeUserDataWithMfa($secret);
        $em = $this->makeEm($userData);

        $totp = $this->createMock(Util::class);
        $totp->method('verifyCode')->willReturn(false);

        $backupSvc = $this->createMock(BackupCodeService::class);
        $backupSvc->method('consume')->willReturn(false);

        $login = new TogareTotpLogin($em, $totp, $backupSvc, new \tests\unit\Espo\Modules\TogareRbac\Stubs\AuditLogContractStub());
        $initialResult = Result::success($user);
        $request = $this->makeRequest('999999');

        $output = $login->login($initialResult, $request);

        $this->assertTrue($output->isFail());
        $this->assertSame(FailReason::CODE_NOT_VERIFIED, $output->getFailReason());
    }
}
