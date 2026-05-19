<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\PublicacaoAmbigua;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\Modules\TogareCore\Entities\PublicacaoAmbigua;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Audit afterSave em `PublicacaoAmbigua` (Story 4b.1a — FR37 + NFR10).
 *
 * Eventos emitidos:
 *  - audit.publicacao_ambigua.created  — em isNew
 *  - audit.publicacao_ambigua.modified — em qualquer change em SENSITIVE_FIELDS
 *  - audit.publicacao_ambigua.status_resolvido     — transição para resolvido
 *  - audit.publicacao_ambigua.status_ignorado      — transição para ignorado
 *  - audit.publicacao_ambigua.status_bulk_ignorado — transição para bulk_ignorado
 *
 * Pattern espelha AuditPrazoHook (Story 4a.3.1). Try/catch \Throwable garante
 * que audit nunca bloqueia save (regra FR37). 4b.1b complementa com writes em
 * `togare_ambiguity_log` via PDO direto pelo AmbiguityResolverService (D5 mãe).
 *
 * @implements AfterSave<PublicacaoAmbigua>
 */
final class AuditPublicacaoAmbiguaHook implements AfterSave
{
    public static int $order = 50;

    /** @var list<string> Allowlist de atributos cuja mudança merece audit. */
    private const SENSITIVE_FIELDS = [
        'status',
        'decisionType',
        'decisionProcessoId',
        'prazoCriadoId',
        'assignedUserId',
        'candidatos',
        'ambiguityReason',
    ];

    public function __construct(
        private readonly AuditLogContract $auditLog,
    ) {
    }

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (! $entity instanceof PublicacaoAmbigua) {
            return;
        }

        $pubId = (string) $entity->getId();

        if ($entity->isNew()) {
            $this->safeLog(
                'audit.publicacao_ambigua.created',
                $pubId,
                $this->buildCreatedContext($entity),
                'created',
            );
            return;
        }

        $changed = [];
        foreach (self::SENSITIVE_FIELDS as $field) {
            if ($entity->isAttributeChanged($field)) {
                $changed[] = $field;
            }
        }

        if ($changed === []) {
            return;
        }

        $this->safeLog(
            'audit.publicacao_ambigua.modified',
            $pubId,
            $this->buildModifiedContext($entity, $changed),
            'modified',
        );

        if (! \in_array('status', $changed, true)) {
            return;
        }

        $newStatus = $entity->get('status');
        $previousStatus = $entity->getFetched('status');

        // Story 4b.1a — 3 eventos derivados de transição de status.
        $derivedEventMap = [
            PublicacaoAmbigua::STATUS_RESOLVIDO => 'audit.publicacao_ambigua.status_resolvido',
            PublicacaoAmbigua::STATUS_IGNORADO => 'audit.publicacao_ambigua.status_ignorado',
            PublicacaoAmbigua::STATUS_BULK_IGNORADO => 'audit.publicacao_ambigua.status_bulk_ignorado',
        ];

        if (! isset($derivedEventMap[$newStatus])) {
            return;
        }

        $context = [
            'previousStatus' => $previousStatus,
            'decisionType' => $entity->get('decisionType'),
            'decisionProcessoId' => $entity->get('decisionProcessoId'),
            'prazoCriadoId' => $entity->get('prazoCriadoId'),
            'decidedById' => $entity->get('decidedById'),
        ];

        $this->safeLog(
            $derivedEventMap[$newStatus],
            $pubId,
            $context,
            $newStatus,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCreatedContext(PublicacaoAmbigua $entity): array
    {
        $candidatosRaw = $entity->get('candidatos');
        $candidatosCount = 0;
        if (\is_string($candidatosRaw) && $candidatosRaw !== '') {
            $decoded = \json_decode($candidatosRaw, true);
            if (\is_array($decoded)) {
                $candidatosCount = \count($decoded);
            }
        }

        return [
            'sourcePubId' => $entity->get('sourcePubId'),
            'numeroProcessoOriginal' => $entity->get('numeroProcessoOriginal'),
            'ambiguityReason' => $entity->get('ambiguityReason'),
            'status' => $entity->get('status'),
            'dataFatal' => $entity->get('dataFatal'),
            'atoCodigo' => $entity->get('atoCodigo'),
            'candidatosCount' => $candidatosCount,
            'assignedUserId' => $entity->get('assignedUserId'),
        ];
    }

    /**
     * @param list<string> $changed
     * @return array<string, mixed>
     */
    private function buildModifiedContext(PublicacaoAmbigua $entity, array $changed): array
    {
        return [
            'changedFields' => $changed,
            'newStatus' => $entity->get('status'),
            'previousStatus' => $entity->getFetched('status'),
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function safeLog(string $type, string $entityId, array $context, string $kind): void
    {
        try {
            $this->auditLog->log($type, 'PublicacaoAmbigua', $entityId, $context);
        } catch (\Throwable $e) {
            TogareLogger::event(
                'error',
                'audit.hook.failed',
                'AuditPublicacaoAmbiguaHook: falha ao registrar ' . $kind,
                ['error' => $e->getMessage()],
            );
        }
    }
}
