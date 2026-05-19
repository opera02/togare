<?php

declare(strict_types=1);

/**
 * Stubs leves de classes core do EspoCRM para testes unit standalone.
 *
 * Cada stub é carregado **apenas** se a classe real ainda não foi definida
 * pelo autoload (mesmo padrão do EntityManagerStub.php). Permite que mocks
 * via `$this->createMock(...)` funcionem em PHPUnit sem precisar do
 * EspoCRM core completo.
 *
 * Story 2.2 — adiciona Config, ConfigWriter, PasswordHash, BadRequest,
 * BeforeSave/AfterSave, SaveOptions, Entity, User, Role, PasswordChangeRequest,
 * UserSecurity\Password\Service.
 */

// ---- Espo\Core\Utils\Config ----

namespace Espo\Core\Utils {
    if (! class_exists(Config::class, false)) {
        class Config
        {
            /** @var array<string, mixed> */
            private array $data = [];

            /** @param array<string, mixed> $data */
            public function __construct(array $data = [])
            {
                $this->data = $data;
            }

            public function get(string $name, mixed $default = null): mixed
            {
                return $this->data[$name] ?? $default;
            }
        }
    }

    if (! class_exists(PasswordHash::class, false)) {
        class PasswordHash
        {
            public function __construct(private Config $config)
            {
            }

            public function hash(#[\SensitiveParameter] string $password): string
            {
                return \password_hash($password, PASSWORD_BCRYPT);
            }

            public function verify(
                #[\SensitiveParameter] string $password,
                #[\SensitiveParameter] string $hash,
            ): bool {
                return \password_verify($password, $hash);
            }
        }
    }
}

// ---- Espo\Core\Utils\Config\ConfigWriter ----

namespace Espo\Core\Utils\Config {
    if (! class_exists(ConfigWriter::class, false)) {
        class ConfigWriter
        {
            /** @var array<string, mixed> */
            public array $applied = [];

            public bool $saved = false;

            public function set(string $name, mixed $value): void
            {
                $this->applied[$name] = $value;
            }

            /** @param array<string, mixed> $params */
            public function setMultiple(array $params): void
            {
                foreach ($params as $k => $v) {
                    $this->applied[$k] = $v;
                }
            }

            public function save(): void
            {
                $this->saved = true;
            }
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

// ---- Espo\ORM\Entity (interface) ----

namespace Espo\ORM {
    if (! interface_exists(Entity::class, false)) {
        interface Entity
        {
            public function getId(): ?string;

            public function get(string $name): mixed;

            public function set(mixed $key, mixed $value = null): void;

            public function isNew(): bool;
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

// ---- Espo\Core\Hook\Hook\BeforeSave / AfterSave ----

namespace Espo\Core\Hook\Hook {
    if (! interface_exists(BeforeSave::class, false)) {
        interface BeforeSave
        {
            public function beforeSave(\Espo\ORM\Entity $entity, \Espo\ORM\Repository\Option\SaveOptions $options): void;
        }
    }

    if (! interface_exists(AfterSave::class, false)) {
        interface AfterSave
        {
            public function afterSave(\Espo\ORM\Entity $entity, \Espo\ORM\Repository\Option\SaveOptions $options): void;
        }
    }
}

// ---- Espo\Entities\User (concrete stub) ----

namespace Espo\Entities {
    if (! class_exists(User::class, false)) {
        class User implements \Espo\ORM\Entity
        {
            public const ENTITY_TYPE = 'User';
            public const TYPE_REGULAR = 'regular';
            public const TYPE_ADMIN = 'admin';
            public const TYPE_API = 'api';
            public const TYPE_PORTAL = 'portal';

            /** @var array<string, mixed> */
            private array $attributes = [];

            /** @var array<string, list<string>> */
            private array $linkIds = [];

            /** @var array<string, array<string, string>> */
            private array $linkNameHash = [];

            private ?string $id = null;

            private bool $new = true;

            public function getId(): ?string
            {
                return $this->id;
            }

            public function setId(string $id): void
            {
                $this->id = $id;
                $this->new = false;
            }

            public function get(string $name): mixed
            {
                return $this->attributes[$name] ?? null;
            }

            public function set(mixed $key, mixed $value = null): void
            {
                if (\is_array($key)) {
                    foreach ($key as $k => $v) {
                        $this->attributes[$k] = $v;
                    }

                    if (isset($key['rolesIds']) && \is_array($key['rolesIds'])) {
                        $this->linkIds['roles'] = $key['rolesIds'];
                    }

                    return;
                }
                $this->attributes[$key] = $value;
            }

            public function isNew(): bool
            {
                return $this->new;
            }

            public function setNotNew(): void
            {
                $this->new = false;
            }

            public function isAdmin(): bool
            {
                return ($this->attributes['type'] ?? null) === self::TYPE_ADMIN;
            }

            public function isApi(): bool
            {
                return ($this->attributes['type'] ?? null) === self::TYPE_API;
            }

            public function isPortal(): bool
            {
                return ($this->attributes['type'] ?? null) === self::TYPE_PORTAL;
            }

            public function isRegular(): bool
            {
                return ($this->attributes['type'] ?? self::TYPE_REGULAR) === self::TYPE_REGULAR;
            }

            /** @return list<string> */
            public function getLinkMultipleIdList(string $link): array
            {
                return $this->linkIds[$link] ?? [];
            }

            /** @return array<string, string> */
            public function getLinkMultipleNameHash(string $link): ?array
            {
                return $this->linkNameHash[$link] ?? null;
            }

            /** @param array<string, string> $hash */
            public function setLinkMultipleNameHash(string $link, array $hash): void
            {
                $this->linkNameHash[$link] = $hash;
            }
        }
    }

    if (! class_exists(Role::class, false)) {
        class Role implements \Espo\ORM\Entity
        {
            public const ENTITY_TYPE = 'Role';

            /** @var array<string, mixed> */
            private array $attributes = [];

            private ?string $id = null;

            public function getId(): ?string
            {
                return $this->id;
            }

            public function setId(string $id): void
            {
                $this->id = $id;
            }

            public function get(string $name): mixed
            {
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

            public function isNew(): bool
            {
                return false;
            }
        }
    }

    if (! class_exists(PasswordChangeRequest::class, false)) {
        class PasswordChangeRequest implements \Espo\ORM\Entity
        {
            public const ENTITY_TYPE = 'PasswordChangeRequest';

            /** @var array<string, mixed> */
            private array $attributes = [];

            private ?string $id = null;

            private bool $new = true;

            public function getId(): ?string
            {
                return $this->id;
            }

            public function setId(string $id): void
            {
                $this->id = $id;
                $this->new = false;
            }

            public function getRequestId(): ?string
            {
                return $this->attributes['requestId'] ?? null;
            }

            public function get(string $name): mixed
            {
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

            public function isNew(): bool
            {
                return $this->new;
            }

            public function setNotNew(): void
            {
                $this->new = false;
            }
        }
    }
}

// ---- Espo\Tools\UserSecurity\Password\Service ----

namespace Espo\Tools\UserSecurity\Password {
    if (! class_exists(Service::class, false)) {
        class Service
        {
            public function sendAccessInfoForNewUser(\Espo\Entities\User $user): void
            {
            }
        }
    }
}

// ---- Espo\Core\Exceptions\Forbidden ----

namespace Espo\Core\Exceptions {
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
}

// ---- Espo\Entities\UserData ----

namespace Espo\Entities {
    if (! class_exists(UserData::class, false)) {
        class UserData implements \Espo\ORM\Entity
        {
            public const ENTITY_TYPE = 'UserData';

            /** @var array<string, mixed> */
            private array $attributes = [];

            /** @var array<string, mixed> */
            private array $fetched = [];

            private ?string $id = null;

            public function getId(): ?string
            {
                return $this->id;
            }

            public function setId(string $id): void
            {
                $this->id = $id;
            }

            public function get(string $name): mixed
            {
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

            public function isNew(): bool
            {
                return $this->id === null;
            }

            public function isAttributeChanged(string $name): bool
            {
                if (! \array_key_exists($name, $this->fetched)) {
                    return \array_key_exists($name, $this->attributes);
                }

                return $this->fetched[$name] !== ($this->attributes[$name] ?? null);
            }

            public function setFetched(string $name, mixed $value): void
            {
                $this->fetched[$name] = $value;
            }
        }
    }
}

// ---- Espo\Core\Api\Request (interface stub) ----

namespace Espo\Core\Api {
    if (! interface_exists(Request::class, false)) {
        interface Request
        {
            public function getHeader(string $name): ?string;

            public function getParsedBody(): mixed;

            public function getBodyContents(): string;

            public function getMethod(): string;

            public function getUri(): string;

            public function getServerParam(string $name): mixed;
        }
    }
}

// ---- Espo\Core\Authentication\AuthenticationData ----

namespace Espo\Core\Authentication {
    if (! class_exists(AuthenticationData::class, false)) {
        class AuthenticationData
        {
            public function __construct(
                private ?string $username = null,
                private ?string $password = null,
                private ?string $method = null,
            ) {
            }

            public function getUsername(): ?string
            {
                return $this->username;
            }
            public function getPassword(): ?string
            {
                return $this->password;
            }
            public function getMethod(): ?string
            {
                return $this->method;
            }
        }
    }
}

// ---- Espo\Core\Authentication\Hook\BeforeLogin + OnResult ----

namespace Espo\Core\Authentication\Hook {
    if (! interface_exists(BeforeLogin::class, false)) {
        interface BeforeLogin
        {
            public function process(
                \Espo\Core\Authentication\AuthenticationData $data,
                \Espo\Core\Api\Request $request,
            ): void;
        }
    }

    if (! interface_exists(OnResult::class, false)) {
        interface OnResult
        {
            public function process(
                \Espo\Core\Authentication\Result $result,
                \Espo\Core\Authentication\AuthenticationData $data,
                \Espo\Core\Api\Request $request,
            ): void;
        }
    }
}

// ---- Espo\Core\Authentication\TwoFactor\Login (interface) ----

namespace Espo\Core\Authentication\TwoFactor {
    if (! interface_exists(Login::class, false)) {
        interface Login
        {
            public function login(
                \Espo\Core\Authentication\Result $result,
                \Espo\Core\Api\Request $request,
            ): \Espo\Core\Authentication\Result;
        }
    }
}

// ---- Espo\Core\Authentication\TwoFactor\Totp\Util ----

namespace Espo\Core\Authentication\TwoFactor\Totp {
    if (! class_exists(Util::class, false)) {
        class Util
        {
            public function verifyCode(string $secret, string $code): bool
            {
                return false;
            }

            public function createSecret(): string
            {
                return \base64_encode(\random_bytes(10));
            }
        }
    }
}

// ---- Espo\Repositories\UserData ----

namespace Espo\Repositories {
    if (! class_exists(UserData::class, false)) {
        class UserData
        {
            public function getByUserId(string $userId): ?\Espo\Entities\UserData
            {
                return null;
            }
        }
    }
}

// ---- Espo\Core\Authentication\TwoFactor\Exceptions\NotConfigured ----

namespace Espo\Core\Authentication\TwoFactor\Exceptions {
    if (! class_exists(NotConfigured::class, false)) {
        class NotConfigured extends \Exception
        {
        }
    }
}

// ---- Espo\Core\Authentication\Result\FailReason ----

namespace Espo\Core\Authentication\Result {
    if (! class_exists(FailReason::class, false)) {
        class FailReason
        {
            public const CODE_NOT_VERIFIED = 'CODE_NOT_VERIFIED';
            public const WRONG_CREDENTIALS = 'WRONG_CREDENTIALS';
        }
    }

    if (! class_exists(Data::class, false)) {
        class Data
        {
            private string $message = '';

            public static function createWithMessage(string $message): self
            {
                $d = new self();
                $d->message = $message;

                return $d;
            }

            public function getMessage(): string
            {
                return $this->message;
            }
        }
    }
}

// ---- Espo\Core\Authentication\Result ----

namespace Espo\Core\Authentication {
    if (! class_exists(Result::class, false)) {
        class Result
        {
            private bool $fail = false;

            private ?string $failReason = null;

            private ?\Espo\Entities\User $user = null;

            private ?\Espo\Core\Authentication\Result\Data $data = null;

            private bool $secondStep = false;

            public static function fail(string $reason): self
            {
                $r = new self();
                $r->fail = true;
                $r->failReason = $reason;

                return $r;
            }

            public static function secondStepRequired(\Espo\Entities\User $user, \Espo\Core\Authentication\Result\Data $data): self
            {
                $r = new self();
                $r->user = $user;
                $r->data = $data;
                $r->secondStep = true;

                return $r;
            }

            public static function success(\Espo\Entities\User $user): self
            {
                $r = new self();
                $r->user = $user;

                return $r;
            }

            public function isFail(): bool
            {
                return $this->fail;
            }

            public function isSecondStepRequired(): bool
            {
                return $this->secondStep;
            }

            public function getFailReason(): ?string
            {
                return $this->failReason;
            }

            public function getUser(): ?\Espo\Entities\User
            {
                return $this->user;
            }

            public function getData(): ?\Espo\Core\Authentication\Result\Data
            {
                return $this->data;
            }
        }
    }

    if (! class_exists(HeaderKey::class, false)) {
        class HeaderKey
        {
            public const AUTHORIZATION_CODE = 'X-Authorization-Code';
        }
    }
}

// ---- Espo\Tools\App\AppParam (interface) ----

namespace Espo\Tools\App {
    if (! interface_exists(AppParam::class, false)) {
        interface AppParam
        {
            public function get(): mixed;
        }
    }
}
