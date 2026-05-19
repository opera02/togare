<?php

// Bootstrap para testes unit.
// Autoload do próprio togare-licensing (deps dev + PSR-4 Espo\Modules\TogareLicensing\*).
require __DIR__ . '/vendor/autoload.php';

// Autoload do EspoCRM (site/). Opcional: testes unit não precisam do core,
// mas integration tests (tests/integration/) precisam.
if (is_file(__DIR__ . '/site/vendor/autoload.php')) {
    require __DIR__ . '/site/vendor/autoload.php';
}

// Stub de Espo\ORM\EntityManager para testes unit standalone (sem EspoCRM core).
// Carrega apenas se a classe real ainda não foi definida pelo autoload do site/.
// Padrão idêntico à Story 1b.1.1.1-followup do togare-core.
if (! class_exists(\Espo\ORM\EntityManager::class, false)) {
    require __DIR__ . '/tests/unit/Espo/Modules/TogareLicensing/Stubs/EntityManagerStub.php';
}
