<?php
declare(strict_types=1);

/**
 * Story 10.1 / FR40 — Smoke F1 CLI (Claude executa; Felipe faz o browser).
 *
 * Valida, no runtime EspoCRM real:
 *  - TogareBriefingService resolve via injectableFactory e getSummaryForUser não lança;
 *  - Admin retorna role=socio-admin com badge 'health' do tipo 'health';
 *  - payload tem { badges, role, generatedAt } com tipos corretos;
 *  - dashlet 'BriefingDoDia' registrado em metadata/dashlets;
 *  - i18n Global.dashlets.BriefingDoDia = 'Briefing do Dia';
 *  - Controller TogareBriefing existe e tem getActionData (endpoint GET);
 *  - Settings.dashboardLayout contém ambos: togare-prazos-do-dia e briefing-do-dia
 *    no tab 'Briefing' (AC5 idempotente AfterInstall).
 *
 * Rerun-safe: somente leitura, não grava nada.
 */

chdir('/var/www/html');
require '/var/www/html/bootstrap.php';

use Espo\Core\Application;
use Espo\Modules\TogareCore\Services\TogareBriefingService;
use Espo\Modules\TogareCore\Services\DashboardLayoutSeeder;

$app       = new Application();
$container = $app->getContainer();
$injectableFactory = $container->get('injectableFactory');

echo "=== STORY 10.1 SMOKE F1 CLI (BriefingDoDia / FR40) ===\n";

$fail = 0;
$ok   = static function (string $msg): void { echo "OK   - {$msg}\n"; };
$ko   = static function (string $msg) use (&$fail): void {
    $fail++;
    echo "FAIL - {$msg}\n";
};

// (1) Resolve service via injectableFactory.
try {
    $service = $injectableFactory->create(TogareBriefingService::class);
    $ok('TogareBriefingService resolvido via DI');
} catch (\Throwable $e) {
    $ko('TogareBriefingService DI falhou: ' . get_class($e) . ': ' . $e->getMessage());
    echo "\n=== SMOKE CLI FALHOU (1) ===\n";
    exit(1);
}

// (2) Obtém o usuário admin (id=1) para chamar getSummaryForUser.
$em      = $container->get('entityManager');
$adminUser = $em->getRDBRepository('User')->where(['type' => 'admin'])->findOne();
if ($adminUser === null) {
    $ko('Nenhum usuário admin encontrado — smoke parcial');
} else {
    $ok('Admin user encontrado: ' . $adminUser->get('userName'));

    // (3) getSummaryForUser não lança (AC6).
    try {
        $result = $service->getSummaryForUser($adminUser);
        $ok('getSummaryForUser() executou sem lançar (AC6)');
    } catch (\Throwable $e) {
        $ko('getSummaryForUser() lançou: ' . get_class($e) . ': ' . $e->getMessage());
        echo "\n=== SMOKE CLI FALHOU ({$fail}) ===\n";
        exit(1);
    }

    // (4) Estrutura de retorno.
    foreach (['badges', 'role', 'generatedAt'] as $k) {
        array_key_exists($k, $result)
            ? $ok("payload tem chave '{$k}'")
            : $ko("payload SEM chave '{$k}'");
    }

    // (5) Admin → socio-admin.
    ($result['role'] ?? '') === 'socio-admin'
        ? $ok("admin → role='socio-admin'")
        : $ko("role inesperado para admin: '" . ($result['role'] ?? 'null') . "'");

    // (6) Sócio/Admin tem badge health do tipo 'health'.
    $healthBadge = null;
    foreach ($result['badges'] ?? [] as $b) {
        if (($b['key'] ?? '') === 'health') {
            $healthBadge = $b;
            break;
        }
    }
    $healthBadge !== null
        ? $ok('badge health presente em socio-admin')
        : $ko('badge health AUSENTE em socio-admin (AC1)');

    if ($healthBadge !== null) {
        ($healthBadge['type'] ?? '') === 'health'
            ? $ok("badge health tem type='health'")
            : $ko("badge health sem type='health': " . json_encode($healthBadge));

        in_array($healthBadge['healthStatus'] ?? '', ['ok', 'lento', 'offline'], true)
            ? $ok("healthStatus válido: '{$healthBadge['healthStatus']}'")
            : $ko("healthStatus inválido: '" . ($healthBadge['healthStatus'] ?? 'null') . "'");
    }

    // (7) generatedAt é ISO-8601 plausível.
    $gen = (string) ($result['generatedAt'] ?? '');
    (strtotime($gen) !== false)
        ? $ok("generatedAt parseável: {$gen}")
        : $ko("generatedAt inválido: '{$gen}'");
}

