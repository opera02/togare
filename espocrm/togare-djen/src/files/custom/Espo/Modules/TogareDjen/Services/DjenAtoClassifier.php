<?php

declare(strict_types=1);

namespace Espo\Modules\TogareDjen\Services;

/**
 * Classificador determinístico de ato processual a partir do texto da
 * publicação DJEN (Story 4a.2 — Decisão #2: ZERO IA/LLM por princípio
 * CLAUDE.md "IA não trava o bot" + PRD §3.4).
 *
 * Estratégia:
 *  1. Aplica patterns POSITIVOS por ordem de especificidade decrescente
 *     (HIGH primeiro, depois MEDIUM). Primeiro match retorna.
 *  2. Se nenhum positivo bater, aplica patterns NEGATIVOS (atos puramente
 *     certificatórios — "Junte-se", "Cumpra-se", "Publique-se", "Conclusos").
 *     Se algum negativo casar → retorna null (publicação sem prazo).
 *  3. Caso conservador: nenhum positivo, nenhum negativo → retorna fallback
 *     `manifestacao_generica` com confidence=low (parser dá um palpite, 4a.3
 *     decide se cria Prazo em rascunho).
 *
 * Multi-sinal: usa `texto` como fonte primária. `tipoComunicacao` e
 * `tipoDocumento` da Comunica API são sinais secundários (na fixture real
 * 32 pubs todas têm `tipoComunicacao='Intimação'` — fraca discriminação).
 *
 * Determinístico: mesma entrada → mesma saída. Auditável (regex em código).
 *
 * Service stateless — pode ser instanciado sem dependências.
 */
final class DjenAtoClassifier
{
    private const FONTE_EXCERPT_MAX_CHARS = 100;
    private const FONTE_EXCERPT_PADDING = 40;

