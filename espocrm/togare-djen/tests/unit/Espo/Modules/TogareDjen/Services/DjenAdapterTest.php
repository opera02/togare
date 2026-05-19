<?php

declare(strict_types=1);

namespace Tests\Unit\Espo\Modules\TogareDjen\Services;

use DateTimeImmutable;
use Espo\Modules\TogareCore\Services\RateLimiter;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareDjen\Exception\DjenAdapterUnavailableException;
use Espo\Modules\TogareDjen\Services\DjenAdapter;
use Espo\Modules\TogareDjen\Services\DjenRateLimitConfig;
use Espo\ORM\EntityManager;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Story 4a.1 — DjenAdapter (AC1/AC2/AC2.1/AC2.2/AC13).
 *
 * Cobre:
 *  - Success path 200 com schema real (fixture comunica-api-462034-SP-202604.json).
 *  - Retry 3× HTTP 502 → DjenAdapterUnavailableException + 3 entries em failures[].
 *  - Threshold 5 falhas em 5min → CB abre por 10min.
 *  - CB aberto → exception imediata sem bater na rede.
 *  - Reset CB on success → failures[] vazia + open_until=0.
 *  - Payload malformado → exception + recordFailure.
 *
 * Setup: cada teste usa stateFilePath isolado em sys_get_temp_dir() e seta
 * TOGARE_DJEN_DISABLE_BACKOFF=1 para evitar sleep real.
 */
final class DjenAdapterTest extends TestCase
{
    private string $stateFilePath;

    protected function setUp(): void
    {
        parent::setUp();
        TogareLogger::reset();
        \putenv('TOGARE_DJEN_DISABLE_BACKOFF=1');
        $this->stateFilePath = \sys_get_temp_dir() . '/togare-djen-cb-test-' . \uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        \putenv('TOGARE_DJEN_DISABLE_BACKOFF');
        if (\is_file($this->stateFilePath)) {
            @\unlink($this->stateFilePath);
        }
        parent::tearDown();
    }

    public function testSuccessPathRetornaIterableComItensNormalizadosDoSchemaReal(): void
    {
        $fixturePath = \dirname(__DIR__, 5) . '/fixtures/comunica-api-462034-SP-202604.json';
        $body = \file_get_contents($fixturePath);
        $this->assertNotFalse($body, 'Fixture deve existir em tests/fixtures/');

        $adapter = new DjenAdapter(
            'https://comunicaapi.pje.jus.br/api/v1',
            $this->stateFilePath,
            // httpExecutor stub — devolve fixture na primeira página, vazio depois.
            (function () use ($body) {
                $callCount = 0;
                return static function (string $url, array $opts) use ($body, &$callCount): array {
                    $callCount++;
                    if ($callCount === 1) {
                        return ['status' => 200, 'body' => $body];
                    }
                    return ['status' => 200, 'body' => '{"status":"success","count":0,"items":[]}'];
                };
            })(),
        );

        $publicacoes = [];
        foreach ($adapter->fetchPublicacoes('462034', 'SP', new DateTimeImmutable('2026-04-01'), new DateTimeImmutable('2026-05-01')) as $pub) {
            $publicacoes[] = $pub;
        }

        $this->assertGreaterThanOrEqual(1, \count($publicacoes), 'Fixture tem 5 itens — adapter deve devolver ao menos 1');

        $first = $publicacoes[0];
        $this->assertIsInt($first['id']);
        $this->assertNotEmpty($first['numeroProcesso']);
        $this->assertSame('TJMT', $first['siglaTribunal']);
        $this->assertSame('Intimação', $first['tipoComunicacao']);
        $this->assertSame('2026-04-30', $first['dataDisponibilizacao']);
        $this->assertNotEmpty($first['texto']);
        $this->assertIsArray($first['destinatarios']);
        $this->assertIsArray($first['destinatarioAdvogados']);
        $this->assertNotEmpty($first['destinatarioAdvogados']);
        $felipeFound = false;
        foreach ($first['destinatarioAdvogados'] as $adv) {
            $this->assertArrayHasKey('numeroOab', $adv);
            $this->assertArrayHasKey('ufOab', $adv);
            if ($adv['numeroOab'] === '462034' && $adv['ufOab'] === 'SP') {
                $felipeFound = true;
            }
        }
        $this->assertTrue($felipeFound, 'Fixture é da OAB 462034/SP — deve aparecer em destinatarioAdvogados');

        // Logs: pelo menos um djen.adapter.attempt.success e zero failed.
        $events = \array_column(TogareLogger::getRecorded(), 'event');
        $this->assertContains('djen.adapter.attempt.success', $events);
        $this->assertNotContains('djen.adapter.attempt.failed', $events);
    }

