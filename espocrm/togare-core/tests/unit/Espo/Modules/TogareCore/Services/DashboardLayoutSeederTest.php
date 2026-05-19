<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Services;

use Espo\Modules\TogareCore\Services\DashboardLayoutSeeder;
use PHPUnit\Framework\TestCase;

/**
 * Cobre lógica pura de mutação do `Settings.dashboardLayout` (Story 4a.5,
 * AC8 — afterInstall idempotente).
 *
 * Testa os 4 cenários do AC8:
 *  1. Install limpo (layout vazio) → cria 1 tab "Briefing" com 1 item dashlet.
 *  2. Re-install (layout já contém o dashlet) → no-op, hasDashlet=true.
 *  3. Layout custom prévio sem o dashlet → anexa nova tab Briefing
 *     (não destrói tabs existentes).
 *  4. Layout custom contendo o dashlet noutro tab → no-op.
 *
 * + Defensivos: input não-array, tab malformada, item malformado.
 */
final class DashboardLayoutSeederTest extends TestCase
{
    // ============================================================
    // hasDashlet() — detecção idempotente
    // ============================================================

    public function testHasDashletRetornaFalseEmLayoutVazio(): void
    {
        self::assertFalse(DashboardLayoutSeeder::hasDashlet([]));
    }

    public function testHasDashletRetornaFalseEmInputNull(): void
    {
        self::assertFalse(DashboardLayoutSeeder::hasDashlet(null));
    }

    public function testHasDashletRetornaFalseEmInputNaoArray(): void
    {
        self::assertFalse(DashboardLayoutSeeder::hasDashlet('string-invalido'));
        self::assertFalse(DashboardLayoutSeeder::hasDashlet(42));
    }

    public function testHasDashletRetornaTrueQuandoDashletEstaPresente(): void
    {
        $layout = [
            [
                'id' => 'tab-1',
                'name' => 'Briefing',
                'layout' => [
                    ['id' => 'item-1', 'name' => 'togare-prazos-do-dia', 'x' => 0, 'y' => 0, 'width' => 4, 'height' => 4],
                ],
            ],
        ];
        self::assertTrue(DashboardLayoutSeeder::hasDashlet($layout));
    }

    public function testHasDashletRetornaTrueQuandoDashletEstaNoSegundoTab(): void
    {
        $layout = [
            [
                'id' => 'tab-1',
                'name' => 'Outra',
                'layout' => [
                    ['id' => 'item-stream', 'name' => 'Stream'],
                ],
            ],
            [
                'id' => 'tab-2',
                'name' => 'Briefing',
                'layout' => [
                    ['id' => 'item-x', 'name' => 'togare-prazos-do-dia'],
                ],
            ],
        ];
        self::assertTrue(DashboardLayoutSeeder::hasDashlet($layout));
    }

    public function testHasDashletRetornaFalseQuandoDashletDiferenteEstaPresente(): void
    {
        $layout = [
            [
                'id' => 'tab-1',
                'name' => 'Default',
                'layout' => [
                    ['id' => 'item-tasks', 'name' => 'Tasks'],
                    ['id' => 'item-stream', 'name' => 'Stream'],
                ],
            ],
        ];
        self::assertFalse(DashboardLayoutSeeder::hasDashlet($layout));
    }

    public function testHasDashletToleranteATabMalformada(): void
    {
        // Mistura de tabs válidas e malformadas — não deve lançar.
        $layout = [
            'string-no-meio',
            null,
            42,
            [
                'id' => 'tab-1',
                'name' => 'OK',
                'layout' => [
                    ['id' => 'item-1', 'name' => 'togare-prazos-do-dia'],
                ],
            ],
        ];
        self::assertTrue(DashboardLayoutSeeder::hasDashlet($layout));
    }

    public function testHasDashletToleranteAItemMalformado(): void
    {
        $layout = [
            [
                'id' => 'tab-1',
                'name' => 'Misto',
                'layout' => [
                    'item-string',
                    null,
                    ['name' => 'togare-prazos-do-dia'],
                ],
            ],
        ];
        self::assertTrue(DashboardLayoutSeeder::hasDashlet($layout));
    }

    public function testHasDashletToleranteATabSemLayoutKey(): void
    {
        $layout = [
            ['id' => 'tab-1', 'name' => 'Sem layout'],
        ];
        self::assertFalse(DashboardLayoutSeeder::hasDashlet($layout));
    }

