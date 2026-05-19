<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareTpu\Services;

use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareTpu\Services\RedisConnection;
use Espo\Modules\TogareTpu\Services\TpuCacheService;
use Espo\ORM\EntityManager;
use PDO;
use PHPUnit\Framework\TestCase;
use Predis\Client as PredisClientStub;
use tests\unit\Espo\Modules\TogareTpu\Stubs\TestRedisConnection;

/**
 * Cobertura do TpuCacheService:
 *   - hit Redis retorna direto do cache
 *   - miss Redis + hit DB popula cache
 *   - miss Redis + miss DB retorna null (NÃO cacheia o miss — AC6)
 *   - Redis down → fallback DB direto sem exception (AC7)
 *   - integer cast em codigo
 *   - JSON cache corrompido → cai pro DB
 *
 * Usa SQLite in-memory + PredisClientStub para isolamento total.
 */
final class TpuCacheServiceTest extends TestCase
{
    private PDO $pdo;
    private EntityManager $em;
    private RedisConnection $redis;
    private PredisClientStub $predisStub;

    protected function setUp(): void
    {
        TogareLogger::reset();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Cria togare_tpu_classe com 2 rows + togare_tpu_assunto vazio.
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
            INSERT INTO togare_tpu_classe (codigo, nome, pai_codigo, glossario, ativo, last_synced_at, created_at, updated_at)
            VALUES
                (436, 'Procedimento Comum Cível', 7, 'Procedimento ordinário', 1, '2026-04-28 03:00:00', '2026-04-28 03:00:00', '2026-04-28 03:00:00'),
                (159, 'Procedimento Sumário', 7, NULL, 0, '2026-04-28 03:00:00', '2026-04-28 03:00:00', '2026-04-28 03:00:00')
        ");

        // EntityManager stub retornando o PDO real (sem expectations).
        $this->em = $this->createStub(EntityManager::class);
        $this->em->method('getPDO')->willReturn($this->pdo);

        $this->predisStub = new PredisClientStub();
        $this->redis = new TestRedisConnection($this->predisStub);
    }

    public function testHitRedisRetornaDoCache(): void
    {
        $payload = json_encode(['codigo' => 999, 'nome' => 'Vindo do Cache', 'pai_codigo' => null, 'ativo' => true]);
        $this->predisStub->_seed('togare:tpu:classe:999', (string) $payload);

        $svc = new TpuCacheService($this->em, $this->redis);
        $result = $svc->resolveClasse(999);

        self::assertNotNull($result);
        self::assertSame('Vindo do Cache', $result['nome']);
        $hit = array_filter(TogareLogger::getRecorded(), fn ($e) => $e['event'] === 'tpu.cache.hit');
        self::assertNotEmpty($hit);
    }

    public function testMissRedisHitDbPopulaCache(): void
    {
        $svc = new TpuCacheService($this->em, $this->redis);
        $result = $svc->resolveClasse(436);

        self::assertNotNull($result);
        self::assertSame('Procedimento Comum Cível', $result['nome']);
        self::assertSame(7, $result['pai_codigo']);

        // Cache deve estar populado.
        $cached = $this->predisStub->get('togare:tpu:classe:436');
        self::assertNotNull($cached);
        $decoded = json_decode($cached, true);
        self::assertSame(436, $decoded['codigo']);

        $events = array_column(TogareLogger::getRecorded(), 'event');
        self::assertContains('tpu.cache.miss.db_hit', $events);
    }

    public function testMissRedisMissDbRetornaNull(): void
    {
        $svc = new TpuCacheService($this->em, $this->redis);
        $result = $svc->resolveClasse(999999);

        self::assertNull($result);

        // NÃO deve ter cacheado o miss (AC6).
        self::assertNull($this->predisStub->get('togare:tpu:classe:999999'));

        $events = array_column(TogareLogger::getRecorded(), 'event');
        self::assertContains('tpu.cache.miss.code_not_found', $events);
    }

    public function testRedisDownFallbackParaDb(): void
    {
        $this->predisStub->setUnavailable(true);

        $svc = new TpuCacheService($this->em, $this->redis);
        $result = $svc->resolveClasse(436);

        // Mesmo com Redis down, retorna do DB.
        self::assertNotNull($result);
        self::assertSame('Procedimento Comum Cível', $result['nome']);

        $events = array_column(TogareLogger::getRecorded(), 'event');
        self::assertContains('tpu.cache.miss.redis_unavailable', $events);
    }

    public function testCodigoIntegerCastAplicado(): void
    {
        $svc = new TpuCacheService($this->em, $this->redis);
        // PHP int — método já tipa o param como int, mas verificamos shape.
        $result = $svc->resolveClasse(436);

        self::assertSame(436, $result['codigo']);
        self::assertIsInt($result['codigo']);
        self::assertIsBool($result['ativo']);
    }

    public function testJsonCorrompidoNoCacheCaiParaDb(): void
    {
        // Seeda com JSON inválido — service deve descartar e cair pro DB.
        $this->predisStub->_seed('togare:tpu:classe:436', '{esse json esta corrompido');

        $svc = new TpuCacheService($this->em, $this->redis);
        $result = $svc->resolveClasse(436);

        self::assertNotNull($result);
        self::assertSame('Procedimento Comum Cível', $result['nome']);

        $events = array_column(TogareLogger::getRecorded(), 'event');
        self::assertContains('tpu.cache.miss.json_corrupt', $events);
    }
}

