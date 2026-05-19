<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services\Notification;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Modules\TogareCore\Entities\Prazo;
use PDO;

/**
 * Backfill idempotente para o marco D-0 introduzido na Story 4b.3.
 *
 * A estrategia e conservadora: so cria D-0 para pares prazo/user que ja tinham
 * algum lembrete de proximidade D-7/D-3/D-1. Assim o upgrade completa a fila
 * sem recalcular destinatarios nem ressuscitar prazos vencidos no passado.
 */
final class PrazoD0BackfillService
{
    private const STATUS_PENDENTE_FAMILIA = [
        Prazo::STATUS_PENDENTE,
        Prazo::STATUS_REAGENDADO,
        Prazo::STATUS_AGUARDANDO_CLIENTE,
        Prazo::STATUS_AGUARDANDO_CORRECAO,
    ];

    public function backfill(PDO $pdo, ?DateTimeImmutable $nowUtc = null): int
    {
        $utc = new DateTimeZone('UTC');
        $brt = new DateTimeZone(PrazoLembreteConstants::TZ_BRT);
        $now = ($nowUtc ?? new DateTimeImmutable('now', $utc))->setTimezone($utc);
        $nowStr = $now->format('Y-m-d H:i:s');
        $todayBrt = $now->setTimezone($brt)->format('Y-m-d');

        $candidates = $this->fetchCandidates($pdo, $todayBrt);
        $inserted = 0;

        foreach ($candidates as $row) {
            $scheduledFor = $this->scheduledForD0Utc((string) $row['data_fatal'], $now);
            if ($scheduledFor === null) {
                continue;
            }
            if ($this->insertD0(
                $pdo,
                (string) $row['prazo_id'],
                (string) $row['user_id'],
                $scheduledFor,
                $nowStr,
            )) {
                $inserted++;
            }
        }

        return $inserted;
    }

    /**
     * @return list<array{prazo_id: string, user_id: string, data_fatal: string}>
     */
    private function fetchCandidates(PDO $pdo, string $todayBrt): array
    {
        $statusPlaceholders = [];
        $params = [
            ':d7' => PrazoLembreteConstants::MARCO_D7,
            ':d3' => PrazoLembreteConstants::MARCO_D3,
            ':d1' => PrazoLembreteConstants::MARCO_D1,
            ':d0' => PrazoLembreteConstants::MARCO_D0,
            ':today_brt' => $todayBrt,
        ];

        foreach (self::STATUS_PENDENTE_FAMILIA as $i => $status) {
            $key = ':status_' . $i;
            $statusPlaceholders[] = $key;
            $params[$key] = $status;
        }

        $sql = '
            SELECT DISTINCT l.prazo_id, l.user_id, p.data_fatal
              FROM togare_prazo_lembrete l
              INNER JOIN prazo p ON p.id = l.prazo_id
             WHERE l.marco IN (:d7, :d3, :d1)
               AND p.status IN (' . implode(', ', $statusPlaceholders) . ')
               AND COALESCE(p.deleted, 0) = 0
               AND p.data_fatal >= :today_brt
               AND NOT EXISTS (
                   SELECT 1
                     FROM togare_prazo_lembrete d0
                    WHERE d0.prazo_id = l.prazo_id
                      AND d0.user_id = l.user_id
                      AND d0.marco = :d0
               )
             ORDER BY l.prazo_id, l.user_id';

        $stmt = $pdo->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows === false ? [] : $rows;
    }

    private function scheduledForD0Utc(string $dataFatal, DateTimeImmutable $nowUtc): ?string
    {
        try {
            $brt = new DateTimeZone(PrazoLembreteConstants::TZ_BRT);
            $utc = new DateTimeZone('UTC');
            $fatalBrt = new DateTimeImmutable($dataFatal, $brt);
        } catch (\Throwable) {
            return null;
        }

        $scheduledUtc = $fatalBrt
            ->setTime(
                PrazoLembreteConstants::HORA_DISPARO_BY_MARCO[PrazoLembreteConstants::MARCO_D0],
                PrazoLembreteConstants::MINUTO_DISPARO_BY_MARCO[PrazoLembreteConstants::MARCO_D0],
                0,
            )
            ->setTimezone($utc);

        if ($scheduledUtc < $nowUtc) {
            return $nowUtc->format('Y-m-d H:i:s');
        }

        return $scheduledUtc->format('Y-m-d H:i:s');
    }

    private function insertD0(
        PDO $pdo,
        string $prazoId,
        string $userId,
        string $scheduledFor,
        string $now,
    ): bool {
        $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $sql = $isMysql
            ? 'INSERT IGNORE INTO togare_prazo_lembrete
                (id, prazo_id, user_id, marco, canal, scheduled_for, status, attempt_count, created_at, modified_at)
               VALUES (:id, :prazo_id, :user_id, :marco, :canal, :scheduled_for, :status, 0, :created_at, :modified_at)'
            : 'INSERT OR IGNORE INTO togare_prazo_lembrete
                (id, prazo_id, user_id, marco, canal, scheduled_for, status, attempt_count, created_at, modified_at)
               VALUES (:id, :prazo_id, :user_id, :marco, :canal, :scheduled_for, :status, 0, :created_at, :modified_at)';

        $stmt = $pdo->prepare($sql);
        if ($stmt === false) {
            return false;
        }

        $stmt->execute([
            ':id' => \bin2hex(\random_bytes(12)),
            ':prazo_id' => $prazoId,
            ':user_id' => $userId,
            ':marco' => PrazoLembreteConstants::MARCO_D0,
            ':canal' => PrazoLembreteConstants::CANAL_BOTH,
            ':scheduled_for' => $scheduledFor,
            ':status' => PrazoLembreteConstants::STATUS_PENDING,
            ':created_at' => $now,
            ':modified_at' => $now,
        ]);

        return $stmt->rowCount() > 0;
    }
}