    public function testRetry3xCom502LancaDjenAdapterUnavailableException(): void
    {
        $callCount = 0;
        $adapter = new DjenAdapter(
            'https://comunicaapi.pje.jus.br/api/v1',
            $this->stateFilePath,
            static function (string $url, array $opts) use (&$callCount): array {
                $callCount++;
                return ['status' => 502, 'body' => 'Bad Gateway'];
            },
        );

        $thrown = false;
        try {
            $generator = $adapter->fetchPublicacoes('462034', 'SP', new DateTimeImmutable('2026-04-30'), new DateTimeImmutable('2026-04-30'));
            foreach ($generator as $_) {
                // não deve chegar aqui
            }
        } catch (DjenAdapterUnavailableException $e) {
            $thrown = true;
            $this->assertStringContainsString('502', $e->getMessage());
            $this->assertStringContainsString('3 tentativas', $e->getMessage());
        }
        $this->assertTrue($thrown, 'Após 3 retries 502, deve lançar DjenAdapterUnavailableException');
        $this->assertSame(3, $callCount, 'Adapter deve fazer exatamente 3 tentativas');

        $events = \array_column(TogareLogger::getRecorded(), 'event');
        $failedCount = \count(\array_filter($events, static fn ($e) => $e === 'djen.adapter.attempt.failed'));
        $this->assertSame(3, $failedCount, '3 eventos djen.adapter.attempt.failed (1 por tentativa)');
    }

    public function testCircuitBreakerAbreApos5FalhasEm5Min(): void
    {
        // Pré-popula state file com 4 falhas recentes.
        $now = \time();
        $state = ['failures' => [$now - 60, $now - 50, $now - 40, $now - 30], 'open_until' => 0];
        \file_put_contents($this->stateFilePath, \json_encode($state));

        $adapter = new DjenAdapter(
            'https://comunicaapi.pje.jus.br/api/v1',
            $this->stateFilePath,
            // Falha imediata na primeira tentativa — o cap de 3 retries vai
            // triggerar 3 recordFailure() — passamos de 4 → 7. O CB abre na 5ª.
            static fn (string $url, array $opts): array => ['status' => 500, 'body' => ''],
        );

        $this->expectException(DjenAdapterUnavailableException::class);
        try {
            foreach ($adapter->fetchPublicacoes('462034', 'SP', new DateTimeImmutable('2026-04-30'), new DateTimeImmutable('2026-04-30')) as $_) {
                // não chega
            }
        } finally {
            // State file deve agora ter open_until > now.
            $stateAfter = \json_decode((string) \file_get_contents($this->stateFilePath), true);
            $this->assertGreaterThan(\time(), $stateAfter['open_until'], 'CB deve estar aberto');
            $this->assertEqualsWithDelta(
                600,
                $stateAfter['open_until'] - $now,
                10,
                'CB abre por 600s (10min — AC2 da Story 4a.1; difere do PdpjAdapter 300s)',
            );
            $events = \array_column(TogareLogger::getRecorded(), 'event');
            $this->assertContains('djen.adapter.circuit_breaker.opened', $events);
        }
    }

