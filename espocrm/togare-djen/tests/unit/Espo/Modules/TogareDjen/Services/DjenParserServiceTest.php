<?php

declare(strict_types=1);

namespace Tests\Unit\Espo\Modules\TogareDjen\Services;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Modules\TogareCore\Services\Calendar\BrazilianBusinessCalendar;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareDjen\Services\DjenAtoClassifier;
use Espo\Modules\TogareDjen\Services\DjenParserService;
use Espo\Modules\TogareDjen\Services\DjenPrazoRules;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Story 4a.2 — DjenParserService (AC6/AC7/AC7.1/AC9).
 *
 * Testes unit clássicos com dependências reais (calendar/classifier/rules
 * são puros e baratos — não justificam mock).
 *
 * Cobre:
 *  - AC6 LITERAL: sex 15/05/2026 + 15 úteis (contestação) = seg 08/06/2026
 *    (regra inclusiva confirmada por Felipe 2026-05-03 — disponibilização
 *    não conta, dia 1 da contagem é o D+1 útil forense, INCLUSIVE; Corpus
 *    Christi 04/06 pulado dentro da contagem; ver Open Question §8 #1).
 *  - AC7 LITERAL: cumprimento de sentença com 15 corridos = seg 01/06/2026
 *    (1º dia em 18/05 + 14 corridos = 15 dias inclusive).
 *  - AC7.1: addCalendarDays não pula nada.
 *  - AC9: ato certificatório → null.
 *  - AC4 fallback manifestacao_generica low.
 *  - AC5 override de prazo do texto (30 dias).
 *  - Override IGNORADO em ato legalmente fixo (contestação 30 → aplica 15).
 *  - dataDisponibilizacao ausente → InvalidArgumentException.
 *  - regraVersao sempre 1.0.0.
 *  - dataInicioPrazo pula feriado (Tiradentes).
 */
final class DjenParserServiceTest extends TestCase
{
    private DjenParserService $parser;

    protected function setUp(): void
    {
        parent::setUp();
        TogareLogger::reset();
        $calendar = new BrazilianBusinessCalendar();
        $classifier = new DjenAtoClassifier();
        $rules = new DjenPrazoRules();
        $this->parser = new DjenParserService($calendar, $classifier, $rules);
    }

    /**
     * AC6 LITERAL — sexta 15/05/2026 + 15 úteis (contestação) =
     * **segunda 08/06/2026** (regra correta confirmada por Felipe 2026-05-03).
     *
     * Regra do art. 5º Res. CNJ 455 + CPC art. 219:
     *  - Disponibilização sex 15/05 não conta.
     *  - 1º dia da contagem = seg 18/05 (D+1 útil forense, INCLUSIVE).
     *  - Contagem 15 úteis (1=18/05, ..., 13=03/06, [04/06 Corpus pulado],
     *    14=05/06, 15=08/06).
     *  - dataFatal = seg 08/06/2026.
     */
    public function testParseAplicaArt5SextaMaisQuinzeUteisIgualSeg08Junho(): void
    {
        $payload = [
            'id' => 12345,
            'dataDisponibilizacao' => '2026-05-15',
            'texto' => 'CONCEDO o prazo de 15 dias para apresentar contestação, sob pena de revelia.',
            'tipoComunicacao' => 'Intimação',
        ];
        $result = $this->parser->parse($payload);

        self::assertNotNull($result);
        self::assertSame('contestacao', $result->atoCodigo);
        self::assertSame(15, $result->prazoDias);
        self::assertSame('uteis', $result->contagem);
        self::assertSame('CPC art. 335', $result->referenciaLegal);
        self::assertSame('high', $result->confidence);
        self::assertSame('1.0.0', $result->regraVersao);
        self::assertSame(12345, $result->sourcePubId);

        self::assertSame(
            '2026-05-18',
            $result->dataInicioPrazo->format('Y-m-d'),
            'dataInicioPrazo = D+1 útil forense da disp = sexta 15/05 + 1 útil = segunda 18/05 (1º dia inclusive)',
        );
        self::assertSame(
            '2026-06-08',
            $result->dataFatal->format('Y-m-d'),
            'AC6: dataFatal = 08/06/2026 — contagem inclusive (18/05 é dia 1; com Corpus Christi 04/06 pulado, 15º dia útil cai em 08/06 segunda)',
        );
    }

    /**
     * AC7 LITERAL — cumprimento de sentença usa contagem CORRIDA (15 dias
     * corridos contados a partir do D+1 útil INCLUSIVE).
     *
     * Disponibilização sex 15/05/2026 → dataInicioPrazo = seg 18/05 → +14
     * corridos = 01/06/2026 (1º dia + 14 dias = 15 dias inclusive).
     */
    public function testParseCumprimentoSentencaUsa15Corridos(): void
    {
        $payload = [
            'id' => 22222,
            'dataDisponibilizacao' => '2026-05-15',
            'texto' => 'Intimação para pagamento voluntário no prazo de 15 dias, sob pena de multa do art. 523 §1º CPC.',
            'tipoComunicacao' => 'Intimação',
        ];
        $result = $this->parser->parse($payload);

        self::assertNotNull($result);
        self::assertSame('cumprimento_sentenca', $result->atoCodigo);
        self::assertSame(15, $result->prazoDias);
        self::assertSame('corridos', $result->contagem);
        self::assertSame('CPC art. 523', $result->referenciaLegal);
        self::assertSame('high', $result->confidence);

        self::assertSame('2026-05-18', $result->dataInicioPrazo->format('Y-m-d'));
        // AC7: 18/05 (1) + 14 corridos = 01/06/2026 (15º dia inclusive).
        self::assertSame(
            '2026-06-01',
            $result->dataFatal->format('Y-m-d'),
            'AC7: cumprimento de sentença = 1º dia em 18/05 + 14 corridos = 01/06/2026 (15 dias inclusive)',
        );
    }

    /**
     * AC9 — ato puramente certificatório retorna null.
     */
    public function testParseRetornaNullParaAtoOrdinatorioCertificatorio(): void
    {
        $payload = [
            'id' => 33333,
            'dataDisponibilizacao' => '2026-05-15',
            'texto' => 'Junte-se a petição. Cumpra-se. Publique-se.',
            'tipoDocumento' => 'Ato ordinatório',
        ];
        self::assertNull($this->parser->parse($payload));
    }

    /**
     * AC4 — fallback `manifestacao_generica` confidence=low.
     */
    public function testParseInjetaConfidenceLowQuandoFallback(): void
    {
        $payload = [
            'id' => 44444,
            'dataDisponibilizacao' => '2026-05-15',
            'texto' => 'Intimem-se as partes do conteúdo da decisão proferida nos autos do processo em epígrafe.',
        ];
        $result = $this->parser->parse($payload);

        self::assertNotNull($result);
        self::assertSame('manifestacao_generica', $result->atoCodigo);
        self::assertSame('low', $result->confidence);
        self::assertSame(15, $result->prazoDias);
        self::assertSame('uteis', $result->contagem);
        self::assertSame('CPC art. 218', $result->referenciaLegal);
    }

    /**
     * AC5 — override do número de dias quando texto explicita.
     */
    public function testParseUsaPrazoOverride30DiasEmManifestacao(): void
    {
        $payload = [
            'id' => 55555,
            'dataDisponibilizacao' => '2026-05-15',
            'texto' => 'concedo o prazo de 30 dias para que as partes apresentem alegações finais.',
        ];
        $result = $this->parser->parse($payload);

        self::assertNotNull($result);
        self::assertSame('manifestacao_geral_intimacao', $result->atoCodigo);
        self::assertSame(30, $result->prazoDias, 'Override do texto (30 dias) deve prevalecer em manifestacao_geral_intimacao');
        self::assertSame('uteis', $result->contagem);
    }

    /**
     * Decisão #4 — override do texto é IGNORADO em ato legalmente fixo.
     * Texto diz "30 dias para contestar" → parser aplica 15 (CPC 335).
     */
    public function testParseIgnoraOverrideEmAtoLegalmenteFixo(): void
    {
        $payload = [
            'id' => 66666,
            'dataDisponibilizacao' => '2026-05-15',
            'texto' => 'Concedo o prazo de 30 dias para apresentar contestação.',
        ];
        $result = $this->parser->parse($payload);

        self::assertNotNull($result);
        self::assertSame('contestacao', $result->atoCodigo);
        self::assertSame(
            15,
            $result->prazoDias,
            'Decisão #4: ato legalmente fixo (contestação 15 úteis CPC 335) ignora override do texto',
        );

        $events = TogareLogger::getRecorded();
        $warnings = \array_values(\array_filter(
            $events,
            static fn ($e) => $e['event'] === 'djen.parser.text_overrides_law',
        ));
        self::assertCount(1, $warnings);
        self::assertSame(30, $warnings[0]['context']['prazoTextoLido']);
        self::assertSame(15, $warnings[0]['context']['prazoLeiAplicada']);
    }

    public function testParseLancaInvalidArgumentExceptionQuandoDataDisponibilizacaoAusente(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->parser->parse([
            'id' => 1,
            'texto' => 'qualquer texto com contestação',
        ]);
    }

    public function testParseLancaInvalidArgumentExceptionQuandoDataDisponibilizacaoFormatoInvalido(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->parser->parse([
            'id' => 1,
            'dataDisponibilizacao' => '15/05/2026', // formato BR errado
            'texto' => 'qualquer texto com contestação',
        ]);
    }

    public function testParseRegraVersaoEhSempre1ZeroZero(): void
    {
        $payload = [
            'dataDisponibilizacao' => '2026-05-15',
            'texto' => 'apresentar contestação',
        ];
        $result = $this->parser->parse($payload);
        self::assertNotNull($result);
        self::assertSame('1.0.0', $result->regraVersao);
    }

    /**
     * Disponibilização em véspera de Tiradentes: 17/04/2026 sex.
     * nextBusinessDay(17/04 sex) = seg 20/04 (próximo útil estritamente
     * depois). Tiradentes 21/04 está depois — não afeta o início do prazo
     * neste cenário.
     */
    public function testParseDataInicioPrazoPulaApenasFimDeSemana(): void
    {
        $payload = [
            'dataDisponibilizacao' => '2026-04-17', // sex
            'texto' => 'CONCEDO o prazo de 15 dias para apresentar contestação.',
        ];
        $result = $this->parser->parse($payload);

        self::assertNotNull($result);
        self::assertSame(
            '2026-04-20',
            $result->dataInicioPrazo->format('Y-m-d'),
            'dataInicioPrazo = sex 17/04 + 1 útil = seg 20/04 (Tiradentes 21/04 ainda não impacta)',
        );
    }

    /**
     * Disponibilização EM Tiradentes (terça 21/04/2026): dataInicioPrazo pula
     * o feriado e cai na quarta 22/04.
     */
    public function testParseDataInicioPrazoPulaTiradentes(): void
    {
        $payload = [
            'dataDisponibilizacao' => '2026-04-21', // Tiradentes
            'texto' => 'CONCEDO o prazo de 15 dias para apresentar contestação.',
        ];
        $result = $this->parser->parse($payload);

        self::assertNotNull($result);
        self::assertSame(
            '2026-04-22',
            $result->dataInicioPrazo->format('Y-m-d'),
            'dataInicioPrazo = Tiradentes 21/04 + 1 útil = qua 22/04',
        );
    }

    public function testParsePreservaSourcePubIdNoDTO(): void
    {
        $payload = [
            'id' => 999888777,
            'dataDisponibilizacao' => '2026-05-15',
            'texto' => 'CONCEDO o prazo de 15 dias para apresentar contestação.',
        ];
        $result = $this->parser->parse($payload);
        self::assertNotNull($result);
        self::assertSame(999888777, $result->sourcePubId);
    }

    public function testSourcePubIdEhNullQuandoIdNaoEhInteiro(): void
    {
        $payload = [
            'id' => '123', // string — is_int() false → sourcePubId = null
            'dataDisponibilizacao' => '2026-05-15',
            'texto' => 'CONCEDO o prazo de 15 dias para apresentar contestação.',
        ];
        $result = $this->parser->parse($payload);
        self::assertNotNull($result);
        self::assertNull($result->sourcePubId, 'sourcePubId deve ser null quando id não é inteiro');
    }

    public function testToArraySerializaCamposEsperados(): void
    {
        $payload = [
            'id' => 1,
            'dataDisponibilizacao' => '2026-05-15',
            'texto' => 'apresentar contestação no prazo legal',
        ];
        $result = $this->parser->parse($payload);
        self::assertNotNull($result);

        $arr = $result->toArray();
        self::assertSame('2026-05-18', $arr['dataInicioPrazo']);
        self::assertArrayHasKey('dataFatal', $arr);
        self::assertSame(15, $arr['prazoDias']);
        self::assertSame('uteis', $arr['contagem']);
        self::assertSame('contestacao', $arr['atoCodigo']);
        self::assertSame('CPC art. 335', $arr['referenciaLegal']);
        self::assertSame('1.0.0', $arr['regraVersao']);
    }
}
