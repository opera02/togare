<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Services;

use Espo\Modules\TogareCore\Services\Health\HealthPanelComposer;
use Espo\Modules\TogareCore\Services\HealthCheckService;
use Espo\ORM\EntityManager;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Story 10.2 / FR41 — orquestrador.
 *
 * Em ambiente de teste os módulos premium (togare-djen/tpu/nextcloud-bridge/
 * licensing) NÃO estão no autoload → exercita exatamente a regra AC1
 * ("tolera módulos ausentes") e AC5 ("nunca lança / nunca trava o painel").
 *
 * MariaDB usa SQLite in-memory (SELECT 1 funciona). Redis aponta para uma
 * porta que recusa conexão na hora (fail-safe rápido, sem timeout longo).
 */
final class HealthCheckServiceTest extends TestCase
{
    private HealthCheckService $service;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Redis: 127.0.0.1:1 recusa na hora (sem timeout de 2s).
        \putenv('TOGARE_REDIS_HOST=127.0.0.1');
        \putenv('TOGARE_REDIS_PORT=1');
        \putenv('TOGARE_REDIS_PASSWORD=');
        // Backup: caminho inexistente → tile amarelo "ainda não rodou".
        \putenv('TOGARE_BACKUP_SENTINEL_PATH=/caminho/inexistente/last-success.json');

        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willReturn($pdo);

        $this->service = new HealthCheckService($em);
    }

    protected function tearDown(): void
    {
        \putenv('TOGARE_REDIS_HOST');
        \putenv('TOGARE_REDIS_PORT');
        \putenv('TOGARE_REDIS_PASSWORD');
        \putenv('TOGARE_BACKUP_SENTINEL_PATH');
    }

    public function testGetPanelNuncaLancaERetornaEstrutura(): void
    {
        $panel = $this->service->getPanel();

        self::assertArrayHasKey('tiles', $panel);
        self::assertArrayHasKey('licenca', $panel);
        self::assertArrayHasKey('historico', $panel);
        self::assertArrayHasKey('historicLink', $panel);
        self::assertArrayHasKey('generatedAt', $panel);
        self::assertIsArray($panel['tiles']);
        self::assertCount(6, $panel['tiles']);
        self::assertSame('#Admin/TogareAuditLog', $panel['historicLink']);
    }

    public function testTilesNaOrdemCanonicaDoGrid(): void
    {
        $keys = \array_map(
            static fn ($t) => $t['key'],
            $this->service->getPanel()['tiles'],
        );

        self::assertSame(
            ['djen', 'tpu', 'mariadb', 'nextcloud', 'redis', 'backup'],
            $keys,
        );
    }

    public function testModulosPremiumAusentesViramCinzaNaoErro(): void
    {
        $byKey = [];
        foreach ($this->service->getPanel()['tiles'] as $t) {
            $byKey[$t['key']] = $t;
        }

        foreach (['djen', 'tpu', 'nextcloud'] as $k) {
            self::assertSame(
                HealthPanelComposer::STATE_NAO_INSTALADO,
                $byKey[$k]['state'],
                "Tile {$k} deveria ser cinza 'não instalado' (módulo ausente no teste)",
            );
            self::assertNotSame(
                HealthPanelComposer::STATE_OFFLINE,
                $byKey[$k]['state'],
                "AC1: módulo ausente NUNCA pode ser vermelho",
            );
        }
    }

    public function testMariadbInfraSempreProbadoEFicaOk(): void
    {
        $byKey = [];
        foreach ($this->service->getPanel()['tiles'] as $t) {
            $byKey[$t['key']] = $t;
        }

        self::assertSame(HealthPanelComposer::STATE_OK, $byKey['mariadb']['state']);
        self::assertSame('backup', $byKey['backup']['key']);
        // Backup sem sentinela = amarelo (lento), nunca cinza nem vazio.
        self::assertSame(HealthPanelComposer::STATE_LENTO, $byKey['backup']['state']);
    }

    public function testLicencaNulaSemModuloLicensingEHistoricoVazioSemTabela(): void
    {
        $panel = $this->service->getPanel();

        self::assertNull($panel['licenca']);
        self::assertSame([], $panel['historico']);
    }
}
