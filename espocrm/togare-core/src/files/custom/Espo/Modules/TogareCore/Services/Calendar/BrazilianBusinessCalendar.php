<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Calendário forense brasileiro — feriados nacionais BR + cálculo de dias úteis.
 *
 * Service puro (sem DI). Mantém cache interno por ano para evitar recalcular
 * Páscoa múltiplas vezes na mesma instância. Pensado para ser cross-cutting: parser
 * DJEN (Story 4a.2 — núcleo desta classe), validação de Audiência futura
 * (não casa em feriado nacional), Lançamento Financeiro (boleto vence em
 * dia útil). Cobre **2024-2030** explicitamente nos testes.
 *
 * Feriados cobertos (Lei 662/1949 + Lei 9.093/1995 + Lei 14.759/2023 +
 * tradição forense unânime CPC art. 216):
 *
 *   FIXOS — 1/1, 21/4, 1/5, 7/9, 12/10, 2/11, 15/11, 20/11, 25/12.
 *   MÓVEIS — Carnaval seg+ter (Páscoa - 48/-47), Sexta-Feira Santa
 *            (Páscoa - 2), Corpus Christi (Páscoa + 60).
 *
 * NÃO cobertos no MVP (limitações documentadas — Open Questions §8 da story):
 *   - Recesso forense fim de ano CPC art. 220 (20/12-6/1) — é SUSPENSÃO
 *     de prazo, não feriado; semântica diferente. Story 4b.x ou Epic 10.
 *   - Feriados estaduais/municipais — variam por OAB.uf. Aceitar como
 *     trade-off MVP (parser conservador — só nacionais). Growth.
 *   - Feriados retroativos / antecipados (lei municipal de calamidade) —
 *     prefeitura decreta ponte por decreto. MVP não suporta.
 *
 * Convenção de `addBusinessDays`: retorna o `$days`-ésimo dia útil DEPOIS
 * de `$start` — ou seja, exclusivo de `$start`. Exemplo:
 *   - `addBusinessDays(seg, 1) = ter`
 *   - `addBusinessDays(sex, 1) = seg`
 *   - `addBusinessDays(seg, 5) = seg seguinte` (5 úteis = ter+qua+qui+sex+seg)
 *
 * Implementação Páscoa via algoritmo "Anonymous Gregorian" (Gauss/Meeus —
 * Wikipedia "Computus"). Validado contra calendário oficial 2024-2030 nos
 * testes da Story 4a.2.
 */
final class BrazilianBusinessCalendar
{
    /** Range coberto explicitamente por testes da Story 4a.2. */
    public const SUPPORTED_YEARS_FROM = 2024;
    public const SUPPORTED_YEARS_TO = 2030;

    private const TZ = 'America/Sao_Paulo';

    /**
     * Cache de feriados por ano: ['Y-m-d' => true, ...].
     *
     * @var array<int, array<string, bool>>
     */
    private array $cachedHolidaysByYear = [];

    /**
     * Verifica se a data cai em feriado nacional brasileiro (fixos + móveis +
     * tradição forense unanimemente reconhecida pelo CNJ — Carnaval seg+ter,
     * Sexta-Feira Santa, Corpus Christi).
     *
     * Ignora componentes de hora — compara apenas Y-m-d.
     */
    public function isHolidayBR(DateTimeImmutable $date): bool
    {
        $year = (int) $date->format('Y');
        $key = $date->format('Y-m-d');
        return isset($this->getHolidaysIndex($year)[$key]);
    }

    /**
     * Verifica se a data é dia útil — NÃO é sábado, NÃO é domingo, NÃO é
     * feriado nacional BR. Ignora componentes de hora.
     */
    public function isBusinessDay(DateTimeImmutable $date): bool
    {
        $dayOfWeek = (int) $date->format('N'); // 1=Mon..7=Sun
        if ($dayOfWeek >= 6) {
            return false;
        }
        return ! $this->isHolidayBR($date);
    }

    /**
     * Próximo dia útil ESTRITAMENTE depois de `$date` — pula sábados, domingos
     * e feriados nacionais BR. Mesmo que `$date` seja útil, retorna o dia útil
     * SEGUINTE (semantica do art. 5º Res. CNJ 455: disponibilização +
     * 1 dia útil = data de publicação efetiva).
     *
     * Atenção: alguns calendários definem "next business day" como "este dia
     * se for útil, senão o seguinte". Aqui é SEMPRE o seguinte.
     */
    public function nextBusinessDay(DateTimeImmutable $date): DateTimeImmutable
    {
        $cursor = $this->normalizeToDate($date)->modify('+1 day');
        $loops = 0;
        while (! $this->isBusinessDay($cursor)) {
            $cursor = $cursor->modify('+1 day');
            // Defensive cap — feriado-cluster máximo plausível ~6 dias
            // (ex.: Carnaval 4 dias seguidos com fim de semana). 30 é
            // overkill mas evita loop infinito por bug.
            if (++$loops > 30) {
                throw new \RuntimeException(
                    'BrazilianBusinessCalendar.nextBusinessDay: loop > 30 dias a partir de ' .
                    $date->format('Y-m-d') . ' — bug ou configuração de feriados inconsistente.',
                );
            }
        }
        return $cursor;
    }

