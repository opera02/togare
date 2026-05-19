<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Services\Health;

use Espo\Modules\TogareCore\Contracts\ValueObject\HealthCheckResult;
use Espo\Modules\TogareCore\Services\Health\HealthPanelComposer;
use PHPUnit\Framework\TestCase;

/**
 * Story 10.2 / FR41 — lógica pura de composição do painel.
 *
 * Foco crítico: o "problema do 4º estado" (AC1) — módulo ausente NUNCA pode
 * virar vermelho; é `nao_instalado` decidido sem `HealthCheckResult`.
 */
final class HealthPanelComposerTest extends TestCase
{
    public function testMapeiaOs3StatusDoContrato(): void
    {
        self::assertSame(
            HealthPanelComposer::STATE_OK,
            HealthPanelComposer::mapStatusToState(HealthCheckResult::STATUS_HEALTHY),
        );
        self::assertSame(
            HealthPanelComposer::STATE_LENTO,
            HealthPanelComposer::mapStatusToState(HealthCheckResult::STATUS_DEGRADED),
        );
        self::assertSame(
            HealthPanelComposer::STATE_OFFLINE,
            HealthPanelComposer::mapStatusToState(HealthCheckResult::STATUS_UNHEALTHY),
        );
    }

    public function testStatusDesconhecidoNuncaPintaVerde(): void
    {
        self::assertSame(
            HealthPanelComposer::STATE_OFFLINE,
            HealthPanelComposer::mapStatusToState('lixo'),
        );
    }

    public function testTileForAbsentModuleEhCinzaNaoErro(): void
    {
        $tile = HealthPanelComposer::tileForAbsentModule('djen');

        self::assertSame('djen', $tile['key']);
        self::assertSame('DJEN', $tile['label']);
        self::assertSame(HealthPanelComposer::STATE_NAO_INSTALADO, $tile['state']);
        self::assertSame('Não instalado', $tile['message']);
        self::assertNull($tile['detailLink']);
        // Regra AC1: ausente NUNCA é offline/vermelho.
        self::assertNotSame(HealthPanelComposer::STATE_OFFLINE, $tile['state']);
    }

    public function testTileFromResultMapeiaEPreservaMensagemEDetailLink(): void
    {
        $r = new HealthCheckResult(HealthCheckResult::STATUS_DEGRADED, '900ms — resposta lenta');
        $tile = HealthPanelComposer::tileFromResult('redis', $r, '#Admin/jobs');

        self::assertSame('redis', $tile['key']);
        self::assertSame('Redis', $tile['label']);
        self::assertSame(HealthPanelComposer::STATE_LENTO, $tile['state']);
        self::assertSame('900ms — resposta lenta', $tile['message']);
        self::assertSame('#Admin/jobs', $tile['detailLink']);
    }

    public function testOrderTilesSegueGridCanonico(): void
    {
        $shuffled = [
            HealthPanelComposer::tileForAbsentModule('backup'),
            HealthPanelComposer::tileForAbsentModule('mariadb'),
            HealthPanelComposer::tileForAbsentModule('djen'),
            HealthPanelComposer::tileForAbsentModule('redis'),
            HealthPanelComposer::tileForAbsentModule('nextcloud'),
            HealthPanelComposer::tileForAbsentModule('tpu'),
        ];

        $ordered = HealthPanelComposer::orderTiles($shuffled);
        $keys = \array_map(static fn ($t) => $t['key'], $ordered);

        self::assertSame(
            ['djen', 'tpu', 'mariadb', 'nextcloud', 'redis', 'backup'],
            $keys,
        );
    }

    public function testOrderTilesPreservaTileForaDaOrdemNoFim(): void
    {
        $tiles = [
            HealthPanelComposer::tileForAbsentModule('redis'),
            ['key' => 'extra', 'label' => 'X', 'state' => 'ok', 'message' => '', 'detailLink' => null],
            HealthPanelComposer::tileForAbsentModule('djen'),
        ];

        $ordered = HealthPanelComposer::orderTiles($tiles);
        $keys = \array_map(static fn ($t) => $t['key'], $ordered);

        self::assertSame('djen', $keys[0]);
        self::assertSame('redis', $keys[1]);
        self::assertSame('extra', $keys[2]);
    }
}
