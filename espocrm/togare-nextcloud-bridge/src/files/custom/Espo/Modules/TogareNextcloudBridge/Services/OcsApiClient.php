<?php

declare(strict_types=1);

namespace Espo\Modules\TogareNextcloudBridge\Services;

use DateTimeImmutable;
use Espo\Modules\TogareCore\Contracts\EventBusContract;
use Espo\Modules\TogareCore\Events\IntegrationFailedEvent;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareNextcloudBridge\Contracts\NextcloudClientContract;
use Espo\Modules\TogareNextcloudBridge\Exception\NextcloudFileNotFoundException;
use Espo\Modules\TogareNextcloudBridge\Exception\NextcloudUnavailableException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Adapter HTTP que consome a API WebDAV/OCS do Nextcloud da stack
 * (default `http://nextcloud:80/remote.php/dav/files/<user>/togare/...`).
 *
 * Resiliência (espelha DjenAdapter da Story 4a.1, Decisão #7 da Story 5.1):
 *   - Timeout HTTP: 30s por request.
 *   - Retry: 3 tentativas com backoff exponencial (1s, 4s, 16s).
 *   - Circuit breaker: 5 falhas consecutivas em 5min → abre por 5min
 *     (mais permissivo que DjenAdapter — Nextcloud é interno, falhas
 *     tipicamente transientes; AC10 da Story 5.1).
 *
 * HTTP via cURL nativo (mesmo padrão do DjenAdapter — abstração HTTP do
 * EspoCRM é instável entre versões; cURL puro é estável e mocável via
 * httpExecutor injetável).
 *
 * Suporta `file://` no `TOGARE_NEXTCLOUD_BASE_URL` para fixtures locais
 * (smoke sem rede + suite PHPUnit standalone — Decisão #9).
 *
 * EventDispatcher emite `IntegrationFailedEvent(integration='nextcloud',
 * reason='cb_opened'|'retries_exhausted')` em 2 momentos: (1) CB transiciona
 * fechado→aberto, (2) request esgota MAX_ATTEMPTS sem CB ter aberto.
 * HealthPanel da Story 10.2 será o primeiro subscriber.
 */
final class OcsApiClient implements NextcloudClientContract
{
    private const TIMEOUT_SECONDS = 30;
    private const MAX_ATTEMPTS = 3;
    private const BACKOFF_SECONDS = [1, 4, 16];
    private const CIRCUIT_BREAKER_THRESHOLD = 5;
    private const CIRCUIT_BREAKER_WINDOW_SECONDS = 300;
    /** Decisão #7 da Story 5.1: 5min open (vs 10min do DjenAdapter). */
    private const CIRCUIT_BREAKER_OPEN_DURATION_SECONDS = 300;

    private const TOGARE_ROOT = 'togare';

    /** Status HTTP que devem cair no flow de retry (transientes). */
    private const RETRYABLE_STATUS = [408, 423, 425, 429, 500, 502, 503, 504];

    private readonly string $baseUrl;
    private readonly string $user;
    private readonly string $password;
    private readonly string $stateFilePath;

    /** @var callable(string, array<string,mixed>): array{status:int, body:string, headers?:list<string>} */
    private $httpExecutor;

    /** @var callable(string, array<string,mixed>, callable(int,string): int): array{ok:bool, errno:int, error:string, status:int, size:int} */
    private $streamExecutor;

    /** @var callable(): float Devolve "agora" em segundos float (mockável em testes). */
    private $clock;

    /** @var callable(int): void Executa sleep em N segundos (mockável em testes). */
    private $sleeper;

    /** @var (callable(string, array<string,mixed>): void)|null Event emitter callback (Decisão #8). */
    private $eventEmitter;

    private readonly ?EventBusContract $eventBus;

    /**
     * @param string|null   $baseUrl       null → getenv('TOGARE_NEXTCLOUD_BASE_URL') ?? 'http://nextcloud:80'
     * @param string|null   $user          null → getenv('TOGARE_NEXTCLOUD_USER') ?? getenv('NEXTCLOUD_ADMIN_USER') ?? 'admin'
     * @param string|null   $password      null → getenv('TOGARE_NEXTCLOUD_PASSWORD') ?? getenv('NEXTCLOUD_ADMIN_PASSWORD') ?? ''
     * @param string|null   $stateFilePath null → sys_get_temp_dir() + '/togare-nextcloud-bridge-circuit-breaker.json'
     * @param callable|null $httpExecutor  função custom para testes; default usa cURL.
     * @param callable|null $clock          Override do "tempo agora" em testes (`fn(): float`).
     * @param callable|null $sleeper        Override do `sleep()` em testes (`fn(int $s): void`).
     * @param callable|null $eventEmitter   Callback `fn(string $eventName, array $payload): void` para
     *                                      emitir IntegrationFailedEvent. Default null = no-op (testes).
     *                                      Mantido para testes standalone; em produção o EventBusContract
     *                                      é injetado pelo Binding.
     * @param EventBusContract|null $eventBus Event bus real do togare-core para despachar
     *                                        IntegrationFailedEvent.
     * @param callable|null $streamExecutor Executor custom para testes do streaming HTTP.
     */
    public function __construct(
        ?string $baseUrl = null,
        ?string $user = null,
        ?string $password = null,
        ?string $stateFilePath = null,
        ?callable $httpExecutor = null,
        ?callable $clock = null,
        ?callable $sleeper = null,
        ?callable $eventEmitter = null,
        ?EventBusContract $eventBus = null,
        ?callable $streamExecutor = null,
    ) {
        $rawBase = $baseUrl
            ?? (string) (\getenv('TOGARE_NEXTCLOUD_BASE_URL') ?: 'http://nextcloud:80');
        $this->baseUrl = \rtrim($rawBase, '/');

        $this->user = $user
            ?? (string) (\getenv('TOGARE_NEXTCLOUD_USER')
                ?: (\getenv('NEXTCLOUD_ADMIN_USER') ?: 'admin'));

        $this->password = $password
            ?? (string) (\getenv('TOGARE_NEXTCLOUD_PASSWORD')
                ?: (\getenv('NEXTCLOUD_ADMIN_PASSWORD') ?: ''));

        $this->stateFilePath = $stateFilePath
            ?? \sys_get_temp_dir() . '/togare-nextcloud-bridge-circuit-breaker.json';

        $this->httpExecutor = $httpExecutor ?? $this->defaultHttpExecutor();
        $this->streamExecutor = $streamExecutor ?? $this->defaultStreamExecutor();
        $this->clock = $clock ?? static fn (): float => \microtime(true);
        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            if ($seconds > 0 && \getenv('TOGARE_NEXTCLOUD_DISABLE_BACKOFF') !== '1') {
                \sleep($seconds);
            }
        };
        $this->eventEmitter = $eventEmitter;
        $this->eventBus = $eventBus;
    }

    // ============================================================
    // NextcloudClientContract — 7 métodos públicos
    // ============================================================

    public function putWebDav(string $webdavPath, string $binaryContent): void
    {
        $this->ensureParentDirs($webdavPath);
        $url = $this->resolveWebDavUrl($webdavPath);
        $this->request('PUT', $url, [
            'body' => $binaryContent,
            'expectStatuses' => [200, 201, 204],
        ]);
    }

    public function getWebDav(string $webdavPath): string
    {
        $url = $this->resolveWebDavUrl($webdavPath);
        $result = $this->request('GET', $url, [
            'expectStatuses' => [200],
            'allowFileNotFound' => true,
        ]);
        if ($result['status'] === 404) {
            throw new NextcloudFileNotFoundException($webdavPath);
        }

        return $result['body'];
    }

    public function existsWebDav(string $webdavPath): bool
    {
        $url = $this->resolveWebDavUrl($webdavPath);
        $result = $this->request('PROPFIND', $url, [
            'headers' => ['Depth: 0', 'Content-Type: application/xml'],
            'body' => $this->propfindBody(),
            'expectStatuses' => [207],
            'allowFileNotFound' => true,
        ]);

        return $result['status'] === 207;
    }

    public function deleteWebDav(string $webdavPath): bool
    {
        $url = $this->resolveWebDavUrl($webdavPath);
        $result = $this->request('DELETE', $url, [
            'expectStatuses' => [200, 204],
            'allowFileNotFound' => true,
        ]);

        return $result['status'] !== 404;
    }

    public function moveWebDav(string $sourceWebDavPath, string $destinationWebDavPath): void
    {
        $this->ensureParentDirs($destinationWebDavPath);
        $sourceUrl = $this->resolveWebDavUrl($sourceWebDavPath);
        $destinationUrl = $this->resolveWebDavUrl($destinationWebDavPath);
        $result = $this->request('MOVE', $sourceUrl, [
            'headers' => [
                'Destination: ' . $destinationUrl,
                'Overwrite: T',
            ],
            'expectStatuses' => [201, 204],
            'allowFileNotFound' => true,
        ]);
        if ($result['status'] === 404) {
            throw new NextcloudFileNotFoundException($sourceWebDavPath);
        }
    }

    public function propfindList(string $webdavPath): array
    {
        $url = $this->resolveWebDavUrl(\rtrim($webdavPath, '/'));
        $result = $this->request('PROPFIND', $url, [
            'headers' => ['Depth: 1', 'Content-Type: application/xml'],
            'body' => $this->propfindBody(),
            'expectStatuses' => [207],
            'allowFileNotFound' => true,
        ]);
        if ($result['status'] === 404) {
            throw new NextcloudFileNotFoundException($webdavPath);
        }

        return $this->parsePropfindList($result['body'], $webdavPath);
    }

    public function resolveWebDavUrl(string $logicalPath): string
    {
        return $this->buildWebDavUrl($logicalPath, allowRoot: false);
    }

    public function streamWebDav(string $webdavPath, $outputStream, ?callable $beforeFirstByte = null): void
    {
        if (! \is_resource($outputStream)) {
            throw new InvalidArgumentException(
                'streamWebDav: $outputStream deve ser resource aberto para escrita.',
            );
        }

        $url = $this->resolveWebDavUrl($webdavPath);
        $start = ($this->clock)();

        // Suporte file:// para fixtures locais (smoke + PHPUnit) — espelha o
        // simulador WebDAV mínimo do defaultHttpExecutor.
        if (\str_starts_with($url, 'file://') || ! \str_contains($url, '://')) {
            $this->streamFromLocalFile($url, $outputStream, $webdavPath, $start, $beforeFirstByte);
            return;
        }

        $this->streamViaCurl($url, $outputStream, $webdavPath, $start, $beforeFirstByte);
    }

    /**
     * @param resource $outputStream
     */
    private function streamFromLocalFile(
        string $url,
        $outputStream,
        string $webdavPath,
        float $start,
        ?callable $beforeFirstByte,
    ): void {
        $path = self::filePathFromUrl($url);
        if (! \is_file($path)) {
            throw new NextcloudFileNotFoundException($webdavPath);
        }
        $in = @\fopen($path, 'rb');
        if ($in === false) {
            $this->dispatchUnavailableEvent('stream_failed');
            throw new NextcloudUnavailableException([
                'cause' => 'stream_failed',
                'webdavPath' => $webdavPath,
            ]);
        }
        $total = 0;
        $started = false;
        try {
            while (! \feof($in)) {
                $chunk = \fread($in, 1024 * 1024);
                if ($chunk === false) {
                    $this->dispatchUnavailableEvent('stream_failed');
                    throw new NextcloudUnavailableException([
                        'cause' => 'stream_failed',
                        'webdavPath' => $webdavPath,
                    ]);
                }
                if ($chunk === '') {
                    continue;
                }
                if (! $started) {
                    if ($beforeFirstByte !== null) {
                        $beforeFirstByte();
                    }
                    $started = true;
                }
                $this->writeAll($outputStream, $chunk, $webdavPath);
                $total += \strlen($chunk);
            }
        } finally {
            @\fclose($in);
        }

        if (! $started) {
            if ($beforeFirstByte !== null) {
                $beforeFirstByte();
            }
        }

        $durationMs = (int) ((($this->clock)() - $start) * 1000);
        TogareLogger::event(
            'info',
            'nextcloud.storage.stream',
            'streamWebDav (file://) OK',
            ['logicalPath' => $webdavPath, 'sizeBytes' => $total, 'durationMs' => $durationMs],
        );
    }

    /**
     * @param resource $outputStream
     */
    private function streamViaCurl(
        string $url,
        $outputStream,
        string $webdavPath,
        float $start,
        ?callable $beforeFirstByte,
    ): void {
        $headers = [
            'Authorization: Basic ' . \base64_encode($this->user . ':' . $this->password),
            'User-Agent: Togare-Nextcloud-Bridge/0.2.0',
        ];

        $started = false;
        $bytesWritten = 0;
        $streamException = null;
        $result = ($this->streamExecutor)(
            $url,
            [
                'headers' => $headers,
                'connectTimeout' => 10,
                // 10min é folga generosa: PDF 200MB em ~1 Gbps cabe em ~2s; rede ruim pode esticar.
                'timeout' => 600,
                'bufferSize' => 1024 * 1024,
            ],
            function (int $status, string $chunk) use (
                $outputStream,
                $webdavPath,
                $beforeFirstByte,
                &$started,
                &$bytesWritten,
                &$streamException,
            ): int {
                if ($status < 200 || $status >= 300) {
                    return \strlen($chunk);
                }
                if (! $started) {
                    if ($beforeFirstByte !== null) {
                        $beforeFirstByte();
                    }
                    $started = true;
                }
                try {
                    $this->writeAll($outputStream, $chunk, $webdavPath);
                } catch (Throwable $e) {
                    $streamException = $e;
                    return 0;
                }
                $bytesWritten += \strlen($chunk);
                return \strlen($chunk);
            },
        );

        if ($streamException instanceof Throwable) {
            throw $streamException;
        }

        $errno = (int) $result['errno'];
        $errMsg = (string) $result['error'];
        $httpCode = (int) $result['status'];

        $durationMs = (int) ((($this->clock)() - $start) * 1000);

        if ($errno !== 0) {
            TogareLogger::event(
                'error',
                'nextcloud.client.unavailable',
                'Nextcloud stream cURL erro: ' . $errMsg,
                ['method' => 'GET', 'webdavPath' => $webdavPath, 'curlErrno' => $errno],
            );
            $this->dispatchUnavailableEvent('stream_failed');
            throw new NextcloudUnavailableException([
                'cause' => 'stream_failed',
                'webdavPath' => $webdavPath,
                'curlErrno' => $errno,
            ]);
        }

        if ($httpCode === 404) {
            throw new NextcloudFileNotFoundException($webdavPath);
        }

        if ($httpCode === 401 || $httpCode === 403) {
            // Credencial inválida ou ACL Nextcloud. Não-retryable; sem contar no CB.
            throw new NextcloudUnavailableException([
                'cause' => $httpCode === 401 ? 'unauthorized' : 'forbidden',
                'statusCode' => $httpCode,
                'webdavPath' => $webdavPath,
            ]);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            TogareLogger::event(
                'error',
                'nextcloud.client.unavailable',
                'Nextcloud stream HTTP ' . $httpCode,
                ['method' => 'GET', 'webdavPath' => $webdavPath, 'status' => $httpCode],
            );
            $this->dispatchUnavailableEvent('stream_failed');
            throw new NextcloudUnavailableException([
                'cause' => 'stream_failed',
                'webdavPath' => $webdavPath,
                'statusCode' => $httpCode,
            ]);
        }

        if (! $started) {
            if ($beforeFirstByte !== null) {
                $beforeFirstByte();
            }
        }

        TogareLogger::event(
            'info',
            'nextcloud.storage.stream',
            'streamWebDav (curl) OK',
            ['logicalPath' => $webdavPath, 'sizeBytes' => $bytesWritten, 'durationMs' => $durationMs],
        );
    }

    /**
     * @param resource $outputStream
     */
    private function writeAll($outputStream, string $chunk, string $webdavPath): void
    {
        $offset = 0;
        $length = \strlen($chunk);
        while ($offset < $length) {
            $written = \fwrite($outputStream, \substr($chunk, $offset));
            if ($written === false || $written === 0) {
                $this->dispatchUnavailableEvent('stream_failed');
                throw new NextcloudUnavailableException([
                    'cause' => 'stream_failed',
                    'webdavPath' => $webdavPath,
                ]);
            }
            $offset += $written;
        }
    }

    private function buildWebDavUrl(string $logicalPath, bool $allowRoot): string
    {
        $clean = $this->normalizeWebDavPath($logicalPath, allowRoot: $allowRoot);

        return $this->baseUrl
            . '/remote.php/dav/files/'
            . \rawurlencode($this->user)
            . '/' . self::TOGARE_ROOT
            . ($clean === '' ? '' : '/' . $this->encodePathSegments($clean));
    }

    // ============================================================
    // Helpers internos
    // ============================================================

    private function encodePathSegments(string $path): string
    {
        $segments = \explode('/', $path);
        $encoded = \array_map(static fn (string $seg): string => \rawurlencode($seg), $segments);

        return \implode('/', $encoded);
    }

    private function normalizeWebDavPath(string $path, bool $allowRoot = false): string
    {
        if ($path === '') {
            if ($allowRoot) {
                return '';
            }
            throw new InvalidArgumentException('webdavPath não pode ser vazio.');
        }

        if (\str_starts_with($path, '/') || \str_contains($path, '\\')) {
            throw new InvalidArgumentException("webdavPath deve ser relativo e usar '/' como separador: {$path}");
        }

        if (\preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw new InvalidArgumentException('webdavPath não pode conter caracteres de controle.');
        }

        $clean = \trim($path, '/');
        if ($clean === '') {
            if ($allowRoot) {
                return '';
            }
            throw new InvalidArgumentException('webdavPath não pode ser vazio.');
        }

        foreach (\explode('/', $clean) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException("webdavPath contém segmento inválido: {$path}");
            }
        }

        return $clean;
    }

    private function propfindBody(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<d:propfind xmlns:d="DAV:">
  <d:prop>
    <d:getlastmodified/>
    <d:getcontentlength/>
    <d:resourcetype/>
  </d:prop>
</d:propfind>
XML;
    }

    /**
     * Cria diretórios pai do $webdavPath (relativo a togare/) recursivamente
     * via MKCOL. Idempotente — 405 (já existe) é silencioso.
     *
     * Inclui MKCOL do próprio root `togare/` (logical path '') — primeira
     * instalação não tem o root criado ainda; sem isso PUT em qualquer
     * subpath retorna 409 Conflict (descoberto no smoke F1 — bug B-NEW-7).
     */
    private function ensureParentDirs(string $webdavPath): void
    {
        $clean = $this->normalizeWebDavPath($webdavPath);

        // Root togare/ — idempotente.
        $this->doMkcol('');

        $parent = \dirname($clean);
        if ($parent === '' || $parent === '.' || $parent === '/') {
            return;
        }

        $segments = \explode('/', $parent);
        $current = '';
        foreach ($segments as $seg) {
            if ($seg === '') {
                continue;
            }
            $current = $current === '' ? $seg : $current . '/' . $seg;
            $this->doMkcol($current);
        }
    }

    private function doMkcol(string $webdavPath): void
    {
        $url = $this->buildWebDavUrl($webdavPath, allowRoot: true);
        // MKCOL é idempotente: 201 (created) ou 405 (already exists) ambos OK.
        // Outros status caem no flow de retry/CB normal.
        $this->request('MKCOL', $url, [
            'expectStatuses' => [201, 405],
        ]);
    }

    /**
     * Centraliza retry+CB+timeout para todas as operações HTTP.
     *
     * @param array{
     *   headers?: list<string>,
     *   body?: string,
     *   expectStatuses: list<int>,
     *   allowFileNotFound?: bool
     * } $opts
     *
     * @return array{status: int, body: string, headers: list<string>}
     */
    private function request(string $method, string $url, array $opts): array
    {
        $this->guardCircuitBreaker();

        $expectStatuses = $opts['expectStatuses'];
        $allowFileNotFound = $opts['allowFileNotFound'] ?? false;
        $lastError = null;
        $lastStatus = 0;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $failed = false;

            try {
                $result = ($this->httpExecutor)($url, [
                    'method' => $method,
                    'timeout' => self::TIMEOUT_SECONDS,
                    'headers' => $this->buildHeaders($opts['headers'] ?? []),
                    'body' => $opts['body'] ?? null,
                ]);
                $status = (int) ($result['status'] ?? 0);
                $body = (string) ($result['body'] ?? '');
                $headers = (array) ($result['headers'] ?? []);
                $lastStatus = $status;

                TogareLogger::event(
                    'debug',
                    'nextcloud.client.request_attempted',
                    "Nextcloud {$method} {$url}",
                    ['method' => $method, 'webdavPath' => $url, 'attempt' => $attempt, 'status' => $status],
                );

                if (\in_array($status, $expectStatuses, true)) {
                    $this->resetCircuitBreakerOnSuccess();

                    return ['status' => $status, 'body' => $body, 'headers' => \array_values($headers)];
                }

                if ($status === 404 && $allowFileNotFound) {
                    // 404 esperado (delete idempotente, exists false, get com NotFound).
                    // NÃO conta como falha do CB.
                    $this->resetCircuitBreakerOnSuccess();

                    return ['status' => 404, 'body' => $body, 'headers' => \array_values($headers)];
                }

                if ($status === 401 || $status === 403) {
                    // 401/403 = credencial inválida ou ACL Nextcloud. NÃO retryable —
                    // CB não acumula porque é erro de configuração, não falha de
                    // disponibilidade. Lança imediato.
                    throw new NextcloudUnavailableException([
                        'cause' => $status === 401 ? 'unauthorized' : 'forbidden',
                        'attempt' => $attempt,
                        'statusCode' => $status,
                    ]);
                }

                if (! \in_array($status, self::RETRYABLE_STATUS, true)) {
                    // Status inesperado não-retryable. Conta como falha do CB
                    // (Nextcloud retornou 5xx ou 4xx desconhecido) e lança.
                    $this->recordFailureAndMaybeOpen();
                    throw new NextcloudUnavailableException([
                        'cause' => 'unexpected_status',
                        'attempt' => $attempt,
                        'statusCode' => $status,
                    ]);
                }

                $failed = true;
                $lastError = "HTTP status {$status}";
                $backoffSeconds = $attempt < self::MAX_ATTEMPTS
                    ? (self::BACKOFF_SECONDS[$attempt - 1] ?? 16)
                    : 0;
                TogareLogger::event(
                    'warning',
                    'nextcloud.client.retry',
                    "Nextcloud {$method} {$url} status {$status} retryable",
                    [
                        'method' => $method,
                        'webdavPath' => $url,
                        'attempt' => $attempt,
                        'backoffSeconds' => $backoffSeconds,
                        'errorMessage' => $lastError,
                        'status' => $status,
                    ],
                );
            } catch (NextcloudUnavailableException $e) {
                throw $e;
            } catch (Throwable $e) {
                $failed = true;
                $lastError = $e->getMessage();
                $backoffSeconds = $attempt < self::MAX_ATTEMPTS
                    ? (self::BACKOFF_SECONDS[$attempt - 1] ?? 16)
                    : 0;
                TogareLogger::event(
                    'warning',
                    'nextcloud.client.retry',
                    "Nextcloud {$method} {$url} exception: {$lastError}",
                    [
                        'method' => $method,
                        'webdavPath' => $url,
                        'attempt' => $attempt,
                        'backoffSeconds' => $backoffSeconds,
                        'errorMessage' => $lastError,
                        'exception' => \get_class($e),
                    ],
                );
            }

            if ($failed) {
                $this->recordFailureAndMaybeOpen();
            }

            if ($attempt < self::MAX_ATTEMPTS) {
                $sleep = self::BACKOFF_SECONDS[$attempt - 1] ?? 16;
                ($this->sleeper)($sleep);
            }
        }

        // Esgotou MAX_ATTEMPTS — emite evento (Decisão #8) e lança.
        $this->dispatchUnavailableEvent('retries_exhausted');
        TogareLogger::event(
            'error',
            'nextcloud.client.unavailable',
            'Nextcloud ' . $method . ' ' . $url . ' esgotou ' . self::MAX_ATTEMPTS . ' tentativas: ' . $lastError,
            ['method' => $method, 'reason' => 'retries_exhausted', 'lastError' => $lastError, 'lastStatus' => $lastStatus],
        );
        throw new NextcloudUnavailableException([
            'cause' => 'retries_exhausted',
            'attempt' => self::MAX_ATTEMPTS,
            'statusCode' => $lastStatus,
        ]);
    }

    /**
     * @param list<string> $extra
     * @return list<string>
     */
    private function buildHeaders(array $extra): array
    {
        $base = [
            'Authorization: Basic ' . \base64_encode($this->user . ':' . $this->password),
            'User-Agent: Togare-Nextcloud-Bridge/0.1.0',
        ];

        return \array_values(\array_merge($base, $extra));
    }

    /**
     * Parser PROPFIND XML — extrai paths de `<d:href>` que NÃO sejam o próprio
     * dir consultado. Retorna apenas o segmento final (filename) — caller (a
     * `restoreFromTombstone()` na Story 5.5) sabe o $webdavPath base.
     *
     * Tolerante a namespace `D:` ou `d:` (Decisão B-NEW-4 — Nextcloud 31 usa
     * lowercase, mas registramos namespace via xpath para portabilidade).
     *
     * @return list<string>
     */
    private function parsePropfindList(string $xmlBody, string $webdavPathRequested): array
    {
        if ($xmlBody === '') {
            return [];
        }

        // Suprime warnings de XML mal-formado — falha vira lista vazia
        // (CB já contou como sucesso porque status era 207).
        $previous = \libxml_use_internal_errors(true);
        try {
            $xml = \simplexml_load_string($xmlBody);
            if ($xml === false) {
                return [];
            }
            $xml->registerXPathNamespace('d', 'DAV:');
            $responses = $xml->xpath('//d:response');
            if ($responses === false || $responses === null) {
                return [];
            }

            $expectedSelfUrl = $this->resolveWebDavUrl(\rtrim($webdavPathRequested, '/'));
            $expectedSelfPath = (string) \parse_url($expectedSelfUrl, PHP_URL_PATH);

            $names = [];
            foreach ($responses as $response) {
                $response->registerXPathNamespace('d', 'DAV:');
                $hrefNodes = $response->xpath('d:href');
                if ($hrefNodes === false || $hrefNodes === [] || $hrefNodes === null) {
                    continue;
                }
                $hrefNode = $hrefNodes[0];
                $hrefStr = \trim((string) $hrefNode);
                if ($hrefStr === '') {
                    continue;
                }
                // href pode ser path absoluto ou URL completa — normalizamos.
                $path = (string) \parse_url($hrefStr, PHP_URL_PATH);
                $decoded = \rawurldecode($path);
                $decodedSelf = \rawurldecode($expectedSelfPath);
                $trimmedDecoded = \rtrim($decoded, '/');
                $trimmedSelf = \rtrim($decodedSelf, '/');

                // Skip o próprio dir consultado (PROPFIND Depth=1 inclui).
                if ($trimmedDecoded === $trimmedSelf) {
                    continue;
                }
                if ($trimmedSelf !== '' && \str_starts_with($trimmedDecoded, $trimmedSelf . '/')) {
                    $relative = \substr($trimmedDecoded, \strlen($trimmedSelf) + 1);
                    if ($relative !== '') {
                        $names[] = $this->markCollectionEntry($response, $relative);
                    }
                    continue;
                }

                // Fallback: usa basename como nome — ainda assim útil em casos
                // onde o expected path não bate (ex.: subpaths inesperados).
                $names[] = $this->markCollectionEntry($response, \basename($trimmedDecoded));
            }

            return \array_values(\array_unique($names));
        } finally {
            \libxml_clear_errors();
            \libxml_use_internal_errors($previous);
        }
    }

    private function markCollectionEntry(\SimpleXMLElement $response, string $relative): string
    {
        $response->registerXPathNamespace('d', 'DAV:');
        $collection = $response->xpath('d:propstat/d:prop/d:resourcetype/d:collection');
        if ($collection !== false && $collection !== [] && ! \str_ends_with($relative, '/')) {
            return $relative . '/';
        }

        return $relative;
    }

    // ============================================================
    // Circuit breaker (espelho DjenAdapter)
    // ============================================================

    private function guardCircuitBreaker(): void
    {
        $state = $this->loadState();
        $openUntil = (int) ($state['open_until'] ?? 0);
        if ($openUntil > $this->now()) {
            TogareLogger::event(
                'warning',
                'nextcloud.client.unavailable',
                'Nextcloud circuit breaker aberto até ' . \date('c', $openUntil),
                ['reason' => 'cb_open', 'cbOpenUntil' => $openUntil],
            );
            throw new NextcloudUnavailableException([
                'cause' => 'cb_open',
                'cbOpenUntil' => $openUntil,
            ]);
        }
    }

    /**
     * Registra a falha. Se o CB transicionar de fechado→aberto, dispatch
     * IntegrationFailedEvent UMA ÚNICA VEZ (Decisão #8).
     */
    private function recordFailureAndMaybeOpen(): void
    {
        $dir = \dirname($this->stateFilePath);
        if (! \is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }

        $openedState = null;
        $fh = @\fopen($this->stateFilePath, 'c+');
        if ($fh === false) {
            return;
        }

        try {
            \flock($fh, LOCK_EX);
            \rewind($fh);
            $state = $this->decodeState(\stream_get_contents($fh));
            $now = $this->now();
            $windowStart = $now - self::CIRCUIT_BREAKER_WINDOW_SECONDS;

            $failures = \array_values(\array_filter(
                (array) ($state['failures'] ?? []),
                static fn ($t) => \is_int($t) && $t >= $windowStart,
            ));
            $failures[] = $now;
            $state['failures'] = $failures;

            $wasOpen = ((int) ($state['open_until'] ?? 0)) > $now;
            $shouldOpen = \count($failures) >= self::CIRCUIT_BREAKER_THRESHOLD;

            if ($shouldOpen && ! $wasOpen) {
                $state['open_until'] = $now + self::CIRCUIT_BREAKER_OPEN_DURATION_SECONDS;
                $state['opened_at'] = $now;
                $openedState = [
                    'failuresInWindow' => \count($failures),
                    'openUntilIso8601' => \date('c', (int) $state['open_until']),
                ];
            }

            \rewind($fh);
            \ftruncate($fh, 0);
            \fwrite($fh, \json_encode($state, JSON_THROW_ON_ERROR));
        } finally {
            \flock($fh, LOCK_UN);
            \fclose($fh);
        }

        if ($openedState !== null) {
            TogareLogger::event(
                'error',
                'nextcloud.client.cb_opened',
                'Circuit breaker do Nextcloud aberto após '
                    . self::CIRCUIT_BREAKER_THRESHOLD . ' falhas em '
                    . self::CIRCUIT_BREAKER_WINDOW_SECONDS . 's',
                $openedState,
            );
            $this->dispatchUnavailableEvent('cb_opened');
        }
    }

    private function resetCircuitBreakerOnSuccess(): void
    {
        $state = $this->loadState();
        if (((int) ($state['open_until'] ?? 0)) === 0 && (($state['failures'] ?? []) === [])) {
            return;
        }
        $state['failures'] = [];
        $state['open_until'] = 0;
        $state['opened_at'] = 0;
        $this->saveState($state);
    }

    /**
     * @param 'cb_opened'|'retries_exhausted' $reason
     */
    private function dispatchUnavailableEvent(string $reason): void
    {
        if ($this->eventBus !== null) {
            try {
                $this->eventBus->dispatch(new IntegrationFailedEvent(
                    'nextcloud',
                    $reason,
                    new DateTimeImmutable(),
                ));
            } catch (Throwable $e) {
                TogareLogger::event(
                    'warning',
                    'nextcloud.client.event_dispatch_failed',
                    'Falha ao emitir IntegrationFailedEvent — bridge prossegue',
                    ['exception' => \get_class($e), 'message' => $e->getMessage()],
                );
            }
        }

        if ($this->eventEmitter === null) {
            return;
        }
        try {
            ($this->eventEmitter)('nextcloud.unavailable', [
                'integration' => 'nextcloud',
                'reason' => $reason,
                'occurredAt' => \date('c'),
            ]);
        } catch (Throwable $e) {
            // Fail-open: telemetria nunca trava o fluxo principal.
            TogareLogger::event(
                'warning',
                'nextcloud.client.event_dispatch_failed',
                'Falha ao emitir IntegrationFailedEvent — bridge prossegue',
                ['exception' => \get_class($e), 'message' => $e->getMessage()],
            );
        }
    }

    /**
     * @return array{failures:list<int>, open_until:int, opened_at:int}
     */
    private function loadState(): array
    {
        $empty = ['failures' => [], 'open_until' => 0, 'opened_at' => 0];
        if (! \is_file($this->stateFilePath)) {
            return $empty;
        }
        $fh = @\fopen($this->stateFilePath, 'r');
        if ($fh === false) {
            return $empty;
        }
        try {
            \flock($fh, LOCK_SH);
            $raw = \stream_get_contents($fh);
        } finally {
            \flock($fh, LOCK_UN);
            \fclose($fh);
        }
        return $this->decodeState($raw);
    }

    /**
     * @return array{failures:list<int>, open_until:int, opened_at:int}
     */
    private function decodeState(string|false|null $raw): array
    {
        if ($raw === false || $raw === null || $raw === '') {
            return ['failures' => [], 'open_until' => 0, 'opened_at' => 0];
        }
        try {
            $decoded = \json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return ['failures' => [], 'open_until' => 0, 'opened_at' => 0];
        }

        return [
            'failures' => \array_values(\array_map('\intval', (array) ($decoded['failures'] ?? []))),
            'open_until' => (int) ($decoded['open_until'] ?? 0),
            'opened_at' => (int) ($decoded['opened_at'] ?? 0),
        ];
    }

    private function now(): int
    {
        return (int) \floor((float) ($this->clock)());
    }

    /**
     * @param array{failures:list<int>, open_until:int, opened_at:int} $state
     */
    private function saveState(array $state): void
    {
        $dir = \dirname($this->stateFilePath);
        if (! \is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }
        $fh = @\fopen($this->stateFilePath, 'c+');
        if ($fh === false) {
            return;
        }
        try {
            \flock($fh, LOCK_EX);
            \ftruncate($fh, 0);
            \fwrite($fh, \json_encode($state, JSON_THROW_ON_ERROR));
        } finally {
            \flock($fh, LOCK_UN);
            \fclose($fh);
        }
    }

    /**
     * @return callable(string, array<string,mixed>, callable(int,string): int): array{ok:bool, errno:int, error:string, status:int, size:int}
     */
    private function defaultStreamExecutor(): callable
    {
        return static function (string $url, array $opts, callable $bodyHandler): array {
            $ch = \curl_init($url);
            if ($ch === false) {
                return ['ok' => false, 'errno' => 1, 'error' => 'curl_init retornou false', 'status' => 0, 'size' => 0];
            }

            $headers = (array) ($opts['headers'] ?? []);
            $statusFromHeader = 0;
            $size = 0;

            \curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => (int) ($opts['connectTimeout'] ?? 10),
                CURLOPT_TIMEOUT => (int) ($opts['timeout'] ?? 600),
                CURLOPT_BUFFERSIZE => (int) ($opts['bufferSize'] ?? 1024 * 1024),
                CURLOPT_FAILONERROR => false,
                CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$statusFromHeader): int {
                    if (\preg_match('/^HTTP\/\S+\s+(\d{3})/', \trim($header), $m) === 1) {
                        $statusFromHeader = (int) $m[1];
                    }
                    return \strlen($header);
                },
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (
                    &$statusFromHeader,
                    &$size,
                    $bodyHandler,
                ): int {
                    $status = (int) \curl_getinfo($curl, CURLINFO_HTTP_CODE);
                    if ($status === 0) {
                        $status = $statusFromHeader;
                    }
                    $length = \strlen($chunk);
                    $size += $length;
                    return $bodyHandler($status, $chunk);
                },
            ]);

            $ok = \curl_exec($ch) !== false;
            $errno = \curl_errno($ch);
            $error = \curl_error($ch);
            $status = (int) \curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($status === 0) {
                $status = $statusFromHeader;
            }
            \curl_close($ch);

            return [
                'ok' => $ok,
                'errno' => $errno,
                'error' => $error,
                'status' => $status,
                'size' => $size,
            ];
        };
    }

    /**
     * @return callable(string, array<string,mixed>): array{status:int, body:string, headers:list<string>}
     */
    private function defaultHttpExecutor(): callable
    {
        return static function (string $url, array $opts): array {
            // Suporta file:// e http(s)://.
            if (\str_starts_with($url, 'file://') || ! \str_contains($url, '://')) {
                return self::executeFileRequest($url, $opts);
            }

            $ch = \curl_init($url);
            if ($ch === false) {
                throw new \RuntimeException('curl_init retornou false');
            }
            $method = \strtoupper((string) ($opts['method'] ?? 'GET'));
            $headers = (array) ($opts['headers'] ?? []);
            $body = $opts['body'] ?? null;

            \curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_TIMEOUT => (int) ($opts['timeout'] ?? 30),
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => false, // WebDAV redirects são bug; capturar status real.
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
            ]);
            if ($body !== null) {
                \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }

            $raw = \curl_exec($ch);
            $status = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = \curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $err = \curl_error($ch);
            \curl_close($ch);

            if ($raw === false) {
                throw new \RuntimeException("cURL erro em {$url}: {$err}");
            }

            $rawStr = (string) $raw;
            $headersStr = \substr($rawStr, 0, (int) $headerSize);
            $bodyStr = \substr($rawStr, (int) $headerSize);
            $headerLines = \array_values(\array_filter(\explode("\r\n", $headersStr), static fn ($l) => $l !== ''));

            return [
                'status' => (int) $status,
                'body' => $bodyStr,
                'headers' => $headerLines,
            ];
        };
    }

    /**
     * Simulador WebDAV mínimo para baseUrl file:// usado em fixtures/smoke local.
     *
     * @param array<string,mixed> $opts
     * @return array{status:int, body:string, headers:list<string>}
     */
    private static function executeFileRequest(string $url, array $opts): array
    {
        $method = \strtoupper((string) ($opts['method'] ?? 'GET'));
        $path = self::filePathFromUrl($url);

        return match ($method) {
            'MKCOL' => self::fileMkcol($path),
            'PUT' => self::filePut($path, (string) ($opts['body'] ?? '')),
            'GET' => \is_file($path)
                ? ['status' => 200, 'body' => (string) \file_get_contents($path), 'headers' => []]
                : ['status' => 404, 'body' => '', 'headers' => []],
            'DELETE' => self::fileDelete($path),
            'MOVE' => self::fileMove($path, self::destinationPathFromHeaders((array) ($opts['headers'] ?? []))),
            'PROPFIND' => self::filePropfind($path, (array) ($opts['headers'] ?? [])),
            default => ['status' => 405, 'body' => '', 'headers' => []],
        };
    }

    private static function filePathFromUrl(string $url): string
    {
        $path = (string) \parse_url($url, PHP_URL_PATH);
        $path = \rawurldecode($path !== '' ? $path : $url);
        if (\preg_match('/^\/[A-Za-z]:\//', $path) === 1) {
            $path = \substr($path, 1);
        }

        return $path;
    }

    /**
     * @param list<string> $headers
     */
    private static function destinationPathFromHeaders(array $headers): string
    {
        foreach ($headers as $header) {
            if (\stripos((string) $header, 'Destination:') === 0) {
                return self::filePathFromUrl(\trim(\substr((string) $header, \strlen('Destination:'))));
            }
        }

        throw new RuntimeException('MOVE file:// sem header Destination.');
    }

    /**
     * @return array{status:int, body:string, headers:list<string>}
     */
    private static function fileMkcol(string $path): array
    {
        if (\is_dir($path)) {
            return ['status' => 405, 'body' => '', 'headers' => []];
        }
        if (\file_exists($path)) {
            return ['status' => 405, 'body' => '', 'headers' => []];
        }
        if (! @\mkdir($path, 0775, true) && ! \is_dir($path)) {
            return ['status' => 409, 'body' => '', 'headers' => []];
        }

        return ['status' => 201, 'body' => '', 'headers' => []];
    }

    /**
     * @return array{status:int, body:string, headers:list<string>}
     */
    private static function filePut(string $path, string $body): array
    {
        $existed = \is_file($path);
        $dir = \dirname($path);
        if (! \is_dir($dir)) {
            @\mkdir($dir, 0775, true);
        }

        return @\file_put_contents($path, $body) === false
            ? ['status' => 500, 'body' => '', 'headers' => []]
            : ['status' => $existed ? 204 : 201, 'body' => '', 'headers' => []];
    }

    /**
     * @return array{status:int, body:string, headers:list<string>}
     */
    private static function fileDelete(string $path): array
    {
        if (! \file_exists($path)) {
            return ['status' => 404, 'body' => '', 'headers' => []];
        }
        if (\is_dir($path)) {
            self::deleteDirectory($path);
        } else {
            @\unlink($path);
        }

        return ['status' => 204, 'body' => '', 'headers' => []];
    }

    /**
     * @return array{status:int, body:string, headers:list<string>}
     */
    private static function fileMove(string $source, string $destination): array
    {
        if (! \file_exists($source)) {
            return ['status' => 404, 'body' => '', 'headers' => []];
        }
        if (\file_exists($destination)) {
            self::fileDelete($destination);
        }
        $dir = \dirname($destination);
        if (! \is_dir($dir)) {
            @\mkdir($dir, 0775, true);
        }

        return @\rename($source, $destination)
            ? ['status' => 201, 'body' => '', 'headers' => []]
            : ['status' => 500, 'body' => '', 'headers' => []];
    }

    /**
     * @param list<string> $headers
     * @return array{status:int, body:string, headers:list<string>}
     */
    private static function filePropfind(string $path, array $headers): array
    {
        if (! \file_exists($path)) {
            return ['status' => 404, 'body' => '', 'headers' => []];
        }
        $depth = '0';
        foreach ($headers as $header) {
            if (\stripos((string) $header, 'Depth:') === 0) {
                $depth = \trim(\substr((string) $header, \strlen('Depth:')));
            }
        }

        $paths = [$path];
        if ($depth === '1' && \is_dir($path)) {
            foreach (\scandir($path) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $paths[] = $path . DIRECTORY_SEPARATOR . $entry;
            }
        }

        $responses = '';
        foreach ($paths as $item) {
            $href = \str_replace(DIRECTORY_SEPARATOR, '/', $item);
            if (\is_dir($item) && ! \str_ends_with($href, '/')) {
                $href .= '/';
            }
            $collection = \is_dir($item) ? '<d:resourcetype><d:collection/></d:resourcetype>' : '<d:resourcetype/>';
            $responses .= '<d:response><d:href>' . \htmlspecialchars($href, ENT_XML1)
                . '</d:href><d:propstat><d:prop>' . $collection
                . '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>';
        }

        return [
            'status' => 207,
            'body' => '<?xml version="1.0" encoding="utf-8"?><d:multistatus xmlns:d="DAV:">'
                . $responses . '</d:multistatus>',
            'headers' => [],
        ];
    }

    private static function deleteDirectory(string $path): void
    {
        foreach (\scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $entry;
            \is_dir($child) ? self::deleteDirectory($child) : @\unlink($child);
        }
        @\rmdir($path);
    }
}
