<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\Prazo;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\Modules\TogareCore\Entities\Prazo;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Audit afterSave em `Prazo` (Story 4a.3 + 4a.3.1).
 *
 * Story 4a.3 emitia 4 eventos derivados (`audit.prazo.confirmed/descartado/cumprido/revertido`).
 * Story 4a.3.1 reescreve o derivedEventMap para 7 eventos alinhados ao status enum
 * 8-visíveis + 1-oculto da v1.1 (ADR-03 v1.1 §1):
 *
 *   STATUS_PENDENTE        → 'audit.prazo.bound'              (substitui audit.prazo.confirmed)
 *   STATUS_DESCARTADO      → 'audit.prazo.descartado'         (preservado — emitido mesmo com dropdown UI oculto)
 *   STATUS_PROTOCOLADO     → 'audit.prazo.protocolado'        (substitui audit.prazo.cumprido)
 *   STATUS_REAGENDADO      → 'audit.prazo.reagendado'         (NOVO; context inclui motivoReagendamento — FR37)
 *   STATUS_AGUARDANDO_CLIENTE → 'audit.prazo.aguardando_cliente' (NOVO)
 *   STATUS_ACOMPANHAMENTO  → 'audit.prazo.acompanhamento'     (NOVO)
 *   [transição protocolado→pendente] → 'audit.prazo.revertido' (evento puro de transição,
 *                                       NÃO baseado em mapping de status)
 *
 * Status `aguardando_correcao` e `ciencia_renuncia` são cobertos pelo evento genérico
 * `audit.prazo.modified` (com previousStatus/newStatus no context) — alinhado a FR37
 * que exige todas as transições auditadas, não exige evento dedicado por status.
 *
 * SENSITIVE_FIELDS expandido na 4a.3.1 (+3): motivoReagendamento, prioridade, tipoPrazo.
 *
 * Cobre FR37 + NFR10 (audit log append-only, retenção 24m). Persiste em
 * `togare_audit_log` via `AuditLogService` (Story 2.4).
 *
 * Try/catch `\Throwable` com fallback log via TogareLogger — audit nunca pode
 * bloquear save (regra FR37).
 *
 * @implements AfterSave<Prazo>
 */
final class AuditPrazoHook implements AfterSave
{
    public static int $order = 50;

    /** @var list<string> Allowlist de atributos cuja mudança merece audit. */
    private const SENSITIVE_FIELDS = [
        'status',
        'processoId',
        'assignedUserId',
        'dataFatal',
        'atoCodigo',
        'parserRegraVersao',
        'motivoReagendamento',
        'prioridade',
        'tipoPrazo',
        // Story 4a.5.1 — operacionalmente importante (afeta briefing do dia).
        'dataCumprimento',
    ];

    public function __construct(
        private readonly AuditLogContract $auditLog,
    ) {
    }

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (! $entity instanceof Prazo) {
            return;
        }

        $prazoId = (string) $entity->getId();

        if ($entity->isNew()) {
            $this->safeLog(
                'audit.prazo.created',
                $prazoId,
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
            'audit.prazo.modified',
            $prazoId,
            $this->buildModifiedContext($entity, $changed),
            'modified',
        );

        if (! \in_array('status', $changed, true)) {
            return;
        }

        $newStatus = $entity->get('status');
        $previousStatus = $entity->getFetched('status');

        // Evento de transição puro: protocolado → pendente vira audit.prazo.revertido
        // (Story 4a.3.1 — Decisão ADR-03 v1.1 §1: revertido deixa de ser status e
        // vira evento puro; preserva auditabilidade da reabertura sem custo de schema).
        if ($previousStatus === Prazo::STATUS_PROTOCOLADO && $newStatus === Prazo::STATUS_PENDENTE) {
            $this->safeLog(
                'audit.prazo.revertido',
                $prazoId,
                [
                    'processoId' => $entity->get('processoId'),
                    'previousStatus' => $previousStatus,
                    'dataFatal' => $entity->get('dataFatal'),
                ],
                'revertido',
            );
            return;
        }

        // Story 4a.3.1: 6 eventos derivados de status (alinhados ADR-03 v1.1 §1).
        $derivedEventMap = [
            Prazo::STATUS_PENDENTE => 'audit.prazo.bound',
            Prazo::STATUS_DESCARTADO => 'audit.prazo.descartado',
            Prazo::STATUS_PROTOCOLADO => 'audit.prazo.protocolado',
            Prazo::STATUS_REAGENDADO => 'audit.prazo.reagendado',
            Prazo::STATUS_AGUARDANDO_CLIENTE => 'audit.prazo.aguardando_cliente',
            Prazo::STATUS_ACOMPANHAMENTO => 'audit.prazo.acompanhamento',
        ];

        if (! isset($derivedEventMap[$newStatus])) {
            return;
        }

        $context = [
            'processoId' => $entity->get('processoId'),
            'previousStatus' => $previousStatus,
            'dataFatal' => $entity->get('dataFatal'),
            'atoCodigo' => $entity->get('atoCodigo'),
        ];

        // FR37: motivoReagendamento incluído no context quando emite reagendado.
        if ($newStatus === Prazo::STATUS_REAGENDADO) {
            $context['motivoReagendamento'] = $entity->get('motivoReagendamento');
        }

        $this->safeLog(
            $derivedEventMap[$newStatus],
            $prazoId,
            $context,
            $newStatus,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCreatedContext(Prazo $entity): array
    {
        return [
            'status' => $entity->get('status'),
            'source' => $entity->get('source'),
            'sourcePubId' => $entity->get('sourcePubId'),
            'processoId' => $entity->get('processoId'),
            'numeroProcessoOriginal' => $entity->get('numeroProcessoOriginal'),
            'assignedUserId' => $entity->get('assignedUserId'),
            'atoCodigo' => $entity->get('atoCodigo'),
            'dataFatal' => $entity->get('dataFatal'),
            'confidence' => $entity->get('confidence'),
            'parserRegraVersao' => $entity->get('parserRegraVersao'),
            'prioridade' => $entity->get('prioridade'),
            'tipoPrazo' => $entity->get('tipoPrazo'),
            'clienteId' => $entity->get('clienteId'),
            'parteContrariaId' => $entity->get('parteContrariaId'),
            // Story 4a.5.1 — registrar dataCumprimento (default aplicado por
            // DefaultDataCumprimentoHook em isNew, ou override manual do user).
            'dataCumprimento' => $entity->get('dataCumprimento'),
        ];
    }

    /**
     * @param list<string> $changed
     * @return array<string, mixed>
     */
    private function buildModifiedContext(Prazo $entity, array $changed): array
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
            $this->auditLog->log($type, 'Prazo', $entityId, $context);
        } catch (\Throwable $e) {
            TogareLogger::event(
                'error',
                'audit.hook.failed',
                'AuditPrazoHook: falha ao registrar ' . $kind,
                ['error' => $e->getMessage()],
            );
        }
    }
}
