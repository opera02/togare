<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\Prazo;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Hook\Hook\AfterSave;
use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\Modules\TogareCore\Entities\Prazo;
use Espo\Modules\TogareCore\Services\Calendar\BrazilianBusinessCalendar;
use Espo\Modules\TogareCore\Services\Notification\PrazoLembreteConstants;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use PDO;
use Throwable;

/**
 * Story 4b.2 — gera entries em `togare_prazo_lembrete` quando Prazo entra em
 * cenário relevante para alerta. Subsistema togare-core/Notifications &
 * Reminders (ADR-04).
 *
 * **Order = 40** (entre AutoLink=20 e Audit=50). Esperamos rodar DEPOIS do
 * payload estar completo (cliente/parteContraria auto-vinculados,
 * dataCumprimento default aplicada) mas ANTES do Audit registrar o estado final.
 *
 * **5 cenários ENQUEUE (Decisão #3 da Story 4b.2):**
 *  1. Prazo CRIADO em `pendente` → enfileira D-7/D-3/D-1.
 *  2. Status TRANSICIONA para `pendente` (de rascunho/aguardando_*) → idem.
 *  3. Status TRANSICIONA para `atrasado_reagendado` → 1 marco imediato.
 *  4. Status TRANSICIONA para `aguardando_cliente` → 1 marco imediato.
 *  5. Status TRANSICIONA para `aguardando_correcao` → 1 marco imediato.
 *
 * **3 cenários CANCEL (Decisão #3):**
 *  6. Status TRANSICIONA para final (`protocolado`/`descartado`/`ciencia_renuncia`/
 *     `acompanhamento`) → cancela todos pending.
 *  7. `dataFatal` muda → cancela TODOS pending + RE-ENQUEUE com nova data.
 *  8. `assignedUserId` muda → cancela pending do user antigo + RE-ENQUEUE para
 *     novo destinatário set.
 *
 * **Defesas vinculantes (AC2 + Defesa #1 das Dev Notes):**
 *  - Try/catch \\Throwable defensivo: hook NUNCA bloqueia save do Prazo.
 *  - UNIQUE INDEX (prazo_id, user_id, marco) no banco protege contra
 *    duplicação em race / re-execução.
 *  - INSERT IGNORE (MariaDB) / INSERT OR IGNORE (SQLite) driver-aware.
 *  - Se 0 destinatários (user inexistente + sem Sócio/Admin) → log info + skip.
 *
 * **Hora de disparo (Decisão #6):**
 *  - D-X = `subtractBusinessDays(dataFatal, X)` às 09:00 BRT.
 *  - Status dirigido = `now` (imediato).
 *
 * @implements AfterSave<Entity>
 */
final class EnqueuePrazoLembretesHook implements AfterSave
{
    public static int $order = 40;

    /** Status enquanto "ainda em jogo" — alerta de proximidade faz sentido. */
    private const STATUS_PENDENTE_FAMILIA = [
        Prazo::STATUS_PENDENTE,
        Prazo::STATUS_REAGENDADO,
        Prazo::STATUS_AGUARDANDO_CLIENTE,
        Prazo::STATUS_AGUARDANDO_CORRECAO,
    ];

    /** Status finais — qualquer pending para o Prazo é cancelado. */
    private const STATUS_FINAIS = [
        Prazo::STATUS_PROTOCOLADO,
        Prazo::STATUS_DESCARTADO,
        Prazo::STATUS_CIENCIA_RENUNCIA,
        Prazo::STATUS_ACOMPANHAMENTO,
    ];

