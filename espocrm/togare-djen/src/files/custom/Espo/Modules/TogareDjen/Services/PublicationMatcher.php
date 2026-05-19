<?php

declare(strict_types=1);

namespace Espo\Modules\TogareDjen\Services;

use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * PublicationMatcher — Story 4b.1b / Decisão #2 mãe.
 *
 * Match em 2 fases entre payload DJEN normalizado e Processos cadastrados:
 *
 *  - **Fase 1 (CNJ exato 20 dígitos):** `digitsOnly($payload.numeroProcesso)`;
 *    se exatamente 20 dígitos, query `findOne` em `Processo.numeroCnj`.
 *    Defensivo: também roda `find()->limit(2)` — se 2+ rows, retorna `multiple`
 *    com `ambiguityReason=cnj_multiplos_processos` (UNIQUE em numero_cnj
 *    deveria impedir mas é defensivo contra bugs históricos de seed).
 *  - **Fase 2 (name-match exato):** apenas se Fase 1 retornou 0 hits ou
 *    digitsOnly ≠ 20. Para cada `destinatario.nome` em `payload.destinatarios`
 *    (skip vazios/dups por `mb_strtolower(trim(...))`), query simultânea
 *    `Cliente where name=$nome` + `ParteContraria where name=$nome`.
 *    Para cada Cliente/PC encontrado, agrega Processos distintos via N:N
 *    (`processo->clientes` / `processo->partesContrarias`). De-dup por
 *    `processoId`. Limita pull a `MAX_NAME_MATCH_CANDIDATES + 1` para
 *    detectar `too_many`.
 *
 * Outcomes (`MatchResult::kind`):
 *  | Fase 1 | Fase 2 | kind        | Notas |
 *  |---|---|---|---|
 *  | 1 hit | (skip) | `single` | preserva 4a.3 |
 *  | ≥2 hits | (skip) | `multiple` cnj_multiplos_processos | defensivo |
 *  | 0 hits | 0 hits | `none` | rascunho |
 *  | 0 hits | 1 hit | `single` | NEW — log namematch_resolved |
 *  | 0 hits | 2-5 hits | `multiple` name_match_multiplos_candidatos | caso principal |
 *  | 0 hits | ≥6 hits | `too_many` | rascunho + warning |
 *
 * Denormalização do snapshot `candidatos` (preenchido só em kind=multiple):
 * cada entry tem 8 fields fixos (processoId, numeroCnj, clienteNome,
 * parteContrariaNome, dataDistribuicao, area, fase, codigoCor). Ordem
 * estável por `numeroCnj` ASC para dedup determinístico nos testes e
 * ordering reproduzível na UI.
 *
 * Não-final para mocking direto em testes (mesmo trade-off PrazoCreatorService
 * / AmbiguityResolverService / DjenAdapter / RedisConnection).
 */
class PublicationMatcher
{
    public const MAX_NAME_MATCH_CANDIDATES = 5;
    public const DIGITS_ONLY_CNJ_LEN = 20;

    /** @var list<string> */
    private const CODIGO_COR_PALETTE = ['azul', 'laranja', 'verde', 'roxo', 'vermelho'];

    public function __construct(
        private readonly EntityManager $entityManager,
    ) {
    }

    /**
     * Executa o match.
     *
     * @param array<string, mixed> $payload Normalizado pelo PublicationSourceAdapterContract.
     */
    public function match(array $payload): MatchResult
    {
        $numeroProcessoOriginal = $this->stringOrEmpty($payload, 'numeroProcesso');

        // Fase 1: CNJ exato.
        $cnjResult = $this->matchByCnj($numeroProcessoOriginal);
        if ($cnjResult !== null) {
            return $cnjResult;
        }

        // Fase 2: name-match nos destinatários.
        return $this->matchByDestinatarioNames($payload, $numeroProcessoOriginal);
    }

    /**
     * Fase 1 — CNJ exato 20 dígitos.
     *
     * Retorna:
     *  - `MatchResult::single($processo, ...)` se 1 hit
     *  - `MatchResult::multiple([...], 'cnj_multiplos_processos', ...)` se 2+ hits (defensivo)
     *  - `null` para sinalizar "passar para Fase 2" (digitsOnly ≠ 20 OU 0 hits)
     */
    private function matchByCnj(string $numeroProcesso): ?MatchResult
    {
        if ($numeroProcesso === '') {
            return null;
        }

        $digits = $this->digitsOnly($numeroProcesso);
        if (\strlen($digits) !== self::DIGITS_ONLY_CNJ_LEN) {
            return null;
        }

        $processos = $this->entityManager->getRDBRepository('Processo')
            ->where(['numeroCnj' => $digits])
            ->limit(0, 2)
            ->find();

        $processosArray = $this->collectionToArray($processos);
        $count = \count($processosArray);

        if ($count === 0) {
            return null;
        }

        if ($count === 1) {
            return MatchResult::single($processosArray[0], $numeroProcesso);
        }

        // Defensivo: UNIQUE em numero_cnj deveria impedir 2+ — mas se acontecer,
        // marcamos como ambíguo com reason específico.
        $candidatos = $this->buildCandidatosSnapshot($processosArray);
        return MatchResult::multiple($candidatos, 'cnj_multiplos_processos', $numeroProcesso);
    }

