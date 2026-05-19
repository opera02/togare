<?php

declare(strict_types=1);

namespace Espo\Modules\TogareDjen\Services;

use DateInterval;
use DateTimeImmutable;
use Espo\Modules\TogareCore\Services\QueueService;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Throwable;

/**
 * Enfileira janelas de sync DJEN — uma por advogado com OAB cadastrado
 * (Story 4a.1 — AC4/AC7/AC8/AC9/AC10).
 *
 * Para cada advogado:
 *   1. Calcula `dataInicio = max(last_synced_at, today-7d)` (cap D-7).
 *   2. Calcula `dataFim = today`.
 *   3. Chama QueueService::enqueue('djen', payload, idempotencyKey) onde
 *      idempotencyKey = "djen.sync.{userId}.{dataInicio}.{dataFim}"
 *      (UNIQUE constraint previne dupla-execução no mesmo dia).
 *   4. Atualiza `togare_djen_user_state.last_synced_at = today`
 *      apenas APÓS enqueue bem-sucedido (AC10).
 *
 * Se o enqueue de um user falhar, loga erro e segue para o próximo
 * (per-user failure isolation — não bloqueia os outros).
 */
// Não-final para permitir mock direto em testes (mesmo trade-off do
// DjenUserStateRepository, RedisConnection na Story 3.3 e PrivilegedActorChecker
// na Story 3.5).
class DjenWindowEnqueuer
{
    private const CAP_DIAS = 7;

    public function __construct(
        private readonly QueueService $queueService,
        private readonly DjenUserStateRepository $repo,
    ) {
    }

    /**
     * @param ?DateTimeImmutable $today  Permite injetar "hoje" em testes
     *                                   (default: now()).
     *
     * @return array{usersTotal:int, enqueued:int, skipped:int, errors:int}
     */
    public function enqueueWindowsForAllAdvogados(?DateTimeImmutable $today = null): array
    {
        $today ??= new DateTimeImmutable('today');
        $todayStr = $today->format('Y-m-d');
        $capInicio = $today->sub(new DateInterval('P' . self::CAP_DIAS . 'D'));
        $capInicioStr = $capInicio->format('Y-m-d');

        $advogados = $this->repo->findActiveAdvogados();
        $totals = [
            'usersTotal' => \count($advogados),
            'enqueued' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        foreach ($advogados as $adv) {
            $userId = $adv['userId'];
            $oab = $adv['oab'];
            $uf = $adv['uf'];
            $lastSyncedAt = $adv['lastSyncedAt'];

            try {
                $dataInicioStr = $this->resolveDataInicio($lastSyncedAt, $capInicioStr, $userId, $todayStr);
                $dataFimStr = $todayStr;

                if ($dataInicioStr > $dataFimStr) {
                    // Edge case: clock skew ou sync já feita hoje — pula.
                    $totals['skipped']++;
                    TogareLogger::event(
                        'debug',
                        'djen.sync.window_skipped',
                        'Janela vazia (dataInicio > dataFim) — pulando',
                        [
                            'userId' => $userId,
                            'dataInicio' => $dataInicioStr,
                            'dataFim' => $dataFimStr,
                        ],
                    );
                    continue;
                }

                // Garante row no togare_djen_user_state (idempotente).
                $this->repo->getOrCreate($userId, $oab, $uf);

                $payload = [
                    'type' => 'sync_window',
                    'userId' => $userId,
                    'oab' => $oab,
                    'uf' => $uf,
                    'dataInicio' => $dataInicioStr,
                    'dataFim' => $dataFimStr,
                ];
                $idempotencyKey = "djen.sync.{$userId}.{$dataInicioStr}.{$dataFimStr}";

                $this->queueService->enqueue('djen', $payload, $idempotencyKey);
                $this->repo->updateLastSyncedAt($userId, $today);

                TogareLogger::event(
                    'info',
                    'djen.sync.window_enqueued',
                    "Janela DJEN enfileirada para userId={$userId}",
                    [
                        'userId' => $userId,
                        'oab' => $oab,
                        'uf' => $uf,
                        'dataInicio' => $dataInicioStr,
                        'dataFim' => $dataFimStr,
                    ],
                );
                $totals['enqueued']++;
            } catch (Throwable $e) {
                $totals['errors']++;
                TogareLogger::event(
                    'error',
                    'djen.sync.window_enqueue_failed',
                    "Falha ao enfileirar janela para userId={$userId}: " . $e->getMessage(),
                    [
                        'userId' => $userId,
                        'exception' => \get_class($e),
                    ],
                );
                try {
                    $this->repo->updateLastSyncError($userId, $e->getMessage());
                } catch (Throwable) {
                    // best-effort — não cascateia.
                }
            }
        }

        return $totals;
    }

    private function resolveDataInicio(
        ?string $lastSyncedAt,
        string $capInicioStr,
        string $userId,
        string $todayStr = '',
    ): string {
        if ($lastSyncedAt === null) {
            // Bootstrap: sem last_synced_at → usa cap (D-7) — AC8.
            return $capInicioStr;
        }

        // last_synced_at vem como 'YYYY-MM-DD HH:MM:SS' (datetime); pegamos só a data.
        $lastDate = \substr($lastSyncedAt, 0, 10);

        // Cap futuro: clock skew pode gerar lastDate > today — limita para não pular usuário.
        if ($todayStr !== '' && $lastDate > $todayStr) {
            $lastDate = $todayStr;
        }

        if ($lastDate < $capInicioStr) {
            // Cap D-7: advogado offline >7 dias — só fetch últimos 7 — AC9.
            TogareLogger::event(
                'info',
                'djen.sync.window_capped',
                'last_synced_at antigo capado em D-7',
                [
                    'userId' => $userId,
                    'requestedStart' => $lastDate,
                    'cappedStart' => $capInicioStr,
                ],
            );
            return $capInicioStr;
        }

        return $lastDate;
    }
}
