<?php

declare(strict_types=1);

/**
 * Stubs leves de classes core do EspoCRM para testes unit standalone do
 * togare-core (Story 2.4 — testes dos hooks de audit em entidades nativas).
 *
 * Cada stub é carregado **apenas** se a classe real ainda não foi definida
 * pelo autoload (mesmo padrão do EntityManagerStub.php).
 *
 * Cobre:
 *  - Espo\Core\Hook\Hook\{AfterSave, AfterRemove, BeforeSave} (interfaces)
 *  - Espo\Core\Container (class concreta minimalista)
 *  - Espo\Core\Job\{Job, Job\Data} (interface + classe data)
 *  - Espo\ORM\{Entity, Repository\Option\SaveOptions, Repository\Option\RemoveOptions}
 *  - Espo\Entities\{User, Role, UserData, Settings, AuthLogRecord}
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
        }
    }
}

// ---- Espo\ORM\Repository\Option\{SaveOptions, RemoveOptions} ----
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

    if (! class_exists(RemoveOptions::class, false)) {
        class RemoveOptions
        {
            public static function create(): self
            {
                return new self();
            }
        }
    }
}

// ---- Espo\Core\Hook\Hook\{AfterSave, AfterRemove, BeforeSave} ----
namespace Espo\Core\Hook\Hook {
    if (! interface_exists(AfterSave::class, false)) {
        interface AfterSave
        {
            public function afterSave(
                \Espo\ORM\Entity $entity,
                \Espo\ORM\Repository\Option\SaveOptions $options,
            ): void;
        }
    }

    if (! interface_exists(BeforeSave::class, false)) {
        interface BeforeSave
        {
            public function beforeSave(
                \Espo\ORM\Entity $entity,
                \Espo\ORM\Repository\Option\SaveOptions $options,
            ): void;
        }
    }

    if (! interface_exists(AfterRemove::class, false)) {
        interface AfterRemove
        {
            public function afterRemove(
                \Espo\ORM\Entity $entity,
                \Espo\ORM\Repository\Option\RemoveOptions $options,
            ): void;
        }
    }

    // Story 5.2 — SoftPurgeDocumentoHook precisa BeforeRemove.
    if (! interface_exists(BeforeRemove::class, false)) {
        interface BeforeRemove
        {
            public function beforeRemove(
                \Espo\ORM\Entity $entity,
                \Espo\ORM\Repository\Option\RemoveOptions $options,
            ): void;
        }
    }
}

// ---- Espo\ORM\Repository\Repository + RDBRepository (Story 5.2) ----
// Stubs minimais — tests usam createMock pra mockar getRepository().
namespace Espo\ORM\Repository {
    if (! class_exists(Repository::class, false)) {
        class Repository
        {
        }
    }
    if (! class_exists(RDBRepository::class, false)) {
        class RDBRepository extends Repository
        {
        }
    }
}

// ---- Espo\Core\Exceptions\BadRequest (Story 3.1 — ValidateBrFieldsHook) ----
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

    // ---- Espo\Core\Exceptions\Forbidden (Story 3.5 — EnforceAssignmentPolicyHook) ----
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

    // Story 5.2 — Documento Hooks (Move + SoftPurge).
    if (! class_exists(Error::class, false)) {
        class Error extends \Exception
        {
        }
    }

    if (! class_exists(Conflict::class, false)) {
        class Conflict extends \Exception
        {
        }
    }

    if (! class_exists(NotFound::class, false)) {
        class NotFound extends \Exception
        {
        }
    }

    // ---- Espo\Core\Exceptions\ServiceUnavailable (Story 5.3 — Documento download fallback PHP-proxy) ----
    if (! class_exists(ServiceUnavailable::class, false)) {
        class ServiceUnavailable extends \Exception
        {
        }
    }
}

// ---- Espo\Core\ApplicationState (Story 3.5 — current user resolver) ----
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

            public function checkEntity(mixed $entity, ?string $action = null): bool
            {
                return true;
            }
        }
    }

    if (! class_exists(ApplicationState::class, false)) {
        class ApplicationState
        {
            private ?\Espo\Entities\User $user = null;

            public function setUser(\Espo\Entities\User $user): void
            {
                $this->user = $user;
            }

            public function getUser(): ?\Espo\Entities\User
            {
                return $this->user;
            }

            public function hasUser(): bool
            {
                return $this->user !== null;
            }
        }
    }
}

// ---- Espo\Core\ORM\Entity (base class para entidades de negócio Togare) ----
//
// Story 3.1 — Cliente.php extends \Espo\Core\ORM\Entity. Em testes unit
// standalone (sem site/ EspoCRM real), stub abaixo provê a base mínima
// (get/set/getId/isNew/isAttributeChanged/getFetched) que os hooks de
// Cliente exercitam.
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
                return $this->fetched[$name] !== ($this->attributes[$name] ?? null);
            }

            public function getFetched(string $name): mixed { return $this->fetched[$name] ?? null; }
            public function setFetched(string $name, mixed $value): void { $this->fetched[$name] = $value; }
        }
    }
}

// ---- Espo\Core\Container (class minimalista) ----
namespace Espo\Core {
    if (! class_exists(Container::class, false)) {
        class Container
        {
            /** @var array<string, object> */
            private array $services = [];

            /** @var array<class-string, object> */
            private array $byClass = [];

            public function get(string $name): mixed
            {
                return $this->services[$name] ?? null;
            }

            public function set(string $name, object $instance): void
            {
                $this->services[$name] = $instance;
            }

            /** @template T of object @param class-string<T> $class @return T|null */
            public function getByClass(string $class): mixed
            {
                return $this->byClass[$class] ?? null;
            }

            /** @template T of object @param class-string<T> $class @param T $instance */
            public function setByClass(string $class, object $instance): void
            {
                $this->byClass[$class] = $instance;
            }
        }
    }
}

