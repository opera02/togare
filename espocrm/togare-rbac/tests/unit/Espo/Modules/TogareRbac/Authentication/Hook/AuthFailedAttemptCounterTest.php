<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareRbac\Authentication\Hook;

use Espo\Core\Authentication\AuthenticationData;
use Espo\Core\Authentication\Result;
use Espo\Modules\TogareCore\Services\RateLimiter;
use Espo\Modules\TogareRbac\Authentication\Hook\AuthFailedAttemptCounter;
use Espo\Modules\TogareRbac\Authentication\Hook\AuthRateLimitConfig;
use Espo\ORM\EntityManager;
use PDO;
use PHPUnit\Framework\TestCase;

final class AuthFailedAttemptCounterTest extends TestCase
{
    private PDO $pdo;
    private RateLimiter $rateLimiter;
    private AuthFailedAttemptCounter $hook;

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
        $this->hook = new AuthFailedAttemptCounter($this->rateLimiter);
    }

    private function buildRequest(): \Espo\Core\Api\Request
    {
        return $this->createMock(\Espo\Core\Api\Request::class);
    }

    private function getCounter(string $key): int
    {
        $row = $this->pdo->query("SELECT counter FROM togare_rate_limits WHERE rate_key = '{$key}'")->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? (int) $row['counter'] : 0;
    }

    public function testIncrementaContadorEmFalha(): void
    {
        $user = new \Espo\Entities\User();
        $result = Result::fail('wrongCredentials');
        $data = new AuthenticationData('advogado_test', null, null);

        $this->hook->process($result, $data, $this->buildRequest());

        $key = AuthRateLimitConfig::KEY_PREFIX . 'advogado_test';
        self::assertSame(1, $this->getCounter($key), 'Falha deve incrementar counter para 1.');
    }

    public function testNaoIncrementaEmSucesso(): void
    {
        $user = new \Espo\Entities\User();
        $user->set('userName', 'advogado_test');
        $result = Result::success($user);
        $data = new AuthenticationData('advogado_test', null, null);

        $this->hook->process($result, $data, $this->buildRequest());

        $key = AuthRateLimitConfig::KEY_PREFIX . 'advogado_test';
        self::assertSame(0, $this->getCounter($key), 'Sucesso NÃO deve incrementar counter.');
    }

    public function testIgnoraUsernameVazio(): void
    {
        $result = Result::fail('wrongCredentials');
        $data = new AuthenticationData('', null, null);

        $this->hook->process($result, $data, $this->buildRequest());

        $row = $this->pdo->query('SELECT COUNT(*) as c FROM togare_rate_limits')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(0, (int) $row['c'], 'Username vazio → tabela permanece vazia.');
    }

    public function testIncrementaContadorParaUsernameInexistente(): void
    {
        // AC7: username que não existe no EspoCRM deve ser rate-limitado igual a um real.
        // O hook é agnóstico à existência do User — apenas opera no contador por username string.
        $result = Result::fail('wrongCredentials');
        $data = new AuthenticationData('usuario_fantasma_que_nao_existe', null, null);

        $this->hook->process($result, $data, $this->buildRequest());

        $key = AuthRateLimitConfig::KEY_PREFIX . 'usuario_fantasma_que_nao_existe';
        self::assertSame(1, $this->getCounter($key), 'Username inexistente deve ter contador incrementado igual a um real (AC7).');
    }
}
