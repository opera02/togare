<?php

declare(strict_types=1);

/**
 * Stubs leves de classes core do EspoCRM para testes unit standalone do
 * togare-tpu (Story 3.4 — testes do hook Hooks/Processo/ResolveTpuFieldsHook).
 *
 * Cada stub é carregado apenas se a classe real ainda não foi definida pelo
 * autoload (mesmo padrão do togare-core/Stubs/CoreStubs.php).
 *
 * Cobre o mínimo para os testes do hook em togare-tpu funcionarem
 * standalone (sem site/vendor do EspoCRM real).
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
}
