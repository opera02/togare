<?php

declare(strict_types=1);

namespace Espo\Modules\TogareTpu\Services;

use Espo\Modules\TogareCore\Services\TogareLogger;
use Predis\Client as PredisClient;
use Throwable;

/**
 * Factory singleton de cliente Redis (predis) para o togare-tpu.
 *
 * Lê `TOGARE_REDIS_*` env vars (Decisão #4 do Story 3.3 Dev Notes — DB 1
 * isolado do Nextcloud). Conexão lazy (só conecta no primeiro `getClient()`).
 *
 * `isAvailable()` é defensivo — captura qualquer Throwable e retorna bool.
 * NÃO lança exceção, deixa o consumidor decidir o fallback (TpuCacheService
 * usa para escolher entre Redis e DB direto, NFR18 degradação graciosa).
 */
class RedisConnection
{
    private ?PredisClient $client = null;
    private bool $connectedLogged = false;

    private readonly string $host;
    private readonly int $port;
    private readonly string $password;
    private readonly int $db;

    public function __construct(
        ?string $host = null,
        ?int $port = null,
        ?string $password = null,
        ?int $db = null,
    ) {
        $this->host = $host ?? (string) (getenv('TOGARE_REDIS_HOST') ?: 'redis');
        $this->port = $port ?? (int) (getenv('TOGARE_REDIS_PORT') ?: 6379);
        $this->password = $password ?? (string) (getenv('TOGARE_REDIS_PASSWORD') ?: '');
        $this->db = $db ?? (int) (getenv('TOGARE_REDIS_DB') ?: 1);
    }

    /**
     * Retorna cliente Predis configurado. Conecta no primeiro acesso.
     * Lança Throwable em falha de conexão — chamadores devem envolver em
     * try/catch ou usar `isAvailable()` antes.
     */
    /**
     * Descarta o cliente em cache, forçando reconexão no próximo getClient().
     * Deve ser chamado quando uma operação falha com erro de conexão para
     * permitir recuperação após queda e retorno do Redis.
     */
    public function reset(): void
    {
        $this->client = null;
        $this->connectedLogged = false;
    }

    public function getClient(): PredisClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $params = [
            'scheme' => 'tcp',
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->db,
            'read_write_timeout' => 5,
        ];
        if ($this->password !== '') {
            $params['password'] = $this->password;
        }

        $this->client = new PredisClient($params);

        if (! $this->connectedLogged) {
            TogareLogger::event(
                'info',
                'tpu.redis.client_created',
                'Cliente Predis togare-tpu instanciado (conexão TCP lazy — ocorre na 1ª operação)',
                ['host' => $this->host, 'port' => $this->port, 'db' => $this->db],
            );
            $this->connectedLogged = true;
        }

        return $this->client;
    }

    /**
     * Verifica se o Redis responde a PING. Defensivo: NUNCA lança — captura
     * qualquer Throwable e retorna false. Usado por `TpuCacheService` para
     * escolher entre cache Redis ou fallback DB direto.
     */
    public function isAvailable(): bool
    {
        try {
            $client = $this->getClient();
            $reply = $client->ping();
            // predis aceita string "PONG" ou objeto Status.
            $isPong = (string) $reply === 'PONG' || stripos((string) $reply, 'PONG') !== false;
            if (! $isPong) {
                $this->logUnavailable('PING não retornou PONG');
            }
            return $isPong;
        } catch (Throwable $e) {
            $this->logUnavailable($e->getMessage());
            return false;
        }
    }

    private function logUnavailable(string $reason): void
    {
        TogareLogger::event(
            'warning',
            'tpu.redis.unavailable',
            'Redis indisponível — togare-tpu cairá para fallback DB direto',
            ['host' => $this->host, 'port' => $this->port, 'db' => $this->db, 'reason' => $reason],
        );
    }
}
