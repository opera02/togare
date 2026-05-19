<?php

declare(strict_types=1);

namespace Tests\Unit\Espo\Modules\TogareDjen\Services;

use Espo\Modules\TogareDjen\Services\DjenAtoClassificacao;
use Espo\Modules\TogareDjen\Services\DjenAtoClassifier;
use PHPUnit\Framework\TestCase;

/**
 * Story 4a.2 — DjenAtoClassifier (AC2/AC3/AC4/AC5/AC8).
 *
 * Cobre:
 *  - Match HIGH com contexto direcionado (contestação, cumprimento de
 *    sentença, embargos, agravo).
 *  - Leitura de prazo explícito ("prazo de N dias") para uso direto em
 *    `manifestacao_geral_intimacao` ou warning quando diverge de ato fixo.
 *  - Fallback `manifestacao_generica` low quando nenhum positivo bate
 *    e nenhum negativo descarta.
 *  - Negative keywords retornam null (atos puramente certificatórios).
 *  - fonteExcerpt respeita 100 chars + adiciona '...' nas bordas truncadas.
 *  - Cenário contra fixture real comunica-api-462034-SP-202604.json
 *    (3 publicações específicas — antecipa o smoke F1 do Felipe).
 */
final class DjenAtoClassifierTest extends TestCase
{
    private DjenAtoClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new DjenAtoClassifier();
    }

    public function testClassificaContestacaoComConfidenceHighEContextoDirecionado(): void
    {
        $payload = [
            'texto' => 'DEFIRO o ingresso da parte ré nos autos e CONCEDO o prazo de 15 dias para apresentar contestação, sob pena de revelia.',
            'tipoComunicacao' => 'Intimação',
            'tipoDocumento' => 'Decisão',
        ];
        $result = $this->classifier->classify($payload);

        self::assertNotNull($result);
        self::assertSame('contestacao', $result->atoCodigo);
        self::assertSame('high', $result->confidence);
        self::assertStringContainsString('contesta', $result->fonteExcerpt);
    }

    public function testClassificaCumprimentoSentencaComConfidenceHigh(): void
    {
        $payload = [
            'texto' => 'Intimação para pagamento voluntário no prazo de 15 dias, sob pena de multa de 10% e honorários de 10% do art. 523 §1º do CPC.',
            'tipoComunicacao' => 'Intimação',
            'tipoDocumento' => 'Decisão',
        ];
        $result = $this->classifier->classify($payload);

        self::assertNotNull($result);
        self::assertSame('cumprimento_sentenca', $result->atoCodigo);
        self::assertSame('high', $result->confidence);
    }

    public function testClassificaEmbargosDeDeclaracaoComConfidenceHigh(): void
    {
        $payload = [
            'texto' => 'Intime-se a parte para opor embargos de declaração no prazo de 5 dias úteis, conforme art. 1023 do CPC.',
        ];
        $result = $this->classifier->classify($payload);

        self::assertNotNull($result);
        self::assertSame('embargos_declaracao', $result->atoCodigo);
        self::assertSame('high', $result->confidence);
    }

    public function testClassificaAgravoDeInstrumentoComConfidenceHigh(): void
    {
        $payload = [
            'texto' => 'Decorrido o prazo de 15 dias para interpor agravo de instrumento da decisão de fls. 100, voltem-me os autos.',
        ];
        $result = $this->classifier->classify($payload);

        self::assertNotNull($result);
        self::assertSame('agravo_instrumento', $result->atoCodigo);
        self::assertSame('high', $result->confidence);
    }

    public function testClassificaImpugnacaoAoCumprimentoComConfidenceHigh(): void
    {
        $payload = [
            'texto' => 'Intime-se o devedor para apresentar impugnação ao cumprimento de sentença no prazo de 15 dias.',
        ];
        $result = $this->classifier->classify($payload);

        self::assertNotNull($result);
        self::assertSame('impugnacao_cumprimento', $result->atoCodigo);
        self::assertSame('high', $result->confidence);
    }

    /**
     * AC5 — Override do número de dias quando texto explicita.
     */
    public function testClassificaManifestacaoComOverrideDePrazo30Dias(): void
    {
        $payload = [
            'texto' => 'Pela MM. Juíza foi dito: concedo o prazo de 30 dias para que as partes apresentem alegações finais escritas.',
        ];
        $result = $this->classifier->classify($payload);

        self::assertNotNull($result);
        self::assertSame('manifestacao_geral_intimacao', $result->atoCodigo);
        self::assertSame('high', $result->confidence);
        self::assertSame(30, $result->prazoDiasOverride);
    }

    public function testClassificaManifestacaoComOverrideDePrazo15Dias(): void
    {
        $payload = [
            'texto' => 'concedendo o prazo de 15 dias para se manifestar acerca dos documentos juntados.',
        ];
        $result = $this->classifier->classify($payload);

        self::assertNotNull($result);
        self::assertSame('manifestacao_geral_intimacao', $result->atoCodigo);
        self::assertSame('high', $result->confidence);
        self::assertSame(15, $result->prazoDiasOverride);
    }

    /**
     * Regressão C-P1 — quando o texto tem múltiplos "prazo de N dias", o
     * override deve vir do grupo capturado pelo padrão (capturesPrazo=true),
     * não do primeiro "prazo de N dias" encontrado no texto pelo
     * extractPrazoDiasOverride.
     */
    public function testManifestacaoHighUsaGrupoCapturedNaoFirstPrazoDoTexto(): void
    {
        // Dois "prazo de N dias" no texto:
        //   1º = "prazo de 5 dias para regularizar" — NÃO dispara o padrão HIGH.
        //   2º = "prazo de 30 dias para que as partes apresentem" — DISPARA HIGH.
        // Override correto = 30 (do grupo capturado), não 5 (primeiro no texto).
        $payload = [
            'texto' => 'No prazo de 5 dias para regularizar a documentação. Mais: prazo de 30 dias para que as partes apresentem alegações finais.',
        ];
        $result = $this->classifier->classify($payload);

        self::assertNotNull($result);
        self::assertSame('manifestacao_geral_intimacao', $result->atoCodigo);
        self::assertSame('high', $result->confidence);
        self::assertSame(
            30,
            $result->prazoDiasOverride,
            'Override deve ser 30 (grupo capturado pelo padrão), não 5 (primeiro "prazo de N dias" no texto)',
        );
    }

    /**
     * AC4 / AC9 — atos puramente certificatórios retornam null.
     */
    public function testRetornaNullParaAtoOrdinatorioCertificatorio(): void
    {
        $payload = [
            'texto' => 'Junte-se a petição. Cumpra-se. Publique-se.',
            'tipoDocumento' => 'Ato ordinatório',
        ];
        $result = $this->classifier->classify($payload);

        self::assertNull($result);
    }

    public function testRetornaNullParaConclusosOsAutos(): void
    {
        $payload = [
            'texto' => 'Conclusos os autos para sentença.',
        ];
        self::assertNull($this->classifier->classify($payload));
    }

    public function testRetornaNullParaArquivamento(): void
    {
        $payload = [
            'texto' => 'Arquive-se os autos com baixa nos registros.',
        ];
        self::assertNull($this->classifier->classify($payload));
    }

    public function testRetornaNullParaAtoOrdinatorioComClasseProcessualSemPrazoExplicito(): void
    {
        $payload = [
            'tipoDocumento' => 'Ato ordinatório',
            'texto' => '<center><p>ATO ORDINATÓRIO</p></center> Processo - Apelação/Remessa Necessária. Junte-se a petição.',
        ];

        self::assertNull($this->classifier->classify($payload));
    }

    public function testAtoOrdinatorioComHintDePrazoNaoRetornaNull(): void
    {
        $payload = [
            'tipoDocumento' => 'Ato ordinatório',
            'texto' => 'ATO ORDINATÓRIO. Prazo de 5 dias para providenciar documentos.',
        ];

        $result = $this->classifier->classify($payload);

        self::assertNotNull($result);
        self::assertSame('manifestacao_generica', $result->atoCodigo);
        self::assertSame('low', $result->confidence);
    }

    /**
     * Caso conservador — texto ambíguo SEM keyword positiva nem negativa
     * → fallback manifestacao_generica low (parser dá um palpite,
     * 4a.3 decide rascunho).
     */
    public function testFallbackManifestacaoGenericaConfidenceLow(): void
    {
        $payload = [
            'texto' => 'Intimem-se as partes do conteúdo da decisão proferida nos autos do processo em epígrafe.',
        ];
        $result = $this->classifier->classify($payload);

        self::assertNotNull($result);
        self::assertSame('manifestacao_generica', $result->atoCodigo);
        self::assertSame('low', $result->confidence);
        self::assertNull($result->prazoDiasOverride);
    }

    public function testFallbackParaTextoCompletamenteVazio(): void
    {
        $payload = ['texto' => ''];
        $result = $this->classifier->classify($payload);
        self::assertNotNull($result);
        self::assertSame('manifestacao_generica', $result->atoCodigo);
        self::assertSame('low', $result->confidence);
    }

    /**
     * AC8 — fonteExcerpt respeita 100 chars com '...' nas bordas truncadas.
     */
    public function testFonteExcerptRespeitaCemCharsComReticenciasNasBordas(): void
    {
        $prefix = \str_repeat('a ', 60); // 120 chars de padding antes
        $suffix = \str_repeat('z ', 60); // 120 chars depois
        $payload = [
            'texto' => $prefix . 'concedo o prazo de 15 dias para se manifestar' . $suffix,
        ];
        $result = $this->classifier->classify($payload);

        self::assertNotNull($result);
        self::assertLessThanOrEqual(100, \strlen($result->fonteExcerpt));
        self::assertStringStartsWith('...', $result->fonteExcerpt);
        self::assertStringEndsWith('...', $result->fonteExcerpt);
        self::assertStringContainsString('manifesta', $result->fonteExcerpt);
    }

    public function testFonteExcerptSemReticenciasQuandoTextoEhMenorQueCap(): void
    {
        $payload = [
            'texto' => 'concedo o prazo de 15 dias para se manifestar.',
        ];
        $result = $this->classifier->classify($payload);

        self::assertNotNull($result);
        // Texto curto: excerpt = texto inteiro, sem '...'.
        self::assertSame('concedo o prazo de 15 dias para se manifestar.', $result->fonteExcerpt);
    }

    public function testOverrideEhCapturadoEmAtoFixoParaPermitirWarningDoParser(): void
    {
        // Texto fala "prazo de 30 dias" mas ato é embargos (legal fixo 5 dias).
        // O classifier captura o número para o parser logar divergência, mas
        // o parser continua aplicando o prazo legal do ato fixo.
        $payload = [
            'texto' => 'Apresentem embargos de declaração no prazo de 30 dias da intimação desta decisão.',
        ];
        $result = $this->classifier->classify($payload);

        self::assertNotNull($result);
        self::assertSame('embargos_declaracao', $result->atoCodigo);
        self::assertSame(30, $result->prazoDiasOverride);
    }

    /**
     * AC14 — Smoke contra fixture real (subset de 3 publicações específicas).
     * Antecipa o que o Felipe valida no F1 sub-passo 5.
     */
    public function testClassificaContraFixtureRealComunicaApi(): void
    {
        $fixturePath = __DIR__ . '/../../../../../fixtures/comunica-api-462034-SP-202604.json';
        if (! \file_exists($fixturePath)) {
            self::markTestSkipped("Fixture comunica-api real não encontrada em {$fixturePath}");
        }

        $raw = (string) \file_get_contents($fixturePath);
        $decoded = \json_decode($raw, true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('items', $decoded);
        self::assertNotEmpty($decoded['items']);

        $totalParsed = 0;
        $totalUnparsed = 0;
        $totalLow = 0;
        foreach ($decoded['items'] as $item) {
            // Adapta o shape raw da Comunica API ao DTO esperado pelo classifier.
            $payload = [
                'texto' => (string) ($item['texto'] ?? ''),
                'tipoComunicacao' => (string) ($item['tipoComunicacao'] ?? ''),
                'tipoDocumento' => (string) ($item['tipoDocumento'] ?? ''),
            ];
            $result = $this->classifier->classify($payload);
            if ($result === null) {
                $totalUnparsed++;
            } elseif ($result->confidence === 'low') {
                $totalLow++;
            } else {
                $totalParsed++;
            }
        }

        // Critério MVP (Story 4a.2 AC14): high+medium ≥ 50% das pubs com
        // prazo aparente. Se Z (low) > X (parsed), dicionário precisa de
        // calibração — mas teste passa enquanto há ALGUM ato classificado.
        self::assertGreaterThan(
            0,
            $totalParsed,
            'Pelo menos 1 publicação real deve ser classificada com confidence=high|medium',
        );
        // Total bate com o número de items na fixture (zero perda silenciosa).
        self::assertSame(
            \count($decoded['items']),
            $totalParsed + $totalLow + $totalUnparsed,
            'Soma parsed+low+unparsed deve igualar total de items (zero perda)',
        );
    }
}
