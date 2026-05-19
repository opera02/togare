<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Services\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Modules\TogareCore\Services\Calendar\BrazilianBusinessCalendar;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Story 4a.2 — BrazilianBusinessCalendar (AC1, AC1.1, AC1.2).
 *
 * Service puro — testes unit clássicos sem mocks. Cobre:
 *  - Páscoa de Gauss/Meeus para 2024-2030 (validação contra calendário oficial).
 *  - Feriados fixos (1/1, 21/4, 1/5, 7/9, 12/10, 2/11, 15/11, 20/11, 25/12).
 *  - Feriados móveis (Carnaval seg+ter, Sexta Santa, Corpus Christi).
 *  - Consciência Negra (Lei 14.759/2023, eficácia desde 2024).
 *  - addBusinessDays pulando finais de semana.
 *  - addBusinessDays pulando feriado fixo (Tiradentes).
 *  - addBusinessDays pulando feriado móvel (Carnaval).
 *  - addCalendarDays não pula nada.
 *  - nextBusinessDay sempre retorna dia útil ESTRITAMENTE depois.
 *  - **Aritmética PURA do método** (exclusive): seg 18/05/2026 + 15 úteis =
 *    ter 09/06/2026 (Corpus Christi 04/06 pulado).
 *  - **Uso real pelo parser DJEN (regra inclusiva confirmada por Felipe
 *    2026-05-03)**: `addBusinessDays(disp 15/05/2026, 15)` direto = seg
 *    08/06/2026 (1º dia útil seguinte = 18/05 inclusive; Corpus Christi
 *    04/06 pulado). É a evidência empírica de que o parser EVITA exatamente
 *    o tipo de erro que advogado contando manual numa folha de papel comete.
 *  - listHolidaysFor retorna lista cronológica.
 *  - Edge cases: $days < 0 lança InvalidArgumentException.
 */
