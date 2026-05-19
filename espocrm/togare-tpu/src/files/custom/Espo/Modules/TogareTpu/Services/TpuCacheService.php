<?php

declare(strict_types=1);

namespace Espo\Modules\TogareTpu\Services;

use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareTpu\Contracts\AssuntoResolverContract;
use Espo\Modules\TogareTpu\Contracts\ClasseResolverContract;
use Espo\Modules\TogareTpu\Contracts\MovimentoResolverContract;
use Espo\ORM\EntityManager;
use PDO;
use Throwable;

/**
 * Implementação cache-aside dos 3 ResolverContracts (Story 3.3 — AC4/AC5/AC6/AC7).
 *
 * Padrão por lookup:
 *   1. Tenta Redis (`togare:tpu:<tipo>:{codigo}`).
 *      - Hit → decode JSON + log debug `tpu.cache.hit` → return.
 *   2. Miss Redis → query MariaDB.
 *      - Hit DB → log `tpu.cache.miss.db_hit` + populate Redis (TTL 35d) → return.
 *      - Miss DB → log `tpu.cache.miss.code_not_found` → return null
 *        (NÃO cacheia o miss — próximo sync pode adicionar; AC6).
 *   3. Falha Redis em qualquer ponto → log `tpu.cache.miss.redis_unavailable`
 *      → fallback DB direto (AC7 — sistema continua funcional sem cache).
 *
 * NFR17 alvo: p95 ≤100ms. Hit Redis ~1-3ms; miss Redis + hit DB (PRIMARY KEY)
 * ~5-15ms; fallback DB ~5-50ms. Folga grande sobre o alvo.
 */
