<?php

declare(strict_types=1);

/**
 * Stubs leves para testes unit standalone do togare-nextcloud-bridge
 * (Story 5.1). Esta bridge NÃO toca ORM (sem entity, sem migration), então
 * stubs ficam mínimos: só Binder + EventDispatcher + Container suficiente
 * pro Binding test.
 */

// ---- Espo\Core\Binding\Binder + BindingProcessor (stub p/ Binding test) ----
namespace Espo\Core\Binding {
    if (! interface_exists(BindingProcessor::class, false)) {
        interface BindingProcessor
        {
            public function process(Binder $binder): void;
        }
    }

    if (! class_exists(Binder::class, false)) {
        class Binder
        {
            /** @var list<array{interface:string, implementation:string}> */
            public array $implementations = [];

            public function bindImplementation(string $interface, string $implementation): static
            {
                $this->implementations[] = ['interface' => $interface, 'implementation' => $implementation];
                return $this;
            }

            public function bindService(string $key, string $service): static
            {
                return $this;
            }

            public function bindValue(string $key, mixed $value): static
            {
                return $this;
            }

            public function bindInstance(string $key, mixed $instance): static
            {
                return $this;
            }

            public function bindCallback(string $key, callable $callback): static
            {
                return $this;
            }

            public function bindFactory(string $key, string $factory): static
            {
                return $this;
            }

            public function for(string $contextClass): ContextualBinder
            {
                return new ContextualBinder();
            }
        }
    }

    if (! class_exists(ContextualBinder::class, false)) {
        class ContextualBinder
        {
            public function bindCallback(string $key, callable $callback): static
            {
                return $this;
            }

            public function bindImplementation(string $interface, string $implementation): static
            {
                return $this;
            }
        }
    }
}

// ---- Espo\Core\Job\Job + Espo\Core\Job\Job\Data (stub Story 5.5 — TogareBridgeHardDeleteJob) ----
namespace Espo\Core\Job {
    if (! interface_exists(Job::class, false)) {
        interface Job
        {
            public function run(\Espo\Core\Job\Job\Data $data): void;
        }
    }
}

namespace Espo\Core\Job\Job {
    if (! class_exists(Data::class, false)) {
        class Data
        {
            public static function create(): self
            {
                return new self();
            }
        }
    }
}

// ---- Espo\ORM\EntityManager (stub Story 5.5 — para PHPUnit createMock) ----
namespace Espo\ORM {
    if (! class_exists(EntityManager::class, false)) {
        // Não-final: PHPUnit createMock precisa derivar.
        class EntityManager
        {
            public function getPDO(): \PDO
            {
                throw new \RuntimeException(
                    'EntityManagerStub::getPDO() chamado direto — use PHPUnit createMock e willReturn.',
                );
            }
        }
    }
}