    public function testHasDashletToleranteALayoutKeyNaoArray(): void
    {
        $layout = [
            ['id' => 'tab-1', 'name' => 'X', 'layout' => 'string-bizarra'],
        ];
        self::assertFalse(DashboardLayoutSeeder::hasDashlet($layout));
    }

    // ============================================================
    // appendBriefingTab() — mutação idempotente do array
    // ============================================================

    public function testAppendBriefingTabEmLayoutVazioCriaTabUnico(): void
    {
        $result = DashboardLayoutSeeder::appendBriefingTab([], 'tab-fixed', 'item-fixed');

        self::assertCount(1, $result);
        $tab = $result[0];

        self::assertSame('tab-fixed', $tab['id']);
        self::assertSame('Briefing', $tab['name']);

        self::assertCount(1, $tab['layout']);
        $item = $tab['layout'][0];

        self::assertSame('item-fixed', $item['id']);
        self::assertSame('togare-prazos-do-dia', $item['name']);
        self::assertSame(0, $item['x']);
        self::assertSame(0, $item['y']);
        self::assertSame(4, $item['width']);
        self::assertSame(4, $item['height']);
    }

    public function testAppendBriefingTabEmInputNullCriaArrayBase(): void
    {
        $result = DashboardLayoutSeeder::appendBriefingTab(null, 't', 'i');

        self::assertIsArray($result);
        self::assertCount(1, $result);
        self::assertSame('Briefing', $result[0]['name']);
    }

    public function testAppendBriefingTabPreservaTabsExistentes(): void
    {
        $existing = [
            [
                'id' => 'old-1',
                'name' => 'Default',
                'layout' => [
                    ['id' => 'item-a', 'name' => 'Tasks'],
                ],
            ],
            [
                'id' => 'old-2',
                'name' => 'Outra',
                'layout' => [
                    ['id' => 'item-b', 'name' => 'Stream'],
                ],
            ],
        ];

        $result = DashboardLayoutSeeder::appendBriefingTab($existing, 'new-tab', 'new-item');

        self::assertCount(3, $result, 'Deve preservar os 2 tabs existentes + adicionar 1 novo');
        self::assertSame('old-1', $result[0]['id']);
        self::assertSame('old-2', $result[1]['id']);
        self::assertSame('new-tab', $result[2]['id']);
        self::assertSame('Briefing', $result[2]['name']);
    }

    public function testAppendBriefingTabNaoMutaInputOriginal(): void
    {
        $original = [
            ['id' => 'old', 'name' => 'X', 'layout' => []],
        ];
        $copy = $original;

        DashboardLayoutSeeder::appendBriefingTab($original, 'new', 'new-i');

        self::assertSame($copy, $original, 'Input original NÃO deve ser mutado (PHP arrays passam por valor)');
    }

    // ============================================================
    // Constantes públicas (interface estável para AfterInstall.php)
    // ============================================================

    public function testConstantesPublicasSaoEstaveis(): void
    {
        self::assertSame('togare-prazos-do-dia', DashboardLayoutSeeder::DASHLET_NAME);
        self::assertSame('briefing-do-dia', DashboardLayoutSeeder::DASHLET_NAME_BRIEFING);
        self::assertSame('Briefing', DashboardLayoutSeeder::TAB_NAME);
        self::assertSame(4, DashboardLayoutSeeder::DEFAULT_WIDTH);
        self::assertSame(4, DashboardLayoutSeeder::DEFAULT_HEIGHT);
    }

    // ============================================================
    // hasBriefingDoDia() — Story 10.1 AC5
    // ============================================================

    public function testHasBriefingDoDiaRetornaFalseEmLayoutVazio(): void
    {
        self::assertFalse(DashboardLayoutSeeder::hasBriefingDoDia([]));
    }

    public function testHasBriefingDoDiaRetornaFalseEmInputNull(): void
    {
        self::assertFalse(DashboardLayoutSeeder::hasBriefingDoDia(null));
    }

    public function testHasBriefingDoDiaRetornaTrueQuandoPresente(): void
    {
        $layout = [[
            'id' => 'tab-1',
            'name' => 'Briefing',
            'layout' => [
                ['id' => 'i1', 'name' => 'togare-prazos-do-dia'],
                ['id' => 'i2', 'name' => 'briefing-do-dia'],
            ],
        ]];
        self::assertTrue(DashboardLayoutSeeder::hasBriefingDoDia($layout));
    }

