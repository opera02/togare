<?php

// Bootstrap para testes unit do togare-nextcloud-bridge.
// Raiz do monorepo para testes que varrem o source tree (futuras checagens transversais).
$_togareRoot = realpath(__DIR__ . '/../..');
if ($_togareRoot === false) {
    throw new \RuntimeException('Não foi possível resolver a raiz do monorepo a partir de ' . __DIR__);
}
if (! defined('TOGARE_PROJECT_ROOT')) {
    define('TOGARE_PROJECT_ROOT', $_togareRoot);
}
unset($_togareRoot);

// Autoload do próprio togare-nextcloud-bridge (deps dev + PSR-4).
require __DIR__ . '/vendor/autoload.php';

// Autoload do EspoCRM (site/). Opcional: integration tests
// (tests/integration/) precisam.
if (is_file(__DIR__ . '/site/vendor/autoload.php')) {
    require __DIR__ . '/site/vendor/autoload.php';
}

// Fallback PSR-4 para classes do togare-core. Ativo apenas quando
// vendor/autoload.php foi gerado sem autoload-dev (composer install --no-dev).
// Em desenvolvimento normal o vendor/autoload.php já registra este namespace
// via autoload-dev e este loader fica inerte (PHP não repete require de
// classe já definida).
spl_autoload_register(static function (string $class): void {
    $prefix = 'Espo\\Modules\\TogareCore\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $path = __DIR__ . '/../togare-core/src/files/custom/Espo/Modules/TogareCore/' . $relative . '.php';
    if (is_file($path)) {
        require $path;
    }
});

// Stubs locais de classes core do EspoCRM (Forbidden exception, Entity, Job,
// SaveOptions, BadRequest) usados pelos testes que rodam sem site/ populado.
require __DIR__ . '/tests/unit/Espo/Modules/TogareNextcloudBridge/Stubs/CoreStubs.php';

// Stub mínimo da classe Espo\Modules\TogareCore\Services\TogareLogger para
// permitir referência sem precisar do código real do togare-core nos testes.
if (! class_exists(\Espo\Modules\TogareCore\Services\TogareLogger::class, false)) {
    require __DIR__ . '/tests/unit/Espo/Modules/TogareNextcloudBridge/Stubs/TogareLoggerStub.php';
}
