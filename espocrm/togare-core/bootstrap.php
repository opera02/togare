<?php

// Bootstrap para testes unit.
// Raiz do monorepo para testes que varrem o source tree (ex: TenantAwareEntityTest).
$_togareRoot = realpath(__DIR__ . '/../..');
if ($_togareRoot === false) {
    throw new \RuntimeException('Não foi possível resolver a raiz do monorepo a partir de ' . __DIR__);
}
define('TOGARE_PROJECT_ROOT', $_togareRoot);
unset($_togareRoot);

// Autoload do próprio togare-core (deps dev + PSR-4 Espo\Modules\TogareCore\*).
require __DIR__ . '/vendor/autoload.php';

// Autoload do EspoCRM (site/). Opcional: testes unit não precisam do core,
// mas integration tests (tests/integration/) precisam.
if (is_file(__DIR__ . '/site/vendor/autoload.php')) {
    require __DIR__ . '/site/vendor/autoload.php';
}

// Stub de Espo\ORM\EntityManager para testes unit standalone (sem EspoCRM core).
// Carrega apenas se a classe real ainda não foi definida pelo autoload do site/.
if (! class_exists(\Espo\ORM\EntityManager::class, false)) {
    require __DIR__ . '/tests/unit/Espo/Modules/TogareCore/Stubs/EntityManagerStub.php';
}

// Story 2.4 — stubs leves de várias classes core EspoCRM (Hook interfaces,
// Container, Job, Entity, User, Role, UserData, Settings, AuthLogRecord).
// Cada stub carrega apenas se a classe real ainda não foi definida.
require __DIR__ . '/tests/unit/Espo/Modules/TogareCore/Stubs/CoreStubs.php';
