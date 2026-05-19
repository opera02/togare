<?php

declare(strict_types=1);

namespace Espo\Modules\TogareNextcloudBridge\Jobs;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareNextcloudBridge\Contracts\NextcloudClientContract;
use Espo\Modules\TogareNextcloudBridge\Exception\NextcloudUnavailableException;
use Espo\Modules\TogareNextcloudBridge\Services\NextcloudPurgeableStorage;
use Espo\ORM\EntityManager;
use PDO;
use Throwable;

/**
 * Story 5.5 — promove tombstones `event=documento.soft_purged` (gravados pelo
 * `SoftPurgeDocumentoHook` da Story 5.2) em hard-delete definitivo no Nextcloud
 * após a janela de retenção (default 30d gravado no payload por documento).
 *
 * **Cron:** `0 3 * * *` (3h da manhã, todos os dias — registrado em
 * `Resources/metadata/app/scheduledJobs.json`).
 *
 * **Fluxo (AC3 + AC4):**
 *
 * 1. Carrega set de `tombstoneId` já hard-deletados (pre-check idempotência
 *    Camada 1) via single SELECT a `togare_documento_log` event=
 *    `documento.hard_deleted` (set em memória; tamanho steady-state ~150-500
 *    em piloto).
 * 2. SELECT cursor FIFO de candidatos `event=documento.soft_purged`
 *    ORDER BY `created_at ASC, id ASC`, ignorando tombstones que já têm row
 *    irmã hard_deleted, e monta até LIMIT 100 itens vencidos em PHP.
 * 3. Para cada candidato:
 *    a. Decoda payload JSON; se malformado (tombstoneId/logicalPath ausente),
 *       loga `documento.hard_delete_payload_malformed` e CONTINUA (AC7).
 *    b. Se `hardDeleteAt > now`, SKIP sem log (não é erro — só "ainda não").
 *    c. Camada 1: se tombstoneId no set hard-deletados, loga
 *       `documento.hard_delete_already_done` e CONTINUA.
 *    d. Chama `client->deleteWebDav('.purged/<tid>/<logicalPath>')`. Camada 2:
 *       em 404 idempotente (`deleteWebDav` retorna `false`), loga
 *       `documento.hard_delete_skipped_404` warning e SEGUE para escrever
 *       row hard_deleted (consolida idempotência interna — AC5).
 *    e. Sucesso (200/404): também invoca `deleteWebDav('.purged/<tid>')` pra
 *       limpar o diretório do tombstone (T2.7); falha aqui é não-bloqueante.
 *    f. Grava row append-only `event=documento.hard_deleted` em
 *       `togare_documento_log` (payload tem tombstoneId, logicalPath,
 *       hardDeletedAt:<NOW>, durationDays:<diff dias>).
 *    g. Grava entry `documento.hard_deleted` em `togare_audit_log` via
 *       `AuditLogContract::log` (try/catch \\Throwable — audit nunca bloqueia).
 * 4. `NextcloudUnavailableException` (Nextcloud fora) PARA o batch (Decisão #8
 *    AC6) — loga `documento.hard_delete_unavailable` com remaining count;
 *    `OcsApiClient` já dispara `IntegrationFailedEvent` (não duplicar dispatch).
 * 5. Log final `documento.hard_delete_batch_end` com counts ok/skipped_404/
 *    already_done/payload_malformed (AC8).
 *
 * **Decisão #2:** reusa `togare_documento_log` da togare-core (V018 da Story
 * 5.2) — ZERO tabela nova. Row IRMÃ com event=`documento.hard_deleted` marca
 * o tombstone como processado; consultas usam NOT EXISTS (ou set em memória,
 * mais portável entre MariaDB/SQLite).
 *
 * **Decisão #4:** consome `NextcloudClientContract::deleteWebDav` direto, NÃO
 * via `PurgeableStorageContract` (contract não expõe hard-delete por design —
 * é janela retardada interna ao storage).
 *
 * **SLA worst-case:** 100 items × 30s timeout × 3 retries = ~2.6h. Janela
 * de cron (3h-6h) cobre. Steady-state real: <0.5s por item, batch <1min.
 */
