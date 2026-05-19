<?php

declare(strict_types=1);

namespace Espo\Modules\TogareDjen\Services;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Modules\TogareCore\Services\Calendar\BrazilianBusinessCalendar;
use Espo\Modules\TogareCore\Services\TogareLogger;
use InvalidArgumentException;
use Throwable;

/**
 * Parser de publicações DJEN aplicando regra do art. 5º Res. CNJ 455/2022
 * (Story 4a.2 — núcleo jurídico do módulo togare-djen).
 *
 * **Regra do art. 5º (literal):**
 *
 *   "Considera-se data de publicação o primeiro dia útil seguinte à data
 *    de disponibilização da informação no Diário de Justiça Eletrônico
 *    nacional."
 *
 * **Regra inclusiva** (confirmada por Felipe 2026-05-03): disponibilização
 * NÃO conta no prazo, mas o D+1 dia útil forense É O 1º DIA DA CONTAGEM
 * INCLUSIVE. Em pseudo-código:
 *
 *   dataInicioPrazo = nextBusinessDay(dataDisponibilizacao)   // 1º dia inclusive (informativo)
 *   dataFatal = (contagem === 'uteis')
 *       ? addBusinessDays(dataDisponibilizacao, prazoDias)    // direto — sem passo intermediário
 *       : addCalendarDays(dataInicioPrazo, prazoDias - 1)     // corridos: 1º dia + (N-1)
 *
 * Para úteis, `addBusinessDays(disp, N)` direto já implementa a regra
 * inclusiva — a semântica exclusiva do método ("n-ésimo útil DEPOIS de
 * start") é equivalente a "1º útil seguinte = dia 1 + (N-1) úteis
 * adicionais" quando start = dataDisponibilizacao.
 *
 * **Service puro (Decisão #5):**
 * - Zero dependência de DB / EntityManager / HTTP.
 * - 3 deps puras no construtor: BrazilianBusinessCalendar (togare-core
 *   0.16.0+), DjenAtoClassifier, DjenPrazoRules.
 * - Idempotente: mesmo input → mesmo output.
 * - Não loga fluxo normal; emite apenas warning auditável quando o texto
 *   cita prazo divergente da regra legal fixa.
 *
 * **Override de prazo do texto** (Decisão #4): se a classificação retornou
 * `prazoDiasOverride` E `atoCodigo === 'manifestacao_geral_intimacao'`,
 * o parser usa o valor do texto. Em outros atos, override é IGNORADO
 * (lei prevalece sobre redação imprecisa do despacho).
 *
 * **Detecção de override conflitante** (lei sobrepõe texto): se o ato é
 * legalmente fixo (ex.: contestação 15 úteis CPC 335) E classificação
 * retornou `prazoDiasOverride` diferente do default → loga warning
 * `djen.parser.text_overrides_law` para Sócio/Admin investigar.
 */
final class DjenParserService
{
    public function __construct(
        private readonly BrazilianBusinessCalendar $calendar,
        private readonly DjenAtoClassifier $classifier,
        private readonly DjenPrazoRules $rules,
    ) {
    }

