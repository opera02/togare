<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Contracts\ValueObject;

/**
 * Resultado de um health check — imutável. Usado por HealthCheckProviderContract
 * e por TogareHealth (Epic 10) para montar o painel administrativo.
 */
final readonly class HealthCheckResult
{
    public const STATUS_HEALTHY = 'healthy';
    public const STATUS_DEGRADED = 'degraded';
    public const STATUS_UNHEALTHY = 'unhealthy';

    /**
     * @param 'healthy'|'degraded'|'unhealthy' $status
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $status,
        public string $message,
        public array $context = [],
    ) {
    }

    public function isHealthy(): bool
    {
        return $this->status === self::STATUS_HEALTHY;
    }
}
