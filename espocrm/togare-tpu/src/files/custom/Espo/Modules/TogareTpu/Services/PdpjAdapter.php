<?php

declare(strict_types=1);

namespace Espo\Modules\TogareTpu\Services;

use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareTpu\Contracts\TpuSourceAdapterContract;
use Espo\Modules\TogareTpu\Exception\TpuAdapterUnavailableException;
use Generator;
use Throwable;

/**
 * Adapter HTTP que consome o gateway PDPJ (gateway.cloud.pje.jus.br/tpu) para
 * obter os catálogos TPU CNJ (classes, assuntos, movimentos).
 *
 * Resiliência (Decisão #5):
 *   - Timeout HTTP: 30s por request.
 *   - Retry: 3 tentativas com backoff exponencial (1s, 4s, 16s).
 *   - Circuit breaker: 5 falhas consecutivas em 5min → abre, retorna
 *     TpuAdapterUnavailableException imediato sem bater na rede até o
 *     half-open window (5min após abertura).
 *
 * HTTP via cURL nativo (Dev Notes §8 — abstração HTTP do EspoCRM é instável
 * entre versões; cURL puro é estável e mocável via DI substituindo o adapter
 * inteiro por TpuSourceAdapterStub nos testes).
 *
 * Suporta `file://` no `TOGARE_TPU_BASE_URL` para fixtures locais (smoke
 * sem rede — Dev Notes §5).
 */
final class PdpjAdapter implements TpuSourceAdapterContract
{
    private const TIMEOUT_SECONDS = 30;
    private const MAX_ATTEMPTS = 3;
    private const BACKOFF_SECONDS = [1, 4, 16];
    private const CIRCUIT_BREAKER_THRESHOLD = 5;
    private const CIRCUIT_BREAKER_WINDOW_SECONDS = 300;
    private const CIRCUIT_BREAKER_OPEN_DURATION_SECONDS = 300;

    private readonly string $baseUrl;
    private readonly string $stateFilePath;

    /** @var callable(string, array<string,mixed>): array{status:int, body:string} */
    private $httpExecutor;

    /**
     * @param callable|null $httpExecutor função custom para testes; default usa cURL.
     */
    public function __construct(
        ?string $baseUrl = null,
        ?string $stateFilePath = null,
        ?callable $httpExecutor = null,
    ) {
        $this->baseUrl = rtrim(
            $baseUrl ?? (string) (getenv('TOGARE_TPU_BASE_URL') ?: 'https://gateway.cloud.pje.jus.br/tpu'),
            '/',
        );
        $this->stateFilePath = $stateFilePath
            ?? sys_get_temp_dir() . '/togare-tpu-circuit-breaker.json';
        $this->httpExecutor = $httpExecutor ?? $this->defaultHttpExecutor();
    }

    public function fetchClasses(): iterable
    {
        return $this->fetchEndpoint('classes');
    }

    public function fetchAssuntos(): iterable
    {
        return $this->fetchEndpoint('assuntos');
    }

    public function fetchMovimentos(): iterable
    {
        return $this->fetchEndpoint('movimentos');
    }

    /**
     * @return Generator<int, array{codigo:int, nome:string, pai_codigo:?int, glossario:?string, ativo:bool}>
     */
    private function fetchEndpoint(string $endpoint): Generator
    {
        $this->guardCircuitBreaker();

        $url = $this->baseUrl . '/' . $endpoint;
        $payload = $this->fetchWithRetry($url);

        $rows = $this->decodeRows($payload, $endpoint);

        foreach ($rows as $row) {
            yield $this->normalizeRow($row);
        }
    }