final class TogareBridgeHardDeleteJob implements Job
{
    private const BATCH_LIMIT = 100;

    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly NextcloudClientContract $client,
        private readonly AuditLogContract $auditLog,
    ) {
    }

    public function run(Data $data): void
    {
        $pdo = $this->entityManager->getPDO();

        $counts = [
            'ok' => 0,
            'skipped_404' => 0,
            'already_done' => 0,
            'payload_malformed' => 0,
        ];

        TogareLogger::event(
            'info',
            'documento.hard_delete_batch_start',
            'TogareBridgeHardDeleteJob: iniciando batch de hard-delete de tombstones.',
            ['batchLimit' => self::BATCH_LIMIT],
        );

        try {
            $alreadyHardDeleted = $this->loadHardDeletedTombstoneSet($pdo);
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $candidates = $this->findPendingCandidates($pdo, $alreadyHardDeleted, $now);
        } catch (Throwable $e) {
            TogareLogger::event(
                'error',
                'documento.hard_delete_batch_select_failed',
                'TogareBridgeHardDeleteJob: SELECT batch falhou; abortando job.',
                ['error' => $e->getMessage()],
            );
            return;
        }

        $totalCandidates = \count($candidates);
        $processedIndex = 0;

        foreach ($candidates as $row) {
            $processedIndex++;

            try {
                $status = $this->processItem($pdo, $row, $alreadyHardDeleted, $now);
            } catch (NextcloudUnavailableException $e) {
                // AC6 — bubble up parou o batch.
                TogareLogger::event(
                    'error',
                    'documento.hard_delete_unavailable',
                    'TogareBridgeHardDeleteJob: Nextcloud indisponível; batch interrompido.',
                    [
                        'remainingInBatch' => $totalCandidates - $processedIndex + 1,
                        'processedBeforeFailure' => $processedIndex - 1,
                        'error' => $e->getMessage(),
                    ],
                );

                $this->logBatchEnd($counts, interrupted: true, remaining: $totalCandidates - $processedIndex + 1);
                return;
            } catch (Throwable $e) {
                // AC7 — Throwables não-Nextcloud (PDO, JSON, etc.) são contidos
                // por item; loga e continua para o próximo.
                TogareLogger::event(
                    'error',
                    'documento.hard_delete_item_failed',
                    'TogareBridgeHardDeleteJob: falha não-Nextcloud em item; continuando batch.',
                    [
                        'documentoLogId' => (string) ($row['id'] ?? ''),
                        'documentoId' => (string) ($row['documento_id'] ?? ''),
                        'error' => $e->getMessage(),
                    ],
                );
                continue;
            }

            if ($status !== null && isset($counts[$status])) {
                $counts[$status]++;

                // Reflete em memória que este tombstone agora está
                // hard-deletado (defesa em batches grandes onde 2 rows
                // soft_purged compartilham tombstoneId — improvável mas
                // defensivo).
                if (in_array($status, ['ok', 'skipped_404'], true)) {
                    $payload = $this->extractTombstoneAndPath((string) ($row['payload'] ?? ''));
                    if ($payload !== null) {
                        $alreadyHardDeleted[$payload['tombstoneId']] = true;
                    }
                }
            }
        }

        $this->logBatchEnd($counts, interrupted: false, remaining: 0);
    }

    /**
     * Camada 1 idempotência — pre-carrega set de tombstoneId já hard-deletados.
     * O payload das rows hard_deleted tem shape diferente (hardDeletedAt no
     * passado, sem hardDeleteAt) — extrai SÓ tombstoneId.
     *
     * @return array<string, true>
     */
    private function loadHardDeletedTombstoneSet(PDO $pdo): array
    {
        $stmt = $pdo->prepare(
            "SELECT payload FROM togare_documento_log
             WHERE event = 'documento.hard_deleted'"
        );
        $stmt->execute();

        $set = [];
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $tombstoneId = $this->extractTombstoneIdOnly((string) ($row['payload'] ?? ''));
            if ($tombstoneId !== null) {
                $set[$tombstoneId] = true;
            }
        }

        return $set;
    }

    /**
     * Helper leve: extrai SÓ tombstoneId do payload JSON (valida 32-hex).
     * Usado por loadHardDeletedTombstoneSet, onde o payload tem shape de
     * row hard_deleted (sem hardDeleteAt).
     */
    private function extractTombstoneIdOnly(string $payloadJson): ?string
    {
        if ($payloadJson === '') {
            return null;
        }
        try {
            /** @var array<string, mixed>|null $decoded */
            $decoded = \json_decode($payloadJson, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        if (! is_array($decoded)) {
            return null;
        }
        $tid = isset($decoded['tombstoneId']) && is_string($decoded['tombstoneId'])
            ? $decoded['tombstoneId']
            : '';
        if ($tid === '' || ! \preg_match('/^[0-9a-f]{32}$/', $tid)) {
            return null;
        }
        return $tid;
    }

    /**
     * SELECT cursor FIFO de candidatos `event=documento.soft_purged`.
     *
     * A tabela é append-only: rows soft_purged antigas continuam existindo
     * depois que a row irmã hard_deleted é gravada. Por isso a busca precisa
     * excluir hard_deleted antes de aplicar o limite lógico; caso contrário,
     * os primeiros tombstones já processados prendem o cron no passado.
     *
     * A filtragem de `hardDeleteAt <= now` fica em PHP para preservar parsing
     * correto de ISO-8601 com timezone e evitar diferenças MariaDB/SQLite.
     *
     * @param array<string, true> $alreadyHardDeleted
     *
     * @return list<array{id: string, documento_id: ?string, payload: string, created_at: string}>
     */
    private function findPendingCandidates(PDO $pdo, array $alreadyHardDeleted, DateTimeImmutable $now): array
    {
        $stmt = $pdo->prepare(
            "SELECT id, documento_id, payload, created_at
             FROM togare_documento_log
             WHERE event = 'documento.soft_purged'
             ORDER BY created_at ASC, id ASC"
        );
        $stmt->execute();

        $rows = [];
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (\count($rows) >= self::BATCH_LIMIT) {
                break;
            }

            /** @var array{id: string, documento_id: ?string, payload: string, created_at: string} $candidate */
            $candidate = $row;
            $payload = $this->extractTombstoneAndPath((string) ($candidate['payload'] ?? ''));

            if ($payload === null) {
                // Deixa processItem logar AC7 com o documento_log.id.
                $rows[] = $candidate;
                continue;
            }

            if (isset($alreadyHardDeleted[$payload['tombstoneId']])) {
                continue;
            }

            $hardDeleteAt = $this->parseDateTimeOrNull($payload['hardDeleteAt']);
            if ($hardDeleteAt === null || $hardDeleteAt <= $now) {
                $rows[] = $candidate;
            }
        }

        return $rows;
    }

    /**
     * Processa 1 item da fila. Retorna status string ou null se o item
     * foi pulado por not-yet-due (sem log — comportamento normal).
     *
     * @param array<string, mixed> $row
     * @param array<string, true>  $alreadyHardDeleted
     *
     * @return 'ok'|'skipped_404'|'already_done'|'payload_malformed'|null
     *
     * @throws NextcloudUnavailableException relança pra interromper batch (AC6).
     */
    private function processItem(
        PDO $pdo,
        array $row,
        array $alreadyHardDeleted,
        DateTimeImmutable $now,
    ): ?string {
        $documentoLogId = (string) ($row['id'] ?? '');
        $documentoId = (string) ($row['documento_id'] ?? '');
        $createdAtStr = (string) ($row['created_at'] ?? '');
        $payloadJson = (string) ($row['payload'] ?? '');

        $payload = $this->extractTombstoneAndPath($payloadJson);
        if ($payload === null) {
            TogareLogger::event(
                'error',
                'documento.hard_delete_payload_malformed',
                'TogareBridgeHardDeleteJob: payload JSON inválido; row pulada.',
                [
                    'documentoLogId' => $documentoLogId,
                    'documentoId' => $documentoId,
                ],
            );
            return 'payload_malformed';
        }

        $tombstoneId = $payload['tombstoneId'];
        $logicalPath = $payload['logicalPath'];
        $hardDeleteAtIso = $payload['hardDeleteAt'];

        try {
            $hardDeleteAt = new DateTimeImmutable($hardDeleteAtIso);
        } catch (Throwable) {
            TogareLogger::event(
                'error',
                'documento.hard_delete_payload_malformed',
                'TogareBridgeHardDeleteJob: hardDeleteAt não parseável como DateTime; row pulada.',
                [
                    'documentoLogId' => $documentoLogId,
                    'tombstoneId' => $tombstoneId,
                    'hardDeleteAtRaw' => $hardDeleteAtIso,
                ],
            );
            return 'payload_malformed';
        }

        if ($hardDeleteAt > $now) {
            // not yet due — skip sem log (steady-state, não é erro)
            return null;
        }

        // Camada 1 idempotência: tombstone já hard-deletado. O snapshot em
        // memória cobre histórico; o SELECT fresco cobre concorrência
        // cron + run-job manual entre a query de candidatos e o DELETE.
        if (isset($alreadyHardDeleted[$tombstoneId]) || $this->hasHardDeletedRow($pdo, $tombstoneId)) {
            TogareLogger::event(
                'info',
                'documento.hard_delete_already_done',
                'TogareBridgeHardDeleteJob: tombstone já hard-deletado em execução anterior; row pulada.',
                [
                    'tombstoneId' => $tombstoneId,
                    'documentoId' => $documentoId,
                ],
            );
            return 'already_done';
        }

        // Camada 2: deleteWebDav idempotente em 404.
        $tombstonePath = NextcloudPurgeableStorage::PURGED_ROOT . '/' . $tombstoneId . '/' . $logicalPath;
        $existed = $this->client->deleteWebDav($tombstonePath);
        $status = $existed ? 'ok' : 'skipped_404';

        if (! $existed) {
            TogareLogger::event(
                'warning',
                'documento.hard_delete_skipped_404',
                'TogareBridgeHardDeleteJob: tombstone path 404 (já apagado fora-de-banda?); ainda gravando row hard_deleted pra consolidar idempotência.',
                [
                    'tombstoneId' => $tombstoneId,
                    'logicalPath' => $logicalPath,
                ],
            );
        }

        // T2.7 — limpar diretório vazio do tombstone (idempotente, NÃO-BLOQUEANTE).
        // Falha aqui (incluindo NextcloudUnavailableException) é silenciada por
        // design: a operação crítica (file delete) já sucedeu; o dir vazio é
        // limpeza cosmética. O próximo cron tentará novamente em outros tombstones,
        // mas este item já está pronto para ter sua row hard_deleted gravada.
        try {
            $this->client->deleteWebDav(NextcloudPurgeableStorage::PURGED_ROOT . '/' . $tombstoneId);
        } catch (Throwable) {
            // ignorado intencionalmente
        }

        $createdAt = $this->parseDateTimeOrNull($createdAtStr) ?? $now;
        $durationDays = (int) $createdAt->diff($now)->days;

        $this->writeHardDeletedRow(
            $pdo,
            $tombstoneId,
            $logicalPath,
            $documentoId,
            $durationDays,
            $now,
        );

        $this->writeAuditLog(
            $documentoId,
            [
                'tombstoneId' => $tombstoneId,
                'logicalPath' => $logicalPath,
                'storage' => 'nextcloud',
                'durationDays' => $durationDays,
                'status' => $status,
            ],
        );

        TogareLogger::event(
            'info',
            'documento.hard_delete_ok',
            'TogareBridgeHardDeleteJob: tombstone removido definitivamente.',
            [
                'tombstoneId' => $tombstoneId,
                'logicalPath' => $logicalPath,
                'documentoId' => $documentoId,
                'durationDays' => $durationDays,
                'status' => $status,
            ],
        );

        return $status;
    }

    private function hasHardDeletedRow(PDO $pdo, string $tombstoneId): bool
    {
        $stmt = $pdo->prepare(
            "SELECT payload FROM togare_documento_log
             WHERE event = 'documento.hard_deleted'
             ORDER BY created_at DESC, id DESC"
        );
        $stmt->execute();

        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            if ($this->extractTombstoneIdOnly((string) ($row['payload'] ?? '')) === $tombstoneId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decoda payload JSON do log e valida shape mínima (tombstoneId +
     * logicalPath + hardDeleteAt presentes e não-vazios).
     *
     * @return array{tombstoneId: string, logicalPath: string, hardDeleteAt: string}|null
     */
    private function extractTombstoneAndPath(string $payloadJson): ?array
    {
        if ($payloadJson === '') {
            return null;
        }

        try {
            /** @var array<string, mixed>|null $decoded */
            $decoded = \json_decode($payloadJson, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        $tombstoneId = isset($decoded['tombstoneId']) && is_string($decoded['tombstoneId'])
            ? $decoded['tombstoneId']
            : '';
        $logicalPath = isset($decoded['logicalPath']) && is_string($decoded['logicalPath'])
            ? $decoded['logicalPath']
            : '';
        $hardDeleteAt = isset($decoded['hardDeleteAt']) && is_string($decoded['hardDeleteAt'])
            ? $decoded['hardDeleteAt']
            : '';

        if ($tombstoneId === '' || $logicalPath === '' || $hardDeleteAt === '') {
            return null;
        }

        // Defesa: tombstoneId deve ser 32 hex (mesmo regex do
        // NextcloudPurgeableStorage::validateTombstoneId).
        if (! \preg_match('/^[0-9a-f]{32}$/', $tombstoneId)) {
            return null;
        }

        return [
            'tombstoneId' => $tombstoneId,
            'logicalPath' => $logicalPath,
            'hardDeleteAt' => $hardDeleteAt,
        ];
    }

    private function parseDateTimeOrNull(string $iso): ?DateTimeImmutable
    {
        if ($iso === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Grava row append-only `event=documento.hard_deleted` em
     * togare_documento_log (Decisão #2 — sem tabela nova).
     */
    private function writeHardDeletedRow(
        PDO $pdo,
        string $tombstoneId,
        string $logicalPath,
        string $documentoId,
        int $durationDays,
        DateTimeImmutable $now,
    ): void {
        $payload = \json_encode(
            [
                'tombstoneId' => $tombstoneId,
                'logicalPath' => $logicalPath,
                'hardDeletedAt' => $now->format(\DateTimeInterface::ATOM),
                'durationDays' => $durationDays,
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        if ($payload === false) {
            $payload = '{}';
        }

        $stmt = $pdo->prepare(
            'INSERT INTO togare_documento_log (id, event, documento_id, user_id, payload, created_at) '
            . 'VALUES (:id, :event, :documento_id, :user_id, :payload, :created_at)'
        );

        $stmt->execute([
            ':id' => \bin2hex(\random_bytes(16)),
            ':event' => 'documento.hard_deleted',
            ':documento_id' => $documentoId !== '' ? $documentoId : null,
            ':user_id' => null,
            ':payload' => $payload,
            ':created_at' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Grava entry `documento.hard_deleted` em togare_audit_log via
     * AuditLogContract. Try/catch \\Throwable — audit nunca pode bloquear
     * (pattern AuditDocumentoHook Story 5.2).
     *
     * @param array<string, mixed> $context
     */
    private function writeAuditLog(string $documentoId, array $context): void
    {
        try {
            $this->auditLog->log(
                'documento.hard_deleted',
                'Documento',
                $documentoId !== '' ? $documentoId : null,
                $context,
            );
        } catch (Throwable $e) {
            TogareLogger::event(
                'warning',
                'documento.hard_delete_audit_failed',
                'TogareBridgeHardDeleteJob: falha ao gravar audit log (não-bloqueante).',
                [
                    'documentoId' => $documentoId,
                    'error' => $e->getMessage(),
                ],
            );
        }
    }

    /**
     * @param array<string, int> $counts
     */
    private function logBatchEnd(array $counts, bool $interrupted, int $remaining): void
    {
        TogareLogger::event(
            'info',
            'documento.hard_delete_batch_end',
            $interrupted
                ? 'TogareBridgeHardDeleteJob: batch interrompido por Nextcloud indisponível.'
                : 'TogareBridgeHardDeleteJob: batch concluído.',
            \array_merge(
                $counts,
                [
                    'interrupted' => $interrupted,
                    'remaining' => $remaining,
                ],
            ),
        );
    }
}
