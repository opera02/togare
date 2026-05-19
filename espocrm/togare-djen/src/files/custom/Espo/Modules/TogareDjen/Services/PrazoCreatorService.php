<?php

declare(strict_types=1);

namespace Espo\Modules\TogareDjen\Services;

use Espo\Modules\TogareCore\Entities\Prazo;
use Espo\Modules\TogareCore\Entities\PublicacaoAmbigua;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PDOException;
use Throwable;

/**
 * PrazoCreatorService — Story 4a.3 + Story 4b.1b refactor.
 *
 * Toma o `PrazoCalculado` DTO (output do DjenParserService — Story 4a.2) +
 * payload normalizado da publicação (DTO PublicationSourceAdapterContract —
 * Story 4a.1) e ramifica em 1 dos 4 outcomes (`CreationResult`):
 *
 *  - `prazo_bound`        — PublicationMatcher retornou `kind=single`
 *                           (CNJ exato 1 hit OU name-match 1 hit distinct).
 *                           Cria entity Prazo com `processoId` preenchido.
 *  - `prazo_rascunho`     — matcher retornou `kind=none` (0 hits totais)
 *                           OU `kind=too_many` (≥6 hits — escala manual).
 *                           Cria Prazo sem processoId. preserva path da 4a.3.
 *  - `publicacao_ambigua` — matcher retornou `kind=multiple` (2-5 candidatos).
 *                           Cria entity PublicacaoAmbigua com snapshot
 *                           denormalizado em `candidatos`.
 *  - `deduped`            — sourcePubId já existe em `prazo` (Story 4a.3 path)
 *                           OU em `publicacao_ambigua` (NEW Story 4b.1b —
 *                           idempotência cross-table). Re-fetch DJEN nunca
 *                           duplica nem cria nova entrada.
 *
 * Pipeline:
 *  1. **Idempotência cross-table:**
 *     - Query `Prazo where sourcePubId=` — se hit, return `deduped` + log
 *       `djen.prazo.deduped` (preserva 4a.3).
 *     - Query `PublicacaoAmbigua where sourcePubId=` — se hit, return
 *       `deduped` + log `djen.publication.deduped_via_ambigua_existing`
 *       (NEW Story 4b.1b).
 *  2. **Match via PublicationMatcher** — Decisão #2 mãe (CNJ exato → name-match exato).
 *  3. **Ramifica via match() em 4 helpers privados:**
 *     - `kind=single`        → `createPrazoBound`
 *     - `kind=none|too_many` → `createPrazoRascunho` (mesmo path; warning extra
 *                              em too_many vem do matcher)
 *     - `kind=multiple`      → `createPublicacaoAmbigua`
 *  4. **Save** com proteção contra race PDOException 23000 (preservada do path
 *     4a.3 — refetch sourcePubId, retorna concorrente, log `deduped_via_constraint`).
 *  5. **Log estruturado** dos eventos correspondentes.
 *
 * `DjenWorkerService::handlePublication` descarta o retorno do `create()` —
 * backward-compat preservada com Story 4a.3.
 *
 * **B20 endereçada por design:** `PublicationMatcher` é parâmetro OBRIGATÓRIO
 * no construtor (não nullable + sem default). PublicationMatcher recebe só
 * EntityManager — autowire-friendly.
 *
 * Não-final para mocking direto em testes (mesmo trade-off RedisConnection /
 * PrivilegedActorChecker / DjenUserStateRepository).
 */
