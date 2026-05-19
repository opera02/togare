<?php

declare(strict_types=1);

/**
 * Stubs leves de classes core do EspoCRM para testes unit standalone do
 * togare-djen (Story 4a.1 — DjenAdapter + DjenWorkerService + TogareDjenSyncJob).
 *
 * Cada stub é carregado apenas se a classe real ainda não foi definida pelo
 * autoload (mesmo padrão de togare-core/Stubs/CoreStubs.php e togare-tpu).
 */

// ---- Espo\ORM\Entity (interface) ----
namespace Espo\ORM {
    if (! interface_exists(Entity::class, false)) {
        interface Entity
        {
            public function getId(): ?string;

            public function get(string $name): mixed;

            public function set(mixed $key, mixed $value = null): void;

            public function isNew(): bool;

            public function getEntityType(): string;

            public function isAttributeChanged(string $name): bool;
        }
    }
}

// ---- Espo\ORM\Repository\Option\SaveOptions ----
namespace Espo\ORM\Repository\Option {
    if (! class_exists(SaveOptions::class, false)) {
        class SaveOptions
        {
            /** @var array<string, mixed> */
            private array $options = [];

            /** @param array<string, mixed> $options */
            public static function create(array $options = []): self
            {
                $i = new self();
                $i->options = $options;
                return $i;
            }

            public function get(string $option): mixed
            {
                return $this->options[$option] ?? null;
            }

            public function has(string $option): bool
            {
                return \array_key_exists($option, $this->options);
            }
        }
    }
}

// ---- Espo\Core\Hook\Hook\BeforeSave (interface) ----
namespace Espo\Core\Hook\Hook {
    if (! interface_exists(BeforeSave::class, false)) {
        interface BeforeSave
        {
            public function beforeSave(
                \Espo\ORM\Entity $entity,
                \Espo\ORM\Repository\Option\SaveOptions $options,
            ): void;
        }
    }
}

// ---- Espo\Core\Exceptions\BadRequest ----
namespace Espo\Core\Exceptions {
    if (! class_exists(BadRequest::class, false)) {
        class BadRequest extends \Exception
        {
            private ?string $body = null;

            public static function createWithBody(string $reason, string $body): self
            {
                $e = new self($reason);
                $e->body = $body;
                return $e;
            }

            public function getBody(): ?string
            {
                return $this->body;
            }
        }
    }

    if (! class_exists(Forbidden::class, false)) {
        class Forbidden extends \Exception
        {
            private ?string $body = null;

            public static function createWithBody(string $reason, string $body): self
            {
                $e = new self($reason);
                $e->body = $body;
                return $e;
            }

            public function getBody(): ?string
            {
                return $this->body;
            }
        }
    }

    if (! class_exists(Conflict::class, false)) {
        class Conflict extends \Exception
        {
            private ?string $body = null;

            public static function createWithBody(string $reason, string $body): self
            {
                $e = new self($reason);
                $e->body = $body;
                return $e;
            }

            public function getBody(): ?string
            {
                return $this->body;
            }
        }
    }
}

// ---- Espo\Core\Api\Request (interface stub — Story 4b.1b ControllerTest) ----
namespace Espo\Core\Api {
    if (! interface_exists(Request::class, false)) {
        interface Request
        {
            public function getRouteParam(string $name): ?string;

            public function getParsedBody(): mixed;
        }
    }
}

// ---- Espo\Core\Controllers\Record (stub p/ ControllerTest — Story 4b.1b) ----
//
// Stub mínimo apenas para permitir extends + propriedades acl/user/injectableFactory.
// Em runtime real, classe oficial do EspoCRM é injetada.
namespace Espo\Core {
    if (! class_exists(Acl::class, false)) {
        class Acl
        {
            public function check(mixed $subject, ?string $action = null): bool
            {
                return true;
            }

            public function checkScope(string $scope, ?string $action = null): bool
            {
                return true;
            }
        }
    }
}

namespace Espo\Core\Controllers {
    if (! class_exists(Record::class, false)) {
        class Record
        {
            public mixed $acl = null;
            public mixed $user = null;
            public mixed $injectableFactory = null;
            public mixed $entityManager = null;
        }
    }
}

// ---- Espo\Core\Job\Job + Espo\Core\Job\Job\Data ----
namespace Espo\Core\Job\Job {
    if (! class_exists(Data::class, false)) {
        class Data
        {
            /** @var array<string, mixed> */
            private array $raw = [];

            /** @param array<string, mixed> $raw */
            public static function create(array $raw = []): self
            {
                $i = new self();
                $i->raw = $raw;
                return $i;
            }

            public function get(string $key): mixed
            {
                return $this->raw[$key] ?? null;
            }
        }
    }
}

namespace Espo\Core\Job {
    if (! interface_exists(Job::class, false)) {
        interface Job
        {
            public function run(\Espo\Core\Job\Job\Data $data): void;
        }
    }
}

