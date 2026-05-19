<?php

// Bootstrap para testes unit do togare-rbac.
// Autoload local (deps dev + PSR-4 Espo\Modules\TogareRbac\*).
require __DIR__ . '/vendor/autoload.php';

// Autoload do EspoCRM (site/) — opcional.
if (is_file(__DIR__ . '/site/vendor/autoload.php')) {
    require __DIR__ . '/site/vendor/autoload.php';
}

// Stub de Espo\ORM\EntityManager para testes unit standalone.
if (! class_exists(\Espo\ORM\EntityManager::class, false)) {
    require __DIR__ . '/tests/unit/Espo/Modules/TogareRbac/Stubs/EntityManagerStub.php';
}

// Story 2.2 — stubs leves de várias classes core EspoCRM (Config, ConfigWriter,
// PasswordHash, BadRequest, Entity, User, Role, PasswordChangeRequest, hooks,
// SaveOptions, Password\Service). Cada um carrega só se a classe real ausente.
require __DIR__ . '/tests/unit/Espo/Modules/TogareRbac/Stubs/CoreStubs.php';