    /**
     * Patterns positivos em ordem de especificidade DECRESCENTE.
     *
     * Cada entrada:
     *   - atoCodigo: código snake_case (deve existir em DjenPrazoRules).
     *   - confidence: 'high' | 'medium'.
     *   - regex: PCRE com flags i (case insensitive) e u (UTF-8).
     *   - capturesPrazo: bool — se true, group 1 captura nº de dias para override.
     *
     * @var list<array{atoCodigo: string, confidence: string, regex: string, capturesPrazo?: bool}>
     */
    private const POSITIVE_PATTERNS = [
        // ===== HIGH — contexto direcionado / keyword exclusiva =====

        // Impugnação ao cumprimento (15 úteis CPC 525).
        [
            'atoCodigo' => 'impugnacao_cumprimento',
            'confidence' => 'high',
            'regex' => '/impugna[cç][ãa]o\s+(?:ao|do)\s+cumprimento/iu',
        ],

        // Cumprimento de sentença (15 corridos CPC 523). Exige sinal
        // contextual de pagamento voluntário/art. 523 para não confundir
        // classe processual no cabeçalho com intimação de prazo.
        [
            'atoCodigo' => 'cumprimento_sentenca',
            'confidence' => 'high',
            'regex' => '/(?:pagamento\s+volunt[aá]rio|art\.?\s*523|multa\s+de\s+10|honor[aá]rios\s+de\s+10|cumprimento\s+de\s+senten[cç]a[^.]{0,120}(?:pagamento\s+volunt[aá]rio|art\.?\s*523|multa\s+de\s+10))/iu',
        ],

        // Embargos de declaração (5 úteis CPC 1023).
        [
            'atoCodigo' => 'embargos_declaracao',
            'confidence' => 'high',
            'regex' => '/embargos\s+de\s+declara[cç][ãa]o/iu',
        ],

        // Agravo de instrumento (15 úteis CPC 1003 §5º).
        [
            'atoCodigo' => 'agravo_instrumento',
            'confidence' => 'high',
            'regex' => '/agravo\s+de\s+instrumento/iu',
        ],

        // Agravo interno (15 úteis CPC 1021).
        [
            'atoCodigo' => 'agravo_interno',
            'confidence' => 'high',
            'regex' => '/agravo\s+interno/iu',
        ],

        // Quesitos / parecer pericial (15 úteis CPC 465 §1º).
        [
            'atoCodigo' => 'quesitos_pericia',
            'confidence' => 'high',
            'regex' => '/(?:apresent\w+\s+quesitos|parecer\s+pericial|indica[cç][ãa]o\s+de\s+assistente\s+t[ée]cnico)/iu',
        ],

        // Réplica à contestação (15 úteis CPC 350).
        [
            'atoCodigo' => 'replica',
            'confidence' => 'high',
            'regex' => '/r[ée]plica\b/iu',
        ],

        // Recurso de apelação (15 úteis CPC 1003) — HIGH com contexto direcionado.
        [
            'atoCodigo' => 'recurso_apelacao',
            'confidence' => 'high',
            'regex' => '/(?:recurso\s+de\s+apela[cç][ãa]o|para\s+apelar|interpor\s+apela[cç][ãa]o)/iu',
        ],

        // Contestação HIGH com contexto direcionado (verbo + termo).
        [
            'atoCodigo' => 'contestacao',
            'confidence' => 'high',
            'regex' => '/(?:para\s+(?:apresentar\s+)?contesta[rç]|para\s+oferecer\s+contesta[cç][ãa]o|prazo[^.]{0,40}contesta[cç][ãa]o|contesta[cç][ãa]o\s+no\s+prazo)/iu',
        ],

        // Manifestação geral COM extração de número de dias - HIGH.
        // Captura group 1 = número de dias (override).
        // Cobre frases reais da fixture: "concedendo o prazo de 15 dias para se manifestar",
        // "concedo o prazo de 30 dias para que as partes apresentem".
        [
            'atoCodigo' => 'manifestacao_geral_intimacao',
            'confidence' => 'high',
            'regex' => '/prazo\s+de\s+(\d{1,3})\s+dias?\s+para\s+(?:que\s+(?:a\s+|as\s+)?partes?\s+(?:apresentem|se\s+manifestem)|se\s+manifestar|manifesta[cç][ãa]o)/iu',
            'capturesPrazo' => true,
        ],

        // ===== MEDIUM — keywords menos específicas =====

        // Apelação MEDIUM.
        [
            'atoCodigo' => 'recurso_apelacao',
            'confidence' => 'medium',
            'regex' => '/apela[cç][ãa]o/iu',
        ],

        // Contestação MEDIUM (keyword única).
        [
            'atoCodigo' => 'contestacao',
            'confidence' => 'medium',
            'regex' => '/contesta[cç][ãa]o/iu',
        ],

        // Quesitos MEDIUM (keyword única).
        [
            'atoCodigo' => 'quesitos_pericia',
            'confidence' => 'medium',
            'regex' => '/quesitos/iu',
        ],

        // Manifestação SEM extração de prazo - MEDIUM.
        [
            'atoCodigo' => 'manifestacao_geral_intimacao',
            'confidence' => 'medium',
            'regex' => '/(?:para\s+se\s+manifestar(?:em)?|manifestem-se|para\s+manifesta[cç][ãa]o|para\s+que\s+(?:a\s+|as\s+)?partes?\s+(?:apresentem|se\s+manifestem))/iu',
        ],
    ];

    /**
     * Patterns negativos — atos puramente certificatórios SEM prazo.
     *
     * Aplicados APÓS positivos, e SOMENTE se nenhum positivo bater.
     * Match em qualquer parte do texto → null (publicação sem prazo).
     *
     * Caso conservador: se nem positivo nem negativo bater → fallback
     * `manifestacao_generica` low.
     *
     * @var list<string>
     */
    private const NEGATIVE_PATTERNS = [
        '/junte[\s\-]?se/iu',
        '/cumpra[\s\-]?se/iu',
        '/publique[\s\-]?se/iu',
        '/d[êe][\s\-]?se\s+ci[êe]ncia/iu',
        '/conclus(?:os|o|ão|oes|ões)\s+(?:(?:ao|aos|os)\s+)?aut/iu',
        '/voltem(?:[\s\-]?me)?\s+os\s+autos\s+conclus/iu',
        '/arquive[\s\-]?se/iu',
        '/ato\s+ordinat[oó]rio/iu',
        '/intime-se\s+(?:apenas\s+)?para\s+ci[êe]ncia/iu',
        '/vista\s+[àa]s?\s+partes?\s+para\s+(?:conhecimento|ci[êe]ncia)\s*\.?\s*$/iu',
    ];

