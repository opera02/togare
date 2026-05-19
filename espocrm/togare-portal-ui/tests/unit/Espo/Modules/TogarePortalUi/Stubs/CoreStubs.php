<?php

declare(strict_types=1);

/**
 * Stubs leves de classes/interfaces core do EspoCRM para os testes unit
 * standalone do togare-portal-ui (Story 7a.2 — A4 OwnershipChecker +
 * filtro PortalOnlyCliente sem container).
 *
 * Cada símbolo é declarado APENAS se o real ainda não existir (guards
 * `*_exists(..., false)`) — em ambiente de smoke/integração com o
 * EspoCRM real (site/vendor), o core verdadeiro tem precedência.
 *
 * Mesmo padrão de `togare-core/tests/.../Stubs/{CoreStubs,EntityManager
 * Stub}.php`. NÃO usar fora de testes.
 */

// ---- Espo\ORM\Entity ----
namespace Espo\ORM {
    if (! interface_exists(Entity::class, false)) {
        interface Entity
        {
            public function getId(): ?string;
            public function get(string $name): mixed;
            public function set(mixed $key, mixed $value = null): void;
        }
    }

    if (! class_exists(EntityManager::class, false)) {
        class EntityManager
        {
            public function getEntityById(string $entityType, string $id): mixed
            {
                return null;
            }

            public function getRDBRepository(string $entityType): mixed
            {
                return null;
            }

            public function saveEntity(mixed $entity, array $options = []): void
            {
            }
        }
    }
}

// ---- Espo\ORM\Name\Attribute ----
namespace Espo\ORM\Name {
    if (! class_exists(Attribute::class, false)) {
        class Attribute
        {
            public const ID = 'id';
        }
    }
}

// ---- Espo\ORM\Query\SelectBuilder (spy mínimo) ----
namespace Espo\ORM\Query {
    if (! class_exists(SelectBuilder::class, false)) {
        class SelectBuilder
        {
            /** @var list<mixed> */
            public array $whereCalls = [];

            public function where(mixed $clause): self
            {
                $this->whereCalls[] = $clause;

                return $this;
            }
        }
    }
}

// ---- Espo\Entities\User ----
namespace Espo\Entities {
    if (! class_exists(User::class, false)) {
        class User
        {
            public const TYPE_PORTAL = 'portal';

            /** @param array<string, mixed> $attrs */
            public function __construct(private array $attrs = [])
            {
            }

            public function getId(): ?string
            {
                return $this->attrs['id'] ?? null;
            }

            public function get(string $name): mixed
            {
                return $this->attrs[$name] ?? null;
            }

            public function isAdmin(): bool
            {
                return (bool) ($this->attrs['isAdmin'] ?? false);
            }
        }
    }
}

// ---- Espo\Core\Acl\OwnershipOwnChecker ----
namespace Espo\Core\Acl {
    if (! interface_exists(OwnershipChecker::class, false)) {
        interface OwnershipChecker
        {
        }
    }

    if (! interface_exists(OwnershipOwnChecker::class, false)) {
        interface OwnershipOwnChecker extends OwnershipChecker
        {
            public function checkOwn(
                \Espo\Entities\User $user,
                \Espo\ORM\Entity $entity,
            ): bool;
        }
    }
}

// ---- Espo\Core\Select\AccessControl\Filter ----
namespace Espo\Core\Select\AccessControl {
    if (! interface_exists(Filter::class, false)) {
        interface Filter
        {
            public function apply(\Espo\ORM\Query\SelectBuilder $queryBuilder): void;
        }
    }
}
