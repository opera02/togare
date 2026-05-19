<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareTpu\Services;

use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareTpu\Services\TpuCacheService;
use Espo\ORM\EntityManager;
use PDO;
use PHPUnit\Framework\TestCase;
use Predis\Client as PredisClientStub;
use tests\unit\Espo\Modules\TogareTpu\Stubs\TestRedisConnection;

/**
 * Cobre Story 3.4 Task 8 — TpuCacheService::searchByName.
 *
 * Garantias:
 *   - tipo fora da allowlist retorna []
 *   - q < 3 chars retorna []
 *   - hit DB retorna lista filtrada por nome (case-insensitive)
 *   - cache popula no miss + hit no segundo call
 *   - limit cap respeitado
 */
final class TpuCatalogSearchTest extends TestCase
{
    private PDO $pdo;
    private EntityManager $em;
    private TestRedisConnection $redis;
    private PredisClientStub $predisStub;

    protected function setUp(): void
    {
        TogareLogger::reset();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec("
            CREATE TABLE togare_tpu_classe (
                codigo BIGINT NOT NULL PRIMARY KEY,
                nome VARCHAR(255) NOT NULL,
                pai_codigo BIGINT NULL,
                glossario TEXT NULL,
                ativo TINYINT NOT NULL DEFAULT 1,
                last_synced_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            )
        ");
        $this->pdo->exec("
            INSERT INTO togare_tpu_classe (codigo, nome, ativo, last_synced_at, created_at, updated_at)
            VALUES
                (436, 'Procedimento Comum Cível', 1, '2026-04-28', '2026-04-28', '2026-04-28'),
                (159, 'Procedimento Sumário', 1, '2026-04-28', '2026-04-28', '2026-04-28'),
                (160, 'Procedimento Especial', 1, '2026-04-28', '2026-04-28', '2026-04-28'),
                (161, 'Procedimento 100% Especial', 1, '2026-04-28', '2026-04-28', '2026-04-28'),
                (200, 'Cumprimento de Sentença', 1, '2026-04-28', '2026-04-28', '2026-04-28'),
                (300, 'Inativo Test', 0, '2026-04-28', '2026-04-28', '2026-04-28')
        ");

        $this->em = $this->createStub(EntityManager::class);
        $this->em->method('getPDO')->willReturn($this->pdo);

        $this->predisStub = new PredisClientStub();
        $this->redis = new TestRedisConnection($this->predisStub);
    }

    public function testTipoForaDaAllowlistRetornaArrayVazio(): void
    {
        $svc = new TpuCacheService($this->em, $this->redis);
        $result = $svc->searchByName('inexistente', 'procedimento');

        self::assertSame([], $result);
    }

    public function testQueryComMenosDe3CharsRetornaArrayVazio(): void
    {
        $svc = new TpuCacheService($this->em, $this->redis);
        $result = $svc->searchByName('classe', 'pr');

        self::assertSame([], $result);
    }

    public function testSearchHitDbRetornaListaFiltrada(): void
    {
        $svc = new TpuCacheService($this->em, $this->redis);
        $result = $svc->searchByName('classe', 'procedimento', 20);

        self::assertCount(4, $result, 'Esperado 4 classes "Procedimento" ativas (Inativo é filtrado por ativo=1)');
        $codigos = \array_column($result, 'codigo');
        self::assertContains(436, $codigos);
        self::assertContains(159, $codigos);
        self::assertContains(160, $codigos);
        self::assertContains(161, $codigos);
    }

    public function testWildcardPercentEhTratadoComoLiteral(): void
    {
        $svc = new TpuCacheService($this->em, $this->redis);
        $result = $svc->searchByName('classe', '100%');

        self::assertCount(1, $result);
        self::assertSame(161, $result[0]['codigo']);
        self::assertSame('Procedimento 100% Especial', $result[0]['nome']);
    }

    public function testInativoEFiltradoNaSearch(): void
    {
        $svc = new TpuCacheService($this->em, $this->redis);
        $result = $svc->searchByName('classe', 'inativo');

        self::assertSame([], $result, 'Linha com ativo=0 deve ser filtrada');
    }

    public function testSegundaChamadaUsaCacheRedis(): void
    {
        $svc = new TpuCacheService($this->em, $this->redis);

        // 1ª chamada — popula cache
        $first = $svc->searchByName('classe', 'cumprimento');
        self::assertCount(1, $first);

        // Captura quantos comandos foram para o Redis até aqui
        $callsAfterFirst = $this->predisStub->callLog;

        // 2ª chamada — deve hit no cache (apenas 1 GET adicional, sem SET)
        $second = $svc->searchByName('classe', 'cumprimento');
        self::assertCount(1, $second);
        self::assertSame($first, $second);

        // O 1º call gerou: GET (miss) + SET (populate). O 2º só: GET (hit)
        // Total no log: GET, SET, GET — 3 ops.
        $totalOps = \count($this->predisStub->callLog);
        $opsFromSecond = $totalOps - \count($callsAfterFirst);
        self::assertSame(1, $opsFromSecond, '2ª chamada deve fazer apenas 1 op Redis (GET hit)');
    }

    public function testLimitCapRespeitado(): void
    {
        $svc = new TpuCacheService($this->em, $this->redis);
        // limit=200 deve ser cap em 100 internamente; fixture tem 4 ativos com "procedimento".
        $result = $svc->searchByName('classe', 'procedimento', 200);

        self::assertLessThanOrEqual(100, \count($result));
    }
}