    /**
     * Adiciona `$days` dias ÚTEIS a partir de `$start` (exclusivo de `$start`).
     *
     * Pula sábados, domingos e feriados nacionais BR. Retorna o `$days`-ésimo
     * dia útil DEPOIS de `$start`.
     *
     * Convenção:
     *   - `addBusinessDays(seg, 1) = ter` (terça é o 1º dia útil DEPOIS de seg)
     *   - `addBusinessDays(sex, 1) = seg` (segunda é o 1º útil DEPOIS de sex)
     *   - `addBusinessDays(qui, 5) = qui+1sem` (5 dias úteis: sex+seg+ter+qua+qui)
     *
     * Esta convenção é equivalente, para fins do art. 5º Res. CNJ 455 + CPC
     * art. 219, à regra inclusiva (disponibilização não conta; D+1 útil é o
     * 1º dia da contagem inclusive). Logo, no parser DJEN basta:
     *   dataFatal = addBusinessDays(dataDisponibilizacao, prazoDias)
     * sem necessidade de passo intermediário (a iteração começa em
     * `start + 1 day` e conta o N-ésimo útil — equivalente a "1º útil
     * seguinte = dia 1 + (N-1) úteis adicionais").
     *
     * @throws InvalidArgumentException se $days < 0.
     */
    public function addBusinessDays(DateTimeImmutable $start, int $days): DateTimeImmutable
    {
        if ($days < 0) {
            throw new InvalidArgumentException(
                "addBusinessDays: \$days deve ser >= 0, recebido: {$days}",
            );
        }
        if ($days === 0) {
            return $this->normalizeToDate($start);
        }

        $cursor = $this->normalizeToDate($start);
        $remaining = $days;
        $iterations = 0;
        while ($remaining > 0) {
            $cursor = $cursor->modify('+1 day');
            if ($this->isBusinessDay($cursor)) {
                $remaining--;
            }
            // Cap defensivo: 365 iterações cobrem 365/0.71 ≈ 514 dias úteis,
            // muito além de qualquer prazo CPC realístico (max é 30 dias).
            if (++$iterations > 365) {
                throw new \RuntimeException(
                    'BrazilianBusinessCalendar.addBusinessDays: loop > 365 dias para adicionar ' .
                    $days . ' dias úteis a partir de ' . $start->format('Y-m-d'),
                );
            }
        }
        return $cursor;
    }

    /**
     * Subtrai `$days` dias ÚTEIS de `$start` (exclusivo de `$start`).
     *
     * Espelho de `addBusinessDays`. Pula sábados, domingos e feriados
     * nacionais BR. Retorna o `$days`-ésimo dia útil ANTES de `$start`.
     *
     * Convenção (espelho de `addBusinessDays`):
     *   - `subtractBusinessDays(seg, 1) = sex anterior` (sex é o 1º útil ANTES de seg)
     *   - `subtractBusinessDays(qui, 1) = qua`
     *   - `subtractBusinessDays(seg, 5) = seg-1sem`
     *
     * Cross-cutting (Story 4a.5.1 + 4b.2):
     *   - Story 4a.5.1: default `dataCumprimento = dataFatal − 2 dias úteis`
     *     no `DefaultDataCumprimentoHook`.
     *   - Story 4b.2: alertas D-7/D-3/D-1 calculam data de disparo via
     *     `subtractBusinessDays(dataFatal, 7|3|1)`.
     *
     * @throws InvalidArgumentException se $days < 0.
     */
    public function subtractBusinessDays(DateTimeImmutable $start, int $days): DateTimeImmutable
    {
        if ($days < 0) {
            throw new InvalidArgumentException(
                "subtractBusinessDays: \$days deve ser >= 0, recebido: {$days}",
            );
        }
        if ($days === 0) {
            return $this->normalizeToDate($start);
        }

        $cursor = $this->normalizeToDate($start);
        $remaining = $days;
        $iterations = 0;
        while ($remaining > 0) {
            $cursor = $cursor->modify('-1 day');
            if ($this->isBusinessDay($cursor)) {
                $remaining--;
            }
            // Cap defensivo simétrico ao addBusinessDays — 365 iterações cobrem
            // muito além de qualquer subtração realística (margem de segurança
            // operacional max é ~10 úteis).
            if (++$iterations > 365) {
                throw new \RuntimeException(
                    'BrazilianBusinessCalendar.subtractBusinessDays: loop > 365 dias para subtrair ' .
                    $days . ' dias úteis a partir de ' . $start->format('Y-m-d'),
                );
            }
        }
        return $cursor;
    }

