<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareTpu\Services;

use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareTpu\Exception\TpuAdapterUnavailableException;
use Espo\Modules\TogareTpu\Services\PdpjAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Cobertura do PdpjAdapter:
 *   - parsing do fixture success (≥50 rows)
 *   - retry 3x em HTTP 502
 *   - timeout conta como falha pro circuit breaker
 *   - payload malformado lança TpuAdapterUnavailableException
 *   - circuit breaker abre após 5 falhas em 5min
 *
 * Não bate na rede real — todos os tests usam httpExecutor injetado ou file://.
 */
final class PdpjAdapterTest extends TestCase
{
    private string $stateFile;

    protected function setUp(): void
    {
        TogareLogger::reset();
        $this->stateFile = sys_get_temp_dir() . '/togare-tpu-cb-test-' . uniqid() . '.json';
        // Desabilita backoff sleep nos testes para não travar a suite.
        putenv('TOGARE_TPU_DISABLE_BACKOFF=1');
    }

    protected function tearDown(): void
    {
        if (is_file($this->stateFile)) {
            @unlink($this->stateFile);
        }
        putenv('TOGARE_TPU_DISABLE_BACKOFF');
    }

    public function testParseSuccessFixtureRetorna50Rows(): void
    {
        $fixture = __DIR__ . '/../../../../../contracts/fixtures/pdpj/classes-success-sample.json';
        self::assertFileExists($fixture);
        $body = (string) file_get_contents($fixture);

        $executor = static fn () => ['status' => 200, 'body' => $body];
        $adapter = new PdpjAdapter('https://example.test/tpu', $this->stateFile, $executor);
        $rows = iterator_to_array($adapter->fetchClasses(), false);

        self::assertGreaterThanOrEqual(50, count($rows));
        self::assertSame(436, $rows[0]['codigo']);
        self::assertSame('Procedimento Comum Cível', $rows[0]['nome']);
        self::assertSame(7, $rows[0]['pai_codigo']);
        self::assertTrue($rows[0]['ativo']);
    }

    public function testHttp502RetentaTresVezesEntaoFalha(): void
    {
        $attempts = 0;
        $executor = static function (string $url, array $opts) use (&$attempts): array {
            $attempts++;
            return ['status' => 502, 'body' => ''];
        };

        $adapter = new PdpjAdapter('https://example.test/tpu', $this->stateFile, $executor);

        try {
            iterator_to_array($adapter->fetchClasses(), false);
            self::fail('Esperava TpuAdapterUnavailableException');
        } catch (TpuAdapterUnavailableException $e) {
            self::assertStringContainsString('após 3 tentativas', $e->getMessage());
        }

        self::assertSame(3, $attempts, 'Deve tentar 3 vezes (MAX_ATTEMPTS)');
    }

    public function testTimeoutContaComoFalhaParaCircuitBreaker(): void
    {
        $executor = static function (string $url, array $opts): array {
            throw new \RuntimeException('cURL timeout simulado');
        };

        $adapter = new PdpjAdapter('https://example.test/tpu', $this->stateFile, $executor);

        try {
            iterator_to_array($adapter->fetchClasses(), false);
            self::fail('Esperava exception');
        } catch (TpuAdapterUnavailableException) {
            // ok
        }

        // 1 chamada => 3 tentativas => 3 falhas (não abriu cb ainda — threshold 5)
        $state = json_decode((string) file_get_contents($this->stateFile), true);
        self::assertCount(3, $state['failures']);
        self::assertSame(0, $state['open_until']);
    }

    public function testMalformedPayloadLancaTpuAdapterUnavailable(): void
    {
        $fixture = __DIR__ . '/../../../../../contracts/fixtures/pdpj/classes-malformed.json';
        self::assertFileExists($fixture);
        $body = (string) file_get_contents($fixture);

        $executor = static fn () => ['status' => 200, 'body' => $body];
        $adapter = new PdpjAdapter('https://example.test/tpu', $this->stateFile, $executor);

        $this->expectException(TpuAdapterUnavailableException::class);
        iterator_to_array($adapter->fetchClasses(), false);
    }

    public function testCircuitBreakerAbreApos5FalhasEm5Min(): void
    {
        $executor = static function (string $url, array $opts): array {
            return ['status' => 503, 'body' => ''];
        };

        $adapter = new PdpjAdapter('https://example.test/tpu', $this->stateFile, $executor);

        // 1ª chamada: 3 falhas. Não abre.
        try { iterator_to_array($adapter->fetchClasses(), false); } catch (TpuAdapterUnavailableException) {}
        // 2ª chamada: 3 falhas (total 6 ≥ 5). Abre.
        try { iterator_to_array($adapter->fetchClasses(), false); } catch (TpuAdapterUnavailableException) {}

        $state = json_decode((string) file_get_contents($this->stateFile), true);
        self::assertGreaterThan(0, $state['open_until'], 'CB deve estar aberto');

        // 3ª chamada: nem bate na rede — guard lança imediato.
        $this->expectException(TpuAdapterUnavailableException::class);
        $this->expectExceptionMessage('Circuit breaker do PdpjAdapter está aberto');
        iterator_to_array($adapter->fetchClasses(), false);
    }

    public function testFixtureEmptyRetornaListaVazia(): void
    {
        $executor = static fn () => ['status' => 200, 'body' => '[]'];
        $adapter = new PdpjAdapter('https://example.test/tpu', $this->stateFile, $executor);

        $rows = iterator_to_array($adapter->fetchClasses(), false);
        self::assertSame([], $rows);
    }
}
