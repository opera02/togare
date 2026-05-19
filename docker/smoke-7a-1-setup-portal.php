<?php
declare(strict_types=1);

/**
 * Story 7a.1 — Setup do Portal para o smoke browser do Felipe (parte Claude).
 *
 * 7a.1 é APARÊNCIA: para o Felipe VER o PortalSplash branded é preciso um
 * Portal nativo EspoCRM existente + 1 usuário de portal. Autenticação real
 * (senha temporária, troca obrigatória, ACL cross-cliente) é Story 7a.2 —
 * aqui o usuário só precisa existir para alcançar a tela de login do Portal.
 *
 * Idempotente: reusa Portal/usuário se já existirem (match por name/userName).
 */

chdir('/var/www/html');
require '/var/www/html/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\PasswordHash;
use Espo\ORM\EntityManager;

$app = new Application();
$app->setupSystemUser();
$c = $app->getContainer();
$em = $c->getByClass(EntityManager::class);
$config = $c->getByClass(Config::class);
// EspoCRM 9.x NÃO hasheia password ao salvar User via EntityManager direto
// (o hash vive na camada de service, não em hook de entity). Hashear aqui,
// senão o login do Portal falha com "Usuário / senha incorretos".
$passwordHash = new PasswordHash($config);

$PORTAL_NAME = 'Portal do Cliente (smoke 7a.1)';
$PORTAL_USER = 'cliente.smoke';
$PORTAL_PASS = 'SmokeTest2026!';

// --- Portal ---
$portal = $em->getRDBRepository('Portal')->where(['name' => $PORTAL_NAME])->findOne();
if (!$portal) {
    $portal = $em->getNewEntity('Portal');
    $portal->set([
        'name' => $PORTAL_NAME,
        'isActive' => true,
        'isDefault' => true,
    ]);
    $em->saveEntity($portal);
    echo "Portal criado: {$portal->get('id')}\n";
} else {
    if (!$portal->get('isActive')) {
        $portal->set('isActive', true);
        $em->saveEntity($portal);
    }
    echo "Portal já existia: {$portal->get('id')} (reusado)\n";
}

$portalId = $portal->get('id');
$customId = $portal->get('customId');

// --- Portal Role (acesso mínimo; ACL real é 7a.2) ---
$role = $em->getRDBRepository('PortalRole')->where(['name' => 'Smoke Portal Role'])->findOne();
if (!$role) {
    $role = $em->getNewEntity('PortalRole');
    $role->set(['name' => 'Smoke Portal Role']);
    $em->saveEntity($role);
    echo "PortalRole criada: {$role->get('id')}\n";
} else {
    echo "PortalRole já existia: {$role->get('id')} (reusado)\n";
}

// --- Usuário de portal ---
$user = $em->getRDBRepository('User')->where(['userName' => $PORTAL_USER])->findOne();
if (!$user) {
    $user = $em->getNewEntity('User');
    $user->set([
        'userName' => $PORTAL_USER,
        'firstName' => 'Cliente',
        'lastName' => 'Smoke',
        'type' => 'portal',
        'isActive' => true,
        'password' => $passwordHash->hash($PORTAL_PASS),
    ]);
    $user->set('portalsIds', [$portalId]);
    $user->set('portalRolesIds', [$role->get('id')]);
    $em->saveEntity($user, ['skipHooks' => false]);
    echo "Usuário de portal criado: {$user->get('id')} ({$PORTAL_USER})\n";
} else {
    $user->set('isActive', true);
    $user->set('type', 'portal');
    $user->set('password', $passwordHash->hash($PORTAL_PASS));
    $user->set('portalsIds', [$portalId]);
    if ($role) {
        $user->set('portalRolesIds', [$role->get('id')]);
    }
    $em->saveEntity($user);
    echo "Usuário de portal já existia: {$user->get('id')} (reusado + senha re-hasheada, {$PORTAL_USER})\n";
}

$siteUrl = rtrim((string) $config->get('siteUrl'), '/');
$portalUrl = $customId
    ? "$siteUrl/portal/$customId/"
    : "$siteUrl/portal/$portalId/";

echo "\n== URL DE LOGIN DO PORTAL (para o smoke browser do Felipe) ==\n";
echo "  $portalUrl\n";
echo "  usuário: $PORTAL_USER  | senha: $PORTAL_PASS\n";
echo "  (autenticação completa = Story 7a.2; aqui só precisa alcançar a tela)\n";