    /**
     * Adiciona `$days` dias CORRIDOS a partir de `$start` (exclusivo de
     * `$start`). NÃO pula nada — calendar arithmetic puro.
     *
     * Usado para prazos que o CPC chama de "dias corridos" (ex.: cumprimento
     * de sentença CPC art. 523, 15 dias para pagamento voluntário).
     *
     * Atenção: contraste explícito com `addBusinessDays`. Cumprimento de
     * sentença disponibilizado em sex 15/05 → publicação efetiva seg 18/05
     * → dataFatal = 18/05 + 15 corridos = 02/06 (terça). Mesmo que cair em
     * sáb/dom/feriado, é "dia 15" da contagem corrida.
     *
     * @throws InvalidArgumentException se $days < 0.
     */
    public function addCalendarDays(DateTimeImmutable $start, int $days): DateTimeImmutable
    {
        if ($days < 0) {
            throw new InvalidArgumentException(
                "addCalendarDays: \$days deve ser >= 0, recebido: {$days}",
            );
        }
        return $this->normalizeToDate($start)->modify("+{$days} days");
    }

    /**
     * Lista todos os feriados nacionais BR de um ano (fixos + móveis), em
     * ordem cronológica.
     *
     * @return list<DateTimeImmutable>
     */
    public function listHolidaysFor(int $year): array
    {
        $index = $this->getHolidaysIndex($year);
        $tz = new DateTimeZone(self::TZ);
        $out = [];
        foreach (\array_keys($index) as $ymd) {
            $out[] = new DateTimeImmutable($ymd, $tz);
        }
        \usort($out, static fn (DateTimeImmutable $a, DateTimeImmutable $b)
            => $a->getTimestamp() <=> $b->getTimestamp());
        return $out;
    }

    /**
     * Domingo de Páscoa para o ano (algoritmo Anonymous Gregorian — base de
     * Carnaval, Sexta-Feira Santa e Corpus Christi).
     *
     * Validado contra calendário oficial:
     *   2024 → 31/03, 2025 → 20/04, 2026 → 05/04, 2027 → 28/03,
     *   2028 → 16/04, 2029 → 01/04, 2030 → 21/04.
     */
    public function easterSundayFor(int $year): DateTimeImmutable
    {
        $a = $year % 19;
        $b = (int) \floor($year / 100);
        $c = $year % 100;
        $d = (int) \floor($b / 4);
        $e = $b % 4;
        $f = (int) \floor(($b + 8) / 25);
        $g = (int) \floor(($b - $f + 1) / 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = (int) \floor($c / 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = (int) \floor(($a + 11 * $h + 22 * $l) / 451);
        $month = (int) \floor(($h + $l - 7 * $m + 114) / 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        $iso = \sprintf('%04d-%02d-%02d', $year, $month, $day);
        return new DateTimeImmutable($iso, new DateTimeZone(self::TZ));
    }

    /**
     * @return array<string, bool> ymd-indexed para lookup O(1).
     */
    private function getHolidaysIndex(int $year): array
    {
        if (isset($this->cachedHolidaysByYear[$year])) {
            return $this->cachedHolidaysByYear[$year];
        }

        $index = [];

        // FIXOS (Lei 662/1949 + Lei 6802/1980 + Lei 14.759/2023 — Consciência
        // Negra a partir de 2024 inclusive).
        $fixos = [
            '01-01', // Confraternização Universal
            '04-21', // Tiradentes
            '05-01', // Dia do Trabalho
            '09-07', // Independência
            '10-12', // Nossa Senhora Aparecida (Lei 6802/1980)
            '11-02', // Finados
            '11-15', // Proclamação da República
            '12-25', // Natal
        ];
        foreach ($fixos as $md) {
            $index[\sprintf('%04d-%s', $year, $md)] = true;
        }

        // 20/11 — Consciência Negra: Lei 14.759/2023, eficácia desde 2024.
        if ($year >= 2024) {
            $index[\sprintf('%04d-11-20', $year)] = true;
        }

        // MÓVEIS — derivadas da Páscoa (Decisão #1 da Story 4a.2 +
        // tradição forense CPC art. 216).
        $easter = $this->easterSundayFor($year);

        // Sexta-Feira Santa (Páscoa - 2)
        $index[$easter->modify('-2 days')->format('Y-m-d')] = true;

        // Carnaval — segunda + terça (Páscoa - 48 e -47)
        $index[$easter->modify('-48 days')->format('Y-m-d')] = true;
        $index[$easter->modify('-47 days')->format('Y-m-d')] = true;

        // Corpus Christi (Páscoa + 60)
        $index[$easter->modify('+60 days')->format('Y-m-d')] = true;

        $this->cachedHolidaysByYear[$year] = $index;
        return $index;
    }

    /**
     * Garante que retornamos um DateTimeImmutable em America/Sao_Paulo às
     * 00:00:00 (zerar componentes de hora) — assim aritmética de dias é
     * estável em borders de horário de verão / TZ shifts.
     */
    private function normalizeToDate(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date
            ->setTimezone(new DateTimeZone(self::TZ))
            ->setTime(0, 0, 0);
    }
}
