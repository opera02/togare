<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareTpu\Stubs;

use Espo\Modules\TogareTpu\Services\RedisConnection;
use Predis\Client as PredisClient;

/**
 * Substitui RedisConnection::getClient() por um stub injetado no construtor —
 * permite injetar PredisClientStub sem precisar configurar env vars.
 *
 * Em runtime real, NÃO é usado: RedisConnection nativo conecta ao Redis real.
 */
final class TestRedisConnection extends RedisConnection
{
    public function __construct(private PredisClient $stub)
    {
        parent::__construct('test', 0, '', 0);
    }

    public function getClient(): PredisClient
    {
        return $this->stub;
    }

    public function isAvailable(): bool
    {
        try {
            $this->stub->ping();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
