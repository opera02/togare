<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Jobs;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\Modules\TogareCore\Services\Notification\EmailNotificationService;
use Espo\Modules\TogareCore\Services\Notification\PrazoLembreteConstants;
use Espo\Modules\TogareCore\Services\Notification\StreamNotificationService;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\EntityManager;
use PDO;
use Throwable;

/**
 * Story 4b.2 — varre `togare_prazo_lembrete` a cada 5min e despacha
 * notificações via canal duplo (pop-up + email) com retry SMTP exponencial
 * (Decisão #6 + #7).
 *
 * **Cron:** `*\/5 * * * *` (registrado em `Resources/metadata/app/scheduledJobs.json`).
 *
 * **Fluxo (AC3 + AC9):**
 *  1. SELECT batch 100 entries `WHERE status='pending' AND scheduled_for <= NOW()`,
 *     ORDER BY scheduled_for ASC, id ASC (FIFO determinístico).
 *  2. Para cada entry (try/catch \\Throwable individual):
 *     a. Resolve Prazo via getEntityById — se não existe (deletado), marca
 *        entry como `status='cancelled'` (defensivo).
 *     b. Resolve Preferences do user (Preferences.togareLembreteConfig) e
 *        cruza com canal configurado no lembrete via PrazoLembreteConstants::resolveCanal.
 *     c. Se user desativou marco (canal=null) → marca `status='cancelled'`.
 *     d. Constrói subject/body via PrazoLembreteConstants::labelsForMarco +
 *        EmailNotificationService::renderHtml/renderText.
 *     e. Tenta canal popup (se canal in [popup, both]) — síncrono, <200ms.
 *     f. Tenta canal email (se canal in [email, both]) — pode timeout 30s.
 *     g. Se PELO MENOS UM canal sucesso → status='sent', sent_at=NOW(),
 *        audit `notification.delivered` com canais entregues.
 *     h. Se ambos falham → attempt_count++, scheduled_for=NOW()+backoff[attempt],
 *        status permanece 'pending'.
 *     i. Se attempt_count >= 3 (4ª passagem com falha total) → status='failed',
 *        audit `notification.email_failed`.
 *
 * **Decisão simplificada D2.1 (Dev — 2026-05-09):** se canal=both e popup OK
 * + email FAIL, marca status=sent (SLA NFR37 cumprido pelo popup em <1min).
 * Email retry isolado é Growth (`audit.notification.email_partial_failure`
 * gravado para diagnóstico).
 *
 * **Pool:** scheduledJob nativo do EspoCRM 9.x; sem pool dedicado (mesmo
 * pattern de TogareQueueCleanupJob).
 */
