<?php

declare(strict_types=1);

namespace Espo\Modules\TogareDjen\Services;

/**
 * DTO de regra de prazo processual (Story 4a.2 — Decisão #3).
 *
 * Imutável. Carrega 5 campos por entrada do dicionário CPC:
 *  - atoCodigo: identificador snake_case (ex.: 'contestacao').
 *  - dias: número de dias do prazo legal default.
 *  - contagem: 'uteis' (CPC art. 219) ou 'corridos' (caso explícito,
 *    ex.: cumprimento de sentença CPC art. 523).
 *  - referenciaLegal: ex.: "CPC art. 335" — para exibir ao advogado e
 *    para auditoria do parser ("por que dataFatal foi calculada assim?").
 *  - descricao: humano-legível pt-BR para UI futura (CardDePrazo 4a.4).
 */
final readonly class DjenPrazoRule
{
    public function __construct(
        public string $atoCodigo,
        public int $dias,
        public string $contagem,
        public string $referenciaLegal,
        public string $descricao,
    ) {
    }
}
