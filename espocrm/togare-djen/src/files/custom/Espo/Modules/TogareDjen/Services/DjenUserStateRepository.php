<?php

declare(strict_types=1);

namespace Espo\Modules\TogareDjen\Services;

use DateTimeImmutable;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\EntityManager;
use PDO;

/**
 * Repository para `togare_djen_user_state` (Story 4a.1 — Decisão #3.1).
 *
 * - `findActiveAdvogados()` — JOIN com User entity para retornar advogados
 *   com OAB cadastrada e não-deletados. Estado fonte da verdade pra OAB
 *   é o User entity (campos oabNumber/oabUf adicionados via Story 4a.1
 *   Task 4); este repo cacheia denormalizado para não impactar performance
 *   do job a cada execução.
 * - `getOrCreate(userId)` — garante row pra user, populando oab/uf do User
 *   entity se ainda não existir.
 * - `updateLastSyncedAt(userId, datetime)` — never-regress (update só se
 *   newer que o atual; AC10).
 * - `updateLastSyncError(userId, errorMsg)` — para diagnose via Health Panel.
 *
 * DI: recebe EntityManager pelo InjectableFactory do EspoCRM e cacheia o PDO
 * no boot (mesmo pattern do QueueService — togare-core 0.7.3+).
 *
 * Não-final para permitir mock direto em testes (mesmo trade-off de
 * RedisConnection na Story 3.3 e PrivilegedActorChecker na 3.5).
 */
class DjenUserStateRepository
{
    private readonly PDO $pdo;

    public function __construct(EntityManager $entityManager)
    {
        $this->pdo = $entityManager->getPDO();
    }

    /**
     * Lista advogados ativos com OAB+UF preenchidos, prontos pra sync.
     *
     * Usa `LEFT JOIN togare_djen_user_state` para pegar last_synced_at
     * mesmo se ainda não houver row (primeira sync).
     *
     * @return list<array{userId:string, oab:string, uf:string, lastSyncedAt:?string}>
     */
    public function findActiveAdvogados(): array
    {
        $sql = '
            SELECT u.id AS user_id,
                   u.oab_number AS oab,
                   u.oab_uf AS uf,
                   s.last_synced_at AS last_synced_at
            FROM `user` u
            LEFT JOIN togare_djen_user_state s
              ON s.user_id = CONVERT(u.id USING utf8mb4)
            WHERE u.deleted = 0
              AND u.is_active = 1
              AND u.type = ?
              AND u.oab_number IS NOT NULL
              AND u.oab_number <> ?
              AND u.oab_uf IS NOT NULL
              AND u.oab_uf <> ?
            ORDER BY u.id
        ';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['regular', '', '']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'userId' => (string) $row['user_id'],
                'oab' => (string) $row['oab'],
                'uf' => (string) $row['uf'],
                'lastSyncedAt' => $row['last_synced_at'] !== null
                    ? (string) $row['last_synced_at']
                    : null,
            ];
        }
        return $out;
    }

    /**
     * Garante row pra user, criando se não existir. Idempotente.
     */
    public function getOrCreate(string $userId, string $oab, string $uf): void
    {
        $sql = 'SELECT user_id FROM togare_djen_user_state WHERE user_id = :uid';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':uid' => $userId]);
        if ($stmt->fetchColumn() !== false) {
            // Já existe: opcionalmente sincroniza oab/uf se mudou no User entity.
            $upd = $this->pdo->prepare(
                'UPDATE togare_djen_user_state
                 SET oab_number = :oab, oab_uf = :uf, updated_at = :now
                 WHERE user_id = :uid AND (oab_number <> :oab OR oab_uf <> :uf)'
            );
            $upd->execute([
                ':oab' => $oab,
                ':uf' => $uf,
                ':now' => $this->nowString(),
                ':uid' => $userId,
            ]);
            return;
        }

        $now = $this->nowString();
        $ins = $this->pdo->prepare(
            'INSERT INTO togare_djen_user_state
             (user_id, oab_number, oab_uf, last_synced_at, last_sync_error,
              created_at, updated_at)
             VALUES (:uid, :oab, :uf, NULL, NULL, :now1, :now2)'
        );
        try {
            $ins->execute([
                ':uid' => $userId,
                ':oab' => $oab,
                ':uf' => $uf,
                ':now1' => $now,
                ':now2' => $now,
            ]);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            // Duplicate key por race condition TOCTOU — row já existe; ignorar.
        }
    }

    /**
     * Atualiza last_synced_at — nunca regride (AC10).
     *
     * Aceita string formato 'Y-m-d H:i:s' ou DateTimeImmutable.
     */
    public function updateLastSyncedAt(string $userId, DateTimeImmutable|string $when): void
    {
        $whenStr = $when instanceof DateTimeImmutable
            ? $when->format('Y-m-d H:i:s')
            : $when;

        $upd = $this->pdo->prepare(
            'UPDATE togare_djen_user_state
             SET last_synced_at = :when, last_sync_error = NULL, updated_at = :now
             WHERE user_id = :uid
               AND (last_synced_at IS NULL OR last_synced_at < :when2)'
        );
        $upd->execute([
            ':when' => $whenStr,
            ':when2' => $whenStr,
            ':now' => $this->nowString(),
            ':uid' => $userId,
        ]);

        if ($upd->rowCount() === 0) {
            TogareLogger::event(
                'debug',
                'djen.user_state.last_synced_at_skipped',
                'updateLastSyncedAt no-op (timestamp mais recente já registrado ou row ausente)',
                ['userId' => $userId, 'when' => $whenStr],
            );
        }
    }

    /**
     * Registra mensagem de erro da última sync (truncada a 1000 chars).
     */
    public function updateLastSyncError(string $userId, string $errorMsg): void
    {
        $truncated = \substr($errorMsg, 0, 1000);
        $upd = $this->pdo->prepare(
            'UPDATE togare_djen_user_state
             SET last_sync_error = :err, updated_at = :now
             WHERE user_id = :uid'
        );
        $upd->execute([
            ':err' => $truncated,
            ':now' => $this->nowString(),
            ':uid' => $userId,
        ]);
    }

    private function nowString(): string
    {
        return (new DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