class PrazoCreatorService
{
    public const DIGITS_ONLY_CNJ_LEN = 20;
    public const SOCIO_ADMIN_ROLE_NAME = 'Sócio/Admin';

    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly PublicationMatcher $matcher,
    ) {
    }

    /**
     * Cria Prazo OU PublicacaoAmbigua a partir de PrazoCalculado + payload.
     *
     * @param array<string, mixed> $payload Normalizado pelo PublicationSourceAdapterContract.
     * @return CreationResult value-object com `kind` + `entity` (preservado para auditoria/logs).
     */
    public function create(array $payload, PrazoCalculado $prazoCalculado): CreationResult
    {
        $sourcePubId = $payload['id'] ?? null;
        $sourcePubIdInt = \is_int($sourcePubId) ? $sourcePubId : (\is_numeric($sourcePubId) ? (int) $sourcePubId : null);

        // 1. Idempotência cross-table.
        if ($sourcePubIdInt !== null) {
            $existingPrazo = $this->findExistingByPubId($sourcePubIdInt);
            if ($existingPrazo !== null) {
                TogareLogger::event(
                    'info',
                    'djen.prazo.deduped',
                    'Prazo já existe para sourcePubId — re-fetch detectado, NO-OP',
                    [
                        'existingPrazoId' => $existingPrazo->getId(),
                        'sourcePubId' => $sourcePubIdInt,
                    ],
                );
                return CreationResult::deduped($existingPrazo);
            }

            $existingPub = $this->findExistingPubAmbiguaByPubId($sourcePubIdInt);
            if ($existingPub !== null) {
                TogareLogger::event(
                    'info',
                    'djen.publication.deduped_via_ambigua_existing',
                    'PublicacaoAmbigua já existe para sourcePubId — re-fetch detectado, NO-OP cross-table',
                    [
                        'existingPubAmbiguaId' => $existingPub->getId(),
                        'sourcePubId' => $sourcePubIdInt,
                    ],
                );
                return CreationResult::deduped($existingPub);
            }
        }

        // 2. Match via PublicationMatcher (Decisão #2 mãe).
        $matchResult = $this->matcher->match($payload);

        // 3. Ramifica em 4 caminhos.
        return match ($matchResult->kind) {
            'single' => $this->createPrazoBound($payload, $prazoCalculado, $matchResult->processo, $sourcePubIdInt),
            'none', 'too_many' => $this->createPrazoRascunho($payload, $prazoCalculado, $sourcePubIdInt),
            'multiple' => $this->createPublicacaoAmbigua($payload, $prazoCalculado, $matchResult, $sourcePubIdInt),
            default => $this->createPrazoRascunho($payload, $prazoCalculado, $sourcePubIdInt), // defensivo
        };
    }

    /**
     * Constrói Prazo bound a um Processo único (kind=single).
     */
    private function createPrazoBound(array $payload, PrazoCalculado $pc, ?Entity $matchedProcesso, ?int $sourcePubIdInt): CreationResult
    {
        $prazo = $this->assemblePrazo($payload, $pc, $matchedProcesso);
        $persisted = $this->savePrazoWithRaceGuard($prazo, $sourcePubIdInt);
        if ($persisted !== $prazo) {
            // Race detected → returned existing concurrent entity.
            return CreationResult::deduped($persisted);
        }
        $this->logPrazoCreated($prazo, $matchedProcesso, $pc, $payload);
        return CreationResult::prazoBound($prazo);
    }

    /**
     * Constrói Prazo rascunho (kind=none ou kind=too_many).
     */
    private function createPrazoRascunho(array $payload, PrazoCalculado $pc, ?int $sourcePubIdInt): CreationResult
    {
        $prazo = $this->assemblePrazo($payload, $pc, null);
        $persisted = $this->savePrazoWithRaceGuard($prazo, $sourcePubIdInt);
        if ($persisted !== $prazo) {
            return CreationResult::deduped($persisted);
        }
        $this->logPrazoCreated($prazo, null, $pc, $payload);
        return CreationResult::prazoRascunho($prazo);
    }

    /**
     * Constrói PublicacaoAmbigua com snapshot candidatos denormalizado (kind=multiple).
     */
    private function createPublicacaoAmbigua(array $payload, PrazoCalculado $pc, MatchResult $matchResult, ?int $sourcePubIdInt): CreationResult
    {
        $pub = $this->entityManager->getNewEntity(PublicacaoAmbigua::ENTITY_TYPE);

        $assignedUserId = $this->resolveAssignedUser($payload, null);

        $pub->set([
            'status' => PublicacaoAmbigua::STATUS_PENDENTE_REVISAO,
            'sourcePubId' => $sourcePubIdInt,
            'numeroProcessoOriginal' => $matchResult->numeroProcessoOriginal !== '' ? $matchResult->numeroProcessoOriginal : null,
            'payload' => $this->encodePayloadRaw($payload),
            'texto' => $this->stringOrNull($payload, 'texto'),
            'dataDisponibilizacao' => $this->stringOrNull($payload, 'dataDisponibilizacao'),
            'dataInicioPrazo' => $pc->dataInicioPrazo->format('Y-m-d'),
            'dataFatal' => $pc->dataFatal->format('Y-m-d'),
            'prazoDias' => $pc->prazoDias,
            'contagem' => $pc->contagem,
            'atoCodigo' => $pc->atoCodigo,
            'referenciaLegal' => $pc->referenciaLegal,
            'confidence' => $pc->confidence,
            'parserRegraVersao' => $pc->regraVersao,
            'fonteExcerpt' => $pc->fonteExcerpt,
            'candidatos' => \json_encode($matchResult->candidatos, JSON_UNESCAPED_UNICODE),
            'ambiguityReason' => $matchResult->ambiguityReason,
            'assignedUserId' => $assignedUserId,
        ]);

        try {
            $this->entityManager->saveEntity($pub);
        } catch (Throwable $e) {
            if ($this->isDuplicateKeyThrowable($e) && $sourcePubIdInt !== null) {
                $concurrent = $this->findExistingPubAmbiguaByPubId($sourcePubIdInt);
                if ($concurrent !== null) {
                    TogareLogger::event(
                        'warning',
                        'djen.publication.deduped_via_constraint',
                        'Race condition detectada — PublicacaoAmbigua já criada por worker concorrente',
                        [
                            'existingPubAmbiguaId' => $concurrent->getId(),
                            'sourcePubId' => $sourcePubIdInt,
                        ],
                    );
                    return CreationResult::deduped($concurrent);
                }
            }
            throw $e;
        }

        $candidatosProcessoIds = \array_map(static fn (array $c) => $c['processoId'] ?? null, $matchResult->candidatos);

        TogareLogger::event(
            'info',
            'djen.publication.ambiguous_queued',
            'PublicacaoAmbigua criada — ambiguidade detectada, pede leitura humana',
            [
                'publicacaoAmbiguaId' => (string) $pub->getId(),
                'sourcePubId' => $sourcePubIdInt,
                'ambiguityReason' => $matchResult->ambiguityReason,
                'candidatosCount' => \count($matchResult->candidatos),
                'candidatosProcessoIds' => $candidatosProcessoIds,
                'atoCodigo' => $pc->atoCodigo,
                'dataFatal' => $pc->dataFatal->format('Y-m-d'),
                'assignedUserId' => $assignedUserId,
            ],
        );

        return CreationResult::publicacaoAmbigua($pub);
    }

    /**
     * Helper compartilhado: monta entity Prazo (sem persistir).
     */
    private function assemblePrazo(array $payload, PrazoCalculado $pc, ?Entity $matchedProcesso): Entity
    {
        $sourcePubId = $payload['id'] ?? null;
        $sourcePubIdInt = \is_int($sourcePubId) ? $sourcePubId : (\is_numeric($sourcePubId) ? (int) $sourcePubId : null);

        $numeroProcessoRaw = $this->stringOrNull($payload, 'numeroProcesso');
        $assignedUserId = $this->resolveAssignedUser($payload, $matchedProcesso);

        $prazo = $this->entityManager->getNewEntity('Prazo');
        $isBound = $matchedProcesso !== null;
        $status = $isBound ? Prazo::STATUS_PENDENTE : Prazo::STATUS_RASCUNHO;

        $prazo->set([
            'status' => $status,
            'source' => Prazo::SOURCE_DJEN,
            'sourcePubId' => $sourcePubIdInt,
            'numeroProcessoOriginal' => $numeroProcessoRaw,
            'publicacaoOrigemRaw' => $this->encodePayloadRaw($payload),
            'dataDisponibilizacao' => $this->stringOrNull($payload, 'dataDisponibilizacao'),
            'dataInicioPrazo' => $pc->dataInicioPrazo->format('Y-m-d'),
            'dataFatal' => $pc->dataFatal->format('Y-m-d'),
            'prazoDias' => $pc->prazoDias,
            'contagem' => $pc->contagem,
            'atoCodigo' => $pc->atoCodigo,
            'referenciaLegal' => $pc->referenciaLegal,
            'confidence' => $pc->confidence,
            'parserRegraVersao' => $pc->regraVersao,
            'fonteExcerpt' => $pc->fonteExcerpt,
            'processoId' => $isBound ? (string) $matchedProcesso->getId() : null,
            'assignedUserId' => $assignedUserId,
        ]);

        return $prazo;
    }

    /**
     * Save com proteção contra race condition (preservado da Story 4a.3 — AC5.1).
     *
     * @return Entity O Prazo persistido (novo) OU o Prazo concorrente refetched.
     */
    private function savePrazoWithRaceGuard(Entity $prazo, ?int $sourcePubIdInt): Entity
    {
        try {
            $this->entityManager->saveEntity($prazo);
            return $prazo;
        } catch (Throwable $e) {
            if ($this->isDuplicateKeyThrowable($e) && $sourcePubIdInt !== null) {
                $concurrent = $this->findExistingByPubId($sourcePubIdInt);
                if ($concurrent !== null) {
                    TogareLogger::event(
                        'warning',
                        'djen.prazo.deduped_via_constraint',
                        'Race condition detectada — Prazo já criado por worker concorrente',
                        [
                            'existingPrazoId' => $concurrent->getId(),
                            'sourcePubId' => $sourcePubIdInt,
                        ],
                    );
                    return $concurrent;
                }
            }
            throw $e;
        }
    }

    private function logPrazoCreated(Entity $prazo, ?Entity $matchedProcesso, PrazoCalculado $pc, array $payload): void
    {
        $isBound = $matchedProcesso !== null;
        $eventName = $isBound ? 'djen.prazo.created_bound' : 'djen.prazo.created_unbound';
        $msg = $isBound
            ? 'Prazo criado vinculado ao Processo (status=pendente)'
            : 'Prazo criado em rascunho (sem match — status=rascunho)';

        TogareLogger::event(
            'info',
            $eventName,
            $msg,
            [
                'prazoId' => (string) $prazo->getId(),
                'sourcePubId' => $this->intOrNull($payload, 'id'),
                'numeroProcessoOriginal' => $this->stringOrNull($payload, 'numeroProcesso'),
                'processoId' => $isBound ? (string) $matchedProcesso->getId() : null,
                'assignedUserId' => $prazo->get('assignedUserId'),
                'atoCodigo' => $pc->atoCodigo,
                'referenciaLegal' => $pc->referenciaLegal,
                'dataFatal' => $pc->dataFatal->format('Y-m-d'),
                'confidence' => $pc->confidence,
                'parserRegraVersao' => $pc->regraVersao,
            ],
        );
    }

    /**
     * @internal exposto para testes
     */
    public function findExistingByPubId(int $sourcePubId): ?Entity
    {
        return $this->entityManager->getRDBRepository('Prazo')
            ->where(['sourcePubId' => $sourcePubId])
            ->findOne();
    }

    /**
     * @internal exposto para testes
     *
     * Story 4b.1b — idempotência cross-table.
     */
    public function findExistingPubAmbiguaByPubId(int $sourcePubId): ?Entity
    {
        return $this->entityManager->getRDBRepository(PublicacaoAmbigua::ENTITY_TYPE)
            ->where(['sourcePubId' => $sourcePubId])
            ->findOne();
    }

    /**
     * @internal exposto para testes — preservado da Story 4a.3
     * (PublicationMatcher também faz match CNJ; este helper segue público
     * para retrocompat de testes existentes que mockam o creator diretamente).
     */
    public function matchProcessoByNumeroCnj(?string $numeroProcesso, ?int $sourcePubId): ?Entity
    {
        if ($numeroProcesso === null || $numeroProcesso === '') {
            return null;
        }

        $digits = $this->digitsOnly($numeroProcesso);

        if (\strlen($digits) !== self::DIGITS_ONLY_CNJ_LEN) {
            TogareLogger::event(
                'warning',
                'djen.prazo.invalid_cnj_format',
                "CNJ da publicação não tem 20 dígitos após digitsOnly: '{$numeroProcesso}' → '{$digits}'",
                [
                    'sourcePubId' => $sourcePubId,
                    'numeroProcessoOriginal' => $numeroProcesso,
                    'digitsLen' => \strlen($digits),
                ],
            );
            return null;
        }

        return $this->entityManager->getRDBRepository('Processo')
            ->where(['numeroCnj' => $digits])
            ->findOne();
    }

    /**
     * Decisão #4 da Story 4a.3 — heurística cascade (preservada Story 4b.1b).
     *
     * @param array<string, mixed> $payload
     */
    public function resolveAssignedUser(array $payload, ?Entity $matchedProcesso): ?string
    {
        if ($matchedProcesso !== null) {
            $processoAssignee = $matchedProcesso->get('assignedUserId');
            if ($processoAssignee !== null && $processoAssignee !== '') {
                return (string) $processoAssignee;
            }
        }

        $payloadUserId = $this->stringOrNull($payload, 'userId');
        if ($payloadUserId !== null) {
            $user = $this->entityManager->getRDBRepository('User')
                ->where([
                    'id' => $payloadUserId,
                    'isActive' => true,
                ])
                ->findOne();
            if ($user !== null) {
                return (string) $user->getId();
            }
        }

        $socioAdminId = $this->findFirstSocioAdminUserId();
        if ($socioAdminId !== null) {
            TogareLogger::event(
                'warning',
                'djen.prazo.assignee_fallback_socio_admin',
                'Heurística assignedUser caiu em fallback — primeiro Sócio/Admin ativo',
                [
                    'sourcePubId' => $this->intOrNull($payload, 'id'),
                    'assignedUserId' => $socioAdminId,
                ],
            );
            return $socioAdminId;
        }

        TogareLogger::event(
            'warning',
            'djen.prazo.no_assignee_fallback',
            'Heurística assignedUser exauriu cascade — nenhum Sócio/Admin ativo encontrado',
            ['sourcePubId' => $this->intOrNull($payload, 'id')],
        );
        return null;
    }

    /**
     * @internal exposto para testes
     */
    public function findFirstSocioAdminUserId(): ?string
    {
        $user = $this->entityManager->getRDBRepository('User')
            ->distinct()
            ->join('roles')
            ->where([
                'roles.name' => self::SOCIO_ADMIN_ROLE_NAME,
                'isActive' => true,
                'type' => 'regular',
            ])
            ->findOne();

        return $user !== null ? (string) $user->getId() : null;
    }

    /**
     * Extrai apenas dígitos de string (regex `[^0-9]`).
     */
    public function digitsOnly(string $value): string
    {
        return (string) \preg_replace('/[^0-9]/', '', $value);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodePayloadRaw(array $payload): string
    {
        try {
            return \json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $e) {
            TogareLogger::event(
                'warning',
                'djen.prazo.payload_encode_failed',
                'Falha ao serializar payload para publicacaoOrigemRaw: ' . $e->getMessage(),
                [],
            );
            return (string) \json_encode(
                ['_encodeError' => $e->getMessage()],
                JSON_UNESCAPED_UNICODE,
            );
        }
    }

    private function isDuplicateKeyError(PDOException $e): bool
    {
        $code = (string) $e->getCode();
        $msg = $e->getMessage();
        return $code === '23000'
            || \str_contains($msg, 'Duplicate entry')
            || \str_contains($msg, 'prazo_source_pub_id_unique')
            || \str_contains($msg, 'publicacao_ambigua_source_pub_id_unique')
            || \str_contains($msg, 'UNIQUE constraint failed');
    }

    private function isDuplicateKeyThrowable(Throwable $e): bool
    {
        $current = $e;
        while ($current !== null) {
            if ($current instanceof PDOException && $this->isDuplicateKeyError($current)) {
                return true;
            }

            $code = (string) $current->getCode();
            $message = $current->getMessage();
            if ($code === '23000'
                || \str_contains($message, 'Duplicate entry')
                || \str_contains($message, 'prazo_source_pub_id_unique')
                || \str_contains($message, 'publicacao_ambigua_source_pub_id_unique')
                || \str_contains($message, 'UNIQUE constraint failed')
            ) {
                return true;
            }

            $current = $current->getPrevious();
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function stringOrNull(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (\is_string($value)) {
            return $value === '' ? null : $value;
        }
        if (\is_scalar($value)) {
            return (string) $value;
        }
        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function intOrNull(array $payload, string $key): ?int
    {
        $value = $payload[$key] ?? null;
        if (\is_int($value)) {
            return $value;
        }
        if (\is_numeric($value)) {
            return (int) $value;
        }
        return null;
    }
}
