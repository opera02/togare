<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Bool\PublicacaoAmbigua;

use Espo\Modules\TogareCore\Entities\PublicacaoAmbigua;
use PHPUnit\Framework\TestCase;

/**
 * Cobre PrecisaSuaLeitura boolFilter (Story 4b.1a, AC4) — cobertura via
 * file existence + constant binding.
 *
 * **Por que não testar Filter::apply() diretamente:** `Espo\Core\Select\Bool\Filter`
 * + `SelectBuilder` + `OrGroupBuilder` exigem boot do EspoCRM ORM completo,
 * que é custoso em unit. Testes anteriores (MeusPendentes/PendentesParaHoje
 * de Prazo) não cobrem o método apply() em unit — convenção do projeto é
 * cobrir via Metadata (selectDefs + class existence) + smoke real F1.
 *
 * Pattern documentado em deferred-work: criar `SelectBuilderStub` reusável
 * pra todos os 8 boolFilters Prazo + 1 PublicacaoAmbigua quando volume de
 * boolFilters compensar o custo de manutenção do stub.
 */
final class PrecisaSuaLeituraTest extends TestCase
{
    public function testClassFileExisteNoPathDeclaradoEmSelectDefs(): void
    {
        $path = __DIR__ . '/../../../../../../../src/files/custom/Espo/Modules/TogareCore/Classes/Select/Bool/PublicacaoAmbigua/PrecisaSuaLeitura.php';
        self::assertFileExists($path, 'PrecisaSuaLeitura.php deve existir no path declarado em selectDefs.boolFilterClassNameMap');
    }

    public function testStatusConstantUsadaNoFilterEhCanonical(): void
    {
        // Defesa contra renomear/dropar PublicacaoAmbigua::STATUS_PENDENTE_REVISAO
        // sem atualizar PrecisaSuaLeitura — verifica que constante existe e é o
        // valor canônico esperado pelo filter.
        self::assertSame(
            'pendente_revisao',
            PublicacaoAmbigua::STATUS_PENDENTE_REVISAO,
            'STATUS_PENDENTE_REVISAO não pode mudar — boolFilter PrecisaSuaLeitura depende desse valor literal',
        );
    }

    public function testFilterClassFileTemImportsCorretos(): void
    {
        // Smoke estático: verifica que o file aponta para o User correto e
        // PublicacaoAmbigua correto (sem rodar autoloader full do EspoCRM ORM).
        $path = __DIR__ . '/../../../../../../../src/files/custom/Espo/Modules/TogareCore/Classes/Select/Bool/PublicacaoAmbigua/PrecisaSuaLeitura.php';
        $code = (string) \file_get_contents($path);

        self::assertStringContainsString('use Espo\\Core\\Select\\Bool\\Filter;', $code);
        self::assertStringContainsString('use Espo\\Entities\\User;', $code);
        self::assertStringContainsString('use Espo\\Modules\\TogareCore\\Entities\\PublicacaoAmbigua;', $code);
        self::assertStringContainsString('PublicacaoAmbigua::STATUS_PENDENTE_REVISAO', $code);
        self::assertStringContainsString('assignedUserId', $code);
        self::assertStringContainsString('implements Filter', $code);
    }
}
