<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\Fatura;

use Espo\Core\Hook\Hook\AfterRemove;
use Espo\Core\Hook\Hook\AfterSave;
use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\Modules\TogareCore\Entities\Fatura;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\RemoveOptions;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Audit AfterSave + AfterRemove em Fatura (Story 6.3 — FR37, NFR10).
 *
 * Eventos canônicos:
 *  - fatura.created          — isNew
 *  - fatura.modified         — campos sensíveis mudaram (status, valorBruto,
 *                              dataVencimento, contratoHonorariosId)
 *  - fatura.removed          — AfterRemove
 *
 * Pattern AuditContratoHook. Persiste em `togare_audit_log` via AuditLogContract
 * E em `togare_fatura_log` via PDO direto (V021; reusado por Story 8 LGPD futura
 * como signal de "transição auditada" — mesmo papel do togare_documento_log).
 *
 * **Anti-loop:** respeita flag `silent=true` E `_fromRecompute=true` no SaveOptions
 * — saves do FaturaSaldoService::recompute NÃO disparam audit (caso contrário,
 * cada lançamento gera 1 audit do lançamento + 1 audit do recompute da Fatura).
 *
 * Try/catch \Throwable — audit nunca bloqueia save/remove (regra FR37).
 *
 * Order = 50.
 *
 * @implements AfterSave<Fatura>
 * @implements AfterRemove<Fatura>
 */
final class AuditFaturaHook implements AfterSave, AfterRemove
{
    public static int $order = 50;

    /** @var list<string> */
    private const SENSITIVE_FIELDS = [
        'status',
        'valorBruto',
        'dataVencimento',
        'contratoHonorariosId',
        'assignedUserId',
        'motivoCancelamento',
    ];

    public function __construct(
        private readonly AuditLogContract $auditLog,
        private readonly EntityManager $entityManager,
    ) {
    }

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (! $entity instanceof Fatura) {
            return;
        }

        // Anti-loop: saves vindos do FaturaSaldoService NÃO geram audit aqui
        // (já tem audit dedicado fatura.saldo.recomputed lá).
        if ($this->isFromRecompute($options)) {
            return;
        }

        $faturaId = (string) ($entity->getId() ?? '');
        $userId = (string) ($entity->get('modifiedById') ?? $entity->get('createdById') ?? '') ?: null;

        if ($entity->isNew()) {
            $context = $this->buildCreatedContext($entity);
            $this->safeLog('fatura.created', $faturaId, $context);
            $this->safeFaturaLog('fatura.created', $faturaId, $context, $userId);
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
            'numero' => (string) ($entity->get('numero') ?? ''),
            'status' => (string) ($entity->get('status') ?? ''),
            'changedFields' => $changed,
        ];
        $this->safeLog('fatura.modified', $faturaId, $context);
        $this->safeFaturaLog('fatura.modified', $faturaId, $context, $userId);
    }

    public function afterRemove(Entity $entity, RemoveOptions $options): void
    {
        if (! $entity instanceof Fatura) {
            return;
        }

        $faturaId = (string) ($entity->getId() ?? '');
        $userId = (string) ($entity->get('modifiedById') ?? $entity->get('createdById') ?? '') ?: null;
        $context = [
            'numero' => (string) ($entity->get('numero') ?? ''),
            'status' => (string) ($entity->get('status') ?? ''),
            'clienteId' => (string) ($entity->get('clienteId') ?? ''),
            'valorBruto' => $entity->get('valorBruto'),
        ];

        $this->safeLog('fatura.removed', $faturaId, $context);
        $this->safeFaturaLog('fatura.removed', $faturaId, $context, $userId);
    }

    private function isFromRecompute(SaveOptions $options): bool
    {
        return $options->get('silent') === true && $options->get('_fromRecompute') === true;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function safeLog(string $event, string $faturaId, array $context): void
    {
        try {
            $this->auditLog->log($event, Fatura::ENTITY_TYPE, $faturaId, $context);
        } catch (\Throwable $e) {
            TogareLogger::event(
                'warning',
                'fatura.audit_log_failed',
                'AuditFaturaHook: falha ao gravar audit log (não-bloqueante)',
                ['event' => $event, 'faturaId' => $faturaId, 'error' => $e->getMessage()],
            );
        }
    }

    /**
     * Grava row append-only em `togare_fatura_log` (Migration V021).
     * Try/catch defensivo — falha não bloqueia save.
     *
     * @param array<string, mixed> $context
     */
    private function safeFaturaLog(string $event, string $faturaId, array $context, ?string $userId = null): void
    {
        try {
            $pdo = $this->entityManager->getPDO();
            $stmt = $pdo->prepare(
                'INSERT INTO togare_fatura_log (id, event, fatura_id, user_id, payload, created_at) '
                . 'VALUES (:id, :event, :fatura_id, :user_id, :payload, :created_at)',
            );
            if ($stmt === false) {
                return;
            }

            $payload = \json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

            $stmt->execute([
                ':id' => \bin2hex(\random_bytes(16)),
                ':event' => $event,
                ':fatura_id' => $faturaId,
                ':user_id' => $userId,
                ':payload' => $payload,
                ':created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            TogareLogger::event(
                'warning',
                'fatura.fatura_log_failed',
                'AuditFaturaHook: falha ao gravar togare_fatura_log (não-bloqueante)',
                ['event' => $event, 'faturaId' => $faturaId, 'error' => $e->getMessage()],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCreatedContext(Fatura $entity): array
    {
        return [
            'numero' => (string) ($entity->get('numero') ?? ''),
            'descricao' => (string) ($entity->get('descricao') ?? ''),
            'status' => (string) ($entity->get('status') ?? ''),
            'dataEmissao' => (string) $entity->get('dataEmissao'),
            'dataVencimento' => (string) $entity->get('dataVencimento'),
            'valorBruto' => $entity->get('valorBruto'),
            'clienteId' => (string) ($entity->get('clienteId') ?? ''),
            'processoId' => (string) ($entity->get('processoId') ?? ''),
            'contratoHonorariosId' => (string) ($entity->get('contratoHonorariosId') ?? ''),
            'assignedUserId' => (string) ($entity->get('assignedUserId') ?? ''),
        ];
    }
}
