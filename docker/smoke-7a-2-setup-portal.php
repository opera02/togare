<?php
declare(strict_types=1);

/**
 * Story 7a.2 — Setup do smoke (parte Claude; muta o estado).
 *
 * Cria, de forma IDEMPOTENTE (reuso por name/numeroCnj):
 *  - 1 Portal ativo default;
 *  - Cliente A + Cliente B (cada um com e-mail);
 *  - Processo A (vinculado só ao Cliente A) + Processo B (só ao Cliente B)
 *    via a relação N:N `clientes` (ClienteProcesso);
 *  - acesso de Portal para A e B pelo CAMINHO DE PRODUÇÃO real
 *    (`ProvisionService::provisionForCliente`) — prova AC1 em runtime e
 *    gera o link nativo de criação de senha (sem senha em claro — NFR8).
 *
 * Imprime, para o smoke browser do Felipe: URL de login do Portal, os
 * usuários, links de criação de senha de A e B, e os IDs de Processo A/B (para o
 * teste de acesso cruzado por URL direta — AC4).
 *
 * Não imprime nem persiste nenhuma senha (não existe — mecanismo de link).
 */

chdir('/var/www/html');
require '/var/www/html/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Config;
use Espo\ORM\EntityManager;
use Espo\Modules\TogarePortalUi\Tools\PortalAccess\ProvisionService;

$app = new Application();
$app->setupSystemUser();
$c = $app->getContainer();
$em = $c->getByClass(EntityManager::class);
$config = $c->getByClass(Config::class);
$injectableFactory = $c->getByClass(InjectableFactory::class);

$PORTAL_NAME = 'Portal do Cliente (smoke 7a.2)';

// --- Portal ---
$portal = $em->getRDBRepository('Portal')->where(['name' => $PORTAL_NAME])->findOne();
if (!$portal) {
    $portal = $em->getNewEntity('Portal');
    $portal->set(['name' => $PORTAL_NAME, 'isActive' => true, 'isDefault' => true]);
    $em->saveEntity($portal);
    echo "Portal criado: {$portal->get('id')}\n";
} else {
    if (!$portal->get('isActive')) { $portal->set('isActive', true); $em->saveEntity($portal); }
    echo "Portal reusado: {$portal->get('id')}\n";
}
$portalId = $portal->get('id');
$customId = $portal->get('customId');

/**
 * Garante o e-mail primário do Cliente (field tipo `email`, relação
 * email_address). O processador de e-mail do EspoCRM roda na camada de
 * Record service, NÃO em EntityManager::saveEntity direto (mesma classe
 * do gap password do smoke 7a.1). Aqui escrevemos as linhas das tabelas
 * nativas (email_address + entity_email_address) de forma idempotente —
 * é o que `get('email')` lê. Pattern smoke-dividido (fixture, não produto).
 */
function ensureClienteEmail(EntityManager $em, object $cli, string $email): void
{
    $pdo = $em->getPDO();
    $cid = (string) $cli->get('id');
    $lower = mb_strtolower($email);

    $has = $pdo->prepare(
        "SELECT 1 FROM entity_email_address eea
         JOIN email_address ea ON ea.id = eea.email_address_id AND ea.deleted = 0
         WHERE eea.entity_id = ? AND eea.entity_type = 'Cliente'
           AND eea.deleted = 0 AND ea.lower = ? LIMIT 1"
    );
    $has->execute([$cid, $lower]);
    if ($has->fetchColumn()) {
        return;
    }

    $eaId = $pdo->query("SELECT id FROM email_address WHERE lower = " . $pdo->quote($lower) . " AND deleted = 0 LIMIT 1")->fetchColumn();
    if (!$eaId) {
        $eaId = substr(bin2hex(random_bytes(9)), 0, 17);
        $ins = $pdo->prepare("INSERT INTO email_address (id, name, lower, deleted, invalid, opt_out) VALUES (?, ?, ?, 0, 0, 0)");
        $ins->execute([$eaId, $email, $lower]);
    }

    $pdo->prepare(
        "INSERT INTO entity_email_address (entity_id, email_address_id, entity_type, `primary`, deleted)
         VALUES (?, ?, 'Cliente', 1, 0)"
    )->execute([$cid, $eaId]);
}

/** Gera um CPF com dígitos verificadores válidos a partir de 9 dígitos base. */
function makeValidCpf(string $base9): string
{
    $d = array_map('intval', str_split(substr($base9 . '000000000', 0, 9)));
    $calc = function (array $nums): int {
        $w = count($nums) + 1;
        $s = 0;
        foreach ($nums as $n) { $s += $n * $w; $w--; }
        $r = $s % 11;
        return $r < 2 ? 0 : 11 - $r;
    };
    $dv1 = $calc($d);
    $dv2 = $calc(array_merge($d, [$dv1]));
    return implode('', $d) . $dv1 . $dv2;
}