final class TpuCacheService implements
    ClasseResolverContract,
    AssuntoResolverContract,
    MovimentoResolverContract
{
    private const TTL_SECONDS = 3024000; // 35 dias
    private const SEARCH_TTL_SECONDS = 3600; // 1 hora
    private const SEARCH_MIN_QUERY_LENGTH = 3;
    private const SEARCH_MAX_LIMIT = 100;
    private const SEARCH_DEFAULT_LIMIT = 20;
    private const ALLOWED_SEARCH_TIPOS = ['classe', 'assunto', 'movimento'];

    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly RedisConnection $redis,
    ) {
    }

    public function resolveClasse(int $codigo): ?array
    {
        return $this->resolve('classe', 'togare_tpu_classe', $codigo);
    }

    public function resolveAssunto(int $codigo): ?array
    {
        return $this->resolve('assunto', 'togare_tpu_assunto', $codigo);
    }

    public function resolveMovimento(int $codigo): ?array
    {
        return $this->resolve('movimento', 'togare_tpu_movimento', $codigo);
    }

    /**
     * Busca por nome no catálogo TPU (Story 3.4 Task 8 — endpoint search).
     *
     * Cache Redis 1h por (tipo, q normalizado, limit). Min q=3 chars (evita
     * full-table scan). Limit cap 100. Sanitiza wildcards LIKE para evitar
     * pattern denial.
     *
     * @return list<array{codigo:int, nome:string, paiCodigo:?int, ativo:bool}>
     */
    public function searchByName(string $tipo, string $q, int $limit = self::SEARCH_DEFAULT_LIMIT): array
    {
        if (! \in_array($tipo, self::ALLOWED_SEARCH_TIPOS, true)) {
            return [];
        }

        $qNormalized = \mb_strtolower(\trim($q), 'UTF-8');
        if (\mb_strlen($qNormalized, 'UTF-8') < self::SEARCH_MIN_QUERY_LENGTH) {
            return [];
        }

        $limit = \max(1, \min($limit, self::SEARCH_MAX_LIMIT));
        $table = "togare_tpu_{$tipo}";

        $cacheKey = "togare:tpu:search:{$tipo}:" . \md5("{$qNormalized}|{$limit}");

        // 1) Redis hit?
        $redisAvailable = true;
        try {
            $client = $this->redis->getClient();
            $cached = $client->get($cacheKey);
            if (\is_string($cached) && $cached !== '') {
                try {
                    $decoded = \json_decode($cached, true, 8, JSON_THROW_ON_ERROR);
                    if (\is_array($decoded)) {
                        return $decoded;
                    }
                } catch (Throwable $e) {
                    // JSON corrompido — descarta e cai para DB
                    TogareLogger::event(
                        'warning',
                        'tpu.search.cache.json_corrupt',
                        "Cache search com JSON corrompido para {$tipo}",
                        ['tipo' => $tipo, 'reason' => $e->getMessage()],
                    );
                }
            }
        } catch (Throwable $e) {
            $redisAvailable = false;
            $this->redis->reset();
            TogareLogger::event(
                'warning',
                'tpu.search.cache.redis_unavailable',
                "Redis indisponível em search {$tipo} — fallback DB",
                ['tipo' => $tipo, 'reason' => $e->getMessage()],
            );
        }

        // 2) DB query — sanitize wildcards LIKE para evitar pattern attack
        $qSafe = \str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $qNormalized);
        $like = '%' . $qSafe . '%';

        $rows = $this->fetchSearchFromDb($table, $like, $limit);

        // 3) Populate cache (best effort) se Redis estava disponível
        if ($redisAvailable) {
            try {
                $this->redis->getClient()->set(
                    $cacheKey,
                    \json_encode($rows, JSON_THROW_ON_ERROR),
                    'EX',
                    self::SEARCH_TTL_SECONDS,
                );
            } catch (Throwable $e) {
                TogareLogger::event(
                    'warning',
                    'tpu.search.cache.set.failed',
                    "Falha ao popular cache search Redis para {$tipo}",
                    ['tipo' => $tipo, 'reason' => $e->getMessage()],
                );
            }
        }

        return $rows;
    }

    /**
     * @return list<array{codigo:int, nome:string, paiCodigo:?int, ativo:bool}>
     */
    private function fetchSearchFromDb(string $table, string $like, int $limit): array
    {
        $pdo = $this->entityManager->getPDO();
        $stmt = $pdo->prepare(
            "SELECT codigo, nome, pai_codigo, ativo FROM {$table} "
            . "WHERE ativo = 1 AND nome LIKE :like ESCAPE '!' "
            . "ORDER BY nome ASC LIMIT :limit",
        );
        $stmt->bindValue(':like', $like, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === false || $rows === []) {
            return [];
        }
        $result = [];
        foreach ($rows as $row) {
            $pai = $row['pai_codigo'] ?? null;
            $result[] = [
                'codigo' => (int) ($row['codigo'] ?? 0),
                'nome' => (string) ($row['nome'] ?? ''),
                'paiCodigo' => $pai === null || $pai === '' ? null : (int) $pai,
                'ativo' => (bool) ($row['ativo'] ?? true),
            ];
        }
        return $result;
    }

    /**
     * @return array{codigo:int, nome:string, pai_codigo:?int, ativo:bool}|null
     */
    private function resolve(string $tipo, string $table, int $codigo): ?array
    {
        if ($codigo <= 0) {
            return null;
        }

        $cacheKey = "togare:tpu:{$tipo}:{$codigo}";

        // 1) Redis hit?
        $redisAvailable = true;
        try {
            $client = $this->redis->getClient();
            $cached = $client->get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                try {
                    $decoded = json_decode($cached, true, 8, JSON_THROW_ON_ERROR);
                    if (is_array($decoded) && isset($decoded['codigo'])) {
                        TogareLogger::event(
                            'debug',
                            'tpu.cache.hit',
                            "Cache TPU hit para {$tipo} {$codigo}",
                            ['tipo' => $tipo, 'codigo' => $codigo],
                        );
                        return $this->shapeRow($decoded);
                    }
                    // Cache corrompido — descarta entrada e cai para DB.
                } catch (Throwable $e) {
                    // JSON corrompido em cache — descarta e segue para DB.
                    TogareLogger::event(
                        'warning',
                        'tpu.cache.miss.json_corrupt',
                        "Cache TPU com JSON corrompido para {$tipo} {$codigo}",
                        ['tipo' => $tipo, 'codigo' => $codigo, 'reason' => $e->getMessage()],
                    );
                }
            }
        } catch (Throwable $e) {
            $redisAvailable = false;
            $this->redis->reset();
            TogareLogger::event(
                'warning',
                'tpu.cache.miss.redis_unavailable',
                "Redis indisponível em lookup {$tipo} {$codigo} — fallback DB",
                ['tipo' => $tipo, 'codigo' => $codigo, 'reason' => $e->getMessage()],
            );
        }

        // 2) DB lookup.
        $row = $this->fetchFromDb($table, $codigo);

        if ($row === null) {
            TogareLogger::event(
                'info',
                'tpu.cache.miss.code_not_found',
                "Código TPU {$codigo} não encontrado no catálogo de {$tipo}",
                ['tipo' => $tipo, 'codigo' => $codigo],
            );
            return null;
        }

        // 3) Populate cache (best effort) se Redis estava disponível.
        if ($redisAvailable) {
            try {
                $this->redis->getClient()->set(
                    $cacheKey,
                    json_encode($row, JSON_THROW_ON_ERROR),
                    'EX',
                    self::TTL_SECONDS,
                );
                TogareLogger::event(
                    'debug',
                    'tpu.cache.miss.db_hit',
                    "Cache TPU populado a partir do DB para {$tipo} {$codigo}",
                    ['tipo' => $tipo, 'codigo' => $codigo],
                );
            } catch (Throwable $e) {
                TogareLogger::event(
                    'warning',
                    'tpu.cache.set.failed',
                    "Falha ao popular cache Redis para {$tipo} {$codigo}",
                    ['tipo' => $tipo, 'codigo' => $codigo, 'reason' => $e->getMessage()],
                );
            }
        }

        return $row;
    }

    /**
     * @return array{codigo:int, nome:string, pai_codigo:?int, ativo:bool}|null
     */
    private function fetchFromDb(string $table, int $codigo): ?array
    {
        $pdo = $this->entityManager->getPDO();
        $stmt = $pdo->prepare(
            "SELECT codigo, nome, pai_codigo, ativo FROM {$table} WHERE codigo = :codigo LIMIT 1",
        );
        $stmt->execute([':codigo' => $codigo]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || $row === null) {
            return null;
        }
        return $this->shapeRow($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{codigo:int, nome:string, pai_codigo:?int, ativo:bool}
     */
    private function shapeRow(array $row): array
    {
        $pai = $row['pai_codigo'] ?? null;
        return [
            'codigo' => (int) ($row['codigo'] ?? 0),
            'nome' => (string) ($row['nome'] ?? ''),
            'pai_codigo' => $pai === null || $pai === '' ? null : (int) $pai,
            'ativo' => (bool) ($row['ativo'] ?? true),
        ];
    }
}
