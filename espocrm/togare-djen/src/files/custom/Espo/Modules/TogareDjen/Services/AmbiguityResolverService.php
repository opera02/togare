<?php

declare(strict_types=1);

namespace Espo\Modules\TogareDjen\Services;

use DateTimeImmutable;
use Espo\Modules\TogareCore\Entities\Prazo;
use Espo\Modules\TogareCore\Entities\PublicacaoAmbigua;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareDjen\Exception\AlreadyResolvedException;
use Espo\Modules\TogareDjen\Exception\InvalidCandidateException;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PDO;
use PDOException;
use Throwable;

/**
 * AmbiguityResolverService — Story 4b.1b / Decisão #4 mãe.
 *
 * Resolve PublicacaoAmbigua via 3 ações atômicas:
 *
 *  - `resolve($pubId, $chosenProcessoId, $decidedByUserId): Entity`
 *     Cria Prazo `source=manual_ambiguo` vinculado ao Processo escolhido,
 *     marca pub `status=resolvido` + `decisionType=confirmar_candidato`,
 *     append `togare_ambiguity_log`. Tudo em 1 transação MariaDB.
 *
 *  - `ignore($pubId, $decidedByUserId): void`
 *     Marca pub `status=ignorado` + append log. Zero Prazo criado.
 *
 *  - `bulkIgnoreProcesso($processoId, $decidedByUserId, $canEdit = null): int`
 *     Para todas pubs `status=pendente_revisao` onde `$processoId` aparece em
 *     `candidatos[].processoId` (snapshot JSON), revalida o JSON exato, aplica
 *     filtro opcional de ACL por registro, marca `status=bulk_ignorado`
 *     + append log por pub afetada. Retorna count.
 *
 * Pattern oficial EspoCRM 9.x: `$em->getTransactionManager()->run(callback)`.
 * Throwable → rollback automático. Append em `togare_ambiguity_log` via PDO
 * direto (mesma conn da transação) — pattern V009 writeAuditLog.
 *
 * Defesa contra request manipulado: `chosenProcessoId` validado contra
 * `candidatos[].processoId` ANTES de criar Prazo (Dev Note #7 da story).
 *
 * `bulkIgnoreProcesso` usa `LIKE %"processoId":"<id>"%` portável MariaDB+SQLite
 * como filtro inicial e sempre revalida `candidatos[]` decodificado antes de
 * alterar a row (OQ#1 fechada — volume baixo torna performance irrelevante;
 * portabilidade simplifica testes).
 *
 * Não-final para mocking direto em testes (mesmo trade-off PrazoCreatorService).
 */
