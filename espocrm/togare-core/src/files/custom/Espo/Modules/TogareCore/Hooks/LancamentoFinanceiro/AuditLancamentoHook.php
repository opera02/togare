<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\LancamentoFinanceiro;

use Espo\Core\Hook\Hook\AfterRemove;
use Espo\Core\Hook\Hook\AfterSave;
use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\Modules\TogareCore\Entities\LancamentoFinanceiro;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\RemoveOptions;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Audit AfterSave + AfterRemove em LancamentoFinanceiro (Story 6.3 — FR37, NFR10).
 *
 * Eventos canônicos:
 *  - lancamento.created            — isNew
 *  - lancamento.modified           — campos sensíveis mudaram
 *  - lancamento.estorno_aplicado   — tipo=estorno + isNew (refinamento de created)
 *  - lancamento.removed            — AfterRemove
 *
 * Pattern AuditFaturaHook. Persiste em `togare_audit_log` via AuditLogContract
 * E em `togare_lancamento_financeiro_log` via PDO direto (V021).
 *
 * Try/catch \Throwable — audit nunca bloqueia save/remove (regra FR37).
 *
 * Order = 50.
 *
 * @implements AfterSave<LancamentoFinanceiro>
 * @implements AfterRemove<LancamentoFinanceiro>
 */
final class AuditLancamentoHook implements AfterSave, AfterRemove
{
    public static int $order = 50;

    /** @var list<string> */
    private const SENSITIVE_FIELDS = [
        'tipo',
        'valor',
        'faturaId',
        'formaPagamento',
        'dataMovimento',
        'categoria',
    ];

    public function __construct(
        private readonly AuditLogContract $auditLog,
        private readonly EntityManager $entityManager,
    ) {
    }

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (! $entity instanceof LancamentoFinanceiro) {
            return;
        }

        $lancamentoId = (string) ($entity->getId() ?? '');
        $tipo = (string) ($entity->get('tipo') ?? '');
        $userId = (string) ($entity->get('modifiedById') ?? $entity->get('createdById') ?? '') ?: null;

        if ($entity->isNew()) {
            $context = $this->buildCreatedContext($entity);
            $eventName = $tipo === LancamentoFinanceiro::TIPO_ESTORNO
                ? 'lancamento.estorno_aplicado'
                : 'lancamento.created';
            $this->safeLog($eventName, $lancamentoId, $context);
            $this->safeLancamentoLog($eventName, $lancamentoId, $context, $userId);
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

        $context = [
            'tipo' => $tipo,
            'valor' => $entity->get('valor'),
            'faturaId' => (string) ($entity->get('faturaId') ?? ''),
            'changedFields' => $changed,
        ];
        $this->safeLog('lancamento.modified', $lancamentoId, $context);
        $this->safeLancamentoLog('lancamento.modified', $lancamentoId, $context, $userId);
    }

    public function afterRemove(Entity $entity, RemoveOptions $options): void
    {
        if (! $entity instanceof LancamentoFinanceiro) {
            return;
        }

        $lancamentoId = (string) ($entity->getId() ?? '');
        $userId = (string) ($entity->get('modifiedById') ?? $entity->get('createdById') ?? '') ?: null;
        $context = [
            'tipo' => (string) ($entity->get('tipo') ?? ''),
            'valor' => $entity->get('valor'),
            'faturaId' => (string) ($entity->get('faturaId') ?? ''),
            'clienteId' => (string) ($entity->get('clienteId') ?? ''),
        ];
        $this->safeLog('lancamento.removed', $lancamentoId, $context);
        $this->safeLancamentoLog('lancamento.removed', $lancamentoId, $context, $userId);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function safeLog(string $event, string $lancamentoId, array $context): void
    {
        try {
            $this->auditLog->log($event, LancamentoFinanceiro::ENTITY_TYPE, $lancamentoId, $context);
        } catch (\Throwable $e) {
            TogareLogger::event(
                'warning',
                'lancamento.audit_log_failed',
                'AuditLancamentoHook: falha ao gravar audit log (não-bloqueante)',
                ['event' => $event, 'lancamentoId' => $lancamentoId, 'error' => $e->getMessage()],
            );
        }
    }

    /**
     * Grava row append-only em `togare_lancamento_financeiro_log` (Migration V021).
     *
     * @param array<string, mixed> $context
     */
    private function safeLancamentoLog(string $event, string $lancamentoId, array $context, ?string $userId = null): void
    {
        try {
            $pdo = $this->entityManager->getPDO();
            $stmt = $pdo->prepare(
                'INSERT INTO togare_lancamento_financeiro_log (id, event, lancamento_id, user_id, payload, created_at) '
                . 'VALUES (:id, :event, :lancamento_id, :user_id, :payload, :created_at)',
            );
            if ($stmt === false) {
                return;
            }

            $payload = \json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

            $stmt->execute([
                ':id' => \bin2hex(\random_bytes(16)),
                ':event' => $event,
                ':lancamento_id' => $lancamentoId,
                ':user_id' => $userId,
                ':payload' => $payload,
                ':created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            TogareLogger::event(
                'warning',
                'lancamento.lancamento_log_failed',
                'AuditLancamentoHook: falha ao gravar togare_lancamento_financeiro_log (não-bloqueante)',
                ['event' => $event, 'lancamentoId' => $lancamentoId, 'error' => $e->getMessage()],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCreatedContext(LancamentoFinanceiro $entity): array
    {
        return [
            'descricao' => (string) ($entity->get('descricao') ?? ''),
            'tipo' => (string) ($entity->get('tipo') ?? ''),
            'valor' => $entity->get('valor'),
            'dataMovimento' => (string) $entity->get('dataMovimento'),
            'formaPagamento' => (string) ($entity->get('formaPagamento') ?? ''),
            'categoria' => (string) ($entity->get('categoria') ?? ''),
            'faturaId' => (string) ($entity->get('faturaId') ?? ''),
            'clienteId' => (string) ($entity->get('clienteId') ?? ''),
            'processoId' => (string) ($entity->get('processoId') ?? ''),
            'assignedUserId' => (string) ($entity->get('assignedUserId') ?? ''),
        ];
    }
}
