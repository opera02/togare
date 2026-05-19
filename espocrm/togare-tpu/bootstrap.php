<?php

// Bootstrap para testes unit do togare-tpu.
// Raiz do monorepo para testes que varrem o source tree (futuras checagens transversais).
$_togareRoot = realpath(__DIR__ . '/../..');
if ($_togareRoot === false) {
    throw new \RuntimeException('Não foi possível resolver a raiz do monorepo a partir de ' . __DIR__);
}
if (! defined('TOGARE_PROJECT_ROOT')) {
    define('TOGARE_PROJECT_ROOT', $_togareRoot);
}
unset($_togareRoot);

// Autoload do próprio togare-tpu (deps dev + PSR-4 Espo\Modules\TogareTpu\*).
require __DIR__ . '/vendor/autoload.php';

// Autoload do EspoCRM (site/). Opcional: testes unit não precisam do core,
// mas integration tests (tests/integration/) precisam.
if (is_file(__DIR__ . '/site/vendor/autoload.php')) {
    require __DIR__ . '/site/vendor/autoload.php';
}

// Stubs locais de classes core do EspoCRM (Entity interface, SaveOptions,
// BeforeSave hook, BadRequest) — usados pelos testes do hook
// Hooks/Processo/ResolveTpuFieldsHook (Story 3.4). Carregam só se ainda
// não definidos.
require __DIR__ . '/tests/unit/Espo/Modules/TogareTpu/Stubs/CoreStubs.php';

// Stub local de EntityManager (carrega só se a classe real do EspoCRM
// não estiver disponível via site/vendor/autoload.php).
if (! class_exists(\Espo\ORM\EntityManager::class, false)) {
    require __DIR__ . '/tests/unit/Espo/Modules/TogareTpu/Stubs/EntityManagerStub.php';
}

// Stub mínimo da classe Espo\Modules\TogareCore\Services\TogareLogger para
// permitir referência sem precisar do código real do togare-core nos testes.
if (! class_exists(\Espo\Modules\TogareCore\Services\TogareLogger::class, false)) {
    require __DIR__ . '/tests/unit/Espo/Modules/TogareTpu/Stubs/TogareLoggerStub.php';
}

// Stub do Predis\Client para testes standalone (sem composer install).
// Se predis/predis estiver instalado via composer, esta linha é noop.
if (! class_exists(\Predis\Client::class, false)) {
    require __DIR__ . '/tests/unit/Espo/Modules/TogareTpu/Stubs/PredisClientStub.php';
}
