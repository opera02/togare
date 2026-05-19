<?php

declare(strict_types=1);

namespace Espo\Modules\TogareDjen\Services;

use InvalidArgumentException;

/**
 * Dicionário estático de regras de prazo processual brasileiras
 * (Story 4a.2 — Decisão #3).
 *
 * 11 atos cobertos no MVP, base CPC. Override por escritório fica para
 * Growth (não-blocking — defaults CPC cobrem 95% dos casos).
 *
 * **Versionamento:** `REGRA_VERSAO` semver:
 *   - `1.0.0` — esta story (release inicial).
 *   - `1.x.0` — minor: nova entrada adicionada (não-breaking).
 *   - `2.0.0` — major: dias/contagem de entrada existente mudou (breaking
 *     — força re-cálculo de payloads históricos via job housekeeping).
 *
 * Cada `PrazoCalculado` produzido pelo parser carrega `regraVersao` no
 * payload de log — quando regra evoluir, admin pode re-processar
 * publicações antigas usando a versão atual.
 *
 * @see ADR-02-djen-parser-regra-evolucao.md
 */
final class DjenPrazoRules
{
    public const REGRA_VERSAO = '1.0.0';

    public const ATO_FALLBACK = 'manifestacao_generica';

    public const CONTAGEM_UTEIS = 'uteis';
    public const CONTAGEM_CORRIDOS = 'corridos';

    /**
     * Mapa estático ato → (dias, contagem, referência legal CPC, descrição).
     *
     * @var array<string, array{dias: int, contagem: string, referencia: string, descricao: string}>
     */
    private const RULES = [
        'contestacao' => [
            'dias' => 15,
            'contagem' => self::CONTAGEM_UTEIS,
            'referencia' => 'CPC art. 335',
            'descricao' => 'Contestação no procedimento comum',
        ],
        'recurso_apelacao' => [
            'dias' => 15,
            'contagem' => self::CONTAGEM_UTEIS,
            'referencia' => 'CPC art. 1003',
            'descricao' => 'Apelação',
        ],
        'embargos_declaracao' => [
            'dias' => 5,
            'contagem' => self::CONTAGEM_UTEIS,
            'referencia' => 'CPC art. 1023',
            'descricao' => 'Embargos de declaração',
        ],
        'agravo_instrumento' => [
            'dias' => 15,
            'contagem' => self::CONTAGEM_UTEIS,
            'referencia' => 'CPC art. 1003 §5º',
            'descricao' => 'Agravo de instrumento',
        ],
        'agravo_interno' => [
            'dias' => 15,
            'contagem' => self::CONTAGEM_UTEIS,
            'referencia' => 'CPC art. 1021',
            'descricao' => 'Agravo interno',
        ],
        'cumprimento_sentenca' => [
            'dias' => 15,
            'contagem' => self::CONTAGEM_CORRIDOS,
            'referencia' => 'CPC art. 523',
            'descricao' => 'Cumprimento de sentença - pagamento voluntário',
        ],
        'impugnacao_cumprimento' => [
            'dias' => 15,
            'contagem' => self::CONTAGEM_UTEIS,
            'referencia' => 'CPC art. 525',
            'descricao' => 'Impugnação ao cumprimento de sentença',
        ],
        'replica' => [
            'dias' => 15,
            'contagem' => self::CONTAGEM_UTEIS,
            'referencia' => 'CPC art. 350',
            'descricao' => 'Réplica à contestação',
        ],
        'quesitos_pericia' => [
            'dias' => 15,
            'contagem' => self::CONTAGEM_UTEIS,
            'referencia' => 'CPC art. 465 §1º',
            'descricao' => 'Apresentação de quesitos / indicação de assistente técnico',
        ],
        'manifestacao_geral_intimacao' => [
            'dias' => 15,
            'contagem' => self::CONTAGEM_UTEIS,
            'referencia' => 'CPC art. 218',
            'descricao' => 'Manifestação por intimação genérica',
        ],
        'manifestacao_generica' => [
            'dias' => 15,
            'contagem' => self::CONTAGEM_UTEIS,
            'referencia' => 'CPC art. 218',
            'descricao' => 'Manifestação genérica (fallback)',
        ],
    ];

    public function __construct()
    {
        // Validação defensiva no boot (fail-fast em config inconsistente).
        foreach (self::RULES as $atoCodigo => $rule) {
            if ($rule['dias'] <= 0) {
                throw new InvalidArgumentException(
                    "DjenPrazoRules: ato '{$atoCodigo}' com dias <= 0",
                );
            }
            if (! \in_array($rule['contagem'], [self::CONTAGEM_UTEIS, self::CONTAGEM_CORRIDOS], true)) {
                throw new InvalidArgumentException(
                    "DjenPrazoRules: ato '{$atoCodigo}' com contagem inválida ('{$rule['contagem']}')",
                );
            }
        }
    }

    /**
     * Retorna a regra para o ato, ou null se inexistente.
     */
    public function lookup(string $atoCodigo): ?DjenPrazoRule
    {
        if (! isset(self::RULES[$atoCodigo])) {
            return null;
        }
        $r = self::RULES[$atoCodigo];
        return new DjenPrazoRule(
            atoCodigo: $atoCodigo,
            dias: $r['dias'],
            contagem: $r['contagem'],
            referenciaLegal: $r['referencia'],
            descricao: $r['descricao'],
        );
    }

    /**
     * Retorna a regra para o ato, ou o fallback `manifestacao_generica`
     * (15 úteis CPC art. 218) se inexistente.
     */
    public function lookupOrFallback(string $atoCodigo): DjenPrazoRule
    {
        return $this->lookup($atoCodigo) ?? $this->lookup(self::ATO_FALLBACK)
            ?? throw new \LogicException(
                'DjenPrazoRules sem entrada de fallback ' . self::ATO_FALLBACK,
            );
    }

    /**
     * @return list<string>
     */
    public function listAtoCodigos(): array
    {
        return \array_keys(self::RULES);
    }
}