    /**
     * Fase 2 — name-match exato nos destinatários do payload.
     *
     * Para cada nome distinto em `payload.destinatarios[].nome`, agrega
     * Processos via `processo->clientes` (Cliente.name = $nome) +
     * `processo->partesContrarias` (ParteContraria.name = $nome). De-dup
     * por processoId. Limit `MAX_NAME_MATCH_CANDIDATES + 1` para detectar
     * `too_many`.
     */
    private function matchByDestinatarioNames(array $payload, string $numeroProcessoOriginal): MatchResult
    {
        $destinatarios = $payload['destinatarios'] ?? null;
        if (! \is_array($destinatarios) || $destinatarios === []) {
            return MatchResult::none($numeroProcessoOriginal);
        }

        // Coletar nomes distintos (case-insensitive dedup; preserva primeiro casing visto).
        $nomesPorChave = [];
        foreach ($destinatarios as $dest) {
            if (! \is_array($dest)) {
                continue;
            }
            $nome = $dest['nome'] ?? null;
            if (! \is_string($nome)) {
                continue;
            }
            $nomeTrim = \trim($nome);
            if ($nomeTrim === '') {
                continue;
            }
            $key = \mb_strtolower($nomeTrim);
            if (! isset($nomesPorChave[$key])) {
                $nomesPorChave[$key] = $nomeTrim;
            }
        }

        if ($nomesPorChave === []) {
            return MatchResult::none($numeroProcessoOriginal);
        }

        // Coletar processoIds distintos via match em Cliente.name + ParteContraria.name.
        // Limit early via cap = MAX + 1 para detectar too_many sem materializar todos.
        $cap = self::MAX_NAME_MATCH_CANDIDATES + 1;
        $processoIds = [];

        foreach ($nomesPorChave as $nome) {
            // Cliente.name = $nome → processos via processo->clientes (link N:N).
            $clientes = $this->entityManager->getRDBRepository('Cliente')
                ->where(['name' => $nome])
                ->find();
            foreach ($this->collectionToArray($clientes) as $cliente) {
                foreach ($this->collectProcessoIdsFromRelated($cliente, 'processos') as $pid) {
                    if (! isset($processoIds[$pid])) {
                        $processoIds[$pid] = true;
                        if (\count($processoIds) >= $cap) {
                            break 3;
                        }
                    }
                }
            }

            // ParteContraria.name = $nome → processos via processo->partesContrarias.
            $partesContrarias = $this->entityManager->getRDBRepository('ParteContraria')
                ->where(['name' => $nome])
                ->find();
            foreach ($this->collectionToArray($partesContrarias) as $pc) {
                foreach ($this->collectProcessoIdsFromRelated($pc, 'processos') as $pid) {
                    if (! isset($processoIds[$pid])) {
                        $processoIds[$pid] = true;
                        if (\count($processoIds) >= $cap) {
                            break 3;
                        }
                    }
                }
            }
        }

        $count = \count($processoIds);

        if ($count === 0) {
            return MatchResult::none($numeroProcessoOriginal);
        }

        // Carregar Processos completos (ordem estável por numeroCnj ASC).
        $processoIdList = \array_keys($processoIds);
        \sort($processoIdList);

        if ($count > self::MAX_NAME_MATCH_CANDIDATES) {
            TogareLogger::event(
                'warning',
                'djen.match.namematch_too_many',
                'Name-match retornou demasiados candidatos — escala manual; cai em rascunho',
                [
                    'sourcePubId' => $payload['id'] ?? null,
                    'numeroProcessoOriginal' => $numeroProcessoOriginal,
                    'count' => $count,
                    'cap' => self::MAX_NAME_MATCH_CANDIDATES,
                ],
            );
            return MatchResult::tooMany($numeroProcessoOriginal);
        }

        // Carrega entities + ordena por numeroCnj ASC para snapshot determinístico.
        $processos = [];
        foreach ($processoIdList as $pid) {
            $proc = $this->entityManager->getEntityById('Processo', $pid);
            if ($proc !== null) {
                $processos[] = $proc;
            }
        }

        \usort(
            $processos,
            static fn (Entity $a, Entity $b) => \strcmp(
                (string) ($a->get('numeroCnj') ?? ''),
                (string) ($b->get('numeroCnj') ?? ''),
            ),
        );

        if ($processos === []) {
            return MatchResult::none($numeroProcessoOriginal);
        }

        if (\count($processos) === 1) {
            TogareLogger::event(
                'info',
                'djen.match.namematch_resolved',
                'Name-match resolveu publicação para 1 Processo único — Prazo bound',
                [
                    'sourcePubId' => $payload['id'] ?? null,
                    'numeroProcessoOriginal' => $numeroProcessoOriginal,
                    'processoId' => (string) $processos[0]->getId(),
                ],
            );
            return MatchResult::single($processos[0], $numeroProcessoOriginal);
        }

        $candidatos = $this->buildCandidatosSnapshot($processos);
        return MatchResult::multiple($candidatos, 'name_match_multiplos_candidatos', $numeroProcessoOriginal);
    }

