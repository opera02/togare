<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore;

use Espo\Modules\TogareCore\Services\RateLimiter;
use Espo\ORM\EntityManager;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Testes unit do RateLimiter — SQLite in-memory.
 *
 * RateLimiter não usa TogareLogger — classe instanciável pura, sem state
 * estático. Não precisa de RunInSeparateProcess.
 */
final class RateLimiterTest extends TestCase
{
    private PDO $pdo;
    private RateLimiter $limiter;

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
        $this->limiter = new RateLimiter($em);
    }

    public function testAllowsUnderLimit(): void
    {
        for ($i = 0; $i < 29; $i++) {
            self::assertTrue(
                $this->limiter->check('djen:api', limit: 30, windowSeconds: 60),
                "Chamada {$i} deveria ser permitida",
            );
        }

        $row = $this->pdo->query("SELECT counter FROM togare_rate_limits WHERE rate_key = 'djen:api'")
            ->fetch(PDO::FETCH_ASSOC);
        self::assertSame(29, (int) $row['counter']);
    }

    public function testDeniesOverLimit(): void
    {
        for ($i = 0; $i < 30; $i++) {
            self::assertTrue($this->limiter->check('auth:login', limit: 30, windowSeconds: 60));
        }

        // 31ª chamada: acima do limite, dentro da janela.
        self::assertFalse($this->limiter->check('auth:login', limit: 30, windowSeconds: 60));

        // Contador não incrementa além de 30.
        $row = $this->pdo->query("SELECT counter FROM togare_rate_limits WHERE rate_key = 'auth:login'")
            ->fetch(PDO::FETCH_ASSOC);
        self::assertSame(30, (int) $row['counter']);
    }

    public function testResetsAfterWindowExpires(): void
    {
        // Chave artificial: inserir com window_started_at antigo.
        $stmt = $this->pdo->prepare('
            INSERT INTO togare_rate_limits (rate_key, counter, window_started_at, updated_at)
            VALUES (:k, 30, :old, :now)
        ');
        $stmt->execute([
            ':k' => 'expired:key',
            ':old' => (new \DateTimeImmutable('-2 minutes'))->format('Y-m-d H:i:s'),
            ':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        // Janela de 60s expirou → deve resetar e permitir.
        self::assertTrue($this->limiter->check('expired:key', limit: 30, windowSeconds: 60));

        $row = $this->pdo->query("SELECT counter FROM togare_rate_limits WHERE rate_key = 'expired:key'")
            ->fetch(PDO::FETCH_ASSOC);
        self::assertSame(1, (int) $row['counter']);
    }

    public function testResetMethodDeletesKey(): void
    {
        $this->limiter->check('temp:key', 10, 60);
        $this->limiter->reset('temp:key');

        $row = $this->pdo->query("SELECT * FROM togare_rate_limits WHERE rate_key = 'temp:key'")
            ->fetch(PDO::FETCH_ASSOC);
        self::assertFalse($row);
    }

    public function testPeekRetornaTrueQuandoChaveNaoExiste(): void
    {
        $result = $this->limiter->peek('peek:nova', 5, 900);

        self::assertTrue($result, 'Chave inexistente deve retornar true (orçamento disponível).');

        $row = $this->pdo->query("SELECT * FROM togare_rate_limits WHERE rate_key = 'peek:nova'")
            ->fetch(PDO::FETCH_ASSOC);
        self::assertFalse($row, 'peek não deve criar linha na tabela.');
    }

    public function testPeekRetornaFalseQuandoLimitAtingidoNaJanela(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->limiter->check('peek:limit', 5, 900);
        }

        for ($i = 0; $i < 6; $i++) {
            self::assertFalse(
                $this->limiter->peek('peek:limit', 5, 900),
                "peek deve retornar false quando counter=5 (chamada {$i}).",
            );
        }
    }

    public function testPeekNaoIncrementaContador(): void
    {
        $this->limiter->check('peek:counter', 10, 900);

        for ($i = 0; $i < 50; $i++) {
            $this->limiter->peek('peek:counter', 10, 900);
        }

        $this->limiter->check('peek:counter', 10, 900);

        $row = $this->pdo->query("SELECT counter FROM togare_rate_limits WHERE rate_key = 'peek:counter'")
            ->fetch(PDO::FETCH_ASSOC);
        self::assertSame(2, (int) $row['counter'], 'Apenas os 2 check() devem ter incrementado; peek não soma.');
    }

    public function testPeekRetornaTrueQuandoJanelaExpirou(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->limiter->check('peek:expired', 5, 900);
        }

        // Fudge window_started_at para 901s atrás (janela de 900s expirada).
        $stmt = $this->pdo->prepare(
            "UPDATE togare_rate_limits SET window_started_at = :old WHERE rate_key = 'peek:expired'"
        );
        $stmt->execute([':old' => (new \DateTimeImmutable('-901 seconds'))->format('Y-m-d H:i:s')]);

        self::assertTrue(
            $this->limiter->peek('peek:expired', 5, 900),
            'Janela expirada → peek deve retornar true (orçamento renovado).',
        );
    }
}
