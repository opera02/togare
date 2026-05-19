<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareRbac\Authentication\Hook;

use Espo\Core\Authentication\AuthenticationData;
use Espo\Core\Authentication\Result;
use Espo\Modules\TogareCore\Services\RateLimiter;
use Espo\Modules\TogareRbac\Authentication\Hook\AuthRateLimitConfig;
use Espo\Modules\TogareRbac\Authentication\Hook\AuthSuccessRateLimitReset;
use Espo\ORM\EntityManager;
use PDO;
use PHPUnit\Framework\TestCase;

final class AuthSuccessRateLimitResetTest extends TestCase
{
    private PDO $pdo;
    private RateLimiter $rateLimiter;
    private AuthSuccessRateLimitReset $hook;

    protected function setUp(): void
    {
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
        $this->hook = new AuthSuccessRateLimitReset($this->rateLimiter);
    }

    private function buildRequest(): \Espo\Core\Api\Request
    {
        return $this->createMock(\Espo\Core\Api\Request::class);
    }

    public function testResetaContadorEmSucesso(): void
    {
        $key = AuthRateLimitConfig::KEY_PREFIX . 'socio_reset';

        // Pré-popula 3 falhas.
        for ($i = 0; $i < 3; $i++) {
            $this->rateLimiter->check($key, AuthRateLimitConfig::LIMIT, AuthRateLimitConfig::WINDOW_SECONDS);
        }

        $user = new \Espo\Entities\User();
        $user->set('userName', 'socio_reset');
        $result = Result::success($user);
        $data = new AuthenticationData('socio_reset', null, null);

        $this->hook->process($result, $data, $this->buildRequest());

        $row = $this->pdo->query("SELECT * FROM togare_rate_limits WHERE rate_key = '{$key}'")->fetch(PDO::FETCH_ASSOC);
        self::assertFalse($row, 'Sucesso deve deletar a linha do contador.');
    }

    public function testNaoFazNadaEmFalha(): void
    {
        $key = AuthRateLimitConfig::KEY_PREFIX . 'socio_fail';

        $this->rateLimiter->check($key, AuthRateLimitConfig::LIMIT, AuthRateLimitConfig::WINDOW_SECONDS);

        $result = Result::fail('wrongCredentials');
        $data = new AuthenticationData('socio_fail', null, null);

        $this->hook->process($result, $data, $this->buildRequest());

        $row = $this->pdo->query("SELECT counter FROM togare_rate_limits WHERE rate_key = '{$key}'")->fetch(PDO::FETCH_ASSOC);
        self::assertSame(1, (int) $row['counter'], 'Falha → reset NÃO deve apagar o contador.');
    }
}