    /**
     * Constrói snapshot denormalizado dos candidatos (8 fields cada).
     *
     * @param list<Entity> $processos
     * @return list<array{
     *     processoId: string,
     *     numeroCnj: string,
     *     clienteNome: string,
     *     parteContrariaNome: string,
     *     dataDistribuicao: ?string,
     *     area: ?string,
     *     fase: ?string,
     *     codigoCor: string
     * }>
     */
    private function buildCandidatosSnapshot(array $processos): array
    {
        $snapshot = [];
        $palette = self::CODIGO_COR_PALETTE;
        $paletteLen = \count($palette);

        foreach ($processos as $i => $processo) {
            $clienteNome = $this->resumoNomeRelacionado($processo, 'clientes');
            $parteContrariaNome = $this->resumoNomeRelacionado($processo, 'partesContrarias');

            $snapshot[] = [
                'processoId' => (string) $processo->getId(),
                'numeroCnj' => (string) ($processo->get('numeroCnj') ?? ''),
                'clienteNome' => $clienteNome,
                'parteContrariaNome' => $parteContrariaNome,
                'dataDistribuicao' => $this->dateOrNull($processo, 'dataDistribuicao'),
                'area' => $this->stringFromEntityOrNull($processo, 'area'),
                'fase' => $this->stringFromEntityOrNull($processo, 'fase'),
                'codigoCor' => $palette[$i % $paletteLen],
            ];
        }

        return $snapshot;
    }

    /**
     * Retorna resumo do nome do relacionado (Cliente OR ParteContraria) no Processo:
     *  - 0 → "(sem cliente)" / "(sem parte contrária)"
     *  - 1 → name literal
     *  - 2+ → "(múltiplos)"
     *
     * Pattern espelha `AutoLinkClientHook::collectIds`.
     */
    private function resumoNomeRelacionado(Entity $processo, string $linkName): string
    {
        $defaultLabel = $linkName === 'clientes' ? '(sem cliente)' : '(sem parte contrária)';
        $multiplosLabel = '(múltiplos)';

        $collection = $this->entityManager->getRelation($processo, $linkName)->find();

        $nomes = [];
        foreach ($collection as $item) {
            if ($item instanceof Entity) {
                $nome = $item->get('name');
                if (\is_string($nome) && $nome !== '') {
                    $nomes[] = $nome;
                }
            }
        }

        if ($nomes === []) {
            return $defaultLabel;
        }

        if (\count($nomes) === 1) {
            return $nomes[0];
        }

        return $multiplosLabel;
    }

    /**
     * Coleta processoIds via link N:N reverso (`Cliente.processos` / `ParteContraria.processos`).
     *
     * @return list<string>
     */
    private function collectProcessoIdsFromRelated(Entity $related, string $linkName): array
    {
        $collection = $this->entityManager->getRelation($related, $linkName)->find();

        $ids = [];
        foreach ($collection as $proc) {
            if ($proc instanceof Entity) {
                $id = $proc->getId();
                if (\is_string($id) && $id !== '') {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    /**
     * Converte Collection (`find()`) ou Entity única (`findOne()`) para array.
     *
     * @return list<Entity>
     */
    private function collectionToArray(mixed $collection): array
    {
        if ($collection === null) {
            return [];
        }

        if ($collection instanceof Entity) {
            return [$collection];
        }

        if (! \is_iterable($collection)) {
            return [];
        }

        $list = [];
        foreach ($collection as $item) {
            if ($item instanceof Entity) {
                $list[] = $item;
            }
        }
        return $list;
    }

    /**
     * Extrai apenas dígitos de string (regex `[^0-9]`). Espelha
     * `PrazoCreatorService::digitsOnly` — não reusar para evitar acoplamento.
     */
    public function digitsOnly(string $value): string
    {
        return (string) \preg_replace('/[^0-9]/', '', $value);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function stringOrEmpty(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (\is_string($value)) {
            return \trim($value);
        }
        if (\is_scalar($value)) {
            return (string) $value;
        }
        return '';
    }

    private function dateOrNull(Entity $entity, string $attr): ?string
    {
        $value = $entity->get($attr);
        if (! \is_string($value) || $value === '') {
            return null;
        }
        return $value;
    }

    private function stringFromEntityOrNull(Entity $entity, string $attr): ?string
    {
        $value = $entity->get($attr);
        if (! \is_string($value) || $value === '') {
            return null;
        }
        return $value;
    }
}
