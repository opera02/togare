<?php

declare(strict_types=1);

/**
 * load-seeds.php — Orquestrador idempotente de seeds determinísticos para dev local.
 *
 * Uso (dentro do container EspoCRM):
 *   docker compose exec espocrm php /var/www/html/ops/seeds/load-seeds.php
 *
 * Cada arquivo *.seed.json em ops/seeds/ é uma seção. Seções com
 * "_implemented": false são placeholders (pendente de story futura) e
 * apenas logam skip.
 *
 * Story: 1a.10 (apenas seção users implementada). Story 3.1 implementa
 * clientes, 3.4 processos, 3.3 tpu, Epic 8 retention.
 *
 * Pré-requisito: togare-rbac instalado — os 8 roles devem existir na tabela
 * `role` antes deste script rodar (AC6 falha cedo se ausente).
 */

// EspoCRM bootstrap.php exige cwd = root da instalação.
chdir('/var/www/html');
require_once 'bootstrap.php';

$start = microtime(true);

$app = new \Espo\Core\Application();
$container = $app->getContainer();

// Setup system user no service `user` do container — hooks/listeners de save
// (ex.: createdBy/modifiedBy stamping, audit log) acessam $container->get('user')
// e em CLI esse service não está populado por default. Pattern do runner
// oficial Command::run do EspoCRM core. Sem isso: "Could not load 'user' service."
$container->getByClass(\Espo\Core\ApplicationUser::class)->setupSystemUser();

$entityManager = $container->getByClass(\Espo\ORM\EntityManager::class);
$pdo = $entityManager->getPDO();

$seedsDir = __DIR__;
$totals = ['seeded' => 0, 'skipped' => 0, 'errors' => 0];
$exitCode = 0;

// Ordem fixa: users PRIMEIRO (outras seções podem referenciar userName).
$sections = [
    ['file' => 'users.seed.json',              'handler' => 'seedUsers',       'essential' => true],
    ['file' => 'clientes.seed.json',           'handler' => 'seedPlaceholder', 'essential' => false, 'label' => 'clientes'],
    ['file' => 'processos.seed.json',          'handler' => 'seedPlaceholder', 'essential' => false, 'label' => 'processos'],
    ['file' => 'tpu-sample.seed.json',         'handler' => 'seedPlaceholder', 'essential' => false, 'label' => 'tpu'],
    ['file' => 'retention-policies.seed.json', 'handler' => 'seedPlaceholder', 'essential' => false, 'label' => 'retention'],
];

foreach ($sections as $section) {
    $filepath = $seedsDir . '/' . $section['file'];
    if (! is_file($filepath)) {
        echo "[seeds] WARN: arquivo {$section['file']} não encontrado — pulando.\n";
        continue;
    }
    $raw = file_get_contents($filepath);
    if ($raw === false) {
        echo "[seeds] ERROR: não foi possível ler {$section['file']}.\n";
        $totals['errors']++;
        if ($section['essential']) {
            $exitCode = 1;
        }
        continue;
    }
    try {
        $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        echo "[seeds] ERROR: JSON inválido em {$section['file']}: {$e->getMessage()}\n";
        $totals['errors']++;
        if ($section['essential']) {
            $exitCode = 1;
        }
        continue;
    }

    try {
        $result = $section['handler']($entityManager, $pdo, $json, $section['label'] ?? null);
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        // Erros estruturados dos handlers já carregam prefixo [seeds] — imprime direto.
        if (\str_starts_with($msg, '[seeds]')) {
            echo $msg . "\n";
        } else {
            echo "[seeds] ERROR seção {$section['file']}: {$msg}\n";
        }
        $totals['errors']++;
        if ($section['essential']) {
            $exitCode = 1;
            break; // Falha essencial aborta resto das seções.
        }
        continue;
    }

    $totals['seeded']  += $result['seeded'];
    $totals['skipped'] += $result['skipped'];
    $totals['errors']  += $result['errors'] ?? 0;
}

$elapsed = round(microtime(true) - $start, 2);
echo "[seeds] TOTAL: {$totals['seeded']} entidades novas em {$elapsed} sec.\n";

exit($exitCode);

// ============================================================
// Handlers
// ============================================================

/**
 * @param \Espo\ORM\EntityManager $em
 * @param \PDO $pdo
 * @param array<string, mixed> $json
 * @return array{seeded:int, skipped:int, errors:int, durationSec:float}
 */
