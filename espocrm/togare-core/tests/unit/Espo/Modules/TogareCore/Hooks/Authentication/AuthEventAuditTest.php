<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\Authentication;

use Espo\Core\Api\Request;
use Espo\Core\Authentication\AuthenticationData;
use Espo\Core\Authentication\Hook\OnResult;
use Espo\Core\Authentication\Result;
use Espo\Core\Authentication\Result\FailReason;
use Espo\Entities\User;
use Espo\Modules\TogareCore\Hooks\Authentication\AuthEventAudit;
use PHPUnit\Framework\TestCase;
use tests\unit\Espo\Modules\TogareCore\Stubs\AuditLogContractStub;

/**
 * Cobre AC6 — Hook AuthEventAudit (OnResult para auth.login.success/failed).
 */
final class AuthEventAuditTest extends TestCase
{
    public function testImplementsOnResultInterface(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuthEventAudit($stub);
        self::assertInstanceOf(OnResult::class, $hook);
    }

    public function testMapeiaSucessoParaEventCorreto(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuthEventAudit($stub);

        $user = $this->createMock(User::class);
        $user->method('getUserName')->willReturn('socio_smoke');

        $result = Result::success($user);
        $data = new AuthenticationData('socio_smoke', 'pass');
        $request = $this->createMock(Request::class);
        $request->method('getServerParam')->with('REMOTE_ADDR')->willReturn('203.0.113.7');

        $hook->process($result, $data, $request);

        self::assertCount(1, $stub->calls);
        self::assertSame('auth.login.success', $stub->calls[0]['event']);
        self::assertSame('AuthLogRecord', $stub->calls[0]['entityType']);
        self::assertNull($stub->calls[0]['entityId']);
        self::assertSame('socio_smoke', $stub->calls[0]['context']['userName']);
        self::assertSame('203.0.113.7', $stub->calls[0]['context']['ipAddress']);
        self::assertArrayNotHasKey('failReason', $stub->calls[0]['context']);
    }

    public function testMapeisFalhaParaEventCorreto(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuthEventAudit($stub);

        $result = Result::fail(FailReason::WRONG_CREDENTIALS);
        $data = new AuthenticationData('admin', 'wrong');
        $request = $this->createMock(Request::class);
        $request->method('getServerParam')->with('REMOTE_ADDR')->willReturn('10.0.0.1');

        $hook->process($result, $data, $request);

        self::assertCount(1, $stub->calls);
        self::assertSame('auth.login.failed', $stub->calls[0]['event']);
        self::assertSame('admin', $stub->calls[0]['context']['userName']);
        self::assertSame(FailReason::WRONG_CREDENTIALS, $stub->calls[0]['context']['failReason']);
    }

    public function testFailReasonDesconhecidoViiraUnknown(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuthEventAudit($stub);

        $result = Result::fail('some-custom-plugin-reason');
        $data = new AuthenticationData('x', 'y');
        $request = $this->createMock(Request::class);
        $request->method('getServerParam')->willReturn(null);

        $hook->process($result, $data, $request);

        self::assertSame('unknown', $stub->calls[0]['context']['failReason']);
    }

    public function testUserNameVemDeAuthDataQuandoUserNulo(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuthEventAudit($stub);

        $result = Result::fail();
        $data = new AuthenticationData('unknownuser', 'badpass');
        $request = $this->createMock(Request::class);
        $request->method('getServerParam')->willReturn(null);

        $hook->process($result, $data, $request);

        self::assertCount(1, $stub->calls);
        self::assertSame('unknownuser', $stub->calls[0]['context']['userName']);
    }

    public function testIpNuloNaoAdicionadoAoContext(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuthEventAudit($stub);

        $result = Result::fail();
        $data = new AuthenticationData('x', 'y');
        $request = $this->createMock(Request::class);
        $request->method('getServerParam')->willReturn(null);

        $hook->process($result, $data, $request);

        self::assertArrayNotHasKey('ipAddress', $stub->calls[0]['context']);
    }
}
