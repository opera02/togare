<?php
/**
 * Spike 1b.S2 — cria (ou recria) tabela togare_queue_items no MariaDB do spike.
 *
 * THROWAWAY. Reaproveita a Migration V004 do togare-core (Story 1a.4c) sem
 * precisar de MigrationRunner completo — spike não tem contexto EspoCRM
 * instalado, só o autoloader.
 *
 * Uso:
 *   docker compose -f docker-compose.spike.yml run --rm spike-cli \
 *     /opt/togare-spike/init-schema.php [--reset]
 *
 *   --reset  opcional; DROP TABLE togare_queue_items antes de recriar.
 *            (default: cria se não existe — CREATE TABLE IF NOT EXISTS).
 */

declare(strict_types=1);

chdir('/usr/src/espocrm');
require '/usr/src/espocrm/bootstrap.php';

use Espo\Modules\TogareCore\Migration\V004__create_togare_queue_items;

$opts = \getopt('', ['reset']);
$reset = \array_key_exists('reset', $opts);

$pdo = new PDO(
    \sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        (string) \getenv('TOGARE_DB_HOST'),
        (string) \getenv('TOGARE_DB_NAME'),
    ),
    (string) \getenv('TOGARE_DB_USER'),
    (string) \getenv('TOGARE_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$migration = new V004__create_togare_queue_items();

if ($reset) {
    \fwrite(STDOUT, "[init-schema] --reset: dropping togare_queue_items…\n");
    $migration->down($pdo);
}

\fwrite(STDOUT, "[init-schema] ensuring togare_queue_items…\n");
$migration->up($pdo);

$row = $pdo->query('SELECT COUNT(*) AS n FROM togare_queue_items')->fetch(PDO::FETCH_ASSOC);
\fwrite(STDOUT, \sprintf("[init-schema] OK — togare_queue_items contém %d linha(s)\n", (int) ($row['n'] ?? 0)));
