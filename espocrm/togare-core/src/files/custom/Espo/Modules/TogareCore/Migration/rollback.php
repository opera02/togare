<?php

/**
 * Script CLI para rollback manual de uma migration específica.
 *
 * Uso (dentro do container EspoCRM):
 *   php /var/www/html/custom/Espo/Modules/TogareCore/Migration/rollback.php V003__nome
 *
 * Credenciais PDO vêm do EspoCRM config (data/config.php). Exit 0 em sucesso
 * ou idempotent; exit 1 em erro.
 */

declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "Uso: php rollback.php <version>\n");
    fwrite(STDERR, "Ex.: php rollback.php V003__add_togare_core_smoke_created_idx\n");
    exit(2);
}

$version = (string) $argv[1];

// Bootstrap mínimo do EspoCRM para acessar config + autoloader.
$espoRoot = '/var/www/html';
if (! is_file($espoRoot . '/vendor/autoload.php')) {
    fwrite(STDERR, "EspoCRM root não encontrado em {$espoRoot}. Ajustar caminho se necessário.\n");
    exit(1);
}
require $espoRoot . '/vendor/autoload.php';

// EspoCRM divide config em data/config.php (user-facing) e data/config-internal.php
// (database + segredos). Procuramos 'database' nos dois, em ordem.
$db = null;
foreach (['/data/config-internal.php', '/data/config.php'] as $rel) {
    $path = $espoRoot . $rel;
    if (! is_file($path)) {
        continue;
    }
    $loaded = require $path;
    if (is_array($loaded) && isset($loaded['database']) && is_array($loaded['database'])) {
        $db = $loaded['database'];
        break;
    }
}

if ($db === null) {
    fwrite(STDERR, "Seção 'database' não encontrada em data/config*.php.\n");
    exit(1);
}

$host = $db['host'] ?? 'mariadb';
$port = $db['port'] ?? 3306;
$name = $db['dbname'] ?? 'espocrm';
$user = $db['user'] ?? 'espocrm';
$pass = $db['password'] ?? '';

$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$migrationDir = __DIR__;
$runner = new \Espo\Modules\TogareCore\Services\MigrationRunner($pdo);

try {
    $reverted = $runner->rollback($version, $migrationDir);
    if ($reverted) {
        echo "✓ Rollback de {$version} concluído.\n";
        exit(0);
    }

    echo "ℹ {$version} não está aplicada (noop idempotente).\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "❌ Rollback de {$version} falhou: " . $e->getMessage() . "\n");
    exit(1);
}
