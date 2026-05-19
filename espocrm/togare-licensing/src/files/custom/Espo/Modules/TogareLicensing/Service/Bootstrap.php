<?php

declare(strict_types=1);

namespace Espo\Modules\TogareLicensing\Service;

/**
 * Carrega o autoloader do vendor empacotado da extensão.
 *
 * Necessário porque togare-licensing tem dependência composer (lcobucci/jwt)
 * que NÃO está no autoload global do EspoCRM. O bootstrap registra o autoload
 * do vendor/ embarcado em /var/www/html/custom/Espo/Modules/TogareLicensing/vendor.
 *
 * Idempotente — múltiplas chamadas são no-op. Toda classe da família
 * togare-licensing chama Bootstrap::init() no início de operações que usam
 * lcobucci/jwt.
 */
final class Bootstrap
{
    private static bool $initialized = false;

    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        // Detecta o ComposerAutoloaderInit gerado pelo composer install do nosso
        // vendor empacotado — se já está carregado (ex.: tests via outer composer
        // autoload com mesmo composer.lock), não recarrega: dupla inclusão
        // quebra com "Cannot declare class ... already in use".
        $autoloadFile = __DIR__ . '/../vendor/composer/autoload_real.php';
        if (\is_file($autoloadFile)) {
            $contents = (string) \file_get_contents($autoloadFile);
            if (\preg_match('/class\s+(ComposerAutoloaderInit\w+)/', $contents, $m) === 1) {
                if (\class_exists($m[1], false)) {
                    self::$initialized = true;

                    return;
                }
            }
        }

        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (\is_file($autoload)) {
            require_once $autoload;
        }

        self::$initialized = true;
    }
}
