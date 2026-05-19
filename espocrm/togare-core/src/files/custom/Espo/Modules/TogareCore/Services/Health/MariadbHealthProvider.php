<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services\Health;

use Espo\Modules\TogareCore\Contracts\HealthCheckProviderContract;
use Espo\Modules\TogareCore\Contracts\ValueObject\HealthCheckResult;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Health do MariaDB — fonte da verdade do CRM (Story 10.2, FR41).
 *
 * Infra SEMPRE presente no compose (nunca cai em "não-instalado"). Probe
 * barato e não-bloqueante: `SELECT 1` na conexão PDO que o EspoCRM já mantém
 * aberta — se o app está de pé, isto é praticamente instantâneo. Nunca lança
 * (contrato `HealthCheckProviderContract`): falha vira `unhealthy`.
 */
final class MariadbHealthProvider implements HealthCheckProviderContract
{
    private readonly \PDO $pdo;

    public function __construct(EntityManager $entityManager)
    {
        $this->pdo = $entityManager->getPDO();
    }

    public function name(): string
    {
        return 'mariadb';
    }

    public function check(): HealthCheckResult
    {
        try {
            $started = \microtime(true);
            $value = $this->pdo->query('SELECT 1')?->fetchColumn();
            $elapsedMs = (int) \round((\microtime(true) - $started) * 1000);

            if ((int) $value !== 1) {
                return new HealthCheckResult(
                    HealthCheckResult::STATUS_UNHEALTHY,
                    'Banco fora do ar',
                    ['elapsedMs' => $elapsedMs],
                );
            }

            // > 1s para um SELECT 1 = banco sob pressão (lento).
            if ($elapsedMs > 1000) {
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
                'Banco fora do ar',
                ['cause' => $e->getMessage()],
            );
        }
    }
}
