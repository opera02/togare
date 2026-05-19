<?php

// Bootstrap para testes unit do togare-portal-ui.
// Raiz do monorepo para testes que varrem o source tree.
$_togareRoot = realpath(__DIR__ . '/../..');
if ($_togareRoot === false) {
    throw new \RuntimeException('Não foi possível resolver a raiz do monorepo a partir de ' . __DIR__);
}
if (! defined('TOGARE_PROJECT_ROOT')) {
    define('TOGARE_PROJECT_ROOT', $_togareRoot);
}
unset($_togareRoot);

// Autoload do próprio togare-portal-ui (deps dev + PSR-4).
require __DIR__ . '/vendor/autoload.php';

// Autoload do EspoCRM (site/), opcional para testes de integração.
if (is_file(__DIR__ . '/site/vendor/autoload.php')) {
    require __DIR__ . '/site/vendor/autoload.php';
}

// Fallback PSR-4 para classes do togare-core (ativo só quando vendor foi
// gerado sem autoload-dev). Em dev normal o autoload-dev já cobre.
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