/** Cria/reusa um Cliente PF por name (CPF único gerado; retry em colisão). */
function ensureCliente(EntityManager $em, string $name, string $email, string $seedBase): object
{
    $cli = $em->getRDBRepository('Cliente')->where(['name' => $name])->findOne();
    if (!$cli) {
        $lastErr = null;
        for ($i = 0; $i < 12; $i++) {
            $cpf = makeValidCpf(str_pad((string) ((int) $seedBase + $i * 7919), 9, '0', STR_PAD_LEFT));
            if ($em->getRDBRepository('Cliente')->where(['cpf' => $cpf])->findOne()) {
                continue; // CPF já usado por outro smoke — tenta o próximo
            }
            try {
                $cli = $em->getNewEntity('Cliente');
                $cli->set(['name' => $name, 'tipoPessoa' => 'pf', 'cpf' => $cpf]);
                $em->saveEntity($cli);
                echo "Cliente criado: {$cli->get('id')} ($name) cpf=$cpf\n";
                break;
            } catch (\PDOException $e) {
                $lastErr = $e; // corrida de unicidade — regenera
                $cli = null;
            }
        }
        if (!$cli) {
            throw $lastErr ?? new \RuntimeException("Não foi possível alocar CPF único para $name");
        }
    } else {
        echo "Cliente reusado: {$cli->get('id')} ($name)\n";
    }

    ensureClienteEmail($em, $cli, $email);
    $reloaded = $em->getEntityById('Cliente', (string) $cli->get('id'));
    echo "  email primário: " . var_export($reloaded->get('email'), true) . "\n";

    return $reloaded;
}

/**
 * Gera um numeroCnj VÁLIDO (DV mod-97, Res. CNJ 65) no formato
 * NNNNNNN-DD.AAAA.J.TR.OOOO. mod97 sobre string (o número tem 18+ dígitos,
 * estoura int de 64 bits).
 */
function makeValidCnj(int $seq, int $ano = 2026, string $j = '8', string $tr = '26', string $orig = '0100'): string
{
    $n = str_pad((string) $seq, 7, '0', STR_PAD_LEFT);
    $base = $n . $ano . $j . $tr . $orig; // sem o DV
    $acc = 0;
    foreach (str_split($base) as $d) { $acc = ($acc * 10 + (int) $d) % 97; }
    $acc = ($acc * 100) % 97;
    $dv = str_pad((string) (98 - $acc), 2, '0', STR_PAD_LEFT);
    return "$n-$dv.$ano.$j.$tr.$orig";
}

/** Cria/reusa um Processo por numeroCnj e garante o vínculo com $cliente. */
function ensureProcesso(EntityManager $em, string $cnj, string $nome, object $cliente): object
{
    // togare-core normaliza numeroCnj p/ dígitos — buscar pelos dígitos.
    $digits = preg_replace('/\D/', '', $cnj);
    $proc = $em->getRDBRepository('Processo')->where(['numeroCnj' => $digits])->findOne()
        ?? $em->getRDBRepository('Processo')->where(['numeroCnj' => $cnj])->findOne();
    if (!$proc) {
        $proc = $em->getNewEntity('Processo');
        $proc->set([
            'name' => $nome,
            'numeroCnj' => $cnj,
            'classeCodigo' => 436,
            'assuntoCodigo' => 5951,
            'area' => 'civel',
            'instancia' => 'primeira',
            'fase' => 'conhecimento',
            'status' => 'ativo',
            'polo' => 'ativo',
        ]);
        $em->saveEntity($proc);
        echo "Processo criado: {$proc->get('id')} ($cnj)\n";
    } else {
        echo "Processo reusado: {$proc->get('id')} ($cnj)\n";
    }
    // Vínculo N:N idempotente (relate é no-op se já relacionado).
    $em->getRDBRepository('Processo')->getRelation($proc, 'clientes')->relate($cliente);
    return $proc;
}

// CPFs de teste com dígitos verificadores válidos (distintos).
try {
    $cliA = ensureCliente($em, 'Cliente A (smoke 7a.2)', 'clienteA.smoke@example.test', '700100200');
    $cliB = ensureCliente($em, 'Cliente B (smoke 7a.2)', 'clienteB.smoke@example.test', '700300400');

    $procA = ensureProcesso($em, makeValidCnj(9700071), 'Processo do Cliente A (smoke 7a.2)', $cliA);
    $procB = ensureProcesso($em, makeValidCnj(9700072), 'Processo do Cliente B (smoke 7a.2)', $cliB);
} catch (\Throwable $e) {
    echo 'ERRO setup Cliente/Processo: ' . get_class($e) . ': ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
    exit(1);
}

