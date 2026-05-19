<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services\Health;

use Espo\Modules\TogareCore\Contracts\HealthCheckProviderContract;
use Espo\Modules\TogareCore\Contracts\ValueObject\HealthCheckResult;
use Throwable;

/**
 * Health do DJEN — integração de publicações (Story 10.2, FR41 + NFR18/NFR19).
 *
 * Só é instanciado quando o módulo `togare-djen` está presente (premium
 * opcional); ausente → tile cinza "Não instalado" decidido pelo
 * `HealthCheckService` (AC1). Probe NÃO faz chamada de rede: lê o state-file
 * do circuit breaker (`TOGARE_DJEN_CB_STATE_PATH`, volume compartilhado
 * `togare_djen_cb_state` — mesmo arquivo que o worker do DJEN escreve, Story
 * 4b.4 / ADR 0009). Espelha a semântica de `TogareDjenStatus::getActionSnapshot`
 * sem acoplar a classe. Limiar de banner = 30min indisponível (NFR19).
 * Nunca lança.
 */
final class DjenHealthProvider implements HealthCheckProviderContract
{
    private const MIN_MINUTES_OFFLINE = 30;

    public function __construct(private readonly string $cbStatePath)
    {
    }

    public function name(): string
    {
        return 'djen';
    }

    public function check(): HealthCheckResult
    {
        try {
            if (! \is_file($this->cbStatePath)) {
                // Sem state-file = circuit breaker nunca abriu = saudável.
                return new HealthCheckResult(
                    HealthCheckResult::STATUS_HEALTHY,
                    'OK',
                );
            }

            $raw = @\file_get_contents($this->cbStatePath);
            $state = \is_string($raw) ? \json_decode($raw, true) : null;
            if (! \is_array($state)) {
                return new HealthCheckResult(
                    HealthCheckResult::STATUS_DEGRADED,
                    'Estado indeterminado',
                );
            }

            $now = \time();
            $unavailableSince = (int) ($state['unavailable_since'] ?? 0);
            $openUntil = (int) ($state['open_until'] ?? 0);

            if ($unavailableSince <= 0) {
                return new HealthCheckResult(
                    HealthCheckResult::STATUS_HEALTHY,
                    'OK',
                );
            }

            $minutesOffline = (int) \floor(\max(0, $now - $unavailableSince) / 60);

            // < 30min indisponível: degradado (amarelo), ainda em retry.
            if ($minutesOffline < self::MIN_MINUTES_OFFLINE) {
                return new HealthCheckResult(
                    HealthCheckResult::STATUS_DEGRADED,
                    \sprintf('Instável há %d min', $minutesOffline),
                    ['minutesOffline' => $minutesOffline, 'openUntil' => $openUntil],
                );
            }

            return new HealthCheckResult(
                HealthCheckResult::STATUS_UNHEALTHY,
                \sprintf('Fora do ar há %d min', $minutesOffline),
                ['minutesOffline' => $minutesOffline, 'openUntil' => $openUntil],
            );
        } catch (Throwable $e) {
            return new HealthCheckResult(
                HealthCheckResult::STATUS_DEGRADED,
                'Estado indeterminado',
                ['cause' => $e->getMessage()],
            );
        }
    }
}
