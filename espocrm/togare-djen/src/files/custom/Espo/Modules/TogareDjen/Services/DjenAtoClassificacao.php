<?php

declare(strict_types=1);

namespace Espo\Modules\TogareDjen\Services;

/**
 * DTO de saída do `DjenAtoClassifier` (Story 4a.2).
 *
 * Imutável (`readonly`) — classifier é puro e seu output não pode ser
 * mutado por consumidores. Consumido por `DjenParserService` para olhar
 * o `DjenPrazoRules` e calcular `dataFatal`.
 *
 * Confidence levels:
 *  - `high`: regex com contexto direcionado (verbo + termo, ex.: "para
 *    contestar"), match único e específico, OU keyword exclusiva de
 *    determinado ato (ex.: "embargos de declaração"). Indica que o ato
 *    está claramente declarado no texto.
 *  - `medium`: keyword única menos específica (ex.: só "contestação"
 *    sem contexto), pode ser ambíguo.
 *  - `low`: nenhum match positivo — fallback `manifestacao_generica`.
 *    Sinaliza que o parser deu o melhor palpite mas o ato não pôde ser
 *    classificado com segurança. Story 4a.3 deve criar Prazo em rascunho
 *    para revisão humana.
 */
final readonly class DjenAtoClassificacao
{
    public function __construct(
        /** Código snake_case do ato (ex.: 'contestacao', 'cumprimento_sentenca'). */
        public string $atoCodigo,
        /** 'high' | 'medium' | 'low'. */
        public string $confidence,
        /** Janela de até 100 chars do texto que originou o match (com '...' nas bordas se truncado). */
        public string $fonteExcerpt,
        /** Regex que disparou o match (debug — útil em logs e testes). Null no fallback. */
        public ?string $matchedPattern = null,
        /**
         * Override do número de dias quando o texto cita explicitamente
         * (ex.: "concedo o prazo de 30 dias para se manifestar" → 30).
         * Aplicado APENAS se atoCodigo === 'manifestacao_geral_intimacao'
         * (Decisão #4 da Story 4a.2 — atos legalmente fixos como
         * contestação ignoram override do texto).
         */
        public ?int $prazoDiasOverride = null,
    ) {
    }
}