function seedUsers(\Espo\ORM\EntityManager $em, \PDO $pdo, array $json, ?string $label = null): array
{
    $sectionStart = microtime(true);
    $users = $json['users'] ?? [];
    $seeded = 0;
    $skipped = 0;
    $errors = 0;

    foreach ($users as $userSpec) {
        $userName = (string) ($userSpec['userName'] ?? '');
        if ($userName === '') {
            $errors++;
            echo "[seeds] WARN user sem userName — pulando.\n";
            continue;
        }

        // Idempotência: se o user_name já existe (deleted=0), pula o user inteiro.
        // Como pulamos antes de tocar em user_role, nunca tentamos INSERT
        // duplicado na chave composta (user_id, role_id) — ver Dev Notes Armadilha 6.
        $stmt = $pdo->prepare('SELECT id FROM user WHERE user_name = :userName AND deleted = 0 LIMIT 1');
        $stmt->execute(['userName' => $userName]);
        if ($stmt->fetchColumn() !== false) {
            $skipped++;
            continue;
        }

        try {
            // Resolver TODOS os roles ANTES de criar o user — falha cedo se faltar role (AC6).
            // Regular roles vão pra tabela `role` → setados em rolesIds (link `roles`).
            // Portal roles vão pra tabela `portal_role` → setados em portalRolesIds
            // (link `portalRoles`). Em EspoCRM 9.x são entidades distintas; portal
            // user com regular role não fica linkado (FK errada).
            $roleIds = [];
            foreach (($userSpec['roles'] ?? []) as $roleName) {
                $roleStmt = $pdo->prepare('SELECT id FROM role WHERE name = :name AND deleted = 0 LIMIT 1');
                $roleStmt->execute(['name' => $roleName]);
                $roleId = $roleStmt->fetchColumn();
                if ($roleId === false) {
                    throw new \RuntimeException(
                        "[seeds] ERROR: role '{$roleName}' não encontrado na tabela `role`.\n" .
                        "[seeds] Causa provável: togare-rbac não instalado. Execute:\n" .
                        "[seeds]   docker compose exec espocrm php command.php extension --action=install --name=TogareRbac\n" .
                        "[seeds] (ou instale via Admin → Extensions). Reexecute load-seeds.php depois."
                    );
                }
                $roleIds[] = $roleId;
            }

            $portalRoleIds = [];
            foreach (($userSpec['portalRoles'] ?? []) as $portalRoleName) {
                $portalRoleStmt = $pdo->prepare('SELECT id FROM portal_role WHERE name = :name AND deleted = 0 LIMIT 1');
                $portalRoleStmt->execute(['name' => $portalRoleName]);
                $portalRoleId = $portalRoleStmt->fetchColumn();
                if ($portalRoleId === false) {
                    throw new \RuntimeException(
                        "[seeds] ERROR: portal role '{$portalRoleName}' não encontrado na tabela `portal_role`.\n" .
                        "[seeds] Portal roles ainda NÃO são seedados pelo togare-rbac (Story 2.1 só seedou regular roles).\n" .
                        "[seeds] Quando a story de portal_role chegar, atualizar users.seed.json para referenciar o nome correto."
                    );
                }
                $portalRoleIds[] = $portalRoleId;
            }

            // Criar User via EntityManager (respeita hooks/before-save EspoCRM).
            // rolesIds DEVE ser populado ANTES de saveEntity — o hook
            // togare-rbac UserRoleRequired::beforeSave valida via
            // getLinkMultipleIdList('roles') e bloqueia se vazio. Setar a
            // relação link-multiple via `rolesIds` faz o EntityManager
            // sincronizar a tabela `user_role` durante o save (substitui o
            // INSERT direto que existia antes — a chave composta UNIQUE
            // continua protegendo idempotência via skip do user inteiro acima).
            $user = $em->getNewEntity('User');
            $user->set('userName',     $userName);
            $user->set('firstName',    (string) ($userSpec['firstName']    ?? ''));
            $user->set('lastName',     (string) ($userSpec['lastName']     ?? ''));
            $user->set('emailAddress', (string) ($userSpec['emailAddress'] ?? ''));
            $user->set('type',         (string) ($userSpec['type']         ?? 'regular'));
            $user->set('isActive',     (bool)   ($userSpec['isActive']     ?? true));
            $user->set('password',     password_hash(
                (string) ($userSpec['passwordRaw'] ?? ''),
                PASSWORD_BCRYPT,
                ['cost' => 12]
            ));
            $user->set('rolesIds', $roleIds);
            $user->set('portalRolesIds', $portalRoleIds);
            $em->saveEntity($user);

            if ((string) ($userSpec['type'] ?? '') === 'portal' && empty($portalRoleIds)) {
                echo "[seeds] WARN user '{$userName}' (type=portal) criado sem portalRoles — Portal vai funcionar mas sem permissões granulares. Ver _pendingPortalRole em users.seed.json.\n";
            }

            $seeded++;
        } catch (\Throwable $e) {
            // Erros estruturados [seeds] ERROR: (role/portal_role não encontrado) são fatais — AC6.
            if (\str_starts_with($e->getMessage(), '[seeds] ERROR:')) {
                throw $e;
            }
            $errors++;
            echo "[seeds] ERROR user '{$userName}': {$e->getMessage()}\n";
        }
    }

    $sec = round(microtime(true) - $sectionStart, 2);
    echo "[seeds] users: {$seeded} seeded, {$skipped} skipped, {$errors} errors ({$sec} sec)\n";

    return ['seeded' => $seeded, 'skipped' => $skipped, 'errors' => $errors, 'durationSec' => $sec];
}

/**
 * @param array<string, mixed> $json
 * @return array{seeded:int, skipped:int, errors:int}
 */
function seedPlaceholder(\Espo\ORM\EntityManager $em, \PDO $pdo, array $json, ?string $label): array
{
    $story = (string) ($json['_pendingStory'] ?? 'TBD');
    // Se o pendingStory já é um Epic (ex.: "Epic 8"), não prefixar com "Story".
    $prefix = str_starts_with($story, 'Epic') ? '' : 'Story ';
    $reason = ($json['_implemented'] ?? null) === false
        ? "placeholder — pendente {$prefix}{$story}"
        : 'implementado mas handler ainda não escrito (BUG)';
    echo "[seeds] {$label}: 0 seeded, 0 skipped ({$reason})\n";
    return ['seeded' => 0, 'skipped' => 0, 'errors' => 0];
}
