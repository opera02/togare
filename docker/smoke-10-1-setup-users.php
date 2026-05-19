<?php
declare(strict_types=1);

/**
 * Story 10.1 — fixture de smoke browser: cria 2 usuários de teste com role
 * togare-rbac correto para o Felipe validar Passo 5 (EmptyState Advogado) e
 * Passo 8 (blindagem cruzada Financeiro) do roteiro de smoke.
 *
 * Idempotente (rerun-safe): se o usuário já existe, reseta senha + garante
 * isActive + vínculo de Role. NÃO apaga nada.
 *
 * Senha hasheada via container `passwordHash` — EntityManager::saveEntity
 * direto NÃO hasheia (trap conhecido smoke 7a.1), então pré-hasheamos.
 *
 * Ao final imprime o payload de TogareBriefingService p/ cada user — assim
 * sabemos exatamente o que o browser vai mostrar (em especial se o Advogado
 * cai em EmptyState ou se há audiência da semana office-wide).
 */

chdir('/var/www/html');
require '/var/www/html/bootstrap.php';

use Espo\Core\Application;
use Espo\Modules\TogareCore\Services\TogareBriefingService;

$app       = new Application();
$app->setupSystemUser();
$container = $app->getContainer();
$em        = $container->get('entityManager');
$passwordHash = $container->get('passwordHash');
$injectableFactory = $container->get('injectableFactory');

echo "=== STORY 10.1 — SETUP USUÁRIOS DE SMOKE ===\n";

$TARGETS = [
    [
        'userName' => 'smoke.advogado',
        'name'     => 'Smoke Advogado (teste)',
        'password' => 'SmokeAdv2026!',
        'roleName' => 'Advogado',
    ],
    [
        'userName' => 'smoke.financeiro',
        'name'     => 'Smoke Financeiro (teste)',
        'password' => 'SmokeFin2026!',
        'roleName' => 'Financeiro',
    ],
];

/** Resolve Role togare-rbac por nome exato (o que TogareBriefingService casa). */
function findRole($em, string $name): ?object
{
    $r = $em->getRDBRepository('Role')
        ->where(['name' => $name, 'deleted' => false])
        ->findOne();
    return $r ?: null;
}

$fail = 0;

foreach ($TARGETS as $t) {
    echo "\n--- {$t['userName']} (role: {$t['roleName']}) ---\n";

    $role = findRole($em, $t['roleName']);
    if ($role === null) {
        echo "FAIL - Role '{$t['roleName']}' não existe (togare-rbac seedou?). Abortando este user.\n";
        $fail++;
        continue;
    }
    echo "OK   - Role '{$t['roleName']}' encontrado (id={$role->get('id')})\n";

    $user = $em->getRDBRepository('User')
        ->where(['userName' => $t['userName'], 'deleted' => false])
        ->findOne();

    if ($user === null) {
        $user = $em->getEntity('User');
        $user->set('userName', $t['userName']);
        echo "OK   - Criando usuário novo\n";
    } else {
        echo "OK   - Usuário já existe (id={$user->get('id')}) — resetando\n";
    }

    $user->set('name', $t['name']);
    $user->set('type', 'regular');
    $user->set('isActive', true);
    $user->set('password', $passwordHash->hash($t['password']));

    // Role DEVE estar no link-multiple ANTES do save: togare-rbac
    // UserRoleRequired (beforeSave order=5) bloqueia create sem role lendo
    // getLinkMultipleIdList('roles'). Set rolesIds/rolesNames na entity.
    $existingRoleIds = $user->getLinkMultipleIdList('roles');
    if (! in_array($role->get('id'), $existingRoleIds, true)) {
        $existingRoleIds[] = $role->get('id');
    }
    $user->set('rolesIds', $existingRoleIds);
    $namesMap = (object) [];
    foreach ($existingRoleIds as $rid) {
        $namesMap->{$rid} = $rid === $role->get('id')
            ? $role->get('name')
            : (string) ($em->getEntityById('Role', $rid)?->get('name') ?? $rid);
    }
    $user->set('rolesNames', $namesMap);

    $em->saveEntity($user);
    echo "OK   - Salvo com Role '{$t['roleName']}' vinculado (rolesIds setado pré-save)\n";

    // Payload real do briefing p/ este user.
    try {
        $svc = $injectableFactory->create(TogareBriefingService::class);
        $reloaded = $em->getEntityById('User', $user->get('id'));
        $payload = $svc->getSummaryForUser($reloaded);
        $roleKey = $payload['role'] ?? '(vazio)';
        echo "     → role resolvido: '{$roleKey}'\n";
        $badges = $payload['badges'] ?? [];
        if (! $badges) {
            echo "     → badges: [] (nenhum)\n";
        }
        $allZero = true;
        foreach ($badges as $b) {
            $c = $b['count'] ?? null;
            $extra = isset($b['healthStatus']) ? " healthStatus={$b['healthStatus']}"
                : (isset($b['licencaStatus']) ? " licencaStatus={$b['licencaStatus']}" : '');
            $cStr = $c === null ? 'null' : (string) $c;
            echo "     → badge {$b['key']}: count={$cStr}{$extra}\n";
            if ($c !== null && (int) $c !== 0) { $allZero = false; }
            if (isset($b['type']) && $b['type'] === 'health') { $allZero = false; }
            if (($b['key'] ?? '') === 'licenca') { $allZero = false; }
        }
        if ($roleKey === 'advogado') {
            echo $allZero
                ? "     ✅ Passo 5: vai mostrar EmptyStateCalmo (tudo zero)\n"
                : "     ⚠ Passo 5: NÃO vai mostrar EmptyState (há badge >0, ex. audiência da semana office-wide) — cards reais aparecem; comportamento EmptyState já coberto por vitest G2-P7\n";
        }
    } catch (\Throwable $e) {
        echo "FAIL - getSummaryForUser lançou: " . get_class($e) . ': ' . $e->getMessage() . "\n";
        $fail++;
    }
}

echo "\n=== " . ($fail === 0 ? 'SETUP OK' : "SETUP COM {$fail} FALHA(S)") . " ===\n";
echo "\nCredenciais (login: https://localhost):\n";
foreach ($TARGETS as $t) {
    echo "  • {$t['userName']}  /  {$t['password']}   (role {$t['roleName']})\n";
}
exit($fail === 0 ? 0 : 1);
