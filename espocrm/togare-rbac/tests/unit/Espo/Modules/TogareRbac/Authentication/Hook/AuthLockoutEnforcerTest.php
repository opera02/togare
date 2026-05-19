<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareRbac\Authentication\Hook;

use Espo\Core\Authentication\AuthenticationData;
use Espo\Core\Authentication\Hook\BeforeLogin;
use Espo\Core\Exceptions\Forbidden;
use Espo\Modules\TogareCore\Services\RateLimiter;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareRbac\Authentication\Hook\AuthLockoutEnforcer;
use Espo\Modules\TogareRbac\Authentication\Hook\AuthRateLimitConfig;
use Espo\ORM\EntityManager;
use PDO;
use PHPUnit\Framework\TestCase;
use tests\unit\Espo\Modules\TogareRbac\Stubs\AuditLogContractStub;

final class AuthLockoutEnforcerTest extends TestCase
{
    private PDO $pdo;
    private RateLimiter $rateLimiter;
    private AuditLogContractStub $auditStub;
    private AuthLockoutEnforcer $hook;

    protected function setUp(): void
    {
        $stdout = \fopen('php://memory', 'w+');
        $stderr = \fopen('php://memory', 'w+');
        TogareLogger::init('test-rbac-lockout', null, $stdout, $stderr);

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE togare_rate_limits (
                rate_key VARCHAR(200) NOT NULL PRIMARY KEY,
                counter INTEGER NOT NULL DEFAULT 0,
                window_started_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            )
        ');

        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willReturn($this->pdo);

        $this->rateLimiter = new RateLimiter($em);
        $this->auditStub = new AuditLogContractStub();
        $this->hook = new AuthLockoutEnforcer($this->rateLimiter, $this->auditStub);
    }

    private function buildRequest(?string $ip = '127.0.0.1'): \Espo\Core\Api\Request
    {
        $req = $this->createMock(\Espo\Core\Api\Request::class);
        $req->method('getServerParam')->with('REMOTE_ADDR')->willReturn($ip);
        return $req;
    }

    public function testImplementsBeforeLogin(): void
    {
        self::assertInstanceOf(BeforeLogin::class, $this->hook);
    }

    public function testProcessNaoBloqueiaQuandoUsernameVazio(): void
    {
        $data = new AuthenticationData('', null, null);

        $this->hook->process($data, $this->buildRequest());

        self::assertEmpty($this->auditStub->calls, 'Username vazio → nenhuma ação.');
    }

    public function testProcessNaoBloqueiaQuandoBudgetDisponivel(): void
    {
        // Apenas 2 falhas — abaixo do limite de 5.
        for ($i = 0; $i < 2; $i++) {
            $this->rateLimiter->check(AuthRateLimitConfig::KEY_PREFIX . 'socio_test', AuthRateLimitConfig::LIMIT, AuthRateLimitConfig::WINDOW_SECONDS);
        }

        $data = new AuthenticationData('socio_test', null, null);

        $this->hook->process($data, $this->buildRequest());

        self::assertEmpty($this->auditStub->calls, 'Budget disponível → sem lockout.');
    }

    public function testProcessLancaForbiddenQuandoLockoutAtivo(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->rateLimiter->check(AuthRateLimitConfig::KEY_PREFIX . 'socio_smoke', AuthRateLimitConfig::LIMIT, AuthRateLimitConfig::WINDOW_SECONDS);
        }

        $data = new AuthenticationData('socio_smoke', null, null);

        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage('Conta temporariamente bloqueada. Tente novamente em 15 minutos.');

        $this->hook->process($data, $this->buildRequest());
    }

    public function testProcessRegistraAuditLogAuthLockoutNoBloqueio(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->rateLimiter->check(AuthRateLimitConfig::KEY_PREFIX . 'socio_audit', AuthRateLimitConfig::LIMIT, AuthRateLimitConfig::WINDOW_SECONDS);
        }

        $data = new AuthenticationData('socio_audit', null, null);

        try {
            $this->hook->process($data, $this->buildRequest('10.0.0.1'));
        } catch (Forbidden) {
        }

        self::assertCount(1, $this->auditStub->calls, 'Deve registrar exatamente 1 audit entry.');

        $call = $this->auditStub->calls[0];
        self::assertSame('auth.lockout', $call['event']);
        self::assertSame('*', $call['entityType']);
        self::assertNull($call['entityId']);
        self::assertSame('socio_audit', $call['context']['userName']);
        self::assertSame('10.0.0.1', $call['context']['ipAddress']);
        self::assertSame(AuthRateLimitConfig::LIMIT, $call['context']['limit']);
        self::assertSame(AuthRateLimitConfig::WINDOW_SECONDS, $call['context']['windowSec']);
    }

    public function testKeyEhLowercaseDoUsername(): void
    {
        // Enche o bucket com a key em lowercase.
        for ($i = 0; $i < 5; $i++) {
            $this->rateLimiter->check(AuthRateLimitConfig::KEY_PREFIX . 'socio_smoke', AuthRateLimitConfig::LIMIT, AuthRateLimitConfig::WINDOW_SECONDS);
        }

        // Hook recebe username com casing misto — deve normalizar para lowercase.
        $data = new AuthenticationData('Socio_Smoke', null, null);

        $this->expectException(Forbidden::class);

        $this->hook->process($data, $this->buildRequest());
    }
}