class AmbiguityResolverService
{
    public const PUBLICACAO_TEXTO_TRUNCATE_BYTES = 5120;

    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly PrazoCreatorService $prazoCreator,
    ) {
    }

    /**
     * Resolve uma PublicacaoAmbigua escolhendo 1 candidato → cria Prazo
     * + marca pub resolvida + grava ambiguity_log atomicamente.
     *
     * @throws AlreadyResolvedException se status != pendente_revisao
     * @throws InvalidCandidateException se chosenProcessoId não está em candidatos[]
     */
    public function resolve(string $publicacaoAmbiguaId, string $chosenProcessoId, string $decidedByUserId): Entity
    {
        $em = $this->entityManager;

        return $em->getTransactionManager()->run(function () use ($em, $publicacaoAmbiguaId, $chosenProcessoId, $decidedByUserId) {
            $pub = $this->loadPendingOrThrow($publicacaoAmbiguaId);

            $candidatos = $this->decodeCandidatos($pub);
            if (! $this->candidateInList($chosenProcessoId, $candidatos)) {
                throw new InvalidCandidateException(
                    'O processo escolhido não está na lista de candidatos desta publicação.',
                );
            }

            $matchedProcesso = $em->getEntityById('Processo', $chosenProcessoId);
            $prazo = $this->assemblePrazoFromPublicacaoAmbigua($pub, $chosenProcessoId, $matchedProcesso);
            try {
                $em->saveEntity($prazo);
            } catch (Throwable $e) {
                if ($this->isDuplicateKeyThrowable($e)) {
                    throw $this->alreadyResolvedFromEntity($pub);
                }
                throw $e;
            }

            $now = new DateTimeImmutable();
            $pub->set([
                'status' => PublicacaoAmbigua::STATUS_RESOLVIDO,
                'decisionType' => PublicacaoAmbigua::DECISION_CONFIRMAR_CANDIDATO,
                'decisionProcessoId' => $chosenProcessoId,
                'prazoCriadoId' => (string) $prazo->getId(),
                'decidedById' => $decidedByUserId,
                'decidedAt' => $now->format('Y-m-d H:i:s'),
            ]);
            $em->saveEntity($pub);

            $this->appendAmbiguityLog([
                'publicacao_ambigua_id' => (string) $pub->getId(),
                'decided_by_user_id' => $decidedByUserId,
                'decided_at' => $now->format('Y-m-d H:i:s'),
                'decision_type' => PublicacaoAmbigua::DECISION_CONFIRMAR_CANDIDATO,
                'chosen_processo_id' => $chosenProcessoId,
                'prazo_criado_id' => (string) $prazo->getId(),
                'candidates_snapshot' => \json_encode($candidatos, JSON_UNESCAPED_UNICODE),
                'excerpt' => (string) ($pub->get('fonteExcerpt') ?? ''),
                'texto_publicacao' => $this->truncateTexto((string) ($pub->get('texto') ?? '')),
            ]);

            TogareLogger::event(
                'info',
                'djen.ambiguity.resolved',
                'PublicacaoAmbigua resolvida — Prazo criado source=manual_ambiguo',
                [
                    'publicacaoAmbiguaId' => (string) $pub->getId(),
                    'chosenProcessoId' => $chosenProcessoId,
                    'prazoCriadoId' => (string) $prazo->getId(),
                    'decidedByUserId' => $decidedByUserId,
                ],
            );

            return $prazo;
        });
    }

    /**
     * Marca PublicacaoAmbigua como ignorada — zero Prazo criado, append log.
     *
     * @throws AlreadyResolvedException se status != pendente_revisao
     */
    public function ignore(string $publicacaoAmbiguaId, string $decidedByUserId): void
    {
        $em = $this->entityManager;

        $em->getTransactionManager()->run(function () use ($em, $publicacaoAmbiguaId, $decidedByUserId) {
            $pub = $this->loadPendingOrThrow($publicacaoAmbiguaId);
            $candidatos = $this->decodeCandidatos($pub);

            $now = new DateTimeImmutable();
            $pub->set([
                'status' => PublicacaoAmbigua::STATUS_IGNORADO,
                'decisionType' => PublicacaoAmbigua::DECISION_IGNORAR,
                'decisionProcessoId' => null,
                'prazoCriadoId' => null,
                'decidedById' => $decidedByUserId,
                'decidedAt' => $now->format('Y-m-d H:i:s'),
            ]);
            $em->saveEntity($pub);

            $this->appendAmbiguityLog([
                'publicacao_ambigua_id' => (string) $pub->getId(),
                'decided_by_user_id' => $decidedByUserId,
                'decided_at' => $now->format('Y-m-d H:i:s'),
                'decision_type' => PublicacaoAmbigua::DECISION_IGNORAR,
                'chosen_processo_id' => null,
                'prazo_criado_id' => null,
                'candidates_snapshot' => \json_encode($candidatos, JSON_UNESCAPED_UNICODE),
                'excerpt' => (string) ($pub->get('fonteExcerpt') ?? ''),
                'texto_publicacao' => $this->truncateTexto((string) ($pub->get('texto') ?? '')),
            ]);

            TogareLogger::event(
                'info',
                'djen.ambiguity.ignored',
                'PublicacaoAmbigua ignorada — sem Prazo gerado, decisão registrada',
                [
                    'publicacaoAmbiguaId' => (string) $pub->getId(),
                    'decidedByUserId' => $decidedByUserId,
                ],
            );
        });
    }

    /**
     * Marca todas as PublicacaoAmbigua pendentes que tenham `$processoId` em
     * `candidatos[].processoId` como bulk_ignorado. Retorna count.
     *
     * @throws InvalidCandidateException se $processoId vazio
     */
    public function bulkIgnoreProcesso(string $processoId, string $decidedByUserId, ?callable $canEdit = null): int
    {
        $processoId = \trim($processoId);
        if ($processoId === '') {
            throw new InvalidCandidateException(
                'Informe o processo a ser bulk-ignorado.',
            );
        }

        $em = $this->entityManager;

        return $em->getTransactionManager()->run(function () use ($em, $processoId, $decidedByUserId, $canEdit): int {
            // Match LIKE %"processoId":"<id>"% — portável MariaDB+SQLite (OQ#1).
            $likePattern = '%"processoId":"' . $processoId . '"%';

            $pubs = $em->getRDBRepository(PublicacaoAmbigua::ENTITY_TYPE)
                ->where([
                    'status' => PublicacaoAmbigua::STATUS_PENDENTE_REVISAO,
                    'candidatos*' => $likePattern,
                ])
                ->find();

            $count = 0;
            $now = new DateTimeImmutable();

            foreach ($pubs as $pub) {
                if (! $pub instanceof Entity) {
                    continue;
                }

                $candidatos = $this->decodeCandidatos($pub);
                if (! $this->candidateInList($processoId, $candidatos)) {
                    continue;
                }
                if ($canEdit !== null && ! $canEdit($pub)) {
                    continue;
                }

                $pub->set([
                    'status' => PublicacaoAmbigua::STATUS_BULK_IGNORADO,
                    'decisionType' => PublicacaoAmbigua::DECISION_BULK_IGNORAR_PROCESSO,
                    'decisionProcessoId' => null,
                    'prazoCriadoId' => null,
                    'decidedById' => $decidedByUserId,
                    'decidedAt' => $now->format('Y-m-d H:i:s'),
                ]);
                $em->saveEntity($pub);

                $this->appendAmbiguityLog([
                    'publicacao_ambigua_id' => (string) $pub->getId(),
                    'decided_by_user_id' => $decidedByUserId,
                    'decided_at' => $now->format('Y-m-d H:i:s'),
                    'decision_type' => PublicacaoAmbigua::DECISION_BULK_IGNORAR_PROCESSO,
                    'chosen_processo_id' => $processoId,
                    'prazo_criado_id' => null,
                    'candidates_snapshot' => \json_encode($candidatos, JSON_UNESCAPED_UNICODE),
                    'excerpt' => (string) ($pub->get('fonteExcerpt') ?? ''),
                    'texto_publicacao' => $this->truncateTexto((string) ($pub->get('texto') ?? '')),
                ]);

                $count++;
            }

            TogareLogger::event(
                'info',
                'djen.ambiguity.bulk_ignored',
                'Bulk-ignore aplicado a publicações ambíguas envolvendo processo',
                [
                    'processoId' => $processoId,
                    'count' => $count,
                    'decidedByUserId' => $decidedByUserId,
                ],
            );

            return $count;
        });
    }

    /**
     * Carrega PublicacaoAmbigua e valida status=pendente_revisao.
     *
     * @throws AlreadyResolvedException se status diferente
     */
    private function loadPendingOrThrow(string $publicacaoAmbiguaId): Entity
    {
        $pub = $this->entityManager->getEntityById(
            PublicacaoAmbigua::ENTITY_TYPE,
            $publicacaoAmbiguaId,
        );

        if ($pub === null) {
            throw new InvalidCandidateException(
                'Publicação ambígua não encontrada (id=' . $publicacaoAmbiguaId . ').',
            );
        }

        $status = $pub->get('status');
        if ($status !== PublicacaoAmbigua::STATUS_PENDENTE_REVISAO) {
            $decidedAtRaw = $pub->get('decidedAt');
            try {
                $decidedAt = $decidedAtRaw !== null && $decidedAtRaw !== ''
                    ? new DateTimeImmutable((string) $decidedAtRaw)
                    : new DateTimeImmutable();
            } catch (Throwable) {
                $decidedAt = new DateTimeImmutable();
            }
            throw new AlreadyResolvedException($decidedAt);
        }

        return $pub;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decodeCandidatos(Entity $pub): array
    {
        $raw = $pub->get('candidatos');
        if (! \is_string($raw) || $raw === '') {
            return [];
        }

        try {
            $decoded = \json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($decoded) ? \array_values($decoded) : [];
    }

    /**
     * @param list<array<string, mixed>> $candidatos
     */
    private function candidateInList(string $chosenProcessoId, array $candidatos): bool
    {
        foreach ($candidatos as $cand) {
            if (\is_array($cand) && (($cand['processoId'] ?? null) === $chosenProcessoId)) {
                return true;
            }
        }
        return false;
    }

    private function assemblePrazoFromPublicacaoAmbigua(Entity $pub, string $chosenProcessoId, ?Entity $matchedProcesso): Entity
    {
        $payloadForAssignee = [];
        $pubAssignedUserId = $pub->get('assignedUserId');
        if (\is_string($pubAssignedUserId) && $pubAssignedUserId !== '') {
            $payloadForAssignee['userId'] = $pubAssignedUserId;
        }

        $assignedUserId = $this->prazoCreator->resolveAssignedUser($payloadForAssignee, $matchedProcesso);

        $prazo = $this->entityManager->getNewEntity('Prazo');
        $prazo->set([
            'status' => Prazo::STATUS_PENDENTE,
            'source' => Prazo::SOURCE_MANUAL_AMBIGUO,
            'sourcePubId' => $pub->get('sourcePubId'),
            'numeroProcessoOriginal' => $pub->get('numeroProcessoOriginal'),
            'publicacaoOrigemRaw' => (string) ($pub->get('payload') ?? ''),
            'dataDisponibilizacao' => $pub->get('dataDisponibilizacao'),
            'dataInicioPrazo' => $pub->get('dataInicioPrazo'),
            'dataFatal' => $pub->get('dataFatal'),
            'prazoDias' => $pub->get('prazoDias'),
            'contagem' => $pub->get('contagem'),
            'atoCodigo' => $pub->get('atoCodigo'),
            'referenciaLegal' => $pub->get('referenciaLegal'),
            'confidence' => $pub->get('confidence'),
            'parserRegraVersao' => $pub->get('parserRegraVersao'),
            'fonteExcerpt' => $pub->get('fonteExcerpt'),
            'processoId' => $chosenProcessoId,
            'assignedUserId' => $assignedUserId,
        ]);

        return $prazo;
    }

    /**
     * Append em `togare_ambiguity_log` via PDO direto (mesma conn da transação).
     * Pattern V009/V014 writeAuditLog.
     *
     * @param array<string, mixed> $row
     */
    private function appendAmbiguityLog(array $row): void
    {
        $pdo = $this->entityManager->getPDO();

        $sql = 'INSERT INTO togare_ambiguity_log
            (id, publicacao_ambigua_id, decided_by_user_id, decided_at, decision_type,
             chosen_processo_id, prazo_criado_id, candidates_snapshot, excerpt,
             texto_publicacao, created_at)
            VALUES
            (:id, :publicacao_ambigua_id, :decided_by_user_id, :decided_at, :decision_type,
             :chosen_processo_id, :prazo_criado_id, :candidates_snapshot, :excerpt,
             :texto_publicacao, :created_at)';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':id' => \bin2hex(\random_bytes(16)),
            ':publicacao_ambigua_id' => $row['publicacao_ambigua_id'],
            ':decided_by_user_id' => $row['decided_by_user_id'],
            ':decided_at' => $row['decided_at'],
            ':decision_type' => $row['decision_type'],
            ':chosen_processo_id' => $row['chosen_processo_id'],
            ':prazo_criado_id' => $row['prazo_criado_id'],
            ':candidates_snapshot' => $row['candidates_snapshot'],
            ':excerpt' => $row['excerpt'],
            ':texto_publicacao' => $row['texto_publicacao'],
            ':created_at' => $row['decided_at'],
        ]);
    }

    private function truncateTexto(string $texto): string
    {
        if (\strlen($texto) <= self::PUBLICACAO_TEXTO_TRUNCATE_BYTES) {
            return $texto;
        }
        return \substr($texto, 0, self::PUBLICACAO_TEXTO_TRUNCATE_BYTES);
    }

    private function isDuplicateKeyError(PDOException $e): bool
    {
        $code = (string) $e->getCode();
        $msg = $e->getMessage();

        return $code === '23000'
            || \str_contains($msg, 'Duplicate entry')
            || \str_contains($msg, 'prazo_source_pub_id_unique')
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
                || \str_contains($message, 'UNIQUE constraint failed')
            ) {
                return true;
            }

            $current = $current->getPrevious();
        }

        return false;
    }

    private function alreadyResolvedFromEntity(Entity $pub): AlreadyResolvedException
    {
        $decidedAtRaw = $pub->get('decidedAt');
        try {
            $decidedAt = $decidedAtRaw !== null && $decidedAtRaw !== ''
                ? new DateTimeImmutable((string) $decidedAtRaw)
                : new DateTimeImmutable();
        } catch (Throwable) {
            $decidedAt = new DateTimeImmutable();
        }

        return new AlreadyResolvedException($decidedAt);
    }
}
