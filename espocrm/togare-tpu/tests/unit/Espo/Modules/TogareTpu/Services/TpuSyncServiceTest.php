<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareTpu\Services;

use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareTpu\Services\RedisConnection;
use Espo\Modules\TogareTpu\Services\TpuSyncService;
use Espo\ORM\EntityManager;
use PDO;
use PHPUnit\Framework\TestCase;
use Predis\Client as PredisClientStub;
use tests\unit\Espo\Modules\TogareTpu\Stubs\TestRedisConnection;
use tests\unit\Espo\Modules\TogareTpu\Stubs\TpuSourceAdapterStub;

/**
 * Cobertura do TpuSyncService:
 *   - syncAll popula 3 tabelas a partir do adapter
 *   - idempotente: rodar 2x produz mesmo row count
 *   - partial failure (uma tabela falha) NÃO trunca outras (AC8)
 *   - invalidate cache pattern usa SCAN, NUNCA KEYS
 *   - log evento `tpu.sync.completed` com counts
 */
final class TpuSyncServiceTest extends TestCase
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

        foreach (['classe', 'assunto', 'movimento'] as $tipo) {
            $this->pdo->exec("
                CREATE TABLE togare_tpu_{$tipo} (
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
        }

        $this->em = $this->createStub(EntityManager::class);
        $this->em->method('getPDO')->willReturn($this->pdo);

        $this->predisStub = new PredisClientStub();
        $this->redis = new TestRedisConnection($this->predisStub);
    }

    public function testSyncAllPopulaTresTabelas(): void
    {
        $adapter = new TpuSourceAdapterStub(
            classes: $this->mkRows(3, 'classe-'),
            assuntos: $this->mkRows(2, 'assunto-'),
            movimentos: $this->mkRows(4, 'mov-'),
        );

        $svc = new TpuSyncService($this->em, $adapter, $this->redis);
        $result = $svc->syncAll();

        self::assertSame(3, $result['classes']);
        self::assertSame(2, $result['assuntos']);
        self::assertSame(4, $result['movimentos']);
        self::assertSame([], $result['failures']);

        self::assertSame('3', (string) $this->pdo->query('SELECT COUNT(*) FROM togare_tpu_classe')->fetchColumn());
        self::assertSame('2', (string) $this->pdo->query('SELECT COUNT(*) FROM togare_tpu_assunto')->fetchColumn());
        self::assertSame('4', (string) $this->pdo->query('SELECT COUNT(*) FROM togare_tpu_movimento')->fetchColumn());

        $events = array_column(TogareLogger::getRecorded(), 'event');
        self::assertContains('tpu.sync.started', $events);
        self::assertContains('tpu.sync.completed', $events);
    }

    public function testSyncAllIdempotenteRodandoDuasVezes(): void
    {
        $rows = $this->mkRows(3, 'classe-');
        $adapter = new TpuSourceAdapterStub(classes: $rows, assuntos: [], movimentos: []);

        $svc = new TpuSyncService($this->em, $adapter, $this->redis);
        $svc->syncAll();
        $countAfter1 = (int) $this->pdo->query('SELECT COUNT(*) FROM togare_tpu_classe')->fetchColumn();

        // Rodar 2x — adapter idempotente (mesmo dataset).
        $svc->syncAll();
        $countAfter2 = (int) $this->pdo->query('SELECT COUNT(*) FROM togare_tpu_classe')->fetchColumn();

        self::assertSame(3, $countAfter1);
        self::assertSame($countAfter1, $countAfter2, 'Idempotência: row count deve permanecer igual.');
    }

    public function testSyncAllPartialFailureNaoTruncaTabelaAfetada(): void
    {
        // Pré-popular togare_tpu_classe com 1 row antiga.
        $this->pdo->exec("
            INSERT INTO togare_tpu_classe (codigo, nome, pai_codigo, glossario, ativo, last_synced_at, created_at, updated_at)
            VALUES (999, 'Antiga', NULL, NULL, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-01-01 00:00:00')
        ");

        // Adapter falha em classes mas funciona em assuntos/movimentos.
        $adapter = new TpuSourceAdapterStub(
            classes: [],
            assuntos: $this->mkRows(2, 'assunto-'),
            movimentos: $this->mkRows(1, 'mov-'),
            failClasses: true,
        );

        $svc = new TpuSyncService($this->em, $adapter, $this->redis);
        $result = $svc->syncAll();

        self::assertContains('classes', $result['failures']);
        self::assertSame(2, $result['assuntos']);
        self::assertSame(1, $result['movimentos']);

        // Tabela classe NÃO foi truncada — row antiga preservada.
        self::assertSame('1', (string) $this->pdo->query('SELECT COUNT(*) FROM togare_tpu_classe')->fetchColumn());
        self::assertSame('Antiga', $this->pdo->query("SELECT nome FROM togare_tpu_classe WHERE codigo=999")->fetchColumn());

        $events = array_column(TogareLogger::getRecorded(), 'event');
        self::assertContains('tpu.sync.failed', $events);
    }

    public function testInvalidateCachePatternUsaScanNuncaKeys(): void
    {
        $this->predisStub->_seed('togare:tpu:classe:1', '{}');
        $this->predisStub->_seed('togare:tpu:classe:2', '{}');
        $this->predisStub->_seed('togare:tpu:assunto:1', '{}');

        $svc = new TpuSyncService($this->em, new TpuSourceAdapterStub(), $this->redis);
        $deleted = $svc->invalidateCachePattern('togare:tpu:classe:*');

        self::assertSame(2, $deleted);
        self::assertContains('SCAN', $this->predisStub->callLog);
        self::assertContains('DEL', $this->predisStub->callLog);
        // Garantia explícita: KEYS NUNCA é chamado (Dev Notes §7).
        self::assertNotContains('KEYS', $this->predisStub->callLog);

        // Chave de outro prefixo permanece.
        self::assertNotNull($this->predisStub->get('togare:tpu:assunto:1'));
        self::assertNull($this->predisStub->get('togare:tpu:classe:1'));
    }

    public function testSyncCompletedLogContemCountsETempo(): void
    {
        $adapter = new TpuSourceAdapterStub(
            classes: $this->mkRows(2, 'c-'),
            assuntos: $this->mkRows(1, 'a-'),
            movimentos: $this->mkRows(3, 'm-'),
        );
        $svc = new TpuSyncService($this->em, $adapter, $this->redis);
        $svc->syncAll();

        $completed = array_filter(
            TogareLogger::getRecorded(),
            fn ($e) => $e['event'] === 'tpu.sync.completed',
        );
        self::assertCount(1, $completed);
        $entry = array_values($completed)[0];
        self::assertSame(6, $entry['context']['totalCount']);
        self::assertArrayHasKey('totalDurationMs', $entry['context']);
        self::assertSame(2, $entry['context']['classes']);
        self::assertSame(1, $entry['context']['assuntos']);
        self::assertSame(3, $entry['context']['movimentos']);
    }

    /**
     * @return list<array{codigo:int, nome:string, pai_codigo:?int, glossario:?string, ativo:bool}>
     */
    private function mkRows(int $count, string $prefix): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'codigo' => 1000 + $i,
                'nome' => $prefix . $i,
                'pai_codigo' => null,
                'glossario' => null,
                'ativo' => true,
            ];
        }
        return $rows;
    }
}
