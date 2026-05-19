<?php

declare(strict_types=1);

namespace Espo\Modules\TogareTpu\Services;

use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareTpu\Contracts\TpuSourceAdapterContract;
use Espo\ORM\EntityManager;
use PDO;
use Throwable;

/**
 * Orquestra o sync mensal das 3 tabelas de catálogo TPU (Story 3.3 — AC2/AC3/AC8).
 *
 * Para cada tabela: itera adapter (yield rows) → INSERT ON DUPLICATE KEY UPDATE
 * em batches → invalida pattern Redis (SCAN+DEL, NUNCA KEYS — Dev Notes §7).
 *
 * Idempotente (AC3): rodar 2x produz mesmas counts; `created_at` preservado;
 * `last_synced_at`/`updated_at` atualizados.
 *
 * Per-table failure isolation (AC8): se adapter falhar em uma tabela, log
 * `tpu.sync.failed` para essa tabela mas continua tentando as próximas. Tabela
 * afetada NÃO é truncada (mantém última sync bem-sucedida — fallback explícito
 * do PRD §5).
 */
final class TpuSyncService
{
    private const BATCH_SIZE = 500;
    private const NFR4_DURATION_BUDGET_MS = 900000; // 15min
    private const MAX_SCAN_ITERATIONS = 10000;

    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly TpuSourceAdapterContract $adapter,
        private readonly RedisConnection $redis,
    ) {
    }

    /**
     * @return array{classes:int, assuntos:int, movimentos:int, durationMs:int, failures:list<string>}
     */
    public function syncAll(): array
    {
        $startTotal = microtime(true);

        TogareLogger::event(
            'info',
            'tpu.sync.started',
            'Sync TPU iniciado (3 tabelas)',
            [],
        );

        $failures = [];
        $counts = ['classes' => 0, 'assuntos' => 0, 'movimentos' => 0];

        $jobs = [
            'classes' => ['table' => 'togare_tpu_classe', 'fetch' => fn () => $this->adapter->fetchClasses()],
            'assuntos' => ['table' => 'togare_tpu_assunto', 'fetch' => fn () => $this->adapter->fetchAssuntos()],
            'movimentos' => ['table' => 'togare_tpu_movimento', 'fetch' => fn () => $this->adapter->fetchMovimentos()],
        ];

        foreach ($jobs as $tipo => $cfg) {
            try {
                $counts[$tipo] = $this->syncTable($tipo, $cfg['table'], ($cfg['fetch'])());
            } catch (Throwable $e) {
                $failures[] = $tipo;
                TogareLogger::event(
                    'error',
                    'tpu.sync.failed',
                    "Sync TPU falhou para {$tipo}: " . $e->getMessage(),
                    ['tipo' => $tipo, 'reason' => $e->getMessage(), 'exception' => get_class($e)],
                );
                TogareLogger::event(
                    'error',
                    "tpu.sync.{$tipo}.failed",
                    "Sync TPU {$tipo} abortado: " . $e->getMessage(),
                    ['tipo' => $tipo],
                );
                // NÃO trunca tabela; NÃO invalida cache para essa tabela.
                continue;
            }
        }

        $durationMs = (int) round((microtime(true) - $startTotal) * 1000);

        TogareLogger::event(
            'info',
            'tpu.sync.completed',
            "Sync TPU concluído em {$durationMs}ms",
            [
                'totalCount' => array_sum($counts),
                'totalDurationMs' => $durationMs,
                'classes' => $counts['classes'],
                'assuntos' => $counts['assuntos'],
                'movimentos' => $counts['movimentos'],
                'failures' => $failures,
            ],
        );

        if ($durationMs > self::NFR4_DURATION_BUDGET_MS) {
            TogareLogger::event(
                'warning',
                'tpu.sync.duration.over_budget',
                "Sync TPU excedeu janela NFR4 (15min): {$durationMs}ms",
                ['durationMs' => $durationMs, 'budgetMs' => self::NFR4_DURATION_BUDGET_MS],
            );
        }

        return [
            'classes' => $counts['classes'],
            'assuntos' => $counts['assuntos'],
            'movimentos' => $counts['movimentos'],
            'durationMs' => $durationMs,
            'failures' => $failures,
        ];
    }

    /**
     * @param iterable<array{codigo:int, nome:string, pai_codigo:?int, glossario:?string, ativo:bool}> $rows
     */
    private function syncTable(string $tipo, string $table, iterable $rows): int
    {
        $startTable = microtime(true);
        $count = 0;
        $batch = [];

        foreach ($rows as $row) {
            $batch[] = $row;
            if (count($batch) >= self::BATCH_SIZE) {
                $this->upsertBatch($table, $batch);
                $count += count($batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $this->upsertBatch($table, $batch);
            $count += count($batch);
        }

        $this->invalidateCachePattern("togare:tpu:{$tipo}:*");

        $durationMs = (int) round((microtime(true) - $startTable) * 1000);
        TogareLogger::event(
            'info',
            "tpu.sync.{$tipo}.completed",
            "Sync TPU {$tipo} concluído: {$count} rows em {$durationMs}ms",
            ['tipo' => $tipo, 'count' => $count, 'durationMs' => $durationMs],
        );

        return $count;
    }

    /**
     * @param list<array{codigo:int, nome:string, pai_codigo:?int, glossario:?string, ativo:bool}> $batch
     */
    private function upsertBatch(string $table, array $batch): void
    {
        $pdo = $this->entityManager->getPDO();
        $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';

        if ($isMysql) {
            $sql = "
                INSERT INTO {$table}
                    (codigo, nome, pai_codigo, glossario, ativo, last_synced_at, created_at, updated_at)
                VALUES
                    (:codigo, :nome, :pai_codigo, :glossario, :ativo, :now, :now2, :now3)
                ON DUPLICATE KEY UPDATE
                    nome = VALUES(nome),
                    pai_codigo = VALUES(pai_codigo),
                    glossario = VALUES(glossario),
                    ativo = VALUES(ativo),
                    last_synced_at = VALUES(last_synced_at),
                    updated_at = VALUES(updated_at)
            ";
        } else {
            // SQLite (testes unit): INSERT OR REPLACE preserva semantics, mas
            // sobrescreve created_at. Para idempotência por linha, usamos
            // INSERT ... ON CONFLICT DO UPDATE (SQLite ≥3.24).
            $sql = "
                INSERT INTO {$table}
                    (codigo, nome, pai_codigo, glossario, ativo, last_synced_at, created_at, updated_at)
                VALUES
                    (:codigo, :nome, :pai_codigo, :glossario, :ativo, :now, :now2, :now3)
                ON CONFLICT(codigo) DO UPDATE SET
                    nome = excluded.nome,
                    pai_codigo = excluded.pai_codigo,
                    glossario = excluded.glossario,
                    ativo = excluded.ativo,
                    last_synced_at = excluded.last_synced_at,
                    updated_at = excluded.updated_at
            ";
        }

        $stmt = $pdo->prepare($sql);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        foreach ($batch as $row) {
            $stmt->execute([
                ':codigo' => $row['codigo'],
                ':nome' => $row['nome'],
                ':pai_codigo' => $row['pai_codigo'],
                ':glossario' => $row['glossario'],
                ':ativo' => $row['ativo'] ? 1 : 0,
                ':now' => $now,
                ':now2' => $now,
                ':now3' => $now,
            ]);
        }
    }

    /**
     * Invalida chaves Redis matching pattern via SCAN + DEL (NUNCA KEYS *
     * — Dev Notes §7). Em falha do Redis, log warning e segue (TTL 35d
     * auto-expira mesmo sem invalidação ativa).
     *
     * @return int número de chaves deletadas
     */
    public function invalidateCachePattern(string $pattern): int
    {
        $deleted = 0;
        try {
            $client = $this->redis->getClient();
            $cursor = '0';
            $iterations = 0;
            do {
                if (++$iterations > self::MAX_SCAN_ITERATIONS) {
                    TogareLogger::event(
                        'warning',
                        'tpu.cache.invalidation.scan_limit',
                        "SCAN excedeu limite de iterações para padrão {$pattern} — abortando",
                        ['pattern' => $pattern, 'iterations' => $iterations, 'deleted' => $deleted],
                    );
                    break;
                }
                $result = $client->scan($cursor, ['MATCH' => $pattern, 'COUNT' => 100]);
                // predis: scan retorna [cursor, keys] como tuple ou objeto Iterator.
                if (is_array($result) && count($result) === 2) {
                    [$cursor, $keys] = $result;
                    $keys = (array) $keys;
                    if ($keys !== []) {
                        $deleted += (int) $client->del($keys);
                    }
                } else {
                    // Fallback defensivo se a forma do retorno mudar.
                    break;
                }
            } while ((string) $cursor !== '0');
        } catch (Throwable $e) {
            TogareLogger::event(
                'warning',
                'tpu.cache.invalidation.failed',
                "Falha ao invalidar cache Redis para padrão {$pattern}",
                ['pattern' => $pattern, 'reason' => $e->getMessage()],
            );
            return 0;
        }
        return $deleted;
    }
}