    /** Status dirigidos → marco textual (Decisão #3). */
    private const STATUS_DIRIGIDO_TO_MARCO = [
        Prazo::STATUS_REAGENDADO => PrazoLembreteConstants::MARCO_STATUS_REAGENDADO,
        Prazo::STATUS_AGUARDANDO_CLIENTE => PrazoLembreteConstants::MARCO_STATUS_AGUARDANDO_CLIENTE,
        Prazo::STATUS_AGUARDANDO_CORRECAO => PrazoLembreteConstants::MARCO_STATUS_AGUARDANDO_CORRECAO,
    ];

    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly BrazilianBusinessCalendar $calendar,
        private readonly AuditLogContract $auditLog,
    ) {
    }

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (! $entity instanceof Prazo) {
            return;
        }
        try {
            $this->process($entity);
        } catch (Throwable $e) {
            // Defensa #1: hook NUNCA bloqueia save (try/catch \\Throwable obrigatório).
            TogareLogger::event(
                'warning',
                'prazo.lembrete.enqueue_failed',
                'EnqueuePrazoLembretesHook: falha ao processar; save do Prazo segue.',
                [
                    'prazoId' => (string) ($entity->getId() ?? ''),
                    'exception' => $e->getMessage(),
                    'class' => $e::class,
                ],
            );
        }
    }

    private function process(Prazo $entity): void
    {
        $prazoId = (string) ($entity->getId() ?? '');
        if ($prazoId === '') {
            // Sem ID persistido → não há como gravar prazo_id. AfterSave deveria
            // sempre ter ID; defensivo apenas.
            return;
        }

        $isNew = $entity->isNew();
        $newStatus = (string) ($entity->get('status') ?? '');

        // === Cenário 6 — STATUS FINAL (early-exit pós cancel). ===
        if (! $isNew
            && $entity->isAttributeChanged('status')
            && \in_array($newStatus, self::STATUS_FINAIS, true)
        ) {
            $this->cancelPendingForPrazo($prazoId, 'status_final');
            return;
        }

        // === Cenários 7 e 8 — dataFatal mudou OU assignedUser mudou ===
        $dataFatalChanged = ! $isNew
            && $entity->isAttributeChanged('dataFatal')
            && \in_array($newStatus, self::STATUS_PENDENTE_FAMILIA, true);

        $assigneeChanged = ! $isNew
            && $entity->isAttributeChanged('assignedUserId')
            && \in_array($newStatus, self::STATUS_PENDENTE_FAMILIA, true);

        if ($dataFatalChanged || $assigneeChanged) {
            $this->cancelPendingForPrazo(
                $prazoId,
                $dataFatalChanged ? 'datafatal_changed' : 'assignee_changed',
            );
            // Continua execução para re-enqueue com novos parâmetros.
        }

        // === Cenários 1 e 2 — ENQUEUE de marcos D-7/D-3/D-1 ===
        $shouldEnqueueDeadline =
            ($isNew && \in_array($newStatus, self::STATUS_PENDENTE_FAMILIA, true))
            || (! $isNew && $entity->isAttributeChanged('status') && \in_array($newStatus, self::STATUS_PENDENTE_FAMILIA, true))
            || $dataFatalChanged
            || $assigneeChanged;

        if ($shouldEnqueueDeadline) {
            $dataFatal = (string) ($entity->get('dataFatal') ?? '');
            if ($dataFatal !== '') {
                $destinatarios = $this->findDestinatarios($entity);
                if ($destinatarios !== []) {
                    $this->enqueueDeadlineMarcos($prazoId, $dataFatal, $destinatarios);
                } else {
                    TogareLogger::event(
                        'info',
                        'prazo.lembrete.no_recipients',
                        'EnqueuePrazoLembretesHook: nenhum destinatário ativo para o Prazo (assignedUser ausente E sem Sócio/Admin ativo).',
                        ['prazoId' => $prazoId],
                    );
                }
            }
        }

        // === Cenários 3-5 — ENQUEUE de status dirigido ===
        if (! $isNew
            && $entity->isAttributeChanged('status')
            && isset(self::STATUS_DIRIGIDO_TO_MARCO[$newStatus])
        ) {
            $marco = self::STATUS_DIRIGIDO_TO_MARCO[$newStatus];
            $destinatarios = $this->findDestinatarios($entity);
            if ($destinatarios !== []) {
                $this->enqueueStatusDirigido($prazoId, $marco, $destinatarios);
            }
        }
    }

    /**
     * Calcula 3 datas (D-7/D-3/D-1) via `subtractBusinessDays` e enfileira
     * 1 entry por (marco × destinatário). UNIQUE INDEX bloqueia duplicação.
     *
     * @param list<string> $destinatariosIds
     */
    private function enqueueDeadlineMarcos(string $prazoId, string $dataFatal, array $destinatariosIds): void
    {
        try {
            $startDate = new DateTimeImmutable($dataFatal, new DateTimeZone(PrazoLembreteConstants::TZ_BRT));
        } catch (Throwable $e) {
            TogareLogger::event(
                'warning',
                'prazo.lembrete.parse_dataFatal_failed',
                'EnqueuePrazoLembretesHook: dataFatal inválida — pulando enqueue de deadline.',
                ['prazoId' => $prazoId, 'dataFatal' => $dataFatal, 'error' => $e->getMessage()],
            );
            return;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $todayBrtYmd = $now
            ->setTimezone(new DateTimeZone(PrazoLembreteConstants::TZ_BRT))
            ->format('Y-m-d');
        $dataFatalYmd = $startDate->format('Y-m-d');

        foreach (PrazoLembreteConstants::DEADLINE_OFFSETS as $marco => $days) {
            if ($marco === PrazoLembreteConstants::MARCO_D0 && $dataFatalYmd < $todayBrtYmd) {
                continue;
            }
            $marcoDate = $this->calendar->subtractBusinessDays($startDate, $days);
            // Story 4b.3 (Decisão #2) — hora/minuto de disparo POR marco. D-0
            // dispara 00:05 BRT (NFR5 + AC original epic linha 1144); D-7/D-3/
            // D-1 mantêm 09:00 BRT (Decisão #6 da Story 4b.2).
            $hora = PrazoLembreteConstants::HORA_DISPARO_BY_MARCO[$marco] ?? PrazoLembreteConstants::HORA_DISPARO;
            $minuto = PrazoLembreteConstants::MINUTO_DISPARO_BY_MARCO[$marco] ?? 0;
            $scheduledForBrt = $marcoDate->setTime($hora, $minuto, 0);
            $scheduledForUtc = $scheduledForBrt->setTimezone(new DateTimeZone('UTC'));

            // Decisão de não enfileirar marcos PASSADOS (`scheduled_for < now`)
            // está intencionalmente FORA: a Migration permite, e o Job vai
            // processar imediatamente (queremos que advogado receba o "atrasou
            // já chegou na faixa D-3" mesmo se o Prazo foi cadastrado tarde).

            foreach ($destinatariosIds as $userId) {
                $this->insertLembrete(
                    $prazoId,
                    $userId,
                    $marco,
                    $scheduledForUtc->format('Y-m-d H:i:s'),
                    $now->format('Y-m-d H:i:s'),
                );
            }
        }
    }

    /**
     * Status dirigido = 1 marco imediato por destinatário (`scheduled_for=now`).
     *
     * @param list<string> $destinatariosIds
     */
    private function enqueueStatusDirigido(string $prazoId, string $marco, array $destinatariosIds): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $nowStr = $now->format('Y-m-d H:i:s');

        foreach ($destinatariosIds as $userId) {
            $this->insertLembrete($prazoId, $userId, $marco, $nowStr, $nowStr);
        }
    }

    /**
     * INSERT IGNORE driver-aware. Falha silenciosa em duplicação (UNIQUE).
     * Grava `audit.notification.scheduled` quando entry é realmente inserida
     * (rowCount > 0).
     */
    private function insertLembrete(
        string $prazoId,
        string $userId,
        string $marco,
        string $scheduledFor,
        string $now,
    ): void {
        $pdo = $this->entityManager->getPDO();
        $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';

        $sql = $isMysql
            ? 'INSERT IGNORE INTO togare_prazo_lembrete
                (id, prazo_id, user_id, marco, canal, scheduled_for, status, attempt_count, created_at, modified_at)
               VALUES (:id, :prazo_id, :user_id, :marco, :canal, :scheduled_for, :status, 0, :created_at, :modified_at)'
            : 'INSERT OR IGNORE INTO togare_prazo_lembrete
                (id, prazo_id, user_id, marco, canal, scheduled_for, status, attempt_count, created_at, modified_at)
               VALUES (:id, :prazo_id, :user_id, :marco, :canal, :scheduled_for, :status, 0, :created_at, :modified_at)';

        $id = \bin2hex(\random_bytes(12));

        try {
            $stmt = $pdo->prepare($sql);
            if ($stmt === false) {
                return;
            }
            $stmt->execute([
                ':id' => $id,
                ':prazo_id' => $prazoId,
                ':user_id' => $userId,
                ':marco' => $marco,
                ':canal' => PrazoLembreteConstants::CANAL_BOTH,
                ':scheduled_for' => $scheduledFor,
                ':status' => PrazoLembreteConstants::STATUS_PENDING,
                ':created_at' => $now,
                ':modified_at' => $now,
            ]);

            if ($stmt->rowCount() > 0) {
                $this->safeAudit('audit.notification.scheduled', $id, [
                    'prazoId' => $prazoId,
                    'userId' => $userId,
                    'marco' => $marco,
                    'scheduledFor' => $scheduledFor,
                    'canal' => PrazoLembreteConstants::CANAL_BOTH,
                ]);
            }
        } catch (Throwable $e) {
            TogareLogger::event(
                'warning',
                'prazo.lembrete.insert_failed',
                'EnqueuePrazoLembretesHook: falha ao inserir entry — segue silencioso.',
                [
                    'prazoId' => $prazoId,
                    'userId' => $userId,
                    'marco' => $marco,
                    'error' => $e->getMessage(),
                ],
            );
        }
    }

    /**
     * Cancela TODAS as entries `pending` de um Prazo via HARD-DELETE (Decisão
     * D1.1 da Story 4b.2).
     *
     * **Por quê DELETE em vez de UPDATE status='cancelled'?** O UNIQUE INDEX
     * `(prazo_id, user_id, marco)` é protector primário de idempotência (Decisão
     * #4 + AC7). Se cancel for soft (UPDATE status=cancelled), uma re-enqueue
     * subsequente (cenário 7 dataFatal mudou ou cenário 8 assignedUser mudou)
     * bate em UNIQUE constraint e o INSERT IGNORE skipa silenciosamente — o
     * usuário acabaria sem lembretes.
     *
     * Solução: tabela `togare_prazo_lembrete` é uma FILA DE TRABALHO; history
     * fica em `togare_audit_log` (tabela apropriada para auditoria — NFR10
     * append-only 24m). Cada entry deletada gera um evento
     * `audit.notification.cancelled` com snapshot completo no context (id
     * original, marco, reason). Recuperação histórica = query no audit log.
     *
     * @param non-empty-string $reason 'status_final'|'datafatal_changed'|'assignee_changed'
     */
    private function cancelPendingForPrazo(string $prazoId, string $reason): void
    {
        $pdo = $this->entityManager->getPDO();

        try {
            $select = $pdo->prepare(
                'SELECT id, user_id, marco, scheduled_for, canal, attempt_count
                 FROM togare_prazo_lembrete
                 WHERE prazo_id = :prazo_id AND status = :pending'
            );
            if ($select === false) {
                return;
            }
            $select->execute([
                ':prazo_id' => $prazoId,
                ':pending' => PrazoLembreteConstants::STATUS_PENDING,
            ]);
            $rows = $select->fetchAll(PDO::FETCH_ASSOC);

            if ($rows === [] || $rows === false) {
                return;
            }

            $delete = $pdo->prepare(
                'DELETE FROM togare_prazo_lembrete
                 WHERE prazo_id = :prazo_id AND status = :pending'
            );
            if ($delete === false) {
                return;
            }
            $delete->execute([
                ':prazo_id' => $prazoId,
                ':pending' => PrazoLembreteConstants::STATUS_PENDING,
            ]);

            foreach ($rows as $row) {
                $this->safeAudit('audit.notification.cancelled', (string) $row['id'], [
                    'prazoLembreteId' => (string) $row['id'],
                    'prazoId' => $prazoId,
                    'userId' => (string) $row['user_id'],
                    'marco' => (string) $row['marco'],
                    'scheduledFor' => (string) $row['scheduled_for'],
                    'canal' => (string) $row['canal'],
                    'attemptCount' => (int) $row['attempt_count'],
                    'reason' => $reason,
                ]);
            }
        } catch (Throwable $e) {
            TogareLogger::event(
                'warning',
                'prazo.lembrete.cancel_failed',
                'EnqueuePrazoLembretesHook: falha ao cancelar pendentes.',
                ['prazoId' => $prazoId, 'reason' => $reason, 'error' => $e->getMessage()],
            );
        }
    }

    /**
     * Resolve destinatários para o Prazo: assignedUserId (se houver) +
     * todos os usuários ativos com role `Sócio/Admin`. Distinct por userId.
     *
     * @return list<string>
     */
    private function findDestinatarios(Prazo $entity): array
    {
        $ids = [];

        $assignedUserId = $entity->get('assignedUserId');
        if (\is_string($assignedUserId) && $assignedUserId !== '') {
            $ids[] = $assignedUserId;
        }

        $socioAdminIds = $this->findSocioAdminUserIds();
        foreach ($socioAdminIds as $sid) {
            if (! \in_array($sid, $ids, true)) {
                $ids[] = $sid;
            }
        }

        return \array_values($ids);
    }

    /**
     * Query JOIN user/user_role/role retornando lista de userIds ativos com
     * role exato `Sócio/Admin` (case-sensitive, seed `socio-admin.json`).
     *
     * Em testes unit (PDO sqlite::memory: sem essas tabelas), captura erro
     * silenciosamente e retorna [] — comportamento correto e defensivo.
     *
     * @return list<string>
     */
    private function findSocioAdminUserIds(): array
    {
        $pdo = $this->entityManager->getPDO();

        try {
            // EspoCRM 9.x: tabela join é `role_user` (NÃO `user_role`).
            // Detectado no smoke F1 da Story 4b.2 — em prod, query original
            // retornava [] silenciosamente via try/catch, Sócio/Admin nunca
            // recebia lembrete.
            $stmt = $pdo->prepare(
                'SELECT DISTINCT u.id FROM user u
                 INNER JOIN role_user ru ON ru.user_id = u.id AND ru.deleted = 0
                 INNER JOIN role r ON r.id = ru.role_id AND r.deleted = 0
                 WHERE r.name = :role_name AND u.is_active = 1 AND u.deleted = 0'
            );
            if ($stmt === false) {
                return [];
            }
            $stmt->execute([':role_name' => PrazoLembreteConstants::ROLE_SOCIO_ADMIN_NAME]);
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if ($rows === false) {
                return [];
            }

            return \array_values(\array_map(static fn ($v): string => (string) $v, $rows));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function safeAudit(string $event, string $entityId, array $context): void
    {
        try {
            $this->auditLog->log($event, 'PrazoLembrete', $entityId, $context);
        } catch (Throwable $e) {
            TogareLogger::event(
                'warning',
                'prazo.lembrete.audit_failed',
                'EnqueuePrazoLembretesHook: falha ao registrar audit log.',
                ['event' => $event, 'entityId' => $entityId, 'error' => $e->getMessage()],
            );
        }
    }
}