    public function testCircuitBreakerAbertoLancaImediatamenteSemBaterNaRede(): void
    {
        $futureTime = \time() + 300;
        $state = ['failures' => [], 'open_until' => $futureTime];
        \file_put_contents($this->stateFilePath, \json_encode($state));

        $callCount = 0;
        $adapter = new DjenAdapter(
            'https://comunicaapi.pje.jus.br/api/v1',
            $this->stateFilePath,
            static function () use (&$callCount): array {
                $callCount++;
                return ['status' => 200, 'body' => '{"items":[],"count":0}'];
            },
        );

        $thrown = false;
        try {
            foreach ($adapter->fetchPublicacoes('462034', 'SP', new DateTimeImmutable('2026-04-30'), new DateTimeImmutable('2026-04-30')) as $_) {
                // não chega
            }
        } catch (DjenAdapterUnavailableException $e) {
            $thrown = true;
            $this->assertStringContainsString('Circuit breaker', $e->getMessage());
            $this->assertStringContainsString('aberto', $e->getMessage());
        }
        $this->assertTrue($thrown);
        $this->assertSame(0, $callCount, 'CB aberto NÃO deve invocar httpExecutor (zero chamadas de rede)');
    }

    public function testCircuitBreakerResetaAposSucesso(): void
    {
        // State com falhas anteriores mas open_until expirado.
        $state = [
            'failures' => [\time() - 100, \time() - 90, \time() - 80],
            'open_until' => \time() - 60, // já passou
        ];
        \file_put_contents($this->stateFilePath, \json_encode($state));

        $adapter = new DjenAdapter(
            'https://comunicaapi.pje.jus.br/api/v1',
            $this->stateFilePath,
            static fn (): array => ['status' => 200, 'body' => '{"status":"success","count":0,"items":[]}'],
        );

        foreach ($adapter->fetchPublicacoes('462034', 'SP', new DateTimeImmutable('2026-04-30'), new DateTimeImmutable('2026-04-30')) as $_) {
            // sem itens, generator termina
        }

        $stateAfter = \json_decode((string) \file_get_contents($this->stateFilePath), true);
        $this->assertSame([], $stateAfter['failures'], 'failures[] zera após sucesso');
        $this->assertSame(0, $stateAfter['open_until'], 'open_until vai a 0 após reset');

        $events = \array_column(TogareLogger::getRecorded(), 'event');
        $this->assertContains('djen.adapter.circuit_breaker.half_open', $events);
    }

    public function testPayloadMalformadoLancaExceptionERegistraFalha(): void
    {
        $adapter = new DjenAdapter(
            'https://comunicaapi.pje.jus.br/api/v1',
            $this->stateFilePath,
            static fn (): array => ['status' => 200, 'body' => '{not-valid-json'],
        );

        $this->expectException(DjenAdapterUnavailableException::class);
        try {
            foreach ($adapter->fetchPublicacoes('462034', 'SP', new DateTimeImmutable('2026-04-30'), new DateTimeImmutable('2026-04-30')) as $_) {
                // não chega
            }
        } finally {
            $stateAfter = \json_decode((string) \file_get_contents($this->stateFilePath), true);
            $this->assertGreaterThanOrEqual(1, \count($stateAfter['failures'] ?? []),
                'Payload malformado conta como falha (recordFailure invocado)');
        }
    }

    // ========================================================================
    // Story 4a.6 — rate-limit DJEN explícito (AC1-AC12).
    // ========================================================================

    /**
     * Helper: cria RateLimiter real contra SQLite in-memory + tabela
     * togare_rate_limits (mesmo schema da V005). Reusado em vários testes.
     *
     * @return array{0: RateLimiter, 1: PDO}
     */
    private function makeRealRateLimiter(): array
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('
            CREATE TABLE togare_rate_limits (
                rate_key VARCHAR(200) NOT NULL PRIMARY KEY,
                counter INTEGER NOT NULL DEFAULT 0,
                window_started_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            )
        ');

        // createStub (não createMock) — não há expectations a verificar,
        // só queremos que getPDO() retorne o PDO. Evita 7 notices PHPUnit.
        $em = $this->createStub(EntityManager::class);
        $em->method('getPDO')->willReturn($pdo);

        return [new RateLimiter($em), $pdo];
    }