    public function testHasBriefingDoDiaRetornaFalseQuandoSoPrazosPresentente(): void
    {
        $layout = [[
            'id' => 'tab-1',
            'name' => 'Briefing',
            'layout' => [['id' => 'i1', 'name' => 'togare-prazos-do-dia']],
        ]];
        self::assertFalse(DashboardLayoutSeeder::hasBriefingDoDia($layout));
    }

    // ============================================================
    // appendBriefingTabWithBoth() — install limpo com ambos (AC5)
    // ============================================================

    public function testAppendBriefingTabWithBothCriaTabComDoisItens(): void
    {
        $result = DashboardLayoutSeeder::appendBriefingTabWithBoth([], 'tid', 'pid', 'bid');

        self::assertCount(1, $result);
        $tab = $result[0];
        self::assertSame('tid', $tab['id']);
        self::assertSame('Briefing', $tab['name']);
        self::assertCount(2, $tab['layout']);

        $prazos   = $tab['layout'][0];
        $briefing = $tab['layout'][1];

        self::assertSame('pid', $prazos['id']);
        self::assertSame('togare-prazos-do-dia', $prazos['name']);
        self::assertSame(0, $prazos['x']);

        self::assertSame('bid', $briefing['id']);
        self::assertSame('briefing-do-dia', $briefing['name']);
        self::assertSame(4, $briefing['x']); // lado a lado
    }

    public function testAppendBriefingTabWithBothPreservaTabsExistentes(): void
    {
        $existing = [
            ['id' => 'old', 'name' => 'Default', 'layout' => [['id' => 'x', 'name' => 'Tasks']]],
        ];
        $result = DashboardLayoutSeeder::appendBriefingTabWithBoth($existing, 't', 'p', 'b');
        self::assertCount(2, $result);
        self::assertSame('old', $result[0]['id']);
        self::assertSame('Briefing', $result[1]['name']);
    }

    // ============================================================
    // appendBriefingDoDiaToExistingTab() — upgrade (AC5)
    // ============================================================

    public function testAppendBriefingDoDiaToExistingTabEncontraPorDashletPrazos(): void
    {
        $layout = [[
            'id' => 'tab-b',
            'name' => 'Briefing',
            'layout' => [['id' => 'p1', 'name' => 'togare-prazos-do-dia', 'x' => 0, 'y' => 0, 'width' => 4, 'height' => 4]],
        ]];

        $result = DashboardLayoutSeeder::appendBriefingDoDiaToExistingTab($layout, 'new-id');

        self::assertCount(1, $result); // mesmo tab
        $tab = $result[0];
        self::assertCount(2, $tab['layout']); // prazos + briefing-do-dia

        $added = $tab['layout'][1];
        self::assertSame('new-id', $added['id']);
        self::assertSame('briefing-do-dia', $added['name']);
        self::assertSame(4, $added['x']);
    }

    public function testAppendBriefingDoDiaToExistingTabFallbackPorNomeBriefing(): void
    {
        // Tab sem togare-prazos-do-dia mas com nome "Briefing".
        $layout = [[
            'id' => 'tab-b',
            'name' => 'Briefing',
            'layout' => [['id' => 'x', 'name' => 'OutroDashlet']],
        ]];

        $result = DashboardLayoutSeeder::appendBriefingDoDiaToExistingTab($layout, 'bid');

        $tab = $result[0];
        self::assertCount(2, $tab['layout']);
        self::assertSame('briefing-do-dia', $tab['layout'][1]['name']);
    }

    public function testAppendBriefingDoDiaToExistingTabSemTabBriefingCriaNovoTab(): void
    {
        // Sem tab "Briefing" nem togare-prazos-do-dia → cria novo tab.
        $layout = [
            ['id' => 'default', 'name' => 'Default', 'layout' => [['id' => 'd', 'name' => 'Tasks']]],
        ];

        $result = DashboardLayoutSeeder::appendBriefingDoDiaToExistingTab($layout, 'bid');

        self::assertCount(2, $result);
        $newTab = $result[1];
        self::assertSame('Briefing', $newTab['name']);
        self::assertCount(1, $newTab['layout']);
        self::assertSame('briefing-do-dia', $newTab['layout'][0]['name']);
    }

    public function testAppendBriefingDoDiaToExistingTabNaoMutaInput(): void
    {
        $layout = [[
            'id' => 'tab-b',
            'name' => 'Briefing',
            'layout' => [['id' => 'p1', 'name' => 'togare-prazos-do-dia']],
        ]];
        $copy = $layout;

        DashboardLayoutSeeder::appendBriefingDoDiaToExistingTab($layout, 'x');

        self::assertSame($copy, $layout);
    }
}
