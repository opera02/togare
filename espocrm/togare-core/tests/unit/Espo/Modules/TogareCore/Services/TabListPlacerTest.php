<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Services;

use Espo\Modules\TogareCore\Services\TabListPlacer;
use PHPUnit\Framework\TestCase;

/**
 * Cobre os 3 níveis de fallback da lógica de inserção das tabs Togare no
 * `tabList` do EspoCRM (Bug fix 0.39.2 — smoke browser fresh-install do
 * Felipe 2026-05-20 achou as 9 tabs caindo depois do `_delimiter_`).
 */
final class TabListPlacerTest extends TestCase
{
    private const TOGARE_KNOWN = [
        'Cliente', 'ParteContraria', 'Processo', 'Audiencia', 'Prazo',
        'PublicacaoAmbigua', 'Fatura', 'LancamentoFinanceiro', 'Funcionario',
    ];

    public function testMissingVazioRetornaTabListOriginal(): void
    {
        $original = ['Account', 'Contact', '_delimiter_', 'User'];
        $result = TabListPlacer::place($original, [], self::TOGARE_KNOWN);

        self::assertSame($original, $result);
    }

    public function testNivel1InsereAposUltimaTabTogareExistente(): void
    {
        // tabList já tem Cliente e Processo; missing = Prazo, Fatura.
        // Esperado: Prazo + Fatura inseridos APÓS Processo, preservando ordem.
        $tabList = ['Account', 'Cliente', 'Contact', 'Processo', '_delimiter_', 'User'];
        $missing = ['Prazo', 'Fatura'];

        $result = TabListPlacer::place($tabList, $missing, self::TOGARE_KNOWN);

        self::assertSame(
            ['Account', 'Cliente', 'Contact', 'Processo', 'Prazo', 'Fatura', '_delimiter_', 'User'],
            $result,
        );
    }

    public function testNivel2InsereAntesDoDelimiterQuandoNaoHaTogarePrevia(): void
    {
        // FIX 0.39.2 — fresh install: nenhuma tab Togare existe ainda;
        // mas há `_delimiter_`. As 9 tabs DEVEM ir ANTES do delimiter
        // (caso contrário caem no dropdown "More" e ficam invisíveis).
        $tabList = ['Account', 'Contact', 'Lead', '_delimiter_', 'User', 'Team'];
        $missing = ['Cliente', 'Processo', 'Prazo'];

        $result = TabListPlacer::place($tabList, $missing, self::TOGARE_KNOWN);

        self::assertSame(
            ['Account', 'Contact', 'Lead', 'Cliente', 'Processo', 'Prazo', '_delimiter_', 'User', 'Team'],
            $result,
        );
    }

    public function testNivel3FallbackAppendQuandoNaoHaDelimiterNemTogare(): void
    {
        $tabList = ['Account', 'Contact', 'User'];
        $missing = ['Cliente', 'Processo'];

        $result = TabListPlacer::place($tabList, $missing, self::TOGARE_KNOWN);

        self::assertSame(
            ['Account', 'Contact', 'User', 'Cliente', 'Processo'],
            $result,
        );
    }

    public function testNivel1TemPrioridadeSobreNivel2(): void
    {
        // Mesmo com `_delimiter_` presente, se há Togare prévia, usa nível 1.
        $tabList = ['Cliente', '_delimiter_', 'User'];
        $missing = ['Processo'];

        $result = TabListPlacer::place($tabList, $missing, self::TOGARE_KNOWN);

        // Processo inserido APÓS Cliente, não antes do delimiter.
        self::assertSame(
            ['Cliente', 'Processo', '_delimiter_', 'User'],
            $result,
        );
    }

    public function testIgnoraEntradasNaoString(): void
    {
        // Dividers no EspoCRM são arrays/stdClass. Devem ser ignorados na
        // detecção de Togare/delimiter (mas preservados no resultado).
        $divider = ['type' => 'divider', 'text' => '$CRM', 'id' => '123'];
        $tabList = [$divider, 'Account', '_delimiter_', 'User'];
        $missing = ['Cliente'];

        $result = TabListPlacer::place($tabList, $missing, self::TOGARE_KNOWN);

        self::assertSame(
            [$divider, 'Account', 'Cliente', '_delimiter_', 'User'],
            $result,
        );
    }

    public function testPreservaOrdemDasMissingNoNivel2(): void
    {
        // Garantia: array_splice preserva a ordem do array $missing.
        $tabList = ['Account', '_delimiter_', 'User'];
        $missing = ['Funcionario', 'Cliente', 'Processo']; // ordem propositalmente "errada"

        $result = TabListPlacer::place($tabList, $missing, self::TOGARE_KNOWN);

        self::assertSame(
            ['Account', 'Funcionario', 'Cliente', 'Processo', '_delimiter_', 'User'],
            $result,
        );
    }

    public function testDelimiterAposTogareUsaNivel1NaoNivel2(): void
    {
        // Edge case real: scenario onde Cliente existe ANTES do delimiter
        // (nível 1) e usuario quer adicionar Processo. Tem que ir após
        // Cliente, NÃO antes do delimiter (que ficaria no meio).
        $tabList = ['Account', 'Cliente', '_delimiter_', 'User'];
        $missing = ['Processo'];

        $result = TabListPlacer::place($tabList, $missing, self::TOGARE_KNOWN);

        self::assertSame(
            ['Account', 'Cliente', 'Processo', '_delimiter_', 'User'],
            $result,
        );
    }
}