    private function fetchWithRetry(string $url): string
    {
        $lastError = null;
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $failed = false;
            try {
                $result = ($this->httpExecutor)($url, [
                    'timeout' => self::TIMEOUT_SECONDS,
                ]);
                $status = $result['status'] ?? 0;
                $body = $result['body'] ?? '';

                if ($status >= 200 && $status < 300) {
                    TogareLogger::event(
                        'info',
                        'tpu.adapter.attempt.success',
                        "TPU adapter sucesso em {$url}",
                        ['url' => $url, 'attempt' => $attempt, 'status' => $status],
                    );
                    $this->resetCircuitBreakerOnSuccess();
                    return $body;
                }

                $failed = true;
                $lastError = "HTTP status {$status}";
                TogareLogger::event(
                    'warning',
                    'tpu.adapter.attempt.failed',
                    "TPU adapter falhou em {$url}: {$lastError}",
                    ['url' => $url, 'attempt' => $attempt, 'status' => $status],
                );
            } catch (Throwable $e) {
                $failed = true;
                $lastError = $e->getMessage();
                TogareLogger::event(
                    'warning',
                    'tpu.adapter.attempt.failed',
                    "TPU adapter exception em {$url}: {$lastError}",
                    ['url' => $url, 'attempt' => $attempt, 'exception' => get_class($e)],
                );
            }

            if ($failed) {
                // Cada tentativa falhada conta para o circuit breaker.
                // (5 falhas em 5min — Decisão #5 — abre CB mesmo dentro de 1
                // só fetch se a fonte estiver totalmente fora do ar.)
                $this->recordFailure();
            }

            if ($attempt < self::MAX_ATTEMPTS) {
                $sleep = self::BACKOFF_SECONDS[$attempt - 1] ?? 16;
                // Em testes, preferir não dormir — caller controla via httpExecutor próprio.
                if ($sleep > 0 && getenv('TOGARE_TPU_DISABLE_BACKOFF') !== '1') {
                    sleep($sleep);
                }
            }
        }

        throw new TpuAdapterUnavailableException(
            "Falha ao buscar TPU em {$url} após " . self::MAX_ATTEMPTS . " tentativas: {$lastError}",
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function decodeRows(string $payload, string $endpoint): array
    {
        if ($payload === '') {
            throw new TpuAdapterUnavailableException(
                "Payload TPU vazio para {$endpoint} (HTTP 204 ou body ausente) — não conta como falha do adapter",
            );
        }

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->recordFailure();
            throw new TpuAdapterUnavailableException(
                "Payload TPU malformado para {$endpoint}: " . $e->getMessage(),
            );
        }

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            $this->recordFailure();
            throw new TpuAdapterUnavailableException(
                "Payload TPU para {$endpoint} não é lista (esperado array de rows)",
            );
        }

        return $decoded;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{codigo:int, nome:string, pai_codigo:?int, glossario:?string, ativo:bool}
     * @throws TpuAdapterUnavailableException se campos obrigatórios estiverem ausentes ou inválidos
     */
    private function normalizeRow(array $row): array
    {
        $codigo = (int) ($row['codigo'] ?? 0);
        if ($codigo <= 0) {
            throw new TpuAdapterUnavailableException(
                'Row TPU com campo codigo ausente ou inválido (codigo=' . $codigo . ') — payload inesperado',
            );
        }
        $nome = (string) ($row['nome'] ?? '');
        if ($nome === '') {
            throw new TpuAdapterUnavailableException(
                "Row TPU com campo nome vazio para codigo={$codigo} — payload inesperado",
            );
        }
        $paiRaw = $row['pai_codigo'] ?? $row['paiCodigo'] ?? null;
        $glossarioRaw = $row['glossario'] ?? null;
        return [
            'codigo' => $codigo,
            'nome' => $nome,
            'pai_codigo' => $paiRaw === null ? null : (int) $paiRaw,
            'glossario' => $glossarioRaw === null ? null : (string) $glossarioRaw,
            'ativo' => (bool) ($row['ativo'] ?? true),
        ];
    }

    private function guardCircuitBreaker(): void
    {
        $state = $this->loadState();
        if (($state['open_until'] ?? 0) > time()) {
            throw new TpuAdapterUnavailableException(
                'Circuit breaker do PdpjAdapter está aberto até ' . date('c', (int) $state['open_until']),
            );
        }
    }

