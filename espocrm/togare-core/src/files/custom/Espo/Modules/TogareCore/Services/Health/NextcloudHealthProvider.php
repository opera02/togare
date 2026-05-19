<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services\Health;

use Espo\Modules\TogareCore\Contracts\HealthCheckProviderContract;
use Espo\Modules\TogareCore\Contracts\ValueObject\HealthCheckResult;
use Throwable;

/**
 * Health do Nextcloud — hub de arquivos (Story 10.2, FR41).
 *
 * Só é instanciado quando o módulo `togare-nextcloud-bridge` está presente; se
 * ausente, o `HealthCheckService` emite tile cinza "Não instalado" SEM chamar
 * este provider (regra AC1). Probe não-bloqueante: `GET {baseUrl}/status.php`
 * (endpoint público e barato do Nextcloud) com timeout curto via stream
 * context — não usa o bridge para não acoplar nem disparar chamada cara.
 * Nunca lança.
 */
final class NextcloudHealthProvider implements HealthCheckProviderContract
{
    private const TIMEOUT_S = 2;
    private const SLOW_THRESHOLD_MS = 1500;

    public function __construct(private readonly string $baseUrl)
    {
    }

    public function name(): string
    {
        return 'nextcloud';
    }

    public function check(): HealthCheckResult
    {
        $base = \rtrim($this->baseUrl, '/');
        if ($base === '') {
            return new HealthCheckResult(
                HealthCheckResult::STATUS_UNHEALTHY,
                'Fora do ar',
                ['cause' => 'baseUrl vazio'],
            );
        }

        try {
            $started = \microtime(true);
            $sslVerify = \getenv('TOGARE_NC_SSL_VERIFY') !== 'false';
            $context = \stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => self::TIMEOUT_S,
                    'ignore_errors' => true,
                    'follow_location' => 0,
                    'header' => "Accept: application/json\r\n",
                ],
                'ssl' => [
                    'verify_peer' => $sslVerify,
                    'verify_peer_name' => $sslVerify,
                ],
            ]);

            $body = @\file_get_contents($base . '/status.php', false, $context);
            $elapsedMs = (int) \round((\microtime(true) - $started) * 1000);

            if ($body === false) {
                return new HealthCheckResult(
                    HealthCheckResult::STATUS_UNHEALTHY,
                    'Fora do ar',
                    ['elapsedMs' => $elapsedMs],
                );
            }

            $decoded = \json_decode($body, true);
            $installed = \is_array($decoded) && ($decoded['installed'] ?? false) === true;

            if (! $installed) {
                return new HealthCheckResult(
                    HealthCheckResult::STATUS_DEGRADED,
                    'Respondeu, mas status inesperado',
                    ['elapsedMs' => $elapsedMs],
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
        }
    }
}