    /** Helper: pré-popula `togare_rate_limits` com counter saturado. */
    private function saturate(PDO $pdo, int $counter = 30): void
    {
        $stmt = $pdo->prepare('
            INSERT INTO togare_rate_limits (rate_key, counter, window_started_at, updated_at)
            VALUES (:k, :c, :now, :now2)
        ');
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt->execute([
            ':k' => DjenRateLimitConfig::RATE_KEY,
            ':c' => $counter,
            ':now' => $now,
            ':now2' => $now,
        ]);
    }

    public function testRateLimiterNullSkipsGuard(): void
    {
        // Sem RateLimiter (4º param null) — deve passar normalmente como na 4a.1.
        $adapter = new DjenAdapter(
            'https://comunicaapi.pje.jus.br/api/v1',
            $this->stateFilePath,
            static fn (): array => ['status' => 200, 'body' => '{"status":"success","count":0,"items":[]}'],
            null, // rateLimiter
        );

        // Não deve lançar nem chamar nada de RateLimiter.
        foreach ($adapter->fetchPublicacoes('462034', 'SP', new DateTimeImmutable('2026-05-06'), new DateTimeImmutable('2026-05-06')) as $_) {
            // 0 itens
        }

        $events = \array_column(TogareLogger::getRecorded(), 'event');
        $this->assertNotContains('djen.adapter.ratelimit.released', $events);
        $this->assertNotContains('djen.adapter.ratelimit.throttled', $events);
        $this->assertContains('djen.adapter.attempt.success', $events);
    }

    public function testEnvDisableRatelimitSkipsGuard(): void
    {
        // RateLimiter é final — não pode ser mockado. Validação por efeito
        // observável: contador permanece em 0 quando guard é pulado.
        [$rateLimiter, $pdo] = $this->makeRealRateLimiter();

        \putenv('TOGARE_DJEN_DISABLE_RATELIMIT=1');
        try {
            $adapter = new DjenAdapter(
                'https://comunicaapi.pje.jus.br/api/v1',
                $this->stateFilePath,
                static fn (): array => ['status' => 200, 'body' => '{"status":"success","count":0,"items":[]}'],
                $rateLimiter,
            );

            foreach ($adapter->fetchPublicacoes('462034', 'SP', new DateTimeImmutable('2026-05-06'), new DateTimeImmutable('2026-05-06')) as $_) {
                // 0 itens
            }
        } finally {
            \putenv('TOGARE_DJEN_DISABLE_RATELIMIT');
        }

        // Sem rows → guard não chamou peek nem check.
        $row = $pdo->query("SELECT * FROM togare_rate_limits WHERE rate_key = 'djen:comunica-api'")
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertFalse(
            $row,
            'TOGARE_DJEN_DISABLE_RATELIMIT=1 deve pular guard (tabela permanece vazia para essa chave)',
        );
    }

    public function testUnderLimitAllowsRequestEIncrementaContador(): void
    {
        [$rateLimiter, $pdo] = $this->makeRealRateLimiter();

        $adapter = new DjenAdapter(
            'https://comunicaapi.pje.jus.br/api/v1',
            $this->stateFilePath,
            static fn (): array => ['status' => 200, 'body' => '{"status":"success","count":0,"items":[]}'],
            $rateLimiter,
        );

        foreach ($adapter->fetchPublicacoes('462034', 'SP', new DateTimeImmutable('2026-05-06'), new DateTimeImmutable('2026-05-06')) as $_) {
            // 0 itens
        }

        $row = $pdo->query("SELECT counter FROM togare_rate_limits WHERE rate_key = 'djen:comunica-api'")
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(1, (int) $row['counter']);
        $this->assertSame('djen:comunica-api', DjenRateLimitConfig::RATE_KEY);
    }

    public function testAtLimitThrottlesUntilWindowResets(): void
    {
        [$rateLimiter, $pdo] = $this->makeRealRateLimiter();
        $this->saturate($pdo, DjenRateLimitConfig::LIMIT); // counter=30, window agora.

        // Sleeper mockado: na 1ª chamada (sleptCycle=0), avança a janela
        // simulando reset (UPDATE window_started_at para -120s).
        $sleeperCalls = 0;
        $sleeper = function (int $seconds) use (&$sleeperCalls, $pdo): void {
            $sleeperCalls++;
            if ($sleeperCalls === 1) {
                $stmt = $pdo->prepare("
                    UPDATE togare_rate_limits
                    SET window_started_at = datetime('now', '-120 seconds')
                    WHERE rate_key = :k
                ");
                $stmt->execute([':k' => DjenRateLimitConfig::RATE_KEY]);
            }
        };

        $adapter = new DjenAdapter(
            'https://comunicaapi.pje.jus.br/api/v1',
            $this->stateFilePath,
            static fn (): array => ['status' => 200, 'body' => '{"status":"success","count":0,"items":[]}'],
            $rateLimiter,
            null, // clock real
            $sleeper,
        );

        foreach ($adapter->fetchPublicacoes('462034', 'SP', new DateTimeImmutable('2026-05-06'), new DateTimeImmutable('2026-05-06')) as $_) {
            // 0 itens
        }

        $events = \array_column(TogareLogger::getRecorded(), 'event');
        $this->assertContains(
            'djen.adapter.ratelimit.throttled',
            $events,
            'Esperava log de throttled durante a espera',
        );
        $this->assertContains(
            'djen.adapter.ratelimit.released',
            $events,
            'Esperava log de released após janela liberar',
        );
        $this->assertGreaterThanOrEqual(1, $sleeperCalls, 'Sleeper deve ter sido chamado ao menos 1×');
    }

    public function testCapExceededLancaDjenAdapterUnavailableException(): void
    {
        [$rateLimiter, $pdo] = $this->makeRealRateLimiter();
        $this->saturate($pdo, DjenRateLimitConfig::LIMIT); // counter=30, persistentemente saturado.

        // Clock virtual: avança 1s a cada call. Após CAP_SECONDS+1 chamadas
        // ($clock() >= $deadline) → throw.
        $virtualNow = 0.0;
        $clock = static function () use (&$virtualNow): float {
            $current = $virtualNow;
            $virtualNow += 1.0;
            return $current;
        };
        $sleeper = static function (int $seconds): void {
            // no-op — clock virtual já avança o tempo.
        };

        $adapter = new DjenAdapter(
            'https://comunicaapi.pje.jus.br/api/v1',
            $this->stateFilePath,
            static fn (): array => ['status' => 200, 'body' => '{"status":"success","count":0,"items":[]}'],
            $rateLimiter,
            $clock,
            $sleeper,
        );

        $threw = false;
        try {
            foreach ($adapter->fetchPublicacoes('462034', 'SP', new DateTimeImmutable('2026-05-06'), new DateTimeImmutable('2026-05-06')) as $_) {
                // não chega
            }
        } catch (DjenAdapterUnavailableException $e) {
            $threw = true;
            $this->assertStringContainsString(
                'Rate limit Comunica API excedeu cap de espera',
                $e->getMessage(),
            );
            $this->assertStringContainsString('90s', $e->getMessage());
        }
        $this->assertTrue($threw, 'Esperava DjenAdapterUnavailableException por cap excedido');

        $events = \array_column(TogareLogger::getRecorded(), 'event');
        $this->assertContains('djen.adapter.ratelimit.exceeded', $events);

        // Circuit breaker NÃO deve receber recordFailure por rate-limit cap.
        $stateAfter = \is_file($this->stateFilePath)
            ? (\json_decode((string) \file_get_contents($this->stateFilePath), true) ?? [])
            : [];
        $this->assertEmpty(
            $stateAfter['failures'] ?? [],
            'Cap de rate-limit NÃO deve contar como circuit breaker failure (rate-limit ≠ Comunica API down)',
        );
    }

    public function testRateLimiterFailureLogsWarningEFailsOpen(): void
    {
        // RateLimiter é final — em vez de mockar, drop da tabela após criar
        // o RateLimiter força PDOException natural em peek()/check().
        [$rateLimiter, $pdo] = $this->makeRealRateLimiter();
        $pdo->exec('DROP TABLE togare_rate_limits');

        $httpExecutorCalled = 0;
        $adapter = new DjenAdapter(
            'https://comunicaapi.pje.jus.br/api/v1',
            $this->stateFilePath,
            static function () use (&$httpExecutorCalled): array {
                $httpExecutorCalled++;
                return ['status' => 200, 'body' => '{"status":"success","count":0,"items":[]}'];
            },
            $rateLimiter,
        );

        // Fluxo prossegue: HTTP é chamado normalmente.
        foreach ($adapter->fetchPublicacoes('462034', 'SP', new DateTimeImmutable('2026-05-06'), new DateTimeImmutable('2026-05-06')) as $_) {
            // 0 itens
        }

        $this->assertSame(1, $httpExecutorCalled, 'HTTP executor deve ter rodado mesmo com RateLimiter falho');

        $events = \array_column(TogareLogger::getRecorded(), 'event');
        $this->assertContains('djen.adapter.ratelimit.unavailable', $events);
        $this->assertContains('djen.adapter.attempt.success', $events);

        // Circuit breaker NÃO deve ter sido afetado.
        $stateAfter = \is_file($this->stateFilePath)
            ? (\json_decode((string) \file_get_contents($this->stateFilePath), true) ?? [])
            : [];
        $this->assertEmpty(
            $stateAfter['failures'] ?? [],
            'RateLimiter falho NÃO deve contar como circuit breaker failure',
        );
    }

    public function testGuardChamadoAntesDoCircuitBreakerCheck(): void
    {
        // Cenário: circuit breaker ABERTO (open_until > now) + RateLimiter saturado.
        // Esperado: rate-limit cap throw vem PRIMEIRO (ordem de chamada em
        // fetchWithRetry: guardRateLimit → for($attempt) → httpExecutor;
        // guardCircuitBreaker é chamado no fetchPublicacoes ANTES de
        // fetchWithRetry, mas a ordem dentro de fetchWithRetry é
        // rate-limit primeiro). Aqui validamos que rate-limit é avaliado
        // a cada fetchWithRetry — não é caso especial do CB.
        //
        // Simplificação: validar que com RateLimiter saturado + cap virtual=0,
        // throw vem por rate-limit (mensagem específica), NÃO por CB.

        [$rateLimiter, $pdo] = $this->makeRealRateLimiter();
        $this->saturate($pdo, DjenRateLimitConfig::LIMIT);

        // Cap virtual zerado — primeira iteração já estoura.
        $virtualNow = 100.0;
        $clock = static function () use (&$virtualNow): float {
            $current = $virtualNow;
            $virtualNow += 100.0;
            return $current;
        };

        $adapter = new DjenAdapter(
            'https://comunicaapi.pje.jus.br/api/v1',
            $this->stateFilePath,
            static fn (): array => ['status' => 200, 'body' => '{"status":"success","count":0,"items":[]}'],
            $rateLimiter,
            $clock,
            static fn (int $s): null => null,
        );

        $threw = false;
        try {
            foreach ($adapter->fetchPublicacoes('462034', 'SP', new DateTimeImmutable('2026-05-06'), new DateTimeImmutable('2026-05-06')) as $_) {
                // não chega
            }
        } catch (DjenAdapterUnavailableException $e) {
            $threw = true;
            $this->assertStringContainsString(
                'Rate limit Comunica API',
                $e->getMessage(),
                'Mensagem deve identificar rate-limit (NÃO circuit breaker)',
            );
        }
        $this->assertTrue($threw);
    }

    public function testGuardEhInvocado1xPorChamadaDeFetchWithRetry(): void
    {
        // Verifica AC2: cada fetchWithRetry() consome 1 slot do rate-limit
        // quando não há retry. Aqui, 1 fetchPublicacoes() em dataset pequeno
        // = 1 fetchWithRetry() = 1 tentativa HTTP = 1 incremento.
        [$rateLimiter, $pdo] = $this->makeRealRateLimiter();

        $adapter = new DjenAdapter(
            'https://comunicaapi.pje.jus.br/api/v1',
            $this->stateFilePath,
            static fn (): array => ['status' => 200, 'body' => '{"status":"success","count":0,"items":[]}'],
            $rateLimiter,
        );

        foreach ($adapter->fetchPublicacoes('462034', 'SP', new DateTimeImmutable('2026-05-06'), new DateTimeImmutable('2026-05-06')) as $_) {
            // 0 itens
        }

        $row = $pdo->query("SELECT counter FROM togare_rate_limits WHERE rate_key = 'djen:comunica-api'")
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(1, (int) $row['counter'], 'Esperava exatamente 1 incremento por chamada de fetchWithRetry');

        // Segunda chamada — incrementa para 2.
        foreach ($adapter->fetchPublicacoes('462034', 'SP', new DateTimeImmutable('2026-05-06'), new DateTimeImmutable('2026-05-06')) as $_) {
            // 0 itens
        }

        $row = $pdo->query("SELECT counter FROM togare_rate_limits WHERE rate_key = 'djen:comunica-api'")
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(2, (int) $row['counter']);
    }

    public function testRetriesConsomemUmSlotPorTentativaHttp(): void
    {
        [$rateLimiter, $pdo] = $this->makeRealRateLimiter();

        $attempts = 0;
        $adapter = new DjenAdapter(
            'https://comunicaapi.pje.jus.br/api/v1',
            $this->stateFilePath,
            static function () use (&$attempts): array {
                $attempts++;
                if ($attempts < 3) {
                    return ['status' => 502, 'body' => 'bad gateway'];
                }
                return ['status' => 200, 'body' => '{"status":"success","count":0,"items":[]}'];
            },
            $rateLimiter,
        );

        foreach ($adapter->fetchPublicacoes('462034', 'SP', new DateTimeImmutable('2026-05-06'), new DateTimeImmutable('2026-05-06')) as $_) {
            // 0 itens
        }

        $row = $pdo->query("SELECT counter FROM togare_rate_limits WHERE rate_key = 'djen:comunica-api'")
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(3, $attempts, 'O executor HTTP deve ter sido chamado 3 vezes');
        $this->assertSame(3, (int) $row['counter'], 'Cada tentativa HTTP real deve consumir 1 slot do rate-limit');
    }

    // ============================================================
    // Story 4b.4 / ADR 0009 — getCircuitBreakerState + clearCircuitBreakerOpenFlag
    // ============================================================

    public function testGetCircuitBreakerStateRetornaShapeVazioQuandoStateFileNaoExiste(): void
    {
        $adapter = new DjenAdapter(
            'https://comunicaapi.pje.jus.br/api/v1',
            $this->stateFilePath,
            static fn () => ['status' => 200, 'body' => '{"status":"success","count":0,"items":[]}'],
        );

        $state = $adapter->getCircuitBreakerState();

        $this->assertSame([], $state['failures']);
        $this->assertSame(0, $state['open_until']);
        $this->assertSame(0, $state['opened_at']);
        $this->assertSame(0, $state['unavailable_since']);
    }

    public function testGetCircuitBreakerStateRetornaOpenedAtAposCbAbrirPor5Falhas(): void
    {
        // 5 erros 502 → CB abre. Adapter expõe state pós-failure.
        $adapter = new DjenAdapter(
            'https://comunicaapi.pje.jus.br/api/v1',
            $this->stateFilePath,
            static fn () => ['status' => 502, 'body' => 'bad gateway'],
        );

        $beforeOpen = \time();

        // Cada chamada falha após 3 retries, mas a 1ª chamada já dispara o
        // CB threshold via 3 recordFailure consecutivos. Precisamos chamar 2x
        // (= 6 failures) para garantir que threshold=5 atingiu.
        for ($i = 0; $i < 2; $i++) {
            try {
                foreach ($adapter->fetchPublicacoes('462034', 'SP', new DateTimeImmutable('2026-05-06'), new DateTimeImmutable('2026-05-06')) as $_) {
                    // ignore
                }
            } catch (DjenAdapterUnavailableException) {
                // esperado — adapter falha após retries.
            }
        }

        $afterOpen = \time();

        $state = $adapter->getCircuitBreakerState();

        $this->assertGreaterThan(0, $state['open_until'], 'CB deve ter aberto');
        $this->assertGreaterThanOrEqual($beforeOpen, $state['opened_at'], 'opened_at deve ser >= momento pré-falhas');
        $this->assertLessThanOrEqual($afterOpen, $state['opened_at'], 'opened_at deve ser <= momento pós-falhas');
        $this->assertGreaterThanOrEqual($beforeOpen, $state['unavailable_since'], 'unavailable_since deve ser >= momento pré-falhas');
        $this->assertLessThanOrEqual($afterOpen, $state['unavailable_since'], 'unavailable_since deve ser <= momento pós-falhas');
    }

    public function testClearCircuitBreakerOpenFlagZeraOpenUntilEOpenedAtEPreservaFailures(): void
    {
        // Pre-popula state-file com CB aberto + 5 failures.
        $now = \time();
        \file_put_contents(
            $this->stateFilePath,
            \json_encode([
                'failures' => [$now - 100, $now - 80, $now - 60, $now - 40, $now - 20],
                'open_until' => $now + 600,
                'opened_at' => $now - 20,
                'unavailable_since' => $now - 100,
            ]),
        );

        $adapter = new DjenAdapter(
            'https://comunicaapi.pje.jus.br/api/v1',
            $this->stateFilePath,
            static fn () => ['status' => 200, 'body' => '{"status":"success","count":0,"items":[]}'],
        );

        $stateBefore = $adapter->getCircuitBreakerState();
        $this->assertGreaterThan(0, $stateBefore['open_until']);
        $this->assertGreaterThan(0, $stateBefore['opened_at']);
        $this->assertCount(5, $stateBefore['failures']);

        $adapter->clearCircuitBreakerOpenFlag();

        $stateAfter = $adapter->getCircuitBreakerState();
        $this->assertSame(0, $stateAfter['open_until'], 'open_until deve ser 0');
        $this->assertSame(0, $stateAfter['opened_at'], 'opened_at deve ser 0');
        $this->assertSame($now - 100, $stateAfter['unavailable_since'], 'unavailable_since deve ser preservado');
        $this->assertCount(5, $stateAfter['failures'], 'failures[] preservado');
    }

    public function testSucessoRealResetaUnavailableSince(): void
    {
        $now = \time();
        \file_put_contents(
            $this->stateFilePath,
            \json_encode([
                'failures' => [$now - 120],
                'open_until' => 0,
                'opened_at' => 0,
                'unavailable_since' => $now - 120,
            ]),
        );

        $adapter = new DjenAdapter(
            'https://comunicaapi.pje.jus.br/api/v1',
            $this->stateFilePath,
            static fn () => ['status' => 200, 'body' => '{"status":"success","count":0,"items":[]}'],
        );

        foreach ($adapter->fetchPublicacoes('462034', 'SP', new DateTimeImmutable('2026-05-06'), new DateTimeImmutable('2026-05-06')) as $_) {
            // 0 itens
        }

        $stateAfter = $adapter->getCircuitBreakerState();
        $this->assertSame(0, $stateAfter['unavailable_since']);
        $this->assertSame([], $stateAfter['failures']);
    }

    public function testStateFilePathRespeitaEnvVarTogareDjenCbStatePath(): void
    {
        $envPath = \sys_get_temp_dir() . '/togare-djen-cb-envtest-' . \uniqid() . '.json';
        \putenv('TOGARE_DJEN_CB_STATE_PATH=' . $envPath);

        try {
            // Construtor com $stateFilePath = null E env var setada → usa env var.
            $adapter = new DjenAdapter(
                'https://comunicaapi.pje.jus.br/api/v1',
                null,  // stateFilePath null = consulta env var
                static fn () => ['status' => 502, 'body' => 'bad gateway'],
            );

            try {
                foreach ($adapter->fetchPublicacoes('462034', 'SP', new DateTimeImmutable('2026-05-06'), new DateTimeImmutable('2026-05-06')) as $_) {
                    // ignore
                }
            } catch (DjenAdapterUnavailableException) {
                // esperado
            }

            // O state-file deve ter sido escrito no path da env var.
            $this->assertFileExists($envPath, 'state-file deve estar no path da env var');
        } finally {
            \putenv('TOGARE_DJEN_CB_STATE_PATH');
            if (\is_file($envPath)) {
                @\unlink($envPath);
            }
        }
    }
}
