<?php

declare(strict_types=1);

namespace Espo\Modules\TogareDjen\Services;

use Espo\ORM\Entity;

/**
 * DTO de saída do `PublicationMatcher` (Story 4b.1b — Decisão #2 mãe).
 *
 * Imutável (`readonly`). Representa 1 dos 4 resultados do match em 2 fases
 * (CNJ exato → name-match exato):
 *
 *  - `single`     — 1 Processo identificado (Fase 1 1 hit OU Fase 2 1 hit
 *                   distinct após dedup Cliente+ParteContraria do mesmo Processo).
 *                   `processo` preenchido. `candidatos` vazio.
 *  - `none`       — Fase 1 0 hits + Fase 2 0 hits. Cai em rascunho.
 *  - `multiple`   — 2-5 Processos candidatos distintos. `candidatos` array com
 *                   2..5 entries denormalizadas (8 fields cada).
 *                   `ambiguityReason` = `cnj_multiplos_processos` (defensivo;
 *                   raríssimo) OU `name_match_multiplos_candidatos` (caso
 *                   principal — Fase 2).
 *  - `too_many`   — Fase 2 ≥6 hits. Cai em rascunho com warning log
 *                   (escala manual; escritório com cliente/parte recorrente
 *                   em muitos processos).
 *
 * `numeroProcessoOriginal` é o `payload.numeroProcesso` cru (pode ser malformado
 * ou vazio) — preservado para snapshot na PublicacaoAmbigua.
 */
final readonly class MatchResult
{
    /**
     * @param list<array{
     *     processoId: string,
     *     numeroCnj: string,
     *     clienteNome: string,
     *     parteContrariaNome: string,
     *     dataDistribuicao: ?string,
     *     area: ?string,
     *     fase: ?string,
     *     codigoCor: string
     * }> $candidatos
     */
    public function __construct(
        public string $kind,
        public ?Entity $processo,
        public array $candidatos,
        public ?string $ambiguityReason,
        public string $numeroProcessoOriginal,
    ) {
    }

    public static function single(Entity $processo, string $numeroProcessoOriginal): self
    {
        return new self(
            kind: 'single',
            processo: $processo,
            candidatos: [],
            ambiguityReason: null,
            numeroProcessoOriginal: $numeroProcessoOriginal,
        );
    }

    public static function none(string $numeroProcessoOriginal): self
    {
        return new self(
            kind: 'none',
            processo: null,
            candidatos: [],
            ambiguityReason: null,
            numeroProcessoOriginal: $numeroProcessoOriginal,
        );
    }

    /**
     * @param list<array{
     *     processoId: string,
     *     numeroCnj: string,
     *     clienteNome: string,
     *     parteContrariaNome: string,
     *     dataDistribuicao: ?string,
     *     area: ?string,
     *     fase: ?string,
     *     codigoCor: string
     * }> $candidatos
     */
    public static function multiple(array $candidatos, string $ambiguityReason, string $numeroProcessoOriginal): self
    {
        return new self(
            kind: 'multiple',
            processo: null,
            candidatos: $candidatos,
            ambiguityReason: $ambiguityReason,
            numeroProcessoOriginal: $numeroProcessoOriginal,
        );
    }

    public static function tooMany(string $numeroProcessoOriginal): self
    {
        return new self(
            kind: 'too_many',
            processo: null,
            candidatos: [],
            ambiguityReason: null,
            numeroProcessoOriginal: $numeroProcessoOriginal,
        );
    }
}
