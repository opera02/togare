<?php

declare(strict_types=1);

namespace Tests\Unit\Espo\Modules\TogareDjen\Services;

use DateTimeImmutable;
use Espo\Modules\TogareDjen\Services\DjenUserStateRepository;
use Espo\ORM\EntityManager;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Story 4a.1 — DjenUserStateRepository (AC10).
 *
 * SQLite in-memory: cobre getOrCreate idempotente + updateLastSyncedAt
 * never-regress + updateLastSyncError.
 */
final class DjenUserStateRepositoryTest extends TestCase
{
    private PDO $pdo;
    private DjenUserStateRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Schema espelha V001 (SQLite-compat — sem ENGINE/CHARSET).
        $this->pdo->exec('
            CREATE TABLE togare_djen_user_state (
                user_id VARCHAR(24) NOT NULL PRIMARY KEY,
                oab_number VARCHAR(20) NULL DEFAULT NULL,
                oab_uf CHAR(2) NULL DEFAULT NULL,
                last_synced_at DATETIME NULL DEFAULT NULL,
                last_sync_error TEXT NULL DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            )
        ');

        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willReturn($this->pdo);

        $this->repo = new DjenUserStateRepository($em);
    }

    public function testGetOrCreateInsereSeNaoExisteEhIdempotente(): void
    {
        $this->repo->getOrCreate('user-1', '462034', 'SP');

        $row = $this->fetchUser('user-1');
        $this->assertSame('462034', $row['oab_number']);
        $this->assertSame('SP', $row['oab_uf']);
        $this->assertNull($row['last_synced_at']);
        $this->assertNotNull($row['created_at']);

        // Re-chamada não duplica nem altera created_at.
        $createdAtOriginal = $row['created_at'];
        $this->repo->getOrCreate('user-1', '462034', 'SP');
        $row2 = $this->fetchUser('user-1');
        $this->assertSame($createdAtOriginal, $row2['created_at'], 'created_at preservado em re-call');

        // Mudança de OAB no User entity → atualiza row sem recriar.
        $this->repo->getOrCreate('user-1', '111111', 'RJ');
        $row3 = $this->fetchUser('user-1');
        $this->assertSame('111111', $row3['oab_number']);
        $this->assertSame('RJ', $row3['oab_uf']);
        $this->assertSame($createdAtOriginal, $row3['created_at']);
    }

    public function testUpdateLastSyncedAtNuncaRegride(): void
    {
        $this->repo->getOrCreate('user-1', '462034', 'SP');

        $this->repo->updateLastSyncedAt('user-1', new DateTimeImmutable('2026-05-08 06:00:00'));
        $row = $this->fetchUser('user-1');
        $this->assertSame('2026-05-08 06:00:00', $row['last_synced_at']);

        // Update com timestamp ANTERIOR → no-op (never-regress).
        $this->repo->updateLastSyncedAt('user-1', new DateTimeImmutable('2026-05-07 06:00:00'));
        $row = $this->fetchUser('user-1');
        $this->assertSame('2026-05-08 06:00:00', $row['last_synced_at'],
            'updateLastSyncedAt com data anterior NÃO regride (AC10)');

        // Update com timestamp posterior → atualiza.
        $this->repo->updateLastSyncedAt('user-1', new DateTimeImmutable('2026-05-09 06:00:00'));
        $row = $this->fetchUser('user-1');
        $this->assertSame('2026-05-09 06:00:00', $row['last_synced_at']);
    }

    public function testUpdateLastSyncErrorTruncaEm1000Chars(): void
    {
        $this->repo->getOrCreate('user-1', '462034', 'SP');

        $longError = \str_repeat('X', 1500);
        $this->repo->updateLastSyncError('user-1', $longError);

        $row = $this->fetchUser('user-1');
        $this->assertSame(1000, \strlen($row['last_sync_error']));
    }

    public function testUpdateLastSyncedAtLimpaLastSyncError(): void
    {
        $this->repo->getOrCreate('user-1', '462034', 'SP');
        $this->repo->updateLastSyncError('user-1', 'previous error');

        $row = $this->fetchUser('user-1');
        $this->assertSame('previous error', $row['last_sync_error']);

        $this->repo->updateLastSyncedAt('user-1', new DateTimeImmutable('2026-05-08 06:00:00'));
        $row = $this->fetchUser('user-1');
        $this->assertNull($row['last_sync_error'], 'Sucesso de sync limpa last_sync_error');
    }

    /** @return array<string, mixed> */
    private function fetchUser(string $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM togare_djen_user_state WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row, "Row para user_id={$userId} esperada");
        return $row;
    }
}
