<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\Processo;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\Modules\TogareCore\Entities\Processo;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Audit afterSave em `Processo`: emite `audit.processo.created` (em `isNew()`)
 * ou `audit.processo.modified` (com lista de campos sensíveis alterados).
 *
 * Cobre FR37 + NFR10 (audit log append-only, retenção 24m). Persiste em
 * `togare_audit_log` via `AuditLogService` (Story 2.4) — concreta resolvida
 * pelo container EspoCRM via DI no contract `AuditLogContract` (binding em
 * `Binding.php` desde Story 3.1).
 *
 * CAMPOS_SENSIVEIS:
 *  - numeroCnj, classeCodigo, assuntoCodigo: identificação processual
 *  - status, fase: estado processual
 *  - segredoJustica: flag legal sensível
 *  - valorCausa: dado financeiro
 *  - assignedUserId: rastreabilidade de atribuição (Story 3.5)
 *  - collaboratorsIds: rastreabilidade de colaboração (Story 3.5)
 *  - observacoes: anotações internas
 *
 * Quando `collaboratorsIds` muda em update, o evento `audit.processo.modified`
 * carrega `addedCollaboratorIds` / `removedCollaboratorIds` no contexto —
 * permitindo reconstruir a história granular sem precisar de hooks
 * AfterRelate/AfterUnrelate (Story 3.5 design decision: linkMultiple field
 * canaliza tudo via PUT save, não via relate/unrelate API).
 *
 * Try/catch `\Throwable` com fallback log via TogareLogger — audit nunca
 * pode bloquear save (regra FR37).
 *
 * @implements AfterSave<Processo>
 */
final class AuditProcessoHook implements AfterSave
{
    public static int $order = 50;

    /** @var list<string> Allowlist de atributos cuja mudança merece audit. */
    private const SENSITIVE_FIELDS = [
        'numeroCnj',
        'classeCodigo',
        'assuntoCodigo',
        'status',
        'fase',
        'segredoJustica',
        'valorCausa',
        'assignedUserId',
        'collaboratorsIds',
        'observacoes',
    ];

    public function __construct(
        private readonly AuditLogContract $auditLog,
    ) {
    }

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (! $entity instanceof Processo) {
            return;
        }

        $processoId = (string) $entity->getId();

        if ($entity->isNew()) {
            try {
                $this->auditLog->log(
                    'audit.processo.created',
                    'Processo',
                    $processoId,
                    $this->buildCreatedContext($entity),
                );
            } catch (\Throwable $e) {
                TogareLogger::event(
                    'error',
                    'audit.hook.failed',
                    'AuditProcessoHook: falha ao registrar created',
                    ['error' => $e->getMessage()],
                );
            }
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

        try {
            $this->auditLog->log(
                'audit.processo.modified',
                'Processo',
                $processoId,
                $this->buildModifiedContext($entity, $changed),
            );
        } catch (\Throwable $e) {
            TogareLogger::event(
                'error',
                'audit.hook.failed',
                'AuditProcessoHook: falha ao registrar modified',
                ['error' => $e->getMessage()],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCreatedContext(Processo $entity): array
    {
        $context = [
            'numeroCnj' => $entity->get('numeroCnj'),
            'classeCodigo' => $entity->get('classeCodigo'),
            'assignedUserId' => $entity->get('assignedUserId'),
        ];

        $collaboratorsIds = $this->normalizeIdList($entity->get('collaboratorsIds'));
        if ($collaboratorsIds !== []) {
            $context['initialCollaboratorIds'] = $collaboratorsIds;
        }

        return $context;
    }

    /**
     * @param list<string> $changed
     * @return array<string, mixed>
     */
    private function buildModifiedContext(Processo $entity, array $changed): array
    {
        $context = [
            'numeroCnj' => $entity->get('numeroCnj'),
            'changedFields' => $changed,
        ];

        if (\in_array('collaboratorsIds', $changed, true)) {
            $current = $this->normalizeIdList($entity->get('collaboratorsIds'));
            $previous = $this->normalizeIdList($entity->getFetched('collaboratorsIds'));

            $context['addedCollaboratorIds'] = \array_values(\array_diff($current, $previous));
            $context['removedCollaboratorIds'] = \array_values(\array_diff($previous, $current));
        }

        return $context;
    }

    /**
     * @return list<string>
     */
    private function normalizeIdList(mixed $value): array
    {
        if (! \is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $item) {
            if (\is_string($item) && $item !== '') {
                $normalized[] = $item;
            }
        }

        return $normalized;
    }
}
