<?php
/**
 * Smoke CLI — Story 6.2 GateBanner (togare-core 0.36.0)
 *
 * Checks:
 *  S1  ContratoHonorariosLookupService resolvable via InjectableFactory
 *  S2  hasContratoVigente(inexistente) → false  (sem contrato → gate bloquearia)
 *  S3  GateBanner CSS presente em assets bundle
 *  S4  gate-banner.tpl presente no bundle
 *  S5  ContratoHonorarios controller tem postActionHasContratoVigente
 *  S6  Extension instalada versão 0.36.0
 */

declare(strict_types=1);

chdir('/var/www/html');
require '/var/www/html/bootstrap.php';

$pass = 0;
$fail = 0;
$errors = [];

function ok(string $label): void {
    global $pass;
    $pass++;
    echo "PASS  {$label}\n";
}

function fail(string $label, string $reason): void {
    global $fail, $errors;
    $fail++;
    $errors[] = $label . ': ' . $reason;
    echo "FAIL  {$label} — {$reason}\n";
}

// Bootstrap app
try {
    $app = new \Espo\Core\Application();
    $app->setupSystemUser();
    $container = $app->getContainer();
    $factory   = $container->getByClass(\Espo\Core\InjectableFactory::class);
} catch (Throwable $e) {
    die("FATAL bootstrap falhou: " . $e->getMessage() . "\n");
}

// S1 — InjectableFactory resolve ContratoHonorariosLookupService
try {
    $lookup = $factory->create(\Espo\Modules\TogareCore\Services\ContratoHonorariosLookupService::class);
    ok('S1 ContratoHonorariosLookupService resolvable');
} catch (Throwable $e) {
    fail('S1 ContratoHonorariosLookupService resolvable', $e->getMessage());
    $lookup = null;
}

// S2 — hasContratoVigente para clienteId inexistente → false
if ($lookup) {
    try {
        $result = $lookup->hasContratoVigente('nonexistent-smoke-id', null);
        if ($result === false) {
            ok('S2 hasContratoVigente(inexistente) == false');
        } else {
            fail('S2 hasContratoVigente(inexistente) == false', 'retornou ' . var_export($result, true));
        }
    } catch (Throwable $e) {
        fail('S2 hasContratoVigente(inexistente) == false', $e->getMessage());
    }
} else {
    fail('S2 hasContratoVigente(inexistente) == false', 'lookup não resolvido (S1 falhou)');
}

// S3 — CSS contém '.togare-gate-banner'
$cssBundle = '/var/www/html/client/custom/modules/togare-core/css/components.css';
if (file_exists($cssBundle)) {
    $css = file_get_contents($cssBundle);
    if (strpos($css, '.togare-gate-banner') !== false) {
        ok('S3 CSS .togare-gate-banner presente');
    } else {
        fail('S3 CSS .togare-gate-banner presente', 'classe não encontrada em ' . $cssBundle);
    }
} else {
    fail('S3 CSS .togare-gate-banner presente', 'arquivo ' . $cssBundle . ' não existe');
}

// S4 — template gate-banner.tpl presente no bundle
$tplBundle = '/var/www/html/client/custom/modules/togare-core/lib/templates.tpl';
if (file_exists($tplBundle)) {
    $tpl = file_get_contents($tplBundle);
    if (strpos($tpl, 'togare-gate-banner') !== false) {
        ok('S4 gate-banner.tpl bundled');
    } else {
        fail('S4 gate-banner.tpl bundled', 'template não encontrado em ' . $tplBundle);
    }
} else {
    fail('S4 gate-banner.tpl bundled', 'arquivo ' . $tplBundle . ' não existe');
}

// S5 — Controller tem postActionHasContratoVigente
$controllerClass = \Espo\Modules\TogareCore\Controllers\ContratoHonorarios::class;
if (method_exists($controllerClass, 'postActionHasContratoVigente')) {
    ok('S5 Controller::postActionHasContratoVigente exists');
} else {
    fail('S5 Controller::postActionHasContratoVigente exists', 'método não encontrado');
}

// S6 — Extension versão 0.36.0
try {
    $em = $container->getByClass(\Espo\ORM\EntityManager::class);
    $ext = $em->getRepository('Extension')
        ->select(['name', 'version'])
        ->where(['name' => 'TogareCore', 'isInstalled' => true])
        ->findOne();
    if ($ext) {
        $ver = $ext->get('version');
        if ($ver === '0.36.0') {
            ok("S6 Extension TogareCore versão 0.36.0");
        } else {
            fail("S6 Extension TogareCore versão 0.36.0", "versão instalada: {$ver}");
        }
    } else {
        fail('S6 Extension TogareCore versão 0.36.0', 'extensão não encontrada no banco');
    }
} catch (Throwable $e) {
    fail('S6 Extension TogareCore versão 0.36.0', $e->getMessage());
}

// Summary
echo "\n--- Smoke 6.2 CLI: {$pass} PASS / {$fail} FAIL ---\n";
if ($fail > 0) {
    echo "Falhas:\n";
    foreach ($errors as $err) {
        echo "  - {$err}\n";
    }
    exit(1);
}
exit(0);
