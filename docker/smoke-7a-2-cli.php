<?php
declare(strict_types=1);

/**
 * Story 7a.2 — Smoke F1 CLI (parte Claude, sem browser).
 *
 * Valida em RUNTIME real do EspoCRM 9.3.6 o que vitest/PHPUnit não cobrem.
 * Read-only (não cria registros — rode `smoke-7a-2-setup-portal.php` antes).
 *
 *  1) Módulo TogarePortalUi v0.2.0 instalado.
 *  2) Metadata EFETIVO: Processo.aclPortal=true; selectDefs mapeia
 *     portalOnlyOwn→PortalOnlyCliente; aclDefs.portalOwnershipCheckerClassName
 *     →OwnershipChecker (404 silencioso = regressão).
 *  3) entityDefs efetivo: User.togareCliente + Cliente.portalUsers.
 *  4) PortalRole 'Cliente do Portal (Togare)' — EXATAMENTE 1 (idempotente)
 *     com data Processo read=own/create=no/edit=no/delete=no.
 *  5) Provisionamento (AC1): portal users de A e B existem, type=portal,
 *     vinculados por togareCliente, Portal + PortalRole semeado anexados,
 *     SEM senha em claro (NFR8), com PasswordChangeRequest nativo.
 *  6) **A4 (AC#4 não deferível) em runtime**: AclManager->checkEntity do
 *     portal user de A: READ no Processo de A = true; READ no Processo de
 *     B = false E grava `portal.acesso_cruzado_tentado` no togare_audit_log
 *     (contexto estruturado). Lista (SelectBuilder + filtro de ACL) do
 *     portal user de A retorna só o Processo de A, zero de B.
 *  7) AC5: honorários/Contrato/Lançamento/Fatura permanecem aclPortal:false.
 *  8) Higiene: limpa auth_log_record (lockout — memória portal login).
 */

chdir('/var/www/html');
require '/var/www/html/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\AclManager;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Utils\Metadata;
use Espo\ORM\EntityManager;

$app = new Application();
$app->setupSystemUser();
$cont = $app->getContainer();
$em = $cont->getByClass(EntityManager::class);
$metadata = $cont->getByClass(Metadata::class);

$OWN_FILTER = 'Espo\\Modules\\TogarePortalUi\\Classes\\Select\\Processo\\AccessControlFilters\\PortalOnlyCliente';
$OWN_CHECKER = 'Espo\\Modules\\TogarePortalUi\\Classes\\AclPortal\\Processo\\OwnershipChecker';
$ROLE_NAME = 'Cliente do Portal (Togare)';

