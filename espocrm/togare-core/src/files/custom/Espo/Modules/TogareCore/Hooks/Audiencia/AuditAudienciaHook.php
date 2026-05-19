<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\Audiencia;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\Modules\TogareCore\Entities\Audiencia;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Audit afterSave em `Audiencia`: emite `audit.audiencia.created` (em
 * `isNew()`) ou `audit.audiencia.modified` (com lista de campos sensíveis
 * alterados). Adicionalmente, quando `status` muda para `cancelada` ou
 * `realizada`, emite eventos dedicados `audit.audiencia.cancelled` /
 * `audit.audiencia.realized` para o consumo downstream
 * (relatórios financeiros, briefing diário, Stories Epic 4b/6).
 *
 * Cobre FR37 + NFR10 (audit log append-only, retenção 24m). Persiste em
 * `togare_audit_log` via `AuditLogService` (Story 2.4) — concreta resolvida
 * pelo container EspoCRM via DI no contract `AuditLogContract` (binding em
 * `Binding.php` desde Story 3.1).
 *
 * SENSITIVE_FIELDS:
 *  - dataHora, tipo, modalidade, status: identificação e classificação.
 *  - processoId: rastreabilidade do vínculo processual.
 *  - assignedUserId: rastreabilidade de delegação (Decisão #5 da story).
 *  - tribunal, vara: localização (mudança costuma indicar redirecionamento).
 *  - observacoes: anotações pós-audiência (resultado).
 *
 * Eventos derivados de status:
 *  - status → 'cancelada'  → emite `audit.audiencia.cancelled` ALÉM do `modified`.
 *  - status → 'realizada'  → emite `audit.audiencia.realized`  ALÉM do `modified`.
 *
 * Try/catch `\Throwable` com fallback log via TogareLogger — audit nunca
 * pode bloquear save (regra FR37).
 *
 * @implements AfterSave<Audiencia>
 */
final class AuditAudienciaHook implements AfterSave
{
    public static int $order = 50;

    /** @var list<string> Allowlist de atributos cuja mudança merece audit. */
    private const SENSITIVE_FIELDS = [
        'dataHora',
        'tipo',
        'modalidade',
        'status',
        'processoId',
        'assignedUserId',
        'tribunal',
        'vara',
        'observacoes',
    ];

    public function __construct(
        private readonly AuditLogContract $auditLog,
    ) {
    }

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (! $entity instanceof Audiencia) {
            return;
        }

        $audienciaId = (string) $entity->getId();

        if ($entity->isNew()) {
            $this->safeLog(
                'audit.audiencia.created',
                $audienciaId,
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
            'audit.audiencia.modified',
            $audienciaId,
            $this->buildModifiedContext($entity, $changed),
            'modified',
        );

        // Eventos derivados de mudança de status — emitidos APÓS o `modified`
        // para preservar ordem cronológica no audit log.
        if (! \in_array('status', $changed, true)) {
            return;
        }

        $newStatus = $entity->get('status');
        $previousStatus = $entity->getFetched('status');

        if ($newStatus === Audiencia::STATUS_CANCELADA) {
            $this->safeLog(
                'audit.audiencia.cancelled',
                $audienciaId,
                [
                    'processoId' => $entity->get('processoId'),
                    'previousStatus' => $previousStatus,
                    'dataHora' => $entity->get('dataHora'),
                ],
                'cancelled',
            );
            return;
        }

        if ($newStatus === Audiencia::STATUS_REALIZADA) {
            $this->safeLog(
                'audit.audiencia.realized',
                $audienciaId,
                [
                    'processoId' => $entity->get('processoId'),
                    'dataHora' => $entity->get('dataHora'),
                    'durationMinutes' => $entity->get('duracaoMinutos'),
                ],
                'realized',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCreatedContext(Audiencia $entity): array
    {
        return [
            'processoId' => $entity->get('processoId'),
            'dataHora' => $entity->get('dataHora'),
            'tipo' => $entity->get('tipo'),
            'modalidade' => $entity->get('modalidade'),
            'assignedUserId' => $entity->get('assignedUserId'),
        ];
    }

    /**
     * @param list<string> $changed
     * @return array<string, mixed>
     */
    private function buildModifiedContext(Audiencia $entity, array $changed): array
    {
        return [
            'processoId' => $entity->get('processoId'),
            'changedFields' => $changed,
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function safeLog(string $type, string $entityId, array $context, string $kind): void
    {
        try {
            $this->auditLog->log($type, 'Audiencia', $entityId, $context);
        } catch (\Throwable $e) {
            TogareLogger::event(
                'error',
                'audit.hook.failed',
                'AuditAudienciaHook: falha ao registrar ' . $kind,
                ['error' => $e->getMessage()],
            );
        }
    }
}
