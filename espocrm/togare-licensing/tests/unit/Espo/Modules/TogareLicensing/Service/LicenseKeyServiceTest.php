<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareLicensing\Service;

use DateTimeImmutable;
use Espo\Modules\TogareCore\Contracts\EventBusContract;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareLicensing\Events\LicenseStatusChangedEvent;
use Espo\Modules\TogareLicensing\Service\JwtValidator;
use Espo\Modules\TogareLicensing\Service\LicenseKeyService;
use Espo\Modules\TogareLicensing\Service\PublicKeyProvider;
use Espo\ORM\EntityManager;
use PDO;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use tests\unit\Espo\Modules\TogareLicensing\JwtFixtures;
use tests\unit\Espo\Modules\TogareLicensing\TestClock;

/**
 * Cobre AC3 itens 7-9 + 15 (idempotência) da Story 1b.1.
 */
final class LicenseKeyServiceTest extends TestCase
{
    private PDO $pdo;
    private TestClock $clock;
    private LicenseKeyService $service;
    /** @var list<LicenseStatusChangedEvent> */
    private array $events = [];

    protected function setUp(): void
    {
        $stdout = \fopen('php://memory', 'w+');
        $stderr = \fopen('php://memory', 'w+');
        TogareLogger::init('test', null, $stdout, $stderr);

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema();

        $this->clock = new TestClock(new DateTimeImmutable('2026-04-24T12:00:00+00:00'));
        $validator = new JwtValidator(new PublicKeyProvider(JwtFixtures::publicKeyPath()), $this->clock);

        $this->events = [];
        $eventBus = $this->makeEventBus();

        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willReturn($this->pdo);
        $this->service = new LicenseKeyService($validator, $em, $eventBus);
    }

    private function createSchema(): void
    {
        // Schema espelha a tabela `module_status` gerada pelo ORM EspoCRM
        // pós-rebuild (Story 1b.1.1.2-followup unificou nesse nome canônico).
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
        $this->pdo->exec('CREATE UNIQUE INDEX uk_module_status_name ON module_status (module_name)');
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
                // not used in tests
            }
        };
    }

    #[RunInSeparateProcess]
    public function testActivateValidJwtCreatesRowsForEachModule(): void
    {
        $jwt = JwtFixtures::makeJwt([
            'iat' => $this->clock->now(),
            'exp' => $this->clock->now()->modify('+30 days'),
            'mod' => ['togare-djen', 'togare-portal-ui'],
        ]);

        $result = $this->service->activate($jwt);

        $this->assertTrue($result->success);
        $this->assertCount(2, $result->modulesActivated);

        $rows = $this->pdo->query('SELECT module_name, status FROM module_status ORDER BY module_name')
            ->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame([
            ['module_name' => 'togare-djen', 'status' => 'active'],
            ['module_name' => 'togare-portal-ui', 'status' => 'active'],
        ], $rows);

        $this->assertCount(2, $this->events);
        $this->assertSame('key_activated', $this->events[0]->reason);
    }

    #[RunInSeparateProcess]
    public function testActivateAgainUpdatesExistingRow(): void
    {
        $jwt1 = JwtFixtures::makeJwt([
            'iat' => $this->clock->now(),
            'exp' => $this->clock->now()->modify('+10 days'),
            'mod' => ['togare-djen'],
        ]);
        $this->service->activate($jwt1);

        $jwt2 = JwtFixtures::makeJwt([
            'iat' => $this->clock->now(),
            'exp' => $this->clock->now()->modify('+365 days'),
            'mod' => ['togare-djen'],
        ]);
        $this->service->activate($jwt2);

        $rows = $this->pdo->query('SELECT module_name FROM module_status')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows, 'UPSERT por module_name não deve duplicar linhas');
    }

    #[RunInSeparateProcess]
    public function testActivateInvalidJwtPersistsNothing(): void
    {
        $result = $this->service->activate('not.a.jwt');

        $this->assertFalse($result->success);

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM module_status')->fetchColumn();
        $this->assertSame(0, $count, 'Atomic fail — nenhuma linha persistida em chave inválida');
        $this->assertEmpty($this->events);
    }

    #[RunInSeparateProcess]
    public function testReactivateAfterReadOnlyTransitsToActiveSemMigration(): void
    {
        // Insere manualmente em estado read_only.
        $this->pdo->exec("
            INSERT INTO module_status
                (id, deleted, module_name, status, expires_at)
            VALUES
                ('id-djen-old', 0, 'togare-djen', 'read_only', '2020-01-01 00:00:00')
        ");

        $jwt = JwtFixtures::makeJwt([
            'iat' => $this->clock->now(),
            'exp' => $this->clock->now()->modify('+30 days'),
            'mod' => ['togare-djen'],
        ]);
        $result = $this->service->activate($jwt);

        $this->assertTrue($result->success);

        $row = $this->pdo->query('SELECT id, status FROM module_status')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('id-djen-old', $row['id'], 'Mesmo ID — não houve INSERT, foi UPDATE');
        $this->assertSame('active', $row['status']);

        $this->assertCount(1, $this->events);
        $this->assertSame('read_only', $this->events[0]->oldStatus);
        $this->assertSame('active', $this->events[0]->newStatus);
        $this->assertSame('key_refreshed', $this->events[0]->reason);
    }

    #[RunInSeparateProcess]
    public function testActivateSameValidJwtTwiceIsIdempotent(): void
    {
        $jwt = JwtFixtures::makeJwt([
            'iat' => $this->clock->now(),
            'exp' => $this->clock->now()->modify('+30 days'),
            'mod' => ['togare-djen'],
        ]);

        $this->service->activate($jwt);
        $this->service->activate($jwt);

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM module_status')->fetchColumn();
        $this->assertSame(1, $count);

        // Segundo activate em estado já 'active' não deve emitir evento (active → active no-op).
        $this->assertCount(1, $this->events);
    }
}