final class PrazoReminderJob implements Job
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly StreamNotificationService $streamService,
        private readonly EmailNotificationService $emailService,
        private readonly AuditLogContract $auditLog,
    ) {
    }

    public function run(Data $data): void
    {
        $pdo = $this->entityManager->getPDO();

        try {
            $stmt = $pdo->prepare(
                'SELECT id, prazo_id, user_id, marco, canal, scheduled_for, status, attempt_count
                 FROM togare_prazo_lembrete
                 WHERE status = :pending AND scheduled_for <= :now
                 ORDER BY scheduled_for ASC, id ASC
                 LIMIT ' . self::BATCH_SIZE
            );
            $stmt->execute([
                ':pending' => PrazoLembreteConstants::STATUS_PENDING,
                ':now' => $this->nowUtc(),
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            TogareLogger::event(
                'error',
                'prazo.reminder.job.select_failed',
                'PrazoReminderJob.run: SELECT batch falhou.',
                ['error' => $e->getMessage()],
            );
            return;
        }

        if ($rows === [] || $rows === false) {
            return;
        }

        $processed = 0;
        $delivered = 0;
        $failed = 0;
        $cancelled = 0;

        foreach ($rows as $row) {
            try {
                $outcome = $this->processEntry($pdo, $row);
                $processed++;
                if ($outcome === 'delivered') {
                    $delivered++;
                }
                if ($outcome === 'failed') {
                    $failed++;
                }
                if ($outcome === 'cancelled') {
                    $cancelled++;
                }
            } catch (Throwable $e) {
                // Por-entry catch — falha em 1 entry NÃO interrompe loop.
                TogareLogger::event(
                    'error',
                    'prazo.reminder.job.entry_failed',
                    'PrazoReminderJob.processEntry falhou (entry isolada).',
                    [
                        'lembreteId' => (string) $row['id'],
                        'prazoId' => (string) $row['prazo_id'],
                        'error' => $e->getMessage(),
                    ],
                );
            }
        }

        TogareLogger::event(
            'info',
            'prazo.reminder.job.batch_done',
            'PrazoReminderJob: batch processado.',
            [
                'total' => \count($rows),
                'processed' => $processed,
                'delivered' => $delivered,
                'failed' => $failed,
                'cancelled' => $cancelled,
            ],
        );
    }

    /**
     * Processa 1 entry. Retorna 'delivered'|'retry_pending'|'failed'|'cancelled'|'skipped'.
     *
     * @param array<string, mixed> $row
     */
    private function processEntry(PDO $pdo, array $row): string
    {
        $lembreteId = (string) $row['id'];
        $prazoId = (string) $row['prazo_id'];
        $userId = (string) $row['user_id'];
        $marco = (string) $row['marco'];
        $configuredCanal = (string) $row['canal'];
        $attemptCount = (int) $row['attempt_count'];

        // Resolve Prazo — se inexistente, cancela.
        $prazo = $this->entityManager->getEntityById('Prazo', $prazoId);
        if ($prazo === null) {
            $this->markCancelled($pdo, $lembreteId, 'prazo_not_found');
            return 'cancelled';
        }

        // Resolve Preferences e canal final por marco.
        $userConfig = $this->resolveUserPreferences($userId);
        $resolvedCanal = PrazoLembreteConstants::resolveCanal($userConfig, $marco);

        if ($resolvedCanal === null) {
            // User desativou esse marco/canal nas Preferences.
            $this->markCancelled($pdo, $lembreteId, 'user_disabled_channel');
            return 'cancelled';
        }

        // Honra a interseção: configured (no INSERT do hook = 'both' default) ∩ resolved (preferences).
        // Se configured=='popup' e resolved=='email' → cai pra 'popup' (mais restritivo);
        // mas no MVP atual configured=='both' sempre, então resolved é a regra.
        $finalCanal = $resolvedCanal;

        // Construir subject + body.
        $cnj = (string) ($prazo->get('numeroProcessoOriginal') ?? '');
        $descricao = (string) ($prazo->get('descricao') ?? '');
        $dataFatal = (string) ($prazo->get('dataFatal') ?? '');
        $dataCumprimento = $prazo->get('dataCumprimento');
        $labels = PrazoLembreteConstants::labelsForMarco($marco, $cnj);

        $bodyVars = [
            'marcoLabel' => $marco,
            'marcoTitle' => $labels['title'],
            'cnj' => $cnj,
            'descricao' => $descricao !== '' ? $descricao : '(sem descrição)',
            'dataFatal' => $dataFatal,
            'dataCumprimento' => \is_string($dataCumprimento) ? $dataCumprimento : null,
            'prazoUrl' => $this->emailService->buildPrazoUrl($prazoId),
            'hedgeJuridico' => PrazoLembreteConstants::HEDGE_JURIDICO,
        ];

        $subject = $labels['subject'];
        $bodyHtml = EmailNotificationService::renderHtml($bodyVars);

        // Tentar canais.
        $popupOk = false;
        $emailOk = false;
        $popupError = null;
        $emailError = null;
        $startedAt = \microtime(true);

        if ($finalCanal === PrazoLembreteConstants::CANAL_POPUP || $finalCanal === PrazoLembreteConstants::CANAL_BOTH) {
            try {
                $this->streamService->notifyPrazoReminder(
                    $userId,
                    $subject,
                    "Prazo: {$cnj} - {$labels['title']}",
                    $prazoId,
                    $marco,
                );
                $popupOk = true;
            } catch (Throwable $e) {
                $popupError = $e->getMessage();
            }
        }

        if ($finalCanal === PrazoLembreteConstants::CANAL_EMAIL || $finalCanal === PrazoLembreteConstants::CANAL_BOTH) {
            try {
                $this->emailService->notify($userId, $subject, $bodyHtml);
                $emailOk = true;
            } catch (Throwable $e) {
                $emailError = $e->getMessage();
            }
        }

        $latencyMs = (int) ((\microtime(true) - $startedAt) * 1000);

        // Se pelo menos 1 canal sucesso → status=sent.
        if ($popupOk || $emailOk) {
            $this->markSent($pdo, $lembreteId);
            $channelsDelivered = [];
            if ($popupOk) {
                $channelsDelivered[] = PrazoLembreteConstants::CANAL_POPUP;
            }
            if ($emailOk) {
                $channelsDelivered[] = PrazoLembreteConstants::CANAL_EMAIL;
            }

            $this->safeAudit('audit.notification.delivered', $lembreteId, [
                'prazoLembreteId' => $lembreteId,
                'prazoId' => $prazoId,
                'userId' => $userId,
                'marco' => $marco,
                'channelsDelivered' => $channelsDelivered,
                'channelsRequested' => $finalCanal,
                'latencyMs' => $latencyMs,
                'popupError' => $popupError,
                'emailError' => $emailError,
            ]);

            // Decisão D2.1 — se popup OK + email FAIL, log warning mas SLA cumprido.
            if ($popupOk && ! $emailOk && $finalCanal === PrazoLembreteConstants::CANAL_BOTH) {
                $this->safeAudit('audit.notification.email_partial_failure', $lembreteId, [
                    'prazoLembreteId' => $lembreteId,
                    'prazoId' => $prazoId,
                    'userId' => $userId,
                    'marco' => $marco,
                    'note' => 'Popup cumpriu SLA NFR37; email falhou e NÃO será re-tentado isoladamente no MVP.',
                    'emailError' => $emailError,
                ]);
            }

            return 'delivered';
        }

        // Ambos canais falharam (ou so email solicitado e falhou). A quarta
        // passagem com attempt_count >= 3 encerra como failed; antes disso
        // ainda agenda os backoffs 1/5/30min.
        if ($attemptCount >= PrazoLembreteConstants::MAX_EMAIL_ATTEMPTS) {
            $this->markFailed($pdo, $lembreteId, $popupError, $emailError);

            $this->safeAudit('audit.notification.email_failed', $lembreteId, [
                'prazoLembreteId' => $lembreteId,
                'prazoId' => $prazoId,
                'userId' => $userId,
                'marco' => $marco,
                'attemptCount' => $attemptCount,
                'lastError' => $emailError ?? $popupError ?? 'unknown',
                'popupError' => $popupError,
                'emailError' => $emailError,
            ]);
            return 'failed';
        }

        $newAttempt = $attemptCount + 1;

        // Retry exponencial.
        $backoffMin = PrazoLembreteConstants::RETRY_BACKOFF_MINUTES[$attemptCount] ?? 30;
        $nextScheduled = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify("+{$backoffMin} minutes");

        $this->markRetry($pdo, $lembreteId, $newAttempt, $nextScheduled, $popupError, $emailError);
        return 'retry_pending';
    }

    /**
     * Lê Preferences do user — campo customizado `togareLembreteConfig` (jsonObject).
     * Em testes, retorna [] silenciosamente quando entity não existe.
     *
     * @return array<string, mixed>
     */
    private function resolveUserPreferences(string $userId): array
    {
        try {
            $prefs = $this->entityManager->getEntityById('Preferences', $userId);
            if ($prefs === null) {
                return [];
            }
            $config = $prefs->get('togareLembreteConfig');
            if (! \is_array($config)) {
                // Pode ser stdClass se vier do banco como JSON object — converter.
                if (\is_object($config)) {
                    $config = \json_decode((string) \json_encode($config), true);
                    if (! \is_array($config)) {
                        return [];
                    }
                } else {
                    return [];
                }
            }
            return $config;
        } catch (Throwable) {
            return [];
        }
    }

    private function markSent(PDO $pdo, string $lembreteId): void
    {
        $now = $this->nowUtc();
        $stmt = $pdo->prepare(
            'UPDATE togare_prazo_lembrete
             SET status = :sent, sent_at = :now, modified_at = :now
             WHERE id = :id'
        );
        $stmt->execute([
            ':sent' => PrazoLembreteConstants::STATUS_SENT,
            ':now' => $now,
            ':id' => $lembreteId,
        ]);
    }

    private function markFailed(PDO $pdo, string $lembreteId, ?string $popupError, ?string $emailError): void
    {
        $err = \trim('popup: ' . ($popupError ?? '-') . ' | email: ' . ($emailError ?? '-'));
        $stmt = $pdo->prepare(
            'UPDATE togare_prazo_lembrete
             SET status = :failed, last_error = :err, modified_at = :now
             WHERE id = :id'
        );
        $stmt->execute([
            ':failed' => PrazoLembreteConstants::STATUS_FAILED,
            ':err' => \mb_substr($err, 0, 2000),
            ':now' => $this->nowUtc(),
            ':id' => $lembreteId,
        ]);
    }

    private function markRetry(
        PDO $pdo,
        string $lembreteId,
        int $newAttempt,
        DateTimeImmutable $nextScheduled,
        ?string $popupError,
        ?string $emailError,
    ): void {
        $err = \trim('popup: ' . ($popupError ?? '-') . ' | email: ' . ($emailError ?? '-'));
        $stmt = $pdo->prepare(
            'UPDATE togare_prazo_lembrete
             SET attempt_count = :att, scheduled_for = :next, last_error = :err, modified_at = :now
             WHERE id = :id'
        );
        $stmt->execute([
            ':att' => $newAttempt,
            ':next' => $nextScheduled->format('Y-m-d H:i:s'),
            ':err' => \mb_substr($err, 0, 2000),
            ':now' => $this->nowUtc(),
            ':id' => $lembreteId,
        ]);
    }

    /**
     * @param non-empty-string $reason
     */
    private function markCancelled(PDO $pdo, string $lembreteId, string $reason): void
    {
        $stmt = $pdo->prepare(
            'UPDATE togare_prazo_lembrete
             SET status = :cancelled, last_error = :err, modified_at = :now
             WHERE id = :id'
        );
        $stmt->execute([
            ':cancelled' => PrazoLembreteConstants::STATUS_CANCELLED,
            ':err' => 'cancelled_by_job: ' . $reason,
            ':now' => $this->nowUtc(),
            ':id' => $lembreteId,
        ]);

        $this->safeAudit('audit.notification.cancelled', $lembreteId, [
            'prazoLembreteId' => $lembreteId,
            'reason' => $reason,
            'cancelledBy' => 'PrazoReminderJob',
        ]);
    }

    private function nowUtc(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
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
                'prazo.reminder.job.audit_failed',
                'PrazoReminderJob: falha ao registrar audit log.',
                ['event' => $event, 'entityId' => $entityId, 'error' => $e->getMessage()],
            );
        }
    }
}