    /**
     * Classifica o ato processual a partir do payload da publicação.
     *
     * @param array{texto?: string, tipoComunicacao?: string, tipoDocumento?: string, ...} $payload
     * @return DjenAtoClassificacao|null null para atos puramente certificatórios.
     */
    public function classify(array $payload): ?DjenAtoClassificacao
    {
        $texto = $this->normalizeTexto((string) ($payload['texto'] ?? ''));
        $tipoDocumento = $this->normalizeTexto((string) ($payload['tipoDocumento'] ?? ''));
        $hasExplicitPrazoSignal = $this->hasExplicitPrazoSignal($texto);
        if ($texto === '') {
            return $this->isAtoOrdinatorio($tipoDocumento) ? null : $this->fallbackManifestacaoGenerica('');
        }

        // Ato ordinatório só vira prazo quando há sinal explícito de prazo/ato.
        // Evita falso positivo em cabeçalhos HTML com classe processual
        // ("Apelação", "Cumprimento de sentença") mas sem comando de prazo.
        if ($this->isAtoOrdinatorio($tipoDocumento) && ! $hasExplicitPrazoSignal) {
            return null;
        }

        // 1. Tenta patterns positivos em ordem.
        foreach (self::POSITIVE_PATTERNS as $pattern) {
            $matches = [];
            if (\preg_match($pattern['regex'], $texto, $matches, \PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            $matchOffset = $matches[0][1];
            $matchLen = \strlen($matches[0][0]);
            $excerpt = $this->extractFonteExcerpt($texto, $matchOffset, $matchLen);

            // Quando capturesPrazo=true, o grupo 1 do regex JÁ capturou o número de
            // dias — usa-o diretamente (PREG_OFFSET_CAPTURE → $matches[1][0]).
            // Isso evita ambiguidade em textos com múltiplos "prazo de N dias":
            // extractPrazoDiasOverride retornaria o PRIMEIRO encontrado, não
            // necessariamente o que disparou o padrão.
            if (($pattern['capturesPrazo'] ?? false) && isset($matches[1][0])) {
                $captured = (int) $matches[1][0];
                $override = ($captured > 0 && $captured <= 365) ? $captured : null;
            } else {
                $override = $this->extractPrazoDiasOverride($texto);
            }

            return new DjenAtoClassificacao(
                atoCodigo: $pattern['atoCodigo'],
                confidence: $pattern['confidence'],
                fonteExcerpt: $excerpt,
                matchedPattern: $pattern['regex'],
                prazoDiasOverride: $override,
            );
        }

        // 2. Sem positivo: tenta NEGATIVOS para descartar como ato sem prazo.
        if (! $hasExplicitPrazoSignal) {
            foreach (self::NEGATIVE_PATTERNS as $negRegex) {
                if (\preg_match($negRegex, $texto) === 1) {
                    return null; // ato puramente certificatório
                }
            }
        }

        // 3. Caso conservador: nenhum positivo, nenhum negativo → fallback low.
        return $this->fallbackManifestacaoGenerica($texto);
    }

    private function normalizeTexto(string $texto): string
    {
        $texto = \html_entity_decode(\strip_tags($texto), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $texto = (string) \preg_replace('/\s+/u', ' ', $texto);
        return \trim($texto);
    }

    private function isAtoOrdinatorio(string $tipoDocumento): bool
    {
        return \preg_match('/ato\s+ordinat[oó]rio/iu', $tipoDocumento) === 1;
    }

    private function hasExplicitPrazoSignal(string $texto): bool
    {
        return \preg_match(
            '/(?:\bprazo\b|\bdias?\b|para\s+(?:apresentar|oferecer|interpor|opor|contestar|apelar|impugnar|se\s+manifestar)|manifestem-se|pagamento\s+volunt[aá]rio|embargos\s+de\s+declara[cç][ãa]o|agravo\s+(?:de\s+instrumento|interno)|quesitos|parecer\s+pericial|r[ée]plica)/iu',
            $texto,
        ) === 1;
    }

    private function extractPrazoDiasOverride(string $texto): ?int
    {
        $matches = [];
        if (\preg_match('/(?:no\s+)?prazo\s+de\s+(\d{1,3})\s+dias?/iu', $texto, $matches) !== 1) {
            return null;
        }

        $captured = (int) $matches[1];
        return $captured > 0 && $captured <= 365 ? $captured : null;
    }

    /**
     * Extrai janela de até FONTE_EXCERPT_MAX_CHARS chars do `$texto` ao redor
     * do match. Adiciona '...' no início/fim se truncado.
     */
    private function extractFonteExcerpt(string $texto, int $matchOffset, int $matchLen): string
    {
        $totalLen = $this->utf8Length($texto);
        if ($totalLen <= self::FONTE_EXCERPT_MAX_CHARS) {
            return $texto;
        }

        $matchStart = $this->byteOffsetToCharOffset($texto, $matchOffset);
        $matchLenChars = $this->utf8Length(\substr($texto, $matchOffset, $matchLen));
        $matchEnd = $matchStart + $matchLenChars;

        $start = \max(0, $matchStart - self::FONTE_EXCERPT_PADDING);
        $end = \min($totalLen, $matchEnd + self::FONTE_EXCERPT_PADDING);

        $prefixLen = $start > 0 ? 3 : 0;
        $suffixLen = $end < $totalLen ? 3 : 0;
        $available = self::FONTE_EXCERPT_MAX_CHARS - $prefixLen - $suffixLen;

        if (($end - $start) > $available) {
            $context = \max(0, $available - $matchLenChars);
            $before = intdiv($context, 2);
            $start = \max(0, $matchStart - $before);
            $end = \min($totalLen, $start + $available);

            if ($end < $matchEnd) {
                $end = $matchEnd;
                $start = \max(0, $end - $available);
            }
        }

        $excerpt = $this->utf8Substring($texto, $start, $end - $start);
        $prefix = $start > 0 ? '...' : '';
        $suffix = $end < $totalLen ? '...' : '';
        return $prefix . $excerpt . $suffix;
    }

    private function fallbackManifestacaoGenerica(string $texto): DjenAtoClassificacao
    {
        $excerpt = $this->truncateFonteExcerpt($texto);
        return new DjenAtoClassificacao(
            atoCodigo: 'manifestacao_generica',
            confidence: 'low',
            fonteExcerpt: $excerpt,
            matchedPattern: null,
            prazoDiasOverride: null,
        );
    }

    private function truncateFonteExcerpt(string $texto): string
    {
        if ($this->utf8Length($texto) <= self::FONTE_EXCERPT_MAX_CHARS) {
            return $texto;
        }

        return $this->utf8Substring($texto, 0, self::FONTE_EXCERPT_MAX_CHARS - 3) . '...';
    }

    private function byteOffsetToCharOffset(string $texto, int $byteOffset): int
    {
        return $this->utf8Length(\substr($texto, 0, $byteOffset));
    }

    private function utf8Length(string $texto): int
    {
        if (\function_exists('mb_strlen')) {
            return \mb_strlen($texto, 'UTF-8');
        }

        $chars = [];
        return \preg_match_all('/./us', $texto, $chars) ?: \strlen($texto);
    }

    private function utf8Substring(string $texto, int $start, ?int $length = null): string
    {
        if (\function_exists('mb_substr')) {
            return \mb_substr($texto, $start, $length, 'UTF-8');
        }

        $chars = [];
        if (\preg_match_all('/./us', $texto, $chars) === false) {
            return $length === null ? \substr($texto, $start) : \substr($texto, $start, $length);
        }

        return \implode('', \array_slice($chars[0], $start, $length));
    }
}