// ---- Espo\Core\Job\Job + Espo\Core\Job\Job\Data ----
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

// ---- Espo\Entities\User ----
namespace Espo\Entities {
    if (! class_exists(User::class, false)) {
        class User implements \Espo\ORM\Entity
        {
            public const ENTITY_TYPE = 'User';
            public const TYPE_REGULAR = 'regular';
            public const TYPE_ADMIN = 'admin';
            public const TYPE_API = 'api';

            /** @var array<string, mixed> */
            private array $attributes = [];
            /** @var array<string, mixed> */
            private array $fetched = [];
            /** @var array<string, list<string>> */
            private array $linkIds = [];
            private ?string $id = null;
            private bool $new = true;

            public function getId(): ?string { return $this->id; }
            public function setId(string $id): void { $this->id = $id; $this->new = false; }
            public function get(string $name): mixed { return $this->attributes[$name] ?? null; }

            public function set(mixed $key, mixed $value = null): void
            {
                if (\is_array($key)) {
                    foreach ($key as $k => $v) {
                        $this->attributes[$k] = $v;
                        // Popula linkIds para qualquer campo *Ids (ex: rolesIds→roles, teamsIds→teams)
                        if (\str_ends_with($k, 'Ids') && \is_array($v)) {
                            $this->linkIds[\substr($k, 0, -3)] = $v;
                        }
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
                return $this->fetched[$name] !== ($this->attributes[$name] ?? null);
            }

            public function getFetched(string $name): mixed { return $this->fetched[$name] ?? null; }
            public function setFetched(string $name, mixed $value): void { $this->fetched[$name] = $value; }

            public function getUserName(): ?string { return $this->attributes['userName'] ?? null; }

            /** @return list<string> */
            public function getLinkMultipleIdList(string $link): array
            {
                return $this->linkIds[$link] ?? [];
            }

            // Story 3.5 — EnforceAssignmentPolicyHook detecta system superadmin.
            public function isAdmin(): bool
            {
                return ($this->attributes['type'] ?? null) === self::TYPE_ADMIN;
            }

            public function isPortal(): bool
            {
                return ($this->attributes['type'] ?? null) === 'portal';
            }
        }
    }

    if (! class_exists(Role::class, false)) {
        class Role implements \Espo\ORM\Entity
        {
            public const ENTITY_TYPE = 'Role';

            /** @var array<string, mixed> */
            private array $attributes = [];
            /** @var array<string, mixed> */
            private array $fetched = [];
            private ?string $id = null;
            private bool $new = true;

            public function getId(): ?string { return $this->id; }
            public function setId(string $id): void { $this->id = $id; $this->new = false; }
            public function get(string $name): mixed { return $this->attributes[$name] ?? null; }

            public function set(mixed $key, mixed $value = null): void
            {
                if (\is_array($key)) {
                    foreach ($key as $k => $v) { $this->attributes[$k] = $v; }
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
                return $this->fetched[$name] !== ($this->attributes[$name] ?? null);
            }

            public function getFetched(string $name): mixed { return $this->fetched[$name] ?? null; }
            public function setFetched(string $name, mixed $value): void { $this->fetched[$name] = $value; }
        }
    }

    if (! class_exists(UserData::class, false)) {
        class UserData implements \Espo\ORM\Entity
        {
            public const ENTITY_TYPE = 'UserData';

            /** @var array<string, mixed> */
            private array $attributes = [];
            /** @var array<string, mixed> */
            private array $fetched = [];
            private ?string $id = null;

            public function getId(): ?string { return $this->id; }
            public function setId(string $id): void { $this->id = $id; }
            public function get(string $name): mixed { return $this->attributes[$name] ?? null; }

            public function set(mixed $key, mixed $value = null): void
            {
                if (\is_array($key)) {
                    foreach ($key as $k => $v) { $this->attributes[$k] = $v; }
                    return;
                }
                $this->attributes[$key] = $value;
            }

            public function isNew(): bool { return $this->id === null; }

            public function isAttributeChanged(string $name): bool
            {
                if (! \array_key_exists($name, $this->fetched)) {
                    return \array_key_exists($name, $this->attributes);
                }
                return $this->fetched[$name] !== ($this->attributes[$name] ?? null);
            }

            public function getFetched(string $name): mixed { return $this->fetched[$name] ?? null; }
            public function setFetched(string $name, mixed $value): void { $this->fetched[$name] = $value; }
        }
    }

    if (! class_exists(Settings::class, false)) {
        class Settings implements \Espo\ORM\Entity
        {
            public const ENTITY_TYPE = 'Settings';

            /** @var array<string, mixed> */
            private array $attributes = [];
            /** @var array<string, mixed> */
            private array $fetched = [];

            public function getId(): ?string { return null; }
            public function get(string $name): mixed { return $this->attributes[$name] ?? null; }

            public function set(mixed $key, mixed $value = null): void
            {
                if (\is_array($key)) {
                    foreach ($key as $k => $v) { $this->attributes[$k] = $v; }
                    return;
                }
                $this->attributes[$key] = $value;
            }

            public function isNew(): bool { return false; }

            public function isAttributeChanged(string $name): bool
            {
                if (! \array_key_exists($name, $this->fetched)) {
                    return \array_key_exists($name, $this->attributes);
                }
                return $this->fetched[$name] !== ($this->attributes[$name] ?? null);
            }

            public function getFetched(string $name): mixed { return $this->fetched[$name] ?? null; }
            public function setFetched(string $name, mixed $value): void { $this->fetched[$name] = $value; }
        }
    }

    if (! class_exists(AuthLogRecord::class, false)) {
        class AuthLogRecord implements \Espo\ORM\Entity
        {
            public const ENTITY_TYPE = 'AuthLogRecord';

            /** @var array<string, mixed> */
            private array $attributes = [];
            private ?string $id = null;
            private bool $new = true;

            public function getId(): ?string { return $this->id; }
            public function setId(string $id): void { $this->id = $id; $this->new = false; }
            public function get(string $name): mixed { return $this->attributes[$name] ?? null; }

            public function set(mixed $key, mixed $value = null): void
            {
                if (\is_array($key)) {
                    foreach ($key as $k => $v) { $this->attributes[$k] = $v; }
                    return;
                }
                $this->attributes[$key] = $value;
            }

            public function isNew(): bool { return $this->new; }
            public function setNotNew(): void { $this->new = false; }
        }
    }
}

// ---- Espo\Core\Authentication\Result\FailReason (interface) — Story 2.4 AC6 ----
namespace Espo\Core\Authentication\Result {
    if (! interface_exists(FailReason::class, false)) {
        interface FailReason
        {
            public const DENIED              = 'denied';
            public const CODE_NOT_VERIFIED   = 'codeNotVerified';
            public const NO_USERNAME         = 'noUsername';
            public const NO_PASSWORD         = 'noPassword';
            public const TOKEN_NOT_FOUND     = 'tokenNotFound';
            public const USER_NOT_FOUND      = 'userNotFound';
            public const WRONG_CREDENTIALS   = 'wrongCredentials';
            public const USER_TOKEN_MISMATCH = 'userTokenMismatch';
            public const HASH_NOT_MATCHED    = 'hashNotMatched';
            public const METHOD_NOT_ALLOWED  = 'methodNotAllowed';
            public const DISCREPANT_DATA     = 'discrepantData';
            public const ANOTHER_USER_NOT_FOUND    = 'anotherUserNotFound';
            public const ANOTHER_USER_NOT_ALLOWED  = 'anotherUserNotAllowed';
            public const ERROR               = 'error';
        }
    }
}

// ---- Espo\Core\Api\Request (interface) — Story 2.4 AC6 ----
namespace Espo\Core\Api {
    if (! interface_exists(Request::class, false)) {
        interface Request
        {
            public function getServerParam(string $name): mixed;
            public function getHeader(string $name): ?string;
            public function getMethod(): string;
        }
    }

    if (! class_exists(Response::class, false)) {
        class Response
        {
            public int $status = 200;
            public string $reason = 'OK';
            /** @var array<string, string> */
            public array $headers = [];
            public string $body = '';

            public function setStatus(int $status, ?string $reason = null): void
            {
                $this->status = $status;
                $this->reason = $reason ?? '';
            }

            public function setHeader(string $name, string $value): void
            {
                $this->headers[$name] = $value;
            }

            public function writeBody(string $body): void
            {
                $this->body .= $body;
            }
        }
    }
}

// ---- Espo\Core\Controllers\Record (stub p/ Controller Documento) ----
namespace Espo\Core\Controllers {
    if (! class_exists(Record::class, false)) {
        class Record
        {
            public mixed $injectableFactory = null;
        }
    }
}

// ---- Espo\Core\Authentication\Hook\OnResult (interface) — Story 2.4 AC6 ----
namespace Espo\Core\Authentication\Hook {
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

// ---- Espo\Core\Authentication\Result + AuthenticationData — Story 2.4 AC6 ----
namespace Espo\Core\Authentication {
    if (! class_exists(Result::class, false)) {
        class Result
        {
            private function __construct(
                private bool $fail,
                private ?\Espo\Entities\User $user,
                private ?string $failReason,
            ) {}

            public static function success(\Espo\Entities\User $user): self
            {
                return new self(false, $user, null);
            }

            public static function fail(?string $reason = null): self
            {
                return new self(true, null, $reason);
            }

            public function isFail(): bool { return $this->fail; }
            public function isSuccess(): bool { return ! $this->fail; }
            public function getUser(): ?\Espo\Entities\User { return $this->user; }
            public function getFailReason(): ?string { return $this->failReason; }
        }
    }

    if (! class_exists(AuthenticationData::class, false)) {
        class AuthenticationData
        {
            public function __construct(
                private ?string $username = null,
                private ?string $password = null,
                private ?string $method = null,
            ) {}

            public function getUsername(): ?string { return $this->username; }
            public function getPassword(): ?string { return $this->password; }
            public function getMethod(): ?string { return $this->method; }
        }
    }
}

// ---- Espo\Core\Utils\Config (Story 4b.2 — EmailNotificationService) ----
namespace Espo\Core\Utils {
    if (! class_exists(Config::class, false)) {
        class Config
        {
            /** @var array<string, mixed> */
            private array $data = [];

            public function get(string $name, mixed $default = null): mixed
            {
                return $this->data[$name] ?? $default;
            }

            public function set(string $name, mixed $value): void
            {
                $this->data[$name] = $value;
            }
        }
    }
}

// ---- Espo\Core\Mail\* + Espo\Entities\Email (Story 4b.2 — EmailNotificationService) ----
namespace Espo\Core\Mail {
    if (! class_exists(EmailFactory::class, false)) {
        class EmailFactory
        {
            public function create(): \Espo\Entities\Email
            {
                return new \Espo\Entities\Email();
            }
        }
    }

    if (! class_exists(EmailSender::class, false)) {
        class EmailSender
        {
            /** @var list<\Espo\Entities\Email> */
            public array $sent = [];
            public bool $shouldThrow = false;

            public function create(): Sender
            {
                return new Sender();
            }

            public function send(\Espo\Entities\Email $email): void
            {
                if ($this->shouldThrow) {
                    throw new Exceptions\SendingError('Simulated SMTP failure');
                }
                $this->sent[] = $email;
            }
        }
    }

    if (! class_exists(Sender::class, false)) {
        class Sender
        {
            /** @var list<\Espo\Entities\Email> */
            public array $sent = [];
            public bool $shouldThrow = false;

            public function send(\Espo\Entities\Email $email): void
            {
                if ($this->shouldThrow) {
                    throw new Exceptions\SendingError('Simulated SMTP failure');
                }
                $this->sent[] = $email;
            }
        }
    }
}

namespace Espo\Core\Mail\Exceptions {
    if (! class_exists(SendingError::class, false)) {
        class SendingError extends \RuntimeException {}
    }
}

namespace Espo\Entities {
    if (! class_exists(Email::class, false)) {
        class Email implements \Espo\ORM\Entity
        {
            public const ENTITY_TYPE = 'Email';

            /** @var array<string, mixed> */
            private array $attributes = [];
            private ?string $id = null;
            private bool $new = true;

            public function getId(): ?string { return $this->id; }
            public function setId(string $id): void { $this->id = $id; $this->new = false; }

            public function get(string $name): mixed
            {
                return $this->attributes[$name] ?? null;
            }

            public function set(mixed $key, mixed $value = null): void
            {
                if (\is_array($key)) {
                    foreach ($key as $k => $v) { $this->attributes[$k] = $v; }
                    return;
                }
                $this->attributes[$key] = $value;
            }

            public function setSubject(string $subject): void { $this->attributes['subject'] = $subject; }
            public function setBody(string $body): void { $this->attributes['body'] = $body; }
            public function addToAddress(string $address): void { $this->attributes['to'] = $address; }

            public function isNew(): bool { return $this->new; }
        }
    }
}

// ---- Espo\Core\Binding\{Binder, BindingProcessor, ContextualBinder} (Story 6.0 — togare-core/Binding test) ----
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

            /** @var list<array{contextClass:string, key:string}> */
            public array $contextualCallbacks = [];

            public function bindImplementation(string $interface, string $implementation): static
            {
                $this->implementations[] = [
                    'interface' => $interface,
                    'implementation' => $implementation,
                ];
                return $this;
            }

            public function bindService(string $key, string $service): static { return $this; }
            public function bindValue(string $key, mixed $value): static { return $this; }
            public function bindInstance(string $key, mixed $instance): static { return $this; }
            public function bindCallback(string $key, callable $callback): static { return $this; }
            public function bindFactory(string $key, string $factory): static { return $this; }

            public function for(string $contextClass): ContextualBinder
            {
                return new ContextualBinder($this, $contextClass);
            }
        }
    }

    if (! class_exists(ContextualBinder::class, false)) {
        class ContextualBinder
        {
            public function __construct(
                private readonly ?Binder $binder = null,
                private readonly string $contextClass = '',
            ) {
            }

            public function bindCallback(string $key, callable $callback): static
            {
                if ($this->binder !== null) {
                    $this->binder->contextualCallbacks[] = [
                        'contextClass' => $this->contextClass,
                        'key' => $key,
                    ];
                }
                return $this;
            }

            public function bindImplementation(string $interface, string $implementation): static { return $this; }
            public function bindValue(string $key, mixed $value): static { return $this; }
        }
    }
}

// ---- Espo\Core\Container (stub minimal — Story 6.0 BindingTest) ----
namespace Espo\Core {
    if (! class_exists(Container::class, false)) {
        class Container
        {
            /** @var array<string, mixed> */
            private array $services = [];

            public function get(string $name): mixed
            {
                return $this->services[$name] ?? null;
            }

            public function getByClass(string $class): mixed
            {
                return $this->services[$class] ?? null;
            }

            public function set(string $name, mixed $value): void
            {
                $this->services[$name] = $value;
            }
        }
    }
}