// ---- Espo\Core\ORM\Entity (base class para Prazo + outras entidades togare-core) ----
//
// Story 4a.3 — PrazoCreatorService instancia Prazo entity (que extends esta
// classe) via EntityManager.getNewEntity. Em testes unit standalone (sem site/
// EspoCRM), stub abaixo provê base mínima (get/set/getId/isNew/setId).
namespace Espo\Core\ORM {
    if (! class_exists(Entity::class, false)) {
        class Entity implements \Espo\ORM\Entity
        {
            /** @var array<string, mixed> */
            private array $attributes = [];
            /** @var array<string, mixed> */
            private array $fetched = [];
            private ?string $id = null;
            private bool $new = true;

            public function getId(): ?string { return $this->id; }
            public function setId(string $id): void { $this->id = $id; $this->new = false; }

            public function get(string $name): mixed
            {
                if ($name === 'id') {
                    return $this->id;
                }
                return $this->attributes[$name] ?? null;
            }

            public function set(mixed $key, mixed $value = null): void
            {
                if (\is_array($key)) {
                    foreach ($key as $k => $v) {
                        $this->attributes[$k] = $v;
                    }
                    return;
                }
                $this->attributes[$key] = $value;
            }

            public function isNew(): bool { return $this->new; }
            public function setNotNew(): void { $this->new = false; }

            public function isAttributeChanged(string $name): bool
            {
                if (! \array_key_exists($name, $this->fetched)) {
                    return \array_key_exists($name, $this->attributes);
                }
                if (! \array_key_exists($name, $this->attributes)) {
                    return false;
                }
                return $this->attributes[$name] !== $this->fetched[$name];
            }

            public function setFetched(string $name, mixed $value): void
            {
                $this->fetched[$name] = $value;
            }

            public function getFetched(string $name): mixed
            {
                return $this->fetched[$name] ?? null;
            }

            public function getEntityType(): string
            {
                $cls = static::class;
                $parts = \explode('\\', $cls);
                return \end($parts);
            }
        }
    }
}

// ---- Espo\Entities\User (stub p/ controller constructor injection) ----
namespace Espo\Entities {
    if (! class_exists(User::class, false)) {
        class User extends \Espo\Core\ORM\Entity
        {
        }
    }
}

// ---- Espo\ORM\Repository\RDBRepository (stub p/ chain where->findOne) ----
//
// Story 4a.3 — PrazoCreatorService usa $em->getRDBRepository('Prazo')
// ->where(...)->findOne() e variantes (distinct, join). Stub mínimo
// fluente abaixo dá suporte aos testes unit; em runtime real, classe
// oficial é injetada pelo container EspoCRM.
namespace Espo\ORM\Repository {
    if (! class_exists(RDBRepository::class, false)) {
        class RDBRepository
        {
            public function where(mixed $where): static { return $this; }
            public function distinct(): static { return $this; }
            public function join(string $relation, ?string $alias = null): static { return $this; }
            public function limit(int $offset = 0, ?int $size = null): static { return $this; }
            public function findOne(): ?\Espo\ORM\Entity { return null; }
            /** @return list<\Espo\ORM\Entity> */
            public function find(): array { return []; }
        }
    }

    if (! class_exists(RDBRelation::class, false)) {
        class RDBRelation
        {
            /** @return list<\Espo\ORM\Entity> */
            public function find(): array { return []; }
        }
    }
}

// ---- Espo\ORM\TransactionManager (stub p/ mock — Story 4b.1b) ----
//
// Story 4b.1b — AmbiguityResolverService envolve operações em transação via
// $em->getTransactionManager()->run(callback). Em testes, mock configura
// $tm->method('run')->willReturnCallback(fn($cb) => $cb()).
namespace Espo\ORM {
    if (! class_exists(TransactionManager::class, false)) {
        class TransactionManager
        {
            public function run(callable $callback): mixed
            {
                return $callback();
            }

            public function start(): void
            {
            }

            public function commit(): void
            {
            }

            public function rollback(): void
            {
            }
        }
    }
}

// ---- Espo\Modules\TogareCore\Services\QueueService (stub p/ mock) ----
namespace Espo\Modules\TogareCore\Services {
    if (! class_exists(QueueService::class, false)) {
        class QueueService
        {
            /** @param array<string, mixed> $payload */
            public function enqueue(string $queueName, array $payload, ?string $idempotencyKey = null): string
            {
                return '';
            }

            /** @return list<array<string, mixed>> */
            public function claim(string $queueName, int $batch = 1): array
            {
                return [];
            }

            public function markDone(string $itemId): void
            {
            }

            public function markFailed(
                string $itemId,
                string $reason,
                bool $deadLetter = false,
                ?int $customDelaySeconds = null,
                ?string $failureCategory = null,
            ): void {
            }

            public function reclaimStuck(string $queueName, int $thresholdSeconds): int
            {
                return 0;
            }

            // Story 4b.4 / ADR 0009 — alinhamento retry × CB
            public function rescheduleAfterCircuitBreakerClose(string $queueName, string $failureCategory): int
            {
                return 0;
            }
        }
    }
}
