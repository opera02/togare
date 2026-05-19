<?php

declare(strict_types=1);

namespace Espo\Modules\TogareDjen\Services;

use DateTimeImmutable;

/**
 * DTO de saída do `DjenParserService` (Story 4a.2 — Decisão #5).
 *
 * Resultado puro do cálculo art. 5º Res. CNJ 455/2022 + dicionário CPC.
 * Imutável (`readonly`). Story 4a.3 consome este DTO para criar entidade
 * Prazo (com `isRascunho=true` se `confidence==='low'`).
 *
 * Campos:
 *  - dataInicioPrazo: 1º dia útil forense seguinte à disponibilização —
 *    é o **1º dia da contagem** (inclusive). Informativo: a UI 4a.4 usa
 *    para exibir "prazo começou em...". NÃO é uma "publicação efetiva"
 *    intermediária; é só o termo inicial da contagem.
 *  - dataFatal: data limite do prazo (CPC). Para úteis (CPC art. 219):
 *    addBusinessDays(disp, prazoDias). Para corridos (CPC art. 523):
 *    dataInicioPrazo + (prazoDias-1) dias corridos.
 *  - prazoDias: número de dias do prazo aplicado (override do texto OU
 *    valor default do dicionário).
 *  - contagem: 'uteis' (CPC art. 219, padrão) ou 'corridos' (CPC art. 523
 *    cumprimento de sentença).
 *  - atoCodigo: código snake_case do ato classificado.
 *  - referenciaLegal: ex.: "CPC art. 335" — exibido na UI + audit.
 *  - confidence: 'high' | 'medium' | 'low' — herdado da classificação.
 *  - fonteExcerpt: trecho do texto da publicação que originou o match
 *    (≤100 chars com '...' nas bordas truncadas).
 *  - regraVersao: versão do dicionário (`DjenPrazoRules::REGRA_VERSAO`)
 *    no momento do cálculo. Permite re-cálculo histórico quando regra
 *    evoluir (ADR-02).
 *  - sourcePubId: id da publicação Comunica API (debug + correlação).
 */
final readonly class PrazoCalculado
{
    public function __construct(
        public DateTimeImmutable $dataInicioPrazo,
        public DateTimeImmutable $dataFatal,
        public int $prazoDias,
        public string $contagem,
        public string $atoCodigo,
        public string $referenciaLegal,
        public string $confidence,
        public string $fonteExcerpt,
        public string $regraVersao,
        public ?int $sourcePubId = null,
    ) {
    }

    /**
     * @return array<string, mixed> serializado para log / payload de queue.
     */
    public function toArray(): array
    {
        return [
            'dataInicioPrazo' => $this->dataInicioPrazo->format('Y-m-d'),
            'dataFatal' => $this->dataFatal->format('Y-m-d'),
            'prazoDias' => $this->prazoDias,
            'contagem' => $this->contagem,
            'atoCodigo' => $this->atoCodigo,
            'referenciaLegal' => $this->referenciaLegal,
            'confidence' => $this->confidence,
            'fonteExcerpt' => $this->fonteExcerpt,
            'regraVersao' => $this->regraVersao,
            'sourcePubId' => $this->sourcePubId,
        ];
    }
}
