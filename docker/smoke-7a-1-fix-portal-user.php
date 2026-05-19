<?php
declare(strict_types=1);

/**
 * Story 7a.1 — diagnóstico + correção do usuário de portal do smoke.
 *
 * Sintoma (Felipe): "Usuário / senha incorretos" ao logar em
 * https://localhost/portal/<id>/ com cliente.smoke / SmokeTest2026!
 *
 * Causa provável: EspoCRM 9.x NÃO hasheia a senha quando ela é setada via
 * EntityManager direto (o hash vive na camada de service, não em hook de
 * entity). O setup-portal salvou a senha como texto puro → login falha.
 *
 * Fix: regrava a senha usando o PasswordHash oficial do EspoCRM e garante
 * type=portal + isActive + vínculo a Portal ativo + PortalRole.
 *
 * NOTA DE ESCOPO: autenticação completa do Portal é a Story 7a.2. Para a
 * 7a.1 (aparência) o que importa é o splash RENDERIZAR pré-auth; este fix
 * é só para o Felipe conseguir validar também o pós-login no smoke.
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
// getByClass(PasswordHash) morre silenciosamente neste bootstrap CLI
// (mesma classe de problema do SettingsService no smoke-cli). PasswordHash
// só precisa de Config no construtor → instanciar direto.
$config = $c->getByClass(Config::class);
$passwordHash = new PasswordHash($config);

$USER = 'cliente.smoke';
$PASS = 'SmokeTest2026!';

$u = $em->getRDBRepository('User')->where(['userName' => $USER])->findOne();
if (!$u) {
    echo "ERRO: usuário $USER não existe — rode docker/smoke-7a-1-setup-portal.php primeiro.\n";
    exit(1);
}

echo "ANTES: id={$u->get('id')} type={$u->get('type')} isActive="
    . var_export($u->get('isActive'), true)
    . " portals=" . json_encode($u->getLinkMultipleIdList('portals'))
    . " portalRoles=" . json_encode($u->getLinkMultipleIdList('portalRoles')) . "\n";

$pdo = $em->getPDO();
$st = $pdo->prepare('SELECT password FROM `user` WHERE user_name = ?');
$st->execute([$USER]);
$pw = (string) ($st->fetchColumn() ?: '');
$looksBcrypt = (bool) preg_match('/^\$2[aby]\$/', $pw);
echo "password atual: len=" . strlen($pw)
    . " bcrypt=" . ($looksBcrypt ? 'SIM' : 'NÃO (texto puro — esta é a causa)') . "\n";

// Garante portal ativo + role + vínculo.
$portal = $em->getRDBRepository('Portal')->where(['name' => 'Portal do Cliente (smoke 7a.1)'])->findOne();
$role = $em->getRDBRepository('PortalRole')->where(['name' => 'Smoke Portal Role'])->findOne();

if ($portal && !$portal->get('isActive')) {
    $portal->set('isActive', true);
    $em->saveEntity($portal);
    echo "Portal reativado.\n";
}

$u->set('type', 'portal');
$u->set('isActive', true);
if ($portal) {
    $u->set('portalsIds', [$portal->get('id')]);
}
if ($role) {
    $u->set('portalRolesIds', [$role->get('id')]);
}
$em->saveEntity($u);

// Regrava a senha COM o hash oficial do EspoCRM (fix da causa-raiz).
$hash = $passwordHash->hash($PASS);
$upd = $pdo->prepare('UPDATE `user` SET password = ? WHERE id = ?');
$upd->execute([$hash, $u->get('id')]);

// Verificação pós-fix.
$st2 = $pdo->prepare('SELECT password FROM `user` WHERE id = ?');
$st2->execute([$u->get('id')]);
$pw2 = (string) ($st2->fetchColumn() ?: '');
$ok = (bool) preg_match('/^\$2[aby]\$/', $pw2);

$u2 = $em->getRDBRepository('User')->where(['userName' => $USER])->findOne();
echo "DEPOIS: type={$u2->get('type')} isActive="
    . var_export($u2->get('isActive'), true)
    . " portals=" . json_encode($u2->getLinkMultipleIdList('portals'))
    . " portalRoles=" . json_encode($u2->getLinkMultipleIdList('portalRoles')) . "\n";
echo "password agora: bcrypt=" . ($ok ? 'SIM ✅' : 'NÃO ❌') . "\n";

$portalId = $portal ? $portal->get('id') : '(sem portal!)';
echo "\n== PRONTO — tente novamente no browser ==\n";
echo "  URL: https://localhost/portal/$portalId/\n";
echo "  usuário: $USER\n";
echo "  senha:   $PASS\n";

exit($ok ? 0 : 1);
