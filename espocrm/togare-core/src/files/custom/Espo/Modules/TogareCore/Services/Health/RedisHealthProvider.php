<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services\Health;

use Espo\Modules\TogareCore\Contracts\HealthCheckProviderContract;
use Espo\Modules\TogareCore\Contracts\ValueObject\HealthCheckResult;
use Throwable;

/**
 * Health do Redis — sessões + cache TPU + contadores de rate-limit
 * (Story 10.2, FR41).
 *
 * Infra SEMPRE presente no compose (nunca "não-instalado"). Probe não-bloqueante:
 * abre socket TCP com timeout curto e envia `PING` cru no protocolo RESP (não
 * depende da extensão phpredis estar carregada). Host/porta/senha vêm das env
 * vars `TOGARE_REDIS_*` que o container espocrm já recebe. Nunca lança.
 */
final class RedisHealthProvider implements HealthCheckProviderContract
{
    private const CONNECT_TIMEOUT_S = 2;
    private const SLOW_THRESHOLD_MS = 1000;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly ?string $password,
    ) {
    }

    public function name(): string
    {
        return 'redis';
    }

    public function check(): HealthCheckResult
    {
        $socket = null;
        try {
            $started = \microtime(true);
            $errno = 0;
            $errstr = '';
            $socket = @\fsockopen(
                $this->host,
                $this->port,
                $errno,
                $errstr,
                self::CONNECT_TIMEOUT_S,
            );

            if ($socket === false) {
                return new HealthCheckResult(
                    HealthCheckResult::STATUS_UNHEALTHY,
                    'Fora do ar',
                    ['cause' => $errstr !== '' ? $errstr : "errno {$errno}"],
                );
            }

            \stream_set_timeout($socket, self::CONNECT_TIMEOUT_S);

            if ($this->password !== null && $this->password !== '') {
                \fwrite($socket, $this->resp(['AUTH', $this->password]));
                $authReply = $this->readLine($socket);
                if ($authReply !== '+OK') {
                    return new HealthCheckResult(
                        HealthCheckResult::STATUS_UNHEALTHY,
                        'Fora do ar',
                        ['cause' => 'autenticação Redis falhou: ' . $authReply],
                    );
                }
            }

            \fwrite($socket, $this->resp(['PING']));
            $reply = $this->readLine($socket);
            $elapsedMs = (int) \round((\microtime(true) - $started) * 1000);

            if (\stripos($reply, 'PONG') === false) {
                return new HealthCheckResult(
                    HealthCheckResult::STATUS_UNHEALTHY,
                    'Fora do ar',
                    ['reply' => $reply, 'elapsedMs' => $elapsedMs],
                );
            }

            if ($elapsedMs > self::SLOW_THRESHOLD_MS) {
                return new HealthCheckResult(
                    HealthCheckResult::STATUS_DEGRADED,
                    \sprintf('%dms — resposta lenta', $elapsedMs),
                    ['elapsedMs' => $elapsedMs],
                );
            }

            return new HealthCheckResult(
                HealthCheckResult::STATUS_HEALTHY,
                'OK',
                ['elapsedMs' => $elapsedMs],
            );
        } catch (Throwable $e) {
            return new HealthCheckResult(
                HealthCheckResult::STATUS_UNHEALTHY,
                'Fora do ar',
                ['cause' => $e->getMessage()],
            );
        } finally {
            if (\is_resource($socket)) {
                @\fclose($socket);
            }
        }
    }

    /**
     * Serializa um comando no protocolo RESP (array de bulk strings).
     *
     * @param list<string> $args
     */
    private function resp(array $args): string
    {
        $out = '*' . \count($args) . "\r\n";
        foreach ($args as $arg) {
            $out .= '$' . \strlen($arg) . "\r\n" . $arg . "\r\n";
        }
        return $out;
    }

    /**
     * @param resource $socket
     */
    private function readLine($socket): string
    {
        $line = \fgets($socket);
        return $line === false ? '' : \trim($line);
    }
}