    private function recordFailure(): void
    {
        $state = $this->loadState();
        $now = time();
        $windowStart = $now - self::CIRCUIT_BREAKER_WINDOW_SECONDS;

        $failures = array_values(array_filter(
            (array) ($state['failures'] ?? []),
            static fn ($t) => is_int($t) && $t >= $windowStart,
        ));
        $failures[] = $now;
        $state['failures'] = $failures;

        if (count($failures) >= self::CIRCUIT_BREAKER_THRESHOLD) {
            $state['open_until'] = $now + self::CIRCUIT_BREAKER_OPEN_DURATION_SECONDS;
            TogareLogger::event(
                'error',
                'tpu.adapter.circuit_breaker.opened',
                'Circuit breaker do PdpjAdapter aberto após '
                    . self::CIRCUIT_BREAKER_THRESHOLD . ' falhas em '
                    . self::CIRCUIT_BREAKER_WINDOW_SECONDS . 's',
                ['open_until' => date('c', $state['open_until'])],
            );
        }

        $this->saveState($state);
    }

    private function resetCircuitBreakerOnSuccess(): void
    {
        $state = $this->loadState();
        if (($state['open_until'] ?? 0) > 0) {
            TogareLogger::event(
                'info',
                'tpu.adapter.circuit_breaker.half_open',
                'Circuit breaker do PdpjAdapter retornou ao normal após sucesso',
                [],
            );
        }
        $state['failures'] = [];
        $state['open_until'] = 0;
        $this->saveState($state);
    }

    /**
     * @return array{failures:list<int>, open_until:int}
     */
    private function loadState(): array
    {
        $empty = ['failures' => [], 'open_until' => 0];
        if (! is_file($this->stateFilePath)) {
            return $empty;
        }
        $fh = @fopen($this->stateFilePath, 'r');
        if ($fh === false) {
            return $empty;
        }
        try {
            flock($fh, LOCK_SH);
            $raw = stream_get_contents($fh);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
        if ($raw === false || $raw === '') {
            return $empty;
        }
        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            TogareLogger::event(
                'warning',
                'tpu.circuit_breaker.state_corrupt',
                'Estado do circuit breaker corrompido — resetando para empty',
                ['path' => $this->stateFilePath],
            );
            return $empty;
        }
        return [
            'failures' => array_values(array_map('intval', (array) ($decoded['failures'] ?? []))),
            'open_until' => (int) ($decoded['open_until'] ?? 0),
        ];
    }

    /**
     * @param array{failures:list<int>, open_until:int} $state
     */
    private function saveState(array $state): void
    {
        $dir = dirname($this->stateFilePath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $fh = @fopen($this->stateFilePath, 'c+');
        if ($fh === false) {
            TogareLogger::event(
                'warning',
                'tpu.circuit_breaker.state_save_failed',
                'Falha ao abrir arquivo de estado do circuit breaker para escrita',
                ['path' => $this->stateFilePath],
            );
            return;
        }
        try {
            flock($fh, LOCK_EX);
            ftruncate($fh, 0);
            fwrite($fh, json_encode($state, JSON_THROW_ON_ERROR));
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * @return callable(string, array<string,mixed>): array{status:int, body:string}
     */
    private function defaultHttpExecutor(): callable
    {
        return static function (string $url, array $opts): array {
            // Suporta file:// e http(s)://.
            if (str_starts_with($url, 'file://') || ! str_contains($url, '://')) {
                $body = @file_get_contents($url);
                if ($body === false) {
                    throw new \RuntimeException("Falha ao ler arquivo TPU: {$url}");
                }
                return ['status' => 200, 'body' => $body];
            }

            $ch = curl_init($url);
            if ($ch === false) {
                throw new \RuntimeException('curl_init retornou false');
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => (int) ($opts['timeout'] ?? 30),
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'User-Agent: Togare-TPU/0.1.0',
                ],
            ]);
            $body = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($body === false) {
                throw new \RuntimeException("cURL erro em {$url}: {$err}");
            }
            return ['status' => (int) $status, 'body' => (string) $body];
        };
    }
}
