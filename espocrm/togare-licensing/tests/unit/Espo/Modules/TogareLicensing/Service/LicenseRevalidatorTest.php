<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareLicensing\Service;

use DateTimeImmutable;
use Espo\Modules\TogareCore\Contracts\EventBusContract;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareLicensing\Events\LicenseStatusChangedEvent;
use Espo\Modules\TogareLicensing\Service\LicenseRevalidator;
use Espo\ORM\EntityManager;
use PDO;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Cobre AC3 itens 10-11 da Story 1b.1 (lógica do RevalidateLicensesJob,
 * extraída em LicenseRevalidator pra ser testável sem EspoCRM EntityManager).
 */
final class LicenseRevalidatorTest extends TestCase
{
    private PDO $pdo;
    private LicenseRevalidator $revalidator;
    /** @var list<LicenseStatusChangedEvent> */
    private array $events = [];

    protected function setUp(): void
    {
        $stdout = \fopen('php://memory', 'w+');
        $stderr = \fopen('php://memory', 'w+');
        TogareLogger::init('test', null, $stdout, $stderr);

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("
            CREATE TABLE module_status (
                id VARCHAR(17) NOT NULL PRIMARY KEY,
                deleted INTEGER NOT NULL DEFAULT 0,
                module_name VARCHAR(100) NOT NULL,
                status VARCHAR(255) NOT NULL DEFAULT 'never_activated',
                installation_id VARCHAR(100) NULL,
                key_jti VARCHAR(100) NULL,
                expires_at DATETIME NULL,
                last_validated_at DATETIME NULL,
                last_validation_outcome VARCHAR(50) NULL,
                activated_at DATETIME NULL
            )
        ");

        $this->events = [];
        $eventBus = $this->makeEventBus();

        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willReturn($this->pdo);
        $this->revalidator = new LicenseRevalidator($em, $eventBus);
    }

    private function makeEventBus(): EventBusContract
    {
        return new class ($this->events) implements EventBusContract {
            /** @param list<object> $events */
            public function __construct(private array &$events)
            {
            }

            public function dispatch(object $event): void
            {
                $this->events[] = $event;
            }

            public function subscribe(string $eventClass, callable $listener): void
            {
            }
        };
    }

    private function insertModule(string $id, string $name, string $status, string $expiresAt): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO module_status
                (id, deleted, module_name, status, expires_at)
            VALUES (?, 0, ?, ?, ?)
        ");
        $stmt->execute([$id, $name, $status, $expiresAt]);
    }

    #[RunInSeparateProcess]
    public function testExpiredActiveModuleTransitsToReadOnly(): void
    {
        $this->insertModule('id-1', 'togare-djen', 'active', '2020-01-01 00:00:00');

        $transitioned = $this->revalidator->revalidate(new DateTimeImmutable('2026-04-24'));

        $this->assertSame(['togare-djen'], $transitioned);

        $row = $this->pdo->query('SELECT status, last_validation_outcome FROM module_status')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('read_only', $row['status']);
        $this->assertSame('expired', $row['last_validation_outcome']);

        $this->assertCount(1, $this->events);
        $this->assertSame('togare-djen', $this->events[0]->module);
        $this->assertSame('active', $this->events[0]->oldStatus);
        $this->assertSame('read_only', $this->events[0]->newStatus);
        $this->assertSame('expired', $this->events[0]->reason);
    }

    #[RunInSeparateProcess]
    public function testFutureExpirationKeepsActive(): void
    {
        $this->insertModule('id-1', 'togare-djen', 'active', '2099-01-01 00:00:00');

        $transitioned = $this->revalidator->revalidate(new DateTimeImmutable('2026-04-24'));

        $this->assertSame([], $transitioned);

        $row = $this->pdo->query('SELECT status FROM module_status')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('active', $row['status']);
        $this->assertEmpty($this->events);
    }

    #[RunInSeparateProcess]
    public function testReadOnlyModuleIsNotReprocessed(): void
    {
        // Já estava read_only — job não deve emitir novo evento (filtro WHERE status='active').
        $this->insertModule('id-1', 'togare-djen', 'read_only', '2020-01-01 00:00:00');

        $transitioned = $this->revalidator->revalidate(new DateTimeImmutable('2026-04-24'));

        $this->assertSame([], $transitioned);
        $this->assertEmpty($this->events);
    }

    #[RunInSeparateProcess]
    public function testNullExpiresAtIsIgnored(): void
    {
        // Módulo never_activated não tem expires_at — não deve ser tocado.
        $this->insertModule('id-1', 'togare-djen', 'never_activated', '2020-01-01');
        $this->pdo->exec("UPDATE module_status SET expires_at = NULL WHERE id = 'id-1'");

        $transitioned = $this->revalidator->revalidate(new DateTimeImmutable('2026-04-24'));

        $this->assertSame([], $transitioned);
    }
}
