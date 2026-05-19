<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareLicensing\Service;

use Espo\Modules\TogareLicensing\Service\ReadOnlyGate;
use Espo\ORM\EntityManager;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Cobre AC3 item 12 da Story 1b.1.
 */
final class ReadOnlyGateTest extends TestCase
{
    private PDO $pdo;
    private ReadOnlyGate $gate;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("
            CREATE TABLE module_status (
                id VARCHAR(17) NOT NULL PRIMARY KEY,
                deleted INTEGER NOT NULL DEFAULT 0,
                module_name VARCHAR(100) NOT NULL,
                status VARCHAR(255) NOT NULL DEFAULT 'never_activated',
                expires_at DATETIME NULL
            )
        ");
        $this->pdo->exec('CREATE UNIQUE INDEX uk_module_status_name ON module_status (module_name)');

        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willReturn($this->pdo);
        $this->gate = new ReadOnlyGate($em);
    }

    public function testIsBlockedReturnsTrueWhenStatusReadOnly(): void
    {
        $this->insertModule('togare-djen', 'read_only', '2020-01-01 00:00:00');

        $this->assertTrue($this->gate->isBlocked('togare-djen'));
    }

    public function testIsBlockedReturnsFalseWhenStatusActive(): void
    {
        $this->insertModule('togare-djen', 'active', '2099-01-01 00:00:00');

        $this->assertFalse($this->gate->isBlocked('togare-djen'));
    }

    public function testIsBlockedReturnsFalseWhenModuleNotFound(): void
    {
        // Nenhuma linha pra togare-djen — não está em read-only.
        $this->assertFalse($this->gate->isBlocked('togare-djen'));
    }

    public function testGetStatusReturnsExpiresAt(): void
    {
        $this->insertModule('togare-portal-ui', 'read_only', '2024-12-31 23:59:59');

        $info = $this->gate->getStatus('togare-portal-ui');

        $this->assertNotNull($info);
        $this->assertSame('read_only', $info['status']);
        $this->assertSame('2024-12-31 23:59:59', $info['expiresAt']);
    }

    private function insertModule(string $name, string $status, string $expiresAt): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO module_status (id, deleted, module_name, status, expires_at) VALUES (?, 0, ?, ?, ?)',
        );
        $stmt->execute([\substr(\bin2hex(\random_bytes(9)), 0, 17), $name, $status, $expiresAt]);
    }
}
