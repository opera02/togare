<?php
declare(strict_types=1);

/**
 * Story 10.2 / FR41 — Smoke F1 CLI (Claude executa; Felipe faz o browser).
 *
 * Valida, no runtime EspoCRM real:
 *  - HealthCheckService resolve via injectableFactory e getPanel() não lança;
 *  - payload tem 6 tiles na ordem canônica do grid + licenca + historico;
 *  - cada tile tem state ∈ {ok,lento,offline,nao_instalado};
 *  - módulos premium ausentes = nao_instalado (AC1), NUNCA offline;
 *  - dashlet TogareHealth registrado em metadata + i18n Global.dashlets;
 *  - Controller TogareHealth existe e tem getActionData (endpoint);
 *  - sentinela de backup montada read-only é legível (ou ausente = amarelo).
 *
 * Rerun-safe: somente leitura, não grava nada.
 */

chdir('/var/www/html');
require '/var/www/html/bootstrap.php';

use Espo\Core\Application;
use Espo\Modules\TogareCore\Services\HealthCheckService;
use Espo\Modules\TogareCore\Services\Health\HealthPanelComposer;

$app = new Application();
$container = $app->getContainer();
$injectableFactory = $container->get('injectableFactory');

echo "=== STORY 10.2 SMOKE F1 CLI (TogareHealth / FR41) ===\n";

$fail = 0;
$ok = static function (string $msg): void {
    echo "OK   - {$msg}\n";
};
$ko = static function (string $msg) use (&$fail): void {
    $fail++;
    echo "FAIL - {$msg}\n";
};

// (1) Resolve service via injectableFactory.
$service = $injectableFactory->create(HealthCheckService::class);
echo "RESOLVED HealthCheckService: " . get_class($service) . "\n";

// (2) getPanel() não lança (AC5).
try {
    $panel = $service->getPanel();
    $ok('getPanel() executou sem lançar (AC5)');
} catch (\Throwable $e) {
    $ko('getPanel() lançou: ' . get_class($e) . ': ' . $e->getMessage());
    echo "\n=== SMOKE CLI FALHOU ({$fail}) ===\n";
    exit(1);
}

// (3) Estrutura do payload.
foreach (['tiles', 'licenca', 'historico', 'historicLink', 'generatedAt'] as $k) {
    array_key_exists($k, $panel) ? $ok("payload tem chave '{$k}'") : $ko("payload SEM chave '{$k}'");
}
($panel['historicLink'] ?? '') === '#Admin/TogareAuditLog'
    ? $ok("historicLink = '#Admin/TogareAuditLog'")
    : $ko("historicLink inesperado: '" . ($panel['historicLink'] ?? 'null') . "'");

// (4) 6 tiles na ordem canônica do grid (UX C15).
$keys = array_map(static fn ($t) => $t['key'], $panel['tiles']);
$expected = ['djen', 'tpu', 'mariadb', 'nextcloud', 'redis', 'backup'];
count($panel['tiles']) === 6 ? $ok('6 tiles') : $ko('esperado 6 tiles, veio ' . count($panel['tiles']));
$keys === $expected
    ? $ok('ordem canônica djen,tpu,mariadb,nextcloud,redis,backup')
    : $ko('ordem inesperada: ' . json_encode($keys));

// (5) Estados válidos + AC1 (premium ausente nunca offline).
$validStates = [
    HealthPanelComposer::STATE_OK,
    HealthPanelComposer::STATE_LENTO,
    HealthPanelComposer::STATE_OFFLINE,
    HealthPanelComposer::STATE_NAO_INSTALADO,
];
foreach ($panel['tiles'] as $t) {
    in_array($t['state'], $validStates, true)
        ? $ok("tile {$t['key']} estado válido ({$t['state']}) — \"{$t['message']}\"")
        : $ko("tile {$t['key']} estado INVÁLIDO: {$t['state']}");
}

// (6) Detecção de módulos: o que não está instalado deve ser cinza, não erro.
$byKey = [];
foreach ($panel['tiles'] as $t) {
    $byKey[$t['key']] = $t;
}
foreach (['djen', 'tpu', 'nextcloud'] as $premium) {
    $st = $byKey[$premium]['state'];
    if ($st === HealthPanelComposer::STATE_NAO_INSTALADO) {
        $ok("premium '{$premium}' ausente → cinza 'Não instalado' (AC1)");
    } elseif ($st === HealthPanelComposer::STATE_OFFLINE) {
        $ko("AC1 VIOLADA: '{$premium}' ausente apareceu como offline (vermelho)");
    } else {
        $ok("premium '{$premium}' instalado → estado real '{$st}'");
    }
}

// (7) Dashlet registrado em metadata + i18n.
$metadata = $container->get('metadata');
$dashlet = $metadata->get(['dashlets', 'TogareHealth']);
($dashlet !== null && ($dashlet['view'] ?? '') === 'togare-core:views/dashlets/togare-health-panel')
    ? $ok('dashlet TogareHealth registrado (metadata/dashlets)')
    : $ko('dashlet TogareHealth NÃO registrado em metadata');

$language = $container->get('language');
$label = $language->translate('TogareHealth', 'dashlets');
$label === 'Saúde do Togare'
    ? $ok("i18n Global.dashlets.TogareHealth = '{$label}'")
    : $ko("i18n dashlets.TogareHealth inesperado: '" . var_export($label, true) . "'");

// (8) Controller existe + endpoint method.
$ctrl = 'Espo\\Modules\\TogareCore\\Controllers\\TogareHealth';
(class_exists($ctrl) && method_exists($ctrl, 'getActionData'))
    ? $ok('Controller TogareHealth::getActionData presente (endpoint GET TogareHealth/action/data)')
    : $ko('Controller TogareHealth ou getActionData ausente');

// (9) Sentinela de backup: montada read-only OU ausente (amarelo calmo).
$sentinel = getenv('TOGARE_BACKUP_SENTINEL_PATH') ?: '/var/backups/togare/last-success.json';
$backupTile = $byKey['backup'];
if (is_file($sentinel)) {
    $ok("sentinela legível em {$sentinel} → tile backup '{$backupTile['state']}': \"{$backupTile['message']}\"");
} else {
    ($backupTile['state'] === HealthPanelComposer::STATE_LENTO)
        ? $ok("sentinela ausente ({$sentinel}) → tile backup amarelo 'ainda não rodou' (calmo, não vermelho)")
        : $ko("sentinela ausente mas tile backup não está amarelo: {$backupTile['state']}");
}

// (10) generatedAt é ISO-8601 plausível.
$gen = (string) ($panel['generatedAt'] ?? '');
(strtotime($gen) !== false)
    ? $ok("generatedAt parseável: {$gen}")
    : $ko("generatedAt inválido: '{$gen}'");

echo "\n";
if ($fail === 0) {
    echo "=== SMOKE CLI OK ===\n";
    exit(0);
}
echo "=== SMOKE CLI FALHOU ({$fail} falha(s)) ===\n";
exit(1);