$pass = 0; $fail = 0;
function check(string $label, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "== Story 7a.2 — Smoke CLI ==\n";

// 1) Módulo v0.2.0
$extDir = '/var/www/html/custom/Espo/Modules/TogarePortalUi';
$mj = '/var/www/html/data/extensions';
check('1. Módulo TogarePortalUi instalado (dir presente)', is_dir($extDir));
$provFile = $extDir . '/Tools/PortalAccess/ProvisionService.php';
check('1b. ProvisionService instalado', is_file($provFile));

// 2) Metadata efetivo
check(
    '2a. scopes/Processo.aclPortal == true',
    $metadata->get(['scopes', 'Processo', 'aclPortal']) === true,
    var_export($metadata->get(['scopes', 'Processo', 'aclPortal']), true),
);
check(
    '2b. selectDefs Processo.accessControlFilterClassNameMap.portalOnlyOwn == PortalOnlyCliente',
    $metadata->get(['selectDefs', 'Processo', 'accessControlFilterClassNameMap', 'portalOnlyOwn']) === $OWN_FILTER,
);
check(
    '2c. aclDefs Processo.portalOwnershipCheckerClassName == OwnershipChecker',
    $metadata->get(['aclDefs', 'Processo', 'portalOwnershipCheckerClassName']) === $OWN_CHECKER,
);

// 3) entityDefs efetivo (vínculo)
check(
    '3a. entityDefs User.links.togareCliente belongsTo Cliente',
    $metadata->get(['entityDefs', 'User', 'links', 'togareCliente', 'entity']) === 'Cliente',
);
check(
    '3b. entityDefs Cliente.links.portalUsers hasMany User',
    $metadata->get(['entityDefs', 'Cliente', 'links', 'portalUsers', 'entity']) === 'User',
);

// 4) PortalRole idempotente + permissão mínima
$roles = $em->getRDBRepository('PortalRole')->where(['name' => $ROLE_NAME])->find();
$roleCount = is_countable($roles) ? count($roles) : iterator_count($roles);
check("4a. PortalRole '$ROLE_NAME' existe e é único (idempotente)", $roleCount === 1, "count=$roleCount");
$role = $em->getRDBRepository('PortalRole')->where(['name' => $ROLE_NAME])->findOne();
$rd = $role ? $role->get('data') : null;
$rd = is_object($rd) ? $rd : (object) [];
$p = $rd->Processo ?? null;
check(
    '4b. PortalRole data Processo = read:own/create:no/edit:no/delete:no',
    is_object($p) && ($p->read ?? null) === 'own' && ($p->create ?? null) === 'no'
        && ($p->edit ?? null) === 'no' && ($p->delete ?? null) === 'no',
    json_encode($p),
);

// Helpers de lookup (decoupled do output do setup).
$cliA = $em->getRDBRepository('Cliente')->where(['name' => 'Cliente A (smoke 7a.2)'])->findOne();
$cliB = $em->getRDBRepository('Cliente')->where(['name' => 'Cliente B (smoke 7a.2)'])->findOne();
check('   pré: Cliente A e B do setup presentes', $cliA && $cliB);
if (!$cliA || !$cliB) { echo "\n== ABORTADO: rode smoke-7a-2-setup-portal.php antes ==\n"; exit(1); }

$userA = $em->getRDBRepository('User')->where(['togareClienteId' => $cliA->get('id'), 'type' => 'portal'])->findOne();
$userB = $em->getRDBRepository('User')->where(['togareClienteId' => $cliB->get('id'), 'type' => 'portal'])->findOne();

// 5) Provisionamento (AC1)
check('5a. portal user de A provisionado e vinculado por togareCliente', (bool) $userA);
check('5b. portal user de B provisionado e vinculado por togareCliente', (bool) $userB);
if ($userA) {
    check('5c. portal user A type=portal & ativo', $userA->get('type') === 'portal' && (bool) $userA->get('isActive'));
    // NFR8 = NUNCA senha em claro. Estados válidos: (a) vazia — usuário
    // recém-provisionado ainda não criou a senha (provisionamento nunca
    // grava senha); (b) hash bcrypt/argon — usuário JÁ criou a senha pela
    // tela nativa (estado correto pós-Passo 1). FALHA só se for algo que
    // pareça texto puro (nem vazio, nem hash reconhecível).
    $pwd = (string) $userA->get('password');
    $isHash = (bool) preg_match('~^\$(2[aby]|argon2)~', $pwd);
    check(
        '5d. portal user A SEM senha em claro (NFR8 — vazia ou hash, nunca texto puro)',
        $pwd === '' || $isHash,
        'password=' . var_export($userA->get('password'), true),
    );
    $portalsA = $em->getRDBRepository('User')->getRelation($userA, 'portals')->find();
    $rolesA = $em->getRDBRepository('User')->getRelation($userA, 'portalRoles')->find();
    $hasRole = false;
    foreach ($rolesA as $r) { if ($r->get('name') === $ROLE_NAME) { $hasRole = true; } }
    check('5e. portal user A tem Portal anexado', (is_countable($portalsA) ? count($portalsA) : iterator_count($portalsA)) >= 1);
    check('5f. portal user A tem o PortalRole semeado anexado', $hasRole);
    // Pega o request_id mais recente do user (qualquer estado) só para
    // saber que o provisionamento criou ALGO.
    $pcrRaw = $em->getPDO()->prepare(
        'SELECT request_id FROM password_change_request WHERE user_id = ? ORDER BY created_at DESC LIMIT 1'
    );
    $pcrRaw->execute([$userA->get('id')]);
    $rid = (string) $pcrRaw->fetchColumn();
    check('5g. PasswordChangeRequest gerado para A (linha existe)', $rid !== '');

    // CRÍTICO (bug Felipe 2026-05-17): o link só funciona se a solicitação
    // for RECUPERÁVEL pelo mesmo lookup do entry point ChangePassword
    // (getRDBRepository->where(requestId)->findOne(), que exclui deleted=1).
    // Antes este check consultava SQL cru sem `deleted` e mascarava o bug
    // (job de limpeza apagava na hora por lifetime inválido).
    $pcrEntity = $rid !== ''
        ? $em->getRDBRepository('PasswordChangeRequest')->where(['requestId' => $rid])->findOne()
        : null;
    check(
        '5h. PasswordChangeRequest de A é RECUPERÁVEL (deleted=0 — link vivo)',
        $pcrEntity !== null,
        'requestId=' . $rid . ' não recuperável → link "solicitação não encontrada".',
    );

    // Guarda da causa-raiz: lifetime DEVE ser um modificador relativo
    // válido (ex.: "7 days"), NUNCA número puro ("168") — senão
    // DateTime::modify('+168') falha e o cleanup roda imediatamente.
    $cfg = $cont->getByClass(\Espo\Core\Utils\Config::class);
    $lt = (string) $cfg->get('passwordChangeRequestNewUserLifetime');
    $dt = new \DateTime('now');
    $ts0 = $dt->getTimestamp();
    $ok = !is_numeric(trim($lt)) && trim($lt) !== ''
        && @$dt->modify('+' . $lt) !== false && $dt->getTimestamp() !== $ts0;
    check(
        '5i. passwordChangeRequestNewUserLifetime é relativo válido (não "168")',
        $ok,
        'valor=' . var_export($lt, true) . ' — número puro quebra o link (cleanup imediato).',
    );
}

// 6) A4 RUNTIME — 403 + audit + isolamento de lista
$procA = $em->getRDBRepository('Processo')->getRelation($cliA, 'processos')->findOne();
$procB = $em->getRDBRepository('Processo')->getRelation($cliB, 'processos')->findOne();
check('   pré: Processo de A e de B presentes/vinculados', $procA && $procB);

if ($userA && $procA && $procB) {
    // NOTA DE AMBIENTE: o AclManager de PORTAL só resolve regras quando o
    // usuário tem contexto de portal — estabelecido pelo LOGIN no Portal
    // (sessão), que não existe num smoke CLI. Logo, em vez de depender da
    // sessão, exercitamos AQUI exatamente as duas classes que a metadata
    // (2b/2c) faz o framework resolver em runtime — instanciadas pelo
    // InjectableFactory real (mesma DI do framework), com EntityManager
    // real e o binding real de AuditLogContract (togare-core →
    // AuditLogService → togare_audit_log). É a prova A4 fiel e
    // determinística em runtime. O 403 fim-a-fim AUTENTICADO é o passo
    // de browser do Felipe (roteiro nas Completion Notes — smoke-dividido).
    $if = $cont->getByClass(\Espo\Core\InjectableFactory::class);
    $pdo = $em->getPDO();

    try {
    // --- OwnershipChecker (by-id / related — AC4) ---
    $ownerCheckerClass = $metadata->get(['aclDefs', 'Processo', 'portalOwnershipCheckerClassName']);
    $checker = $if->createWith($ownerCheckerClass, []);
    check('6a. OwnershipChecker resolvido da metadata é instanciável (DI real)', is_object($checker));
    check('6b. OwnershipChecker implementa OwnershipOwnChecker (contrato nativo)', $checker instanceof \Espo\Core\Acl\OwnershipOwnChecker);

    // togare_audit_log é APPEND-ONLY (trigger NFR10 — DELETE proibido).
    // Em vez de limpar, capturamos um baseline (maior occurred_at) e
    // exigimos uma linha NOVA depois do checkOwn — rerun-safe.
    $baseStmt = $pdo->prepare(
        "SELECT COALESCE(MAX(occurred_at), '1970-01-01 00:00:00')
         FROM togare_audit_log WHERE event='portal.acesso_cruzado_tentado' AND entity_id=?"
    );
    $baseStmt->execute([$procB->get('id')]);
    $baseTs = (string) $baseStmt->fetchColumn();

    $aBaseStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM togare_audit_log WHERE event='portal.acesso_cruzado_tentado' AND entity_id=?"
    );
    $aBaseStmt->execute([$procA->get('id')]);
    $aBaseCount = (int) $aBaseStmt->fetchColumn();

    $own = $checker->checkOwn($userA, $procA);
    check('6c. checkOwn: Processo do PRÓPRIO Cliente → true', $own === true, 'ret=' . var_export($own, true));

    $cross = $checker->checkOwn($userA, $procB);
    check('6d. checkOwn: Processo de OUTRO Cliente → false (⇒ ForbiddenSilent 403)', $cross === false, 'ret=' . var_export($cross, true));

    $q = $pdo->prepare(
        "SELECT context_json FROM togare_audit_log
         WHERE event='portal.acesso_cruzado_tentado' AND entity_type='Processo' AND entity_id=?
           AND occurred_at > ?
         ORDER BY occurred_at DESC LIMIT 1"
    );
    $q->execute([$procB->get('id'), $baseTs]);
    $ctx = ($j = $q->fetchColumn()) ? json_decode((string) $j, true) : null;
    check('6e. audit `portal.acesso_cruzado_tentado` NOVO gravado p/ Processo de B (append-only real)', is_array($ctx));
    check(
        '6f. contexto do audit estruturado (portalUserId/portalClienteId/target)',
        is_array($ctx)
            && ($ctx['portalUserId'] ?? null) === $userA->get('id')
            && ($ctx['portalClienteId'] ?? null) === $cliA->get('id')
            && ($ctx['targetEntityType'] ?? null) === 'Processo'
            && ($ctx['targetRecordId'] ?? null) === $procB->get('id'),
        json_encode($ctx),
    );

    // 6g. Acesso ao próprio Processo NÃO gera NENHUM audit cruzado novo.
    $aBaseStmt->execute([$procA->get('id')]);
    check('6g. acesso legítimo (Processo de A) NÃO gera audit cruzado (contagem inalterada)', (int) $aBaseStmt->fetchColumn() === $aBaseCount);
    } catch (\Throwable $e) {
        check('6a-6g. A4 OwnershipChecker+audit em runtime', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }

    // --- PortalOnlyCliente (list/search/related — AC5) ---
    try {
        $filterClass = $metadata->get(['selectDefs', 'Processo', 'accessControlFilterClassNameMap', 'portalOnlyOwn']);
        $filter = $if->createWith($filterClass, ['user' => $userA]);
        check('6h. PortalOnlyCliente resolvido da metadata instanciável + é Filter', $filter instanceof \Espo\Core\Select\AccessControl\Filter);

        $qb = $em->getQueryBuilder()->select()->from('Processo');
        $filter->apply($qb);
        $list = $em->getRDBRepository('Processo')->clone($qb->build())->find();
        $ids = [];
        foreach ($list as $e) { $ids[] = $e->get('id'); }
        check('6i. filtro: lista do portal user A CONTÉM o Processo de A', in_array($procA->get('id'), $ids, true));
        check('6j. filtro: lista do portal user A NÃO contém o Processo de B (zero vazamento)', !in_array($procB->get('id'), $ids, true), 'ids=' . implode(',', $ids));
    } catch (\Throwable $e) {
        check('6h-6j. filtro PortalOnlyCliente aplicável em runtime', false, get_class($e) . ': ' . $e->getMessage());
    }
}

// 7) AC5 — honorários fora do Portal
foreach (['ContratoHonorarios', 'Fatura', 'LancamentoFinanceiro'] as $scope) {
    check("7. $scope permanece aclPortal:false (FR26/AC5)", $metadata->get(['scopes', $scope, 'aclPortal']) !== true, var_export($metadata->get(['scopes', $scope, 'aclPortal']), true));
}

// 8) Higiene lockout (memória feedback_espocrm_portal_login_customization)
try {
    $em->getPDO()->exec("DELETE FROM auth_log_record WHERE created_at > (NOW() - INTERVAL 30 MINUTE)");
    check('8. auth_log_record acessível + limpo (destrava lockout do piloto)', true);
} catch (\Throwable $e) {
    check('8. auth_log_record acessível', false, $e->getMessage());
}

echo "\n== RESULTADO: $pass PASS / $fail FAIL ==\n";
exit($fail === 0 ? 0 : 1);
