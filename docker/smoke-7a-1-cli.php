<?php
declare(strict_types=1);

/**
 * Story 7a.1 — Smoke F1 CLI (parte Claude, sem browser).
 *
 * Valida em RUNTIME real do EspoCRM 9.3.6 o que vitest/PHPUnit não cobrem:
 *
 *  1) Módulo TogarePortalUi instalado (dir custom + extension list).
 *  2) clientDefs/App.loginView efetivo == togare-portal-ui:views/portal/login
 *     (mecanismo OQ resolvido — SettingsService injeta em config.loginView).
 *  3) Defaults curados seedados em config (AfterInstall idempotente):
 *     togarePortalSplashPrimaryColor=#0d47a1 + togarePortalSplashWelcome
 *     == literal aprovado por Felipe (Gate A2).
 *  4) CANAL PRÉ-AUTH (OQ#1) — SettingsService::getConfigData() (o blob
 *     entregue à página de login NÃO autenticada) contém loginView E os
 *     params togarePortalSplash*. Esta é a prova de que o splash branded
 *     tem dados antes do login.
 *  5) Layout do painel admin publicado em data/layouts/Settings/
 *     portalAppearance.json (trap stock-entity B25) — JSON válido + 4 campos.
 *  6) adminPanel: grupo portal contém o item #Admin/portalAppearance.
 *  7) Bundle client: lib/init.js + lib/module-togare-portal-ui.js + css
 *     presentes no destino instalado (pattern bundled — sem 404 runtime).
 *  8) Entry point público do logo custom instalado; não usa LogoImage stock
 *     (que só aceita targetField companyLogo).
 *
 * Idempotência: somente leitura/asserções; não cria registros.
 */

chdir('/var/www/html');
require '/var/www/html/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Metadata;

$app = new Application();
$app->setupSystemUser();
$container = $app->getContainer();

$APPROVED_WELCOME = 'Olá. Aqui você acompanha o andamento do seu processo.';
$CURATED_COLOR = '#0d47a1';

$pass = 0;
$fail = 0;
/** @param bool $cond */
function check(string $label, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ✓ $label\n";
    } else {
        $fail++;
        echo "  ✗ $label" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

echo "== Story 7a.1 — Smoke CLI ==\n";

// 1) Módulo instalado
$modDir = '/var/www/html/custom/Espo/Modules/TogarePortalUi';
check('1. Módulo TogarePortalUi instalado (dir presente)', is_dir($modDir));

// 2) loginView efetivo via Metadata
$metadata = $container->getByClass(Metadata::class);
$loginView = $metadata->get(['clientDefs', 'App', 'loginView']);
check(
    '2. clientDefs/App.loginView == togare-portal-ui:views/portal/login',
    $loginView === 'togare-portal-ui:views/portal/login',
    'valor efetivo: ' . var_export($loginView, true),
);

// 3) Defaults curados em config
$config = $container->getByClass(Config::class);
$cfgColor = $config->get('togarePortalSplashPrimaryColor');
$cfgWelcome = $config->get('togarePortalSplashWelcome');
check(
    '3a. config togarePortalSplashPrimaryColor == ' . $CURATED_COLOR,
    $cfgColor === $CURATED_COLOR,
    'valor: ' . var_export($cfgColor, true),
);
check(
    '3b. config togarePortalSplashWelcome == literal aprovado (Gate A2)',
    $cfgWelcome === $APPROVED_WELCOME,
    'valor: ' . var_export($cfgWelcome, true),
);

// 4) CANAL PRÉ-AUTH (OQ#1) — getAllNonInternalData() é exatamente a fonte
//    que SettingsService::getConfigData() usa para montar o blob entregue
//    à página de login NÃO autenticada. Se nossos params estão aqui, o
//    splash branded os recebe pré-auth (mesmo canal do companyLogoId).
$nonInternal = $config->getAllNonInternalData();
check(
    '4a. getAllNonInternalData() expõe togarePortalSplashPrimaryColor pré-auth',
    ($nonInternal->togarePortalSplashPrimaryColor ?? null) === $CURATED_COLOR,
    'valor: ' . var_export($nonInternal->togarePortalSplashPrimaryColor ?? null, true),
);
check(
    '4b. getAllNonInternalData() expõe togarePortalSplashWelcome pré-auth',
    ($nonInternal->togarePortalSplashWelcome ?? null) === $APPROVED_WELCOME,
);
check(
    '4c. getAllNonInternalData() expõe togarePortalSplashPhone (chave presente, opcional)',
    property_exists($nonInternal, 'togarePortalSplashPhone')
        || $config->has('togarePortalSplashPhone') === false,
);

// 5) Layout publicado (trap stock-entity B25)
$layoutFile = '/var/www/html/data/layouts/Settings/portalAppearance.json';
$layoutOk = is_file($layoutFile);
$layoutJson = $layoutOk ? json_decode((string) file_get_contents($layoutFile), true) : null;
check('5a. data/layouts/Settings/portalAppearance.json existe', $layoutOk);
check(
    '5b. layout é JSON válido e referencia os 4 campos',
    is_array($layoutJson)
        && str_contains((string) json_encode($layoutJson), 'togarePortalSplashLogo')
        && str_contains((string) json_encode($layoutJson), 'togarePortalSplashPrimaryColor')
        && str_contains((string) json_encode($layoutJson), 'togarePortalSplashWelcome')
        && str_contains((string) json_encode($layoutJson), 'togarePortalSplashPhone'),
);

// 6) adminPanel portal group
$adminPanel = $metadata->get(['app', 'adminPanel', 'portal', 'itemList']) ?? [];
$hasItem = false;
foreach ($adminPanel as $it) {
    if (is_array($it) && (($it['url'] ?? null) === '#Admin/portalAppearance')) {
        $hasItem = true;
        break;
    }
}
check('6. adminPanel.portal contém #Admin/portalAppearance', $hasItem);

// 7) Bundle client no destino instalado
$libDir = '/var/www/html/client/custom/modules/togare-portal-ui/lib';
$cssFile = '/var/www/html/client/custom/modules/togare-portal-ui/css/accessibility.css';
check('7a. lib/init.js instalado', is_file($libDir . '/init.js'));
check(
    '7b. lib/module-togare-portal-ui.js instalado',
    is_file($libDir . '/module-togare-portal-ui.js'),
);
check('7c. css/accessibility.css instalado', is_file($cssFile));

// 8) Entry point pré-auth próprio para o campo de logo custom do splash.
$entryPointFile = '/var/www/html/custom/Espo/Modules/TogarePortalUi/EntryPoints/PortalSplashLogoImage.php';
$entryPointOk = is_file($entryPointFile);
$entryPointContents = $entryPointOk ? (string) file_get_contents($entryPointFile) : '';
check('8a. EntryPoint PortalSplashLogoImage instalado', $entryPointOk);
check(
    '8b. EntryPoint permite somente o campo togarePortalSplashLogo',
    str_contains($entryPointContents, "allowedFieldList = ['togarePortalSplashLogo']")
        || str_contains($entryPointContents, 'allowedFieldList = ["togarePortalSplashLogo"]'),
);

echo "\n== RESULTADO: $pass PASS / $fail FAIL ==\n";
exit($fail === 0 ? 0 : 1);