final class BrazilianBusinessCalendarTest extends TestCase
{
    private BrazilianBusinessCalendar $cal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cal = new BrazilianBusinessCalendar();
    }

    /**
     * Páscoa de Gauss/Meeus — validação contra calendário oficial.
     * Cada ano tem assertion explícita.
     */
    public function testEasterSundayCalculatedCorrectlyFor2024To2030(): void
    {
        $expected = [
            2024 => '2024-03-31',
            2025 => '2025-04-20',
            2026 => '2026-04-05',
            2027 => '2027-03-28',
            2028 => '2028-04-16',
            2029 => '2029-04-01',
            2030 => '2030-04-21',
        ];
        foreach ($expected as $year => $iso) {
            $easter = $this->cal->easterSundayFor($year);
            self::assertSame(
                $iso,
                $easter->format('Y-m-d'),
                "Páscoa {$year} esperada {$iso}, recebida {$easter->format('Y-m-d')}",
            );
        }
    }

    /**
     * Feriados fixos (Lei 662/1949 + Lei 6802/1980 + Lei 14.759/2023).
     * Testa cada um para 3 anos amostrais.
     */
    public function testIsHolidayBRReconheceFeriadosFixos2024A2030(): void
    {
        $fixos = ['01-01', '04-21', '05-01', '09-07', '10-12', '11-02', '11-15', '12-25'];
        foreach ([2024, 2025, 2026, 2027, 2028, 2029, 2030] as $year) {
            foreach ($fixos as $md) {
                $date = new DateTimeImmutable("{$year}-{$md}", new DateTimeZone('America/Sao_Paulo'));
                self::assertTrue(
                    $this->cal->isHolidayBR($date),
                    "{$year}-{$md} deveria ser feriado fixo",
                );
            }
        }
    }

    public function testIsHolidayBRReconheceConscienciaNegraDesde2024(): void
    {
        // Lei 14.759/2023, eficácia desde 2024.
        foreach ([2024, 2025, 2026, 2027, 2028, 2029, 2030] as $year) {
            $date = new DateTimeImmutable("{$year}-11-20", new DateTimeZone('America/Sao_Paulo'));
            self::assertTrue(
                $this->cal->isHolidayBR($date),
                "{$year}-11-20 deveria ser feriado (Consciência Negra)",
            );
        }
        // 2023 NÃO é feriado — lei eficácia exclusivamente a partir de 2024.
        self::assertFalse(
            $this->cal->isHolidayBR(new DateTimeImmutable('2023-11-20', new DateTimeZone('America/Sao_Paulo'))),
            '2023-11-20 NÃO deveria ser feriado (Lei 14.759/2023 eficácia só desde 2024)',
        );
    }

    public function testIsHolidayBRReconheceCarnavalSegETer2024A2030(): void
    {
        // Carnaval = Páscoa - 48 (seg) e -47 (ter).
        // Páscoas: 2024=31/03, 2025=20/04, 2026=05/04, 2027=28/03, 2028=16/04,
        //          2029=01/04, 2030=21/04.
        $expected = [
            2024 => ['2024-02-12', '2024-02-13'], // 31/03 - 48 = 12/02 seg
            2025 => ['2025-03-03', '2025-03-04'], // 20/04 - 48 = 03/03 seg
            2026 => ['2026-02-16', '2026-02-17'],
            2027 => ['2027-02-08', '2027-02-09'],
            2028 => ['2028-02-28', '2028-02-29'],
            2029 => ['2029-02-12', '2029-02-13'],
            2030 => ['2030-03-04', '2030-03-05'],
        ];
        $tz = new DateTimeZone('America/Sao_Paulo');
        foreach ($expected as $year => [$seg, $ter]) {
            self::assertTrue(
                $this->cal->isHolidayBR(new DateTimeImmutable($seg, $tz)),
                "Carnaval segunda {$year} esperado em {$seg}",
            );
            self::assertTrue(
                $this->cal->isHolidayBR(new DateTimeImmutable($ter, $tz)),
                "Carnaval terça {$year} esperado em {$ter}",
            );
        }
    }

    public function testIsHolidayBRReconheceSextaFeiraSanta2024A2030(): void
    {
        // Sexta Santa = Páscoa - 2.
        $expected = [
            2024 => '2024-03-29',
            2025 => '2025-04-18',
            2026 => '2026-04-03',
            2027 => '2027-03-26',
            2028 => '2028-04-14',
            2029 => '2029-03-30',
            2030 => '2030-04-19',
        ];
        $tz = new DateTimeZone('America/Sao_Paulo');
        foreach ($expected as $year => $iso) {
            self::assertTrue(
                $this->cal->isHolidayBR(new DateTimeImmutable($iso, $tz)),
                "Sexta-Feira Santa {$year} esperada em {$iso}",
            );
        }
    }

    public function testIsHolidayBRReconheceCorpusChristi2024A2030(): void
    {
        // Corpus Christi = Páscoa + 60.
        $expected = [
            2024 => '2024-05-30',
            2025 => '2025-06-19',
            2026 => '2026-06-04',
            2027 => '2027-05-27',
            2028 => '2028-06-15',
            2029 => '2029-05-31',
            2030 => '2030-06-20',
        ];
        $tz = new DateTimeZone('America/Sao_Paulo');
        foreach ($expected as $year => $iso) {
            self::assertTrue(
                $this->cal->isHolidayBR(new DateTimeImmutable($iso, $tz)),
                "Corpus Christi {$year} esperado em {$iso}",
            );
        }
    }

    public function testIsHolidayBRRetornaFalseEmDiaUtilNormal(): void
    {
        $tz = new DateTimeZone('America/Sao_Paulo');
        $diasUteis = [
            '2026-05-15', // sex - dia útil normal
            '2026-05-18', // seg - dia útil normal
            '2026-04-22', // qua - dia seguinte ao Tiradentes (útil)
            '2026-06-05', // sex - dia seguinte a Corpus Christi (útil)
        ];
        foreach ($diasUteis as $iso) {
            self::assertFalse(
                $this->cal->isHolidayBR(new DateTimeImmutable($iso, $tz)),
                "{$iso} NÃO deveria ser feriado",
            );
        }
    }

    public function testIsBusinessDayRetornaFalseEmFimDeSemana(): void
    {
        $tz = new DateTimeZone('America/Sao_Paulo');
        // 16/05/2026 sábado, 17/05/2026 domingo.
        self::assertFalse($this->cal->isBusinessDay(new DateTimeImmutable('2026-05-16', $tz)));
        self::assertFalse($this->cal->isBusinessDay(new DateTimeImmutable('2026-05-17', $tz)));
    }

    public function testIsBusinessDayRetornaFalseEmFeriado(): void
    {
        $tz = new DateTimeZone('America/Sao_Paulo');
        // Tiradentes 2026 = ter (dia útil pelo dia da semana, mas é feriado).
        self::assertFalse($this->cal->isBusinessDay(new DateTimeImmutable('2026-04-21', $tz)));
        // Corpus Christi 2026 = quinta (mesmo caso).
        self::assertFalse($this->cal->isBusinessDay(new DateTimeImmutable('2026-06-04', $tz)));
    }

    public function testIsBusinessDayRetornaTrueEmDiaUtil(): void
    {
        $tz = new DateTimeZone('America/Sao_Paulo');
        self::assertTrue($this->cal->isBusinessDay(new DateTimeImmutable('2026-05-15', $tz))); // sex
        self::assertTrue($this->cal->isBusinessDay(new DateTimeImmutable('2026-05-18', $tz))); // seg
    }

    public function testNextBusinessDayPulaSabadoEDomingoDepoisDeSexta(): void
    {
        $tz = new DateTimeZone('America/Sao_Paulo');
        // Sex 15/05/2026 → próximo útil DEPOIS = seg 18/05/2026.
        $sex = new DateTimeImmutable('2026-05-15', $tz);
        $result = $this->cal->nextBusinessDay($sex);
        self::assertSame('2026-05-18', $result->format('Y-m-d'));
    }

    public function testNextBusinessDayDeSegundaRetornaTerca(): void
    {
        $tz = new DateTimeZone('America/Sao_Paulo');
        // Seg útil → próximo útil é a terça (não fica no próprio dia).
        $seg = new DateTimeImmutable('2026-05-18', $tz);
        self::assertSame('2026-05-19', $this->cal->nextBusinessDay($seg)->format('Y-m-d'));
    }

    public function testNextBusinessDayPulaFeriadoIntermediario(): void
    {
        $tz = new DateTimeZone('America/Sao_Paulo');
        // Seg 20/04/2026 → próximo útil DEPOIS = qua 22/04 (pula Tiradentes 21/04).
        $seg = new DateTimeImmutable('2026-04-20', $tz);
        self::assertSame('2026-04-22', $this->cal->nextBusinessDay($seg)->format('Y-m-d'));
    }

    /**
     * Convenção exclusiva do `addBusinessDays`: a partir de seg 18/05/2026
     * conta 15 dias úteis DEPOIS, gerando ter 09/06/2026.
     *
     * **Importante:** a regra do art. 5º Res. CNJ 455 NÃO usa o calendário
     * desse jeito (chamando após `nextBusinessDay(disp)`). O parser real
     * chama `addBusinessDays(disp, prazoDias)` direto — ver
     * `testAddBusinessDaysCobreRegrasCnjArt5_Sex15_05Mais15UteisIgualSeg08_06`
     * abaixo. Este teste valida apenas a aritmética PURA do método.
     */
    public function testAddBusinessDaysExclusiveSeg18_05Mais15UteisIgualTer09_06(): void
    {
        $tz = new DateTimeZone('America/Sao_Paulo');
        $seg = new DateTimeImmutable('2026-05-18', $tz);
        $resultado = $this->cal->addBusinessDays($seg, 15);
        self::assertSame(
            '2026-06-09',
            $resultado->format('Y-m-d'),
            'addBusinessDays exclusive: 18/05 + 15 úteis = 09/06 (Corpus Christi 04/06 pulado)',
        );
        self::assertSame('2', $resultado->format('N'), 'cai numa terça-feira');
    }

    /**
     * Uso real pelo `DjenParserService` (Story 4a.2 art. 5º Res. CNJ 455 +
     * regra inclusiva confirmada por Felipe 2026-05-03):
     *
     * `addBusinessDays(disp, prazoDias)` é exatamente o cálculo do prazo
     * legal inclusive — equivalente a "1º dia útil seguinte = dia 1 +
     * (N-1) úteis adicionais", sem precisar do passo intermediário
     * `nextBusinessDay`.
     *
     * AC6 LITERAL da Story 4a.2: disponibilização sex 15/05/2026 + 15 úteis
     * (contestação CPC art. 335) = seg **08/06/2026** (Corpus Christi 04/06
     * pulado dentro da contagem).
     *
     * Esta é a evidência empírica de que o parser EVITA exatamente o erro
     * que advogado contando na folha de papel comete (esquecer Corpus Christi).
     */
    public function testAddBusinessDaysCobreRegrasCnjArt5_Sex15_05Mais15UteisIgualSeg08_06(): void
    {
        $tz = new DateTimeZone('America/Sao_Paulo');
        $disp = new DateTimeImmutable('2026-05-15', $tz); // sex
        $dataFatal = $this->cal->addBusinessDays($disp, 15);
        self::assertSame(
            '2026-06-08',
            $dataFatal->format('Y-m-d'),
            'AC6: 15/05 + 15 úteis = 08/06 (1º dia útil seguinte=18/05 inclusive; Corpus Christi 04/06 pulado)',
        );
        self::assertSame('1', $dataFatal->format('N'), 'cai numa segunda-feira');
    }

    public function testAddBusinessDaysSegMaisUmIgualTerca(): void
    {
        $tz = new DateTimeZone('America/Sao_Paulo');
        $seg = new DateTimeImmutable('2026-05-18', $tz);
        self::assertSame('2026-05-19', $this->cal->addBusinessDays($seg, 1)->format('Y-m-d'));
    }

    public function testAddBusinessDaysSexMaisUmIgualSegunda(): void
    {
        $tz = new DateTimeZone('America/Sao_Paulo');
        $sex = new DateTimeImmutable('2026-05-15', $tz);
        // sex + 1 útil pula sáb+dom = seg 18/05.
        self::assertSame('2026-05-18', $this->cal->addBusinessDays($sex, 1)->format('Y-m-d'));
    }

    /**
     * AC1.1 — pula Tiradentes 21/04/2026 (terça).
     */
    public function testAddBusinessDaysPulaTiradentes(): void
    {
        $tz = new DateTimeZone('America/Sao_Paulo');
        // Sex 17/04/2026 + 5 úteis: seg 20/04 (1), [21/04 Tiradentes pulado],
        // qua 22/04 (2), qui 23/04 (3), sex 24/04 (4), seg 27/04 (5).
        $sex = new DateTimeImmutable('2026-04-17', $tz);
        self::assertSame(
            '2026-04-27',
            $this->cal->addBusinessDays($sex, 5)->format('Y-m-d'),
        );
    }

    /**
     * AC1.1 — pula Carnaval 16-17/02/2026 (seg+ter).
     */
    public function testAddBusinessDaysPulaCarnaval(): void
    {
        $tz = new DateTimeZone('America/Sao_Paulo');
        // Sex 13/02/2026 + 3 úteis: [16/02 carnaval], [17/02 carnaval],
        // qua 18/02 (1), qui 19/02 (2), sex 20/02 (3).
        $sex = new DateTimeImmutable('2026-02-13', $tz);
        self::assertSame(
            '2026-02-20',
            $this->cal->addBusinessDays($sex, 3)->format('Y-m-d'),
        );
    }

    public function testAddBusinessDaysZeroRetornaMesmaData(): void
    {
        $tz = new DateTimeZone('America/Sao_Paulo');
        $start = new DateTimeImmutable('2026-05-18', $tz);
        // Convenção: 0 dias úteis = mesma data (sem avanço).
        self::assertSame('2026-05-18', $this->cal->addBusinessDays($start, 0)->format('Y-m-d'));
    }

    public function testAddBusinessDaysComDiasNegativoLancaInvalidArgument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $tz = new DateTimeZone('America/Sao_Paulo');
        $this->cal->addBusinessDays(new DateTimeImmutable('2026-05-18', $tz), -1);
    }

    /**
     * AC7.1 — contagem corrida não pula nada.
     */
    public function testAddCalendarDaysNaoPulaFimDeSemana(): void
    {
        $tz = new DateTimeZone('America/Sao_Paulo');
        // Sex 15/05/2026 + 7 corridos = sex 22/05/2026 (sáb 16 e dom 17 contam, não são pulados).
        $sex = new DateTimeImmutable('2026-05-15', $tz);
        $result = $this->cal->addCalendarDays($sex, 7);
        self::assertSame('2026-05-22', $result->format('Y-m-d'));
        self::assertSame('5', $result->format('N')); // 5 = sexta
    }

    public function testAddCalendarDaysNaoPulaFeriado(): void
    {
        $tz = new DateTimeZone('America/Sao_Paulo');
        // Cumprimento de sentença AC7: seg 18/05/2026 + 15 corridos = ter 02/06/2026.
        // Verificar manualmente: 18/05 + 13d = 31/05 (dom); +14 = 01/06 (seg); +15 = 02/06 (ter).
        $seg = new DateTimeImmutable('2026-05-18', $tz);
        self::assertSame('2026-06-02', $this->cal->addCalendarDays($seg, 15)->format('Y-m-d'));
    }

    public function testAddCalendarDaysZeroRetornaMesmaData(): void
    {
        $tz = new DateTimeZone('America/Sao_Paulo');
        $start = new DateTimeImmutable('2026-05-18', $tz);
        self::assertSame('2026-05-18', $this->cal->addCalendarDays($start, 0)->format('Y-m-d'));
    }

    public function testAddCalendarDaysComDiasNegativoLancaInvalidArgument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $tz = new DateTimeZone('America/Sao_Paulo');
        $this->cal->addCalendarDays(new DateTimeImmutable('2026-05-18', $tz), -1);
    }

    /**
     * AC1.2 — listHolidaysFor(2026) com 13 datas conhecidas.
     */
    public function testListHolidaysFor2026Contem13DatasConhecidas(): void
    {
        $list = $this->cal->listHolidaysFor(2026);
        $isos = \array_map(static fn (DateTimeImmutable $d) => $d->format('Y-m-d'), $list);

        $esperadas = [
            '2026-01-01', // Confraternização
            '2026-02-16', // Carnaval seg
            '2026-02-17', // Carnaval ter
            '2026-04-03', // Sexta Santa
            '2026-04-21', // Tiradentes
            '2026-05-01', // Trabalho
            '2026-06-04', // Corpus Christi
            '2026-09-07', // Independência
            '2026-10-12', // Ap Aparecida
            '2026-11-02', // Finados
            '2026-11-15', // Proclamação
            '2026-11-20', // Consciência Negra
            '2026-12-25', // Natal
        ];
        foreach ($esperadas as $iso) {
            self::assertContains($iso, $isos, "{$iso} deveria estar em listHolidaysFor(2026)");
        }
        self::assertCount(13, $list, 'listHolidaysFor(2026) deveria conter 13 feriados');
    }

    public function testListHolidaysForRetornaListaCronologicamenteOrdenada(): void
    {
        $list = $this->cal->listHolidaysFor(2026);
        $previous = null;
        foreach ($list as $date) {
            if ($previous !== null) {
                self::assertGreaterThan(
                    $previous->getTimestamp(),
                    $date->getTimestamp(),
                    'listHolidaysFor deve estar em ordem cronológica',
                );
            }
            $previous = $date;
        }
    }

    public function testCacheDeFeriadosPorAno(): void
    {
        // Mesma instância chamada 2x para o mesmo ano → mesmo conteúdo.
        $list1 = $this->cal->listHolidaysFor(2026);
        $list2 = $this->cal->listHolidaysFor(2026);
        self::assertCount(\count($list1), $list2);
        // Não há jeito direto de testar cache hit sem mock, mas garantimos
        // que repetir não corrompe o resultado.
        self::assertSame(
            \array_map(static fn ($d) => $d->format('Y-m-d'), $list1),
            \array_map(static fn ($d) => $d->format('Y-m-d'), $list2),
        );
    }

    /**
     * Story 4a.5.1 — `subtractBusinessDays` é o espelho exclusivo de
     * `addBusinessDays`. Convenção: a partir de seg, 1 dia útil ANTES é a
     * sex anterior (não o sáb/dom). Pula sábados, domingos e feriados.
     */
    public function testSubtractBusinessDaysSegMinusUmIgualSexAnterior(): void
    {
        $tz = new DateTimeZone('America/Sao_Paulo');
        // Seg 18/05/2026 - 1 útil = sex 15/05/2026.
        $seg = new DateTimeImmutable('2026-05-18', $tz);
        $resultado = $this->cal->subtractBusinessDays($seg, 1);
        self::assertSame('2026-05-15', $resultado->format('Y-m-d'));
        self::assertSame('5', $resultado->format('N'), 'cai numa sexta-feira');
    }

    public function testSubtractBusinessDaysQuiMinusUmIgualQua(): void
    {
        $tz = new DateTimeZone('America/Sao_Paulo');
        // Qui 21/05/2026 - 1 útil = qua 20/05/2026.
        $qui = new DateTimeImmutable('2026-05-21', $tz);
        self::assertSame('2026-05-20', $this->cal->subtractBusinessDays($qui, 1)->format('Y-m-d'));
    }

    public function testSubtractBusinessDaysZeroRetornaMesmaData(): void
    {
        $tz = new DateTimeZone('America/Sao_Paulo');
        // Convenção: 0 dias úteis = mesma data (sem retrocesso).
        $start = new DateTimeImmutable('2026-05-18', $tz);
        self::assertSame('2026-05-18', $this->cal->subtractBusinessDays($start, 0)->format('Y-m-d'));
    }

    /**
     * Story 4a.5.1 — atravessa Carnaval 2026 (seg 16/02 + ter 17/02 +
     * Sexta Santa 03/04 NÃO; aqui Carnaval mesmo).
     *
     * Quarta de cinzas 18/02/2026 - 1 útil = sex 13/02/2026 (Carnaval seg+ter pulados).
     */
    public function testSubtractBusinessDaysAtravessaCarnaval2026(): void
    {
        $tz = new DateTimeZone('America/Sao_Paulo');
        $quaCinzas = new DateTimeImmutable('2026-02-18', $tz); // qua de cinzas
        self::assertSame(
            '2026-02-13',
            $this->cal->subtractBusinessDays($quaCinzas, 1)->format('Y-m-d'),
            'Quarta de cinzas - 1 útil = sex anterior (carnaval pulado)',
        );
    }

    /**
     * Story 4a.5.1 — atravessa Sexta-Feira Santa 03/04/2026 + Páscoa 05/04/2026.
     *
     * Seg 06/04/2026 - 5 úteis: pula Páscoa weekend + Sexta Santa 03/04.
     * Backward: -1=qui 02/04, -2=qua 01/04, -3=ter 31/03, -4=seg 30/03, -5=sex 27/03.
     */
    public function testSubtractBusinessDaysAtravessaSextaSanta2026(): void
    {
        $tz = new DateTimeZone('America/Sao_Paulo');
        $segPosPascoa = new DateTimeImmutable('2026-04-06', $tz);
        self::assertSame(
            '2026-03-27',
            $this->cal->subtractBusinessDays($segPosPascoa, 5)->format('Y-m-d'),
            'Seg pós-Páscoa - 5 úteis = sex 27/03 (Sexta Santa 03/04 + Páscoa weekend pulados)',
        );
    }

    public function testSubtractBusinessDaysComDiasNegativoLancaInvalidArgument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $tz = new DateTimeZone('America/Sao_Paulo');
        $this->cal->subtractBusinessDays(new DateTimeImmutable('2026-05-18', $tz), -1);
    }
}
