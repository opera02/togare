<?php

declare(strict_types=1);

namespace Predis;

/**
 * Stub mínimo de Predis\Client para testes unit do togare-tpu.
 *
 * Carregado pelo bootstrap.php apenas se a classe real do predis não estiver
 * disponível via composer autoload (cenário: composer install ainda não
 * rodou no host; típico em CI ou em workstation sem PHP/composer locais).
 *
 * Implementa um Redis in-memory com:
 *   - GET/SET/DEL/SCAN/PING (suficiente pro togare-tpu).
 *   - Modo "indisponível" via setUnavailable(true) — força lançar exception
 *     em qualquer comando, simulando Redis fora do ar (AC7 fallback DB).
 *
 * Não suporta TTL real (set EX é aceito mas ignorado — testes não dependem
 * de expiração temporal).
 */
class Client
{
    /** @var array<string,string> */
    private array $store = [];

    private bool $unavailable = false;

    /** @var list<string> */
    public array $callLog = [];

    /**
     * @param array<string,mixed>|null $params
     */
    public function __construct(?array $params = null)
    {
        // params ignorados — stub não conecta de fato.
    }

    public function setUnavailable(bool $flag): void
    {
        $this->unavailable = $flag;
    }

    public function ping(): string
    {
        $this->guard('PING');
        return 'PONG';
    }

    public function get(string $key): ?string
    {
        $this->guard('GET');
        return $this->store[$key] ?? null;
    }

    /**
     * Aceita assinaturas:
     *   - set($key, $value)
     *   - set($key, $value, 'EX', $seconds)
     */
    public function set(string $key, string $value, ...$opts): mixed
    {
        $this->guard('SET');
        $this->store[$key] = $value;
        return 'OK';
    }

    /**
     * @param array<int,string>|string $keys
     */
    public function del($keys): int
    {
        $this->guard('DEL');
        if (! is_array($keys)) {
            $keys = [$keys];
        }
        $count = 0;
        foreach ($keys as $key) {
            if (array_key_exists($key, $this->store)) {
                unset($this->store[$key]);
                $count++;
            }
        }
        return $count;
    }

    /**
     * @param string $cursor
     * @param array<string,mixed> $opts
     * @return array{0:string,1:list<string>}
     */
    public function scan($cursor, array $opts = []): array
    {
        $this->guard('SCAN');
        $pattern = (string) ($opts['MATCH'] ?? '*');
        $regex = '/^' . str_replace(['\\*', '\\?'], ['.*', '.'], preg_quote($pattern, '/')) . '$/';
        $matched = [];
        foreach (array_keys($this->store) as $key) {
            if (preg_match($regex, $key) === 1) {
                $matched[] = $key;
            }
        }
        // Stub retorna tudo numa iteração — cursor sempre volta a 0.
        return ['0', $matched];
    }

    /**
     * Helper de teste — força uma chave no store sem passar por SET.
     */
    public function _seed(string $key, string $value): void
    {
        $this->store[$key] = $value;
    }

    /**
     * Helper de teste — inspeção do store.
     *
     * @return array<string,string>
     */
    public function _all(): array
    {
        return $this->store;
    }

    private function guard(string $op): void
    {
        $this->callLog[] = $op;
        if ($this->unavailable) {
            throw new \RuntimeException("Predis stub: indisponível (op={$op})");
        }
    }
}