// (8) Dashlet registrado em metadata + i18n.
$metadata = $container->get('metadata');
$dashlet  = $metadata->get(['dashlets', 'BriefingDoDia']);
($dashlet !== null && ($dashlet['view'] ?? '') === 'togare-core:views/dashlets/briefing-do-dia')
    ? $ok('dashlet BriefingDoDia registrado (metadata/dashlets)')
    : $ko('dashlet BriefingDoDia NÃO registrado em metadata — veio: ' . json_encode($dashlet));

$language = $container->get('language');
$label    = $language->translate('BriefingDoDia', 'dashlets');
$label === 'Briefing do Dia'
    ? $ok("i18n Global.dashlets.BriefingDoDia = '{$label}'")
    : $ko("i18n BriefingDoDia inesperado: '" . var_export($label, true) . "'");

// (9) Controller existe + endpoint method.
$ctrl = 'Espo\\Modules\\TogareCore\\Controllers\\TogareBriefing';
(class_exists($ctrl) && method_exists($ctrl, 'getActionData'))
    ? $ok('Controller TogareBriefing::getActionData presente (endpoint GET TogareBriefing/action/data)')
    : $ko('Controller TogareBriefing ou getActionData ausente');

// (10) Settings.dashboardLayout contém ambos dashlets no tab Briefing (AC5).
$config = $container->get('config');
$layout = $config->get('dashboardLayout');

DashboardLayoutSeeder::hasDashlet($layout)
    ? $ok("dashboardLayout contém togare-prazos-do-dia (AfterInstall AC5)")
    : $ko("dashboardLayout SEM togare-prazos-do-dia");

DashboardLayoutSeeder::hasBriefingDoDia($layout)
    ? $ok("dashboardLayout contém briefing-do-dia (AfterInstall AC5)")
    : $ko("dashboardLayout SEM briefing-do-dia (AC5 FALHOU — verifique AfterInstall)");

// (11) Tab Briefing existe com ambos lado a lado.
$briefingTab = null;
if (is_array($layout)) {
    foreach ($layout as $tab) {
        if (is_array($tab) && ($tab['name'] ?? '') === 'Briefing') {
            $briefingTab = $tab;
            break;
        }
    }
}
$briefingTab !== null
    ? $ok("tab 'Briefing' encontrado no dashboardLayout")
    : $ko("tab 'Briefing' NÃO encontrado no dashboardLayout");

if ($briefingTab !== null) {
    $itemNames = array_map(static fn ($i) => $i['name'] ?? '', $briefingTab['layout'] ?? []);
    (in_array('togare-prazos-do-dia', $itemNames, true) && in_array('briefing-do-dia', $itemNames, true))
        ? $ok("tab Briefing contém ambos: togare-prazos-do-dia + briefing-do-dia")
        : $ko("tab Briefing não tem ambos — itens: " . json_encode($itemNames));
}

echo "\n";
if ($fail === 0) {
    echo "=== SMOKE CLI OK ===\n";
    exit(0);
}
echo "=== SMOKE CLI FALHOU ({$fail} falha(s)) ===\n";
exit(1);