// --- Provisionamento pelo caminho de PRODUÇÃO (AC1) ---
echo "\n== Provisionamento (ProvisionService — caminho de produção) ==\n";
$provision = null;
try {
    $provision = $injectableFactory->create(ProvisionService::class);
} catch (\Throwable $e) {
    echo "  ✗ InjectableFactory->create(ProvisionService) falhou: {$e->getMessage()}\n";
}

$links = [];
if ($provision instanceof ProvisionService) {
    foreach ([['A', $cliA], ['B', $cliB]] as [$tag, $cli]) {
        try {
            $r = $provision->provisionForCliente((string) $cli->get('id'));
            $links[$tag] = [
                'userId' => $r['userId'],
                'userName' => $r['userName'],
                'link' => $r['link'],
                'emailSent' => $r['emailSent'],
                'reused' => $r['reused'],
            ];
            echo "  ✓ Cliente $tag provisionado — userId={$r['userId']} userName={$r['userName']} reused=" . ($r['reused'] ? 'sim' : 'não') . " emailSent=" . ($r['emailSent'] ? 'sim' : 'não') . "\n";
        } catch (\Throwable $e) {
            echo "  ✗ provisionForCliente($tag) falhou: {$e->getMessage()}\n";
        }
    }
}

$siteUrl = rtrim((string) $config->get('siteUrl'), '/');
// URL de LOGIN do Portal (SPA) — independente do link de senha.
// IMPORTANTE: o ProvisionService vincula o user ao 1º Portal ativo (por
// createdAt), que pode NÃO ser o Portal criado aqui. Para o Passo 2 do
// Felipe funcionar, derivamos a URL do Portal REAL ao qual o user A foi
// vinculado (user->portals + Portal repo loadUrlField → getUrl()).
$portalUrl = $customId ? "$siteUrl/portal/$customId/" : "$siteUrl/portal/$portalId/";
try {
    $uA = $em->getRDBRepository('User')
        ->where(['togareClienteId' => $cliA->get('id'), 'type' => 'portal'])
        ->findOne();
    if ($uA) {
        $pA = $em->getRDBRepository('User')->getRelation($uA, 'portals')->findOne();
        if ($pA) {
            $em->getRDBRepositoryByClass(\Espo\Entities\Portal::class)->loadUrlField($pA);
            $pUrl = (string) $pA->get('url');
            if ($pUrl !== '') {
                $portalUrl = rtrim($pUrl, '/') . '/';
            }
        }
    }
} catch (\Throwable $e) {
    // mantém o fallback acima
}

// Guarda de regressão (bug smoke browser Felipe 2026-05-17): o link do
// entry point `changePassword` DEVE ser a RAIZ DO SITE
// (`{siteUrl}/?entryPoint=changePassword&id=…`) — NUNCA sob `/portal/<id>`
// (Caddy reverse-proxy → loop/HTTP 414 ou 404+assets em /portal/client/).
$expectedPrefix = $siteUrl . '/?entryPoint=changePassword&id=';
foreach ($links as $tag => $d) {
    $lk = (string) $d['link'];
    if (!str_starts_with($lk, $expectedPrefix)) {
        echo "  ✗ REGRESSÃO: link do Cliente $tag não é a raiz do site. Esperado começar com `$expectedPrefix`. Link: $lk\n";
        exit(1);
    }
    if (preg_match('~/portal/[^?]*\?entryPoint=changePassword~', $lk)) {
        echo "  ✗ REGRESSÃO: link do Cliente $tag aponta para `/portal/<id>` (loop/414/404). Link: $lk\n";
        exit(1);
    }
}
echo "  ✓ formato do link de criação de senha OK (raiz do site, fora do /portal/).\n";

echo "\n== DADOS PARA O SMOKE BROWSER DO FELIPE ==\n";
echo "  URL de login do Portal: $portalUrl\n";
foreach ($links as $tag => $d) {
    echo "  Cliente $tag — usuário/login do Portal: {$d['userName']}\n";
    echo "  Cliente $tag — link de criação de senha (uso único): {$d['link']}\n";
}
echo "  Processo do Cliente A (id): {$procA->get('id')}\n";
echo "  Processo do Cliente B (id): {$procB->get('id')}\n";
echo "  Teste AC4: logado como Cliente A, abrir #Processo/view/{$procB->get('id')} → DEVE bloquear (403/acesso negado), nunca mostrar dados de B.\n";
echo "  (Mecanismo de link nativo: nenhuma senha trafega/persiste — NFR8.)\n";