    /**
     * Calcula a data fatal de uma publicação DJEN.
     *
     * @param array{
     *     id?: int,
     *     texto?: string,
     *     dataDisponibilizacao?: string,
     *     tipoComunicacao?: string,
     *     tipoDocumento?: string,
     *     ...
     * } $payload
     * @return PrazoCalculado|null null se publicação for puramente certificatória
     *                              (sem prazo). Caso conservador (sem keyword
     *                              positiva nem negativa) ainda retorna
     *                              PrazoCalculado com confidence=low.
     * @throws InvalidArgumentException se dataDisponibilizacao ausente/inválida.
     */
    public function parse(array $payload): ?PrazoCalculado
    {
        $dataDispStr = (string) ($payload['dataDisponibilizacao'] ?? '');
        if ($dataDispStr === '') {
            throw new InvalidArgumentException(
                'DjenParserService.parse: dataDisponibilizacao é obrigatória no payload',
            );
        }

        $dataDisp = $this->safeParseDate($dataDispStr);
        if ($dataDisp === null) {
            throw new InvalidArgumentException(
                "DjenParserService.parse: dataDisponibilizacao inválida ('{$dataDispStr}')",
            );
        }

        $classificacao = $this->classifier->classify($payload);
        if ($classificacao === null) {
            return null; // ato puramente certificatório
        }

        $rule = $this->rules->lookupOrFallback($classificacao->atoCodigo);

        // Decisão #4: override de prazo só vale para manifestacao_geral_intimacao.
        // Para outros atos, override do texto é IGNORADO (lei prevalece) E,
        // se houver discrepância, loga warning para auditoria.
        $usaOverride = (
            $classificacao->prazoDiasOverride !== null
            && $classificacao->atoCodigo === 'manifestacao_geral_intimacao'
        );

        if (
            $classificacao->prazoDiasOverride !== null
            && ! $usaOverride
            && $classificacao->prazoDiasOverride !== $rule->dias
        ) {
            // Texto fala "30 dias para contestar" mas lei manda 15 (CPC 335).
            // Parser aplica 15 e sinaliza para revisão humana.
            try {
                TogareLogger::event(
                    'warning',
                    'djen.parser.text_overrides_law',
                    "Texto da publicação cita prazo divergente da lei aplicável (atoCodigo={$rule->atoCodigo}). Parser aplicou prazo legal.",
                    [
                        'pubId' => $payload['id'] ?? null,
                        'atoCodigo' => $rule->atoCodigo,
                        'prazoTextoLido' => $classificacao->prazoDiasOverride,
                        'prazoLeiAplicada' => $rule->dias,
                        'referenciaLegal' => $rule->referenciaLegal,
                        'fonteExcerpt' => $classificacao->fonteExcerpt,
                    ],
                );
            } catch (Throwable) {
                // Logger é stateless mas pode não estar configurado em testes
                // standalone — falha de log nunca trava parser.
            }
        }

        $prazoDias = $usaOverride ? $classificacao->prazoDiasOverride : $rule->dias;
        if ($prazoDias === null || $prazoDias <= 0) {
            // Defensive — não deveria ocorrer (DjenPrazoRules valida construtor).
            $prazoDias = $rule->dias;
        }

        // `dataInicioPrazo` = 1º dia útil forense **seguinte** à disponibilização.
        // É o **1º dia da contagem** (inclusive). Mantido como informativo no DTO
        // para a UI mostrar "prazo começou em..." (4a.4 CardDePrazo). NÃO é uma
        // "publicação efetiva" intermediária — a contagem do prazo a partir daqui
        // é o que importa juridicamente.
        $dataInicioPrazo = $this->calendar->nextBusinessDay($dataDisp);

        // **Cálculo da dataFatal (regra do art. 5º Res. CNJ 455 + CPC):**
        // - Úteis (CPC art. 219, padrão): `addBusinessDays(disp, N)` direto.
        //   A semântica exclusiva do método (n-ésimo útil DEPOIS de start) já é
        //   equivalente a "1º útil seguinte como dia 1, +(N-1) úteis adicionais".
        //   Ex.: disp=08, N=5 → conta 09(feriado pulado), 10(1), 13(2), 14(3),
        //   15(4), 16(5) → fatal=16.
        // - Corridos (CPC art. 523 cumprimento de sentença): início em
        //   `nextBusinessDay(disp)` + (N-1) corridos a partir daí inclusive.
        //   Ex.: disp=15/05 sex → início=18/05 seg → +14 corridos = 01/06 seg.
        $dataFatal = match ($rule->contagem) {
            DjenPrazoRules::CONTAGEM_UTEIS => $this->calendar->addBusinessDays($dataDisp, $prazoDias),
            DjenPrazoRules::CONTAGEM_CORRIDOS => $this->calendar->addCalendarDays($dataInicioPrazo, $prazoDias - 1),
            default => throw new \LogicException(
                "DjenPrazoRules retornou contagem inválida: {$rule->contagem}",
            ),
        };

        return new PrazoCalculado(
            dataInicioPrazo: $dataInicioPrazo,
            dataFatal: $dataFatal,
            prazoDias: $prazoDias,
            contagem: $rule->contagem,
            atoCodigo: $rule->atoCodigo,
            referenciaLegal: $rule->referenciaLegal,
            confidence: $classificacao->confidence,
            fonteExcerpt: $classificacao->fonteExcerpt,
            regraVersao: DjenPrazoRules::REGRA_VERSAO,
            sourcePubId: isset($payload['id']) && \is_int($payload['id']) ? $payload['id'] : null,
        );
    }

    /**
     * Converte string YYYY-MM-DD em DateTimeImmutable (TZ America/Sao_Paulo)
     * ou retorna null se formato inválido.
     */
    private function safeParseDate(string $iso): ?DateTimeImmutable
    {
        try {
            $tz = new DateTimeZone('America/Sao_Paulo');
            $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $iso, $tz);
            if ($dt === false) {
                return null;
            }
            // Reject inputs com componentes inesperados (ex.: "2026-13-01" silenciosamente normalizado).
            if ($dt->format('Y-m-d') !== $iso) {
                return null;
            }
            return $dt;
        } catch (Throwable) {
            return null;
        }
    }
}
