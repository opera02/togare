<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareRbac\Service\Mfa;

use Espo\Core\Utils\PasswordHash;
use Espo\Entities\User;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareRbac\Service\Mfa\BackupCodeService;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

/**
 * Cobre AC6, AC10 — BackupCodeService (generate, consume, regenerate, status).
 */
final class BackupCodeServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $stdout = \fopen('php://memory', 'w+');
        $stderr = \fopen('php://memory', 'w+');
        TogareLogger::init('test-backup-code', null, $stdout, $stderr);
    }

    private function makeUser(string $id = 'user-01', string $name = 'testuser'): User
    {
        $user = new User();
        $user->setId($id);
        $user->set('userName', $name);
        return $user;
    }

    public function testGenerateProduzNCodigosFormatoCorreto(): void
    {
        $savedEntities = [];

        $newEntity = new class {
            public array $data = [];
            public function set(mixed $k, mixed $v = null): void {
                if (\is_array($k)) { $this->data = \array_merge($this->data, $k); return; }
                $this->data[$k] = $v;
            }
            public function getId(): ?string { return $this->data['id'] ?? null; }
        };

        $em = $this->createMock(EntityManager::class);
        $em->method('getNewEntity')->willReturn($newEntity);
        $em->method('saveEntity')->willReturnCallback(function($e) use (&$savedEntities) {
            $savedEntities[] = clone $e;
        });

        $passwordHash = $this->createStub(PasswordHash::class);
        $passwordHash->method('hash')->willReturnCallback(fn($p) => \password_hash($p, PASSWORD_BCRYPT));

        $service = new BackupCodeService($em, $passwordHash, new \tests\unit\Espo\Modules\TogareRbac\Stubs\AuditLogContractStub());
        $user = $this->makeUser();

        $codes = $service->generate($user, 8);

        $this->assertCount(8, $codes);

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression(
                '/^[abcdefghkmnpqrstuvwxyz23456789]{4}-[abcdefghkmnpqrstuvwxyz23456789]{4}$/',
                $code,
                "Código '{$code}' não segue o formato xxxx-xxxx com alfabeto correto.",
            );
        }

        // Sem repetições (com probabilidade esmagadora)
        $this->assertCount(8, \array_unique($codes));
    }

    public function testConsumeMarcaUsedERetornaTrue(): void
    {
        $plainCode = 'abcd-ef12';
        $normalized = 'abcdef12';
        $hash = \password_hash($normalized, PASSWORD_BCRYPT);

        $entity = new class($hash) {
            public array $data;
            public function __construct(string $hash) {
                $this->data = ['id' => 'code-01', 'codeHash' => $hash, 'used' => false];
            }
            public function get(string $k): mixed { return $this->data[$k] ?? null; }
            public function set(mixed $k, mixed $v = null): void {
                if (\is_array($k)) { $this->data = \array_merge($this->data, $k); return; }
                $this->data[$k] = $v;
            }
            public function getId(): ?string { return $this->data['id']; }
        };

        $collection = new class($entity) implements \Countable, \IteratorAggregate {
            private array $items;
            public function __construct(object $e) { $this->items = [$e]; }
            public function count(): int { return \count($this->items); }
            public function getIterator(): \ArrayIterator { return new \ArrayIterator($this->items); }
            public function toArray(): array { return $this->items; }
        };

        $repo = new class($collection) {
            public function __construct(private $collection) {}
            public function where(array $w): static { return $this; }
            public function find(): mixed { return $this->collection; }
            public function count(): int { return 0; }
        };

        $savedCallCount = 0;
        $em = $this->createMock(EntityManager::class);
        $em->method('getRDBRepository')->willReturn($repo);
        $em->method('saveEntity')->willReturnCallback(function() use (&$savedCallCount) {
            $savedCallCount++;
        });

        $passwordHash = $this->createStub(PasswordHash::class);
        $passwordHash->method('verify')->willReturnCallback(
            fn($p, $h) => \password_verify($p, $h)
        );

        $service = new BackupCodeService($em, $passwordHash, new \tests\unit\Espo\Modules\TogareRbac\Stubs\AuditLogContractStub());
        $user = $this->makeUser();

        $result = $service->consume($user, $plainCode);

        $this->assertTrue($result);
        $this->assertSame(1, $savedCallCount);
        $this->assertTrue((bool) $entity->data['used']);
        $this->assertNotNull($entity->data['usedAt']);
    }

    public function testConsumeFalhaCodigoInexistente(): void
    {
        $collection = new class() implements \Countable, \IteratorAggregate {
            public function count(): int { return 0; }
            public function getIterator(): \ArrayIterator { return new \ArrayIterator([]); }
            public function toArray(): array { return []; }
        };

        $repo = new class($collection) {
            public function __construct(private $collection) {}
            public function where(array $w): static { return $this; }
            public function find(): mixed { return $this->collection; }
            public function count(): int { return 0; }
        };

        $em = $this->createMock(EntityManager::class);
        $em->method('getRDBRepository')->willReturn($repo);

        $passwordHash = $this->createStub(PasswordHash::class);
        $passwordHash->method('verify')->willReturn(false);

        $service = new BackupCodeService($em, $passwordHash, new \tests\unit\Espo\Modules\TogareRbac\Stubs\AuditLogContractStub());
        $user = $this->makeUser();

        $result = $service->consume($user, 'xxxx-xxxx');

        $this->assertFalse($result);
    }

    public function testRegenerateSoftDeletaAntigosEGeraNovos(): void
    {
        $oldEntity = new class {
            public array $data = ['deleted' => false];
            public function get(string $k): mixed { return $this->data[$k] ?? null; }
            public function set(mixed $k, mixed $v = null): void {
                if (\is_array($k)) { $this->data = \array_merge($this->data, $k); return; }
                $this->data[$k] = $v;
            }
            public function getId(): ?string { return 'old-code-01'; }
        };

        $activeCollection = new class($oldEntity) implements \Countable, \IteratorAggregate {
            public function __construct(private object $e) {}
            public function count(): int { return 1; }
            public function getIterator(): \ArrayIterator { return new \ArrayIterator([$this->e]); }
        };

        $newEntity = new class {
            public array $data = [];
            public function set(mixed $k, mixed $v = null): void {
                if (\is_array($k)) { $this->data = \array_merge($this->data, $k); return; }
                $this->data[$k] = $v;
            }
            public function getId(): ?string { return $this->data['id'] ?? null; }
        };

        $callNumber = 0;
        $repo = new class($activeCollection) {
            public function __construct(private $coll) {}
            public function where(array $w): static { return $this; }
            public function find(): mixed { return $this->coll; }
            public function count(): int { return 0; }
        };

        $savedEntities = [];
        $em = $this->createMock(EntityManager::class);
        $em->method('getRDBRepository')->willReturn($repo);
        $em->method('getNewEntity')->willReturn($newEntity);
        $em->method('saveEntity')->willReturnCallback(function($e) use (&$savedEntities) {
            $savedEntities[] = $e;
        });

        $passwordHash = $this->createStub(PasswordHash::class);
        $passwordHash->method('hash')->willReturnCallback(fn($p) => \password_hash($p, PASSWORD_BCRYPT));

        $service = new BackupCodeService($em, $passwordHash, new \tests\unit\Espo\Modules\TogareRbac\Stubs\AuditLogContractStub());
        $user = $this->makeUser();

        $codes = $service->regenerate($user, 4);

        // O old entity foi soft-deletado
        $this->assertTrue((bool) $oldEntity->data['deleted']);
        // Gerou 4 novos codes
        $this->assertCount(4, $codes);
    }
}
