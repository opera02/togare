<?php

declare(strict_types=1);

namespace Tests\Unit\Espo\Modules\TogareDjen\Services;

use Espo\Modules\TogareDjen\Services\DjenPrazoRules;
use PHPUnit\Framework\TestCase;

/**
 * Story 4a.2 — DjenPrazoRules dicionário estático CPC (Decisão #3).
 */
final class DjenPrazoRulesTest extends TestCase
{
    private DjenPrazoRules $rules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rules = new DjenPrazoRules();
    }

    public function testLookupRetornaContestacao15UteisCPC335(): void
    {
        $rule = $this->rules->lookup('contestacao');
        self::assertNotNull($rule);
        self::assertSame('contestacao', $rule->atoCodigo);
        self::assertSame(15, $rule->dias);
        self::assertSame('uteis', $rule->contagem);
        self::assertSame('CPC art. 335', $rule->referenciaLegal);
    }

    public function testLookupRetornaCumprimentoSentenca15Corridos(): void
    {
        $rule = $this->rules->lookup('cumprimento_sentenca');
        self::assertNotNull($rule);
        self::assertSame(15, $rule->dias);
        self::assertSame('corridos', $rule->contagem);
        self::assertSame('CPC art. 523', $rule->referenciaLegal);
    }

    public function testLookupRetornaEmbargos5UteisCPC1023(): void
    {
        $rule = $this->rules->lookup('embargos_declaracao');
        self::assertNotNull($rule);
        self::assertSame(5, $rule->dias);
        self::assertSame('uteis', $rule->contagem);
    }

    public function testLookupRetornaNullParaAtoInexistente(): void
    {
        self::assertNull($this->rules->lookup('inexistente_xyz'));
    }

    public function testLookupOrFallbackRetornaManifestacaoGenerica15UteisCPC218(): void
    {
        $rule = $this->rules->lookupOrFallback('inexistente_xyz');
        self::assertSame('manifestacao_generica', $rule->atoCodigo);
        self::assertSame(15, $rule->dias);
        self::assertSame('uteis', $rule->contagem);
        self::assertSame('CPC art. 218', $rule->referenciaLegal);
    }

    public function testRegraVersaoEhSemverInicial1ZeroZero(): void
    {
        self::assertSame('1.0.0', DjenPrazoRules::REGRA_VERSAO);
    }

    public function testListAtoCodigosContemOs11AtosDoDicionario(): void
    {
        $atos = $this->rules->listAtoCodigos();
        $esperados = [
            'contestacao', 'recurso_apelacao', 'embargos_declaracao',
            'agravo_instrumento', 'agravo_interno', 'cumprimento_sentenca',
            'impugnacao_cumprimento', 'replica', 'quesitos_pericia',
            'manifestacao_geral_intimacao', 'manifestacao_generica',
        ];
        foreach ($esperados as $ato) {
            self::assertContains($ato, $atos, "Dicionário deve conter '{$ato}'");
        }
        self::assertCount(11, $atos, 'Dicionário deve ter 11 atos no MVP');
    }
}
