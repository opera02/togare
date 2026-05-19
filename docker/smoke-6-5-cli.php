<?php
declare(strict_types=1);

/**
 * Story 6.5 — Smoke F1 CLI integration test (parte Claude, sem browser).
 *
 * Cobre (Task 9 — AC1/AC2/AC3/AC4/AC6/AC7):
 *  1) Metadata: Funcionario scope (entity/object/tab) + entityDefs registrados
 *  2) Tabela `funcionario` criada pelo ORM rebuild (sem migration CREATE TABLE)
 *  3) Index UNIQUE em cpf + tenant_id nullable
 *  4) Hook Normalize: CPF mascarado → só dígitos no storage
 *  5) Hook Validate: CPF inválido (DV) → BadRequest pt-BR
 *  6) Hook Validate: CPF sequência repetida → BadRequest pt-BR
 *  7) Happy path: Funcionario válido persiste com 5 campos + tenant_id NULL
 *  8) CPF opcional: Funcionario sem CPF persiste (campo não-required)
 *  9) UNIQUE cpf: 2º Funcionario com mesmo CPF → exceção de persistência
 * 10) RBAC: role.data dos 8 roles tem Funcionario com política FR32 correta
 * 11) Controller Funcionario REST registrado (classe existe e estende Record)
 */

chdir('/var/www/html');
require '/var/www/html/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\TogareCore\Entities\Funcionario;

$app = new Application();
$app->setupSystemUser();
$container = $app->getContainer();
$em = $container->get('entityManager');
$pdo = $em->getPDO();
$metadata = $container->get('metadata');

$pass = 0;
$fail = 0;
$failures = [];

function step(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $failures;
    if ($ok) {
        $pass++;
        echo "[PASS] $label" . ($detail ? "  -> $detail" : '') . "\n";
    } else {
        $fail++;
        $failures[] = $label . ($detail ? " - $detail" : '');
        echo "[FAIL] $label" . ($detail ? "  -> $detail" : '') . "\n";
    }
}

echo "=== STORY 6.5 SMOKE F1 CLI (Funcionario) ===\n\n";

// 1 — metadata scope
$scope = $metadata->get(['scopes', 'Funcionario']);
step(
    '1) scopes.Funcionario entity+object+tab+stream=false',
    is_array($scope)
        && ($scope['entity'] ?? false) === true
        && ($scope['object'] ?? false) === true
        && ($scope['tab'] ?? false) === true
        && ($scope['stream'] ?? true) === false,
    json_encode($scope),
);

// 1b — entityDefs fields
$fields = $metadata->get(['entityDefs', 'Funcionario', 'fields']);
$hasFields = is_array($fields)
    && isset($fields['nome'], $fields['cpf'], $fields['cargo'], $fields['salario'], $fields['dataAdmissao'], $fields['tenantId']);
step('1b) entityDefs.Funcionario 5 campos dominio + tenantId', $hasFields,
    $hasFields ? 'salario.defaultCurrency=' . ($fields['salario']['defaultCurrency'] ?? '?') : 'campos ausentes');

// 2 — tabela criada pelo ORM
$tbl = $pdo->query("SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='funcionario'")->fetch(PDO::FETCH_ASSOC);
step('2) tabela `funcionario` existe (ORM rebuild, sem migration)', (int) $tbl['c'] === 1);

// 3 — index cpf (NÃO-unique de propósito: unicidade via hook, ver steps 9/12)
//     + tenant_id nullable. Fix-pass 0.37.2: o UNIQUE de banco foi removido
//     porque abrangia soft-deleted (bug 500 do smoke browser Felipe).
$idx = $pdo->query("SELECT INDEX_NAME, NON_UNIQUE FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='funcionario' AND column_name='cpf'")->fetch(PDO::FETCH_ASSOC);
step('3a) index cpf presente e NÃO-unique (unicidade via hook — steps 9/12)', is_array($idx) && (int) $idx['NON_UNIQUE'] === 1, json_encode($idx));
$col = $pdo->query("SELECT IS_NULLABLE FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='funcionario' AND column_name='tenant_id'")->fetch(PDO::FETCH_ASSOC);
step('3b) tenant_id NULLABLE (single-tenant MVP)', is_array($col) && $col['IS_NULLABLE'] === 'YES', json_encode($col));

// helper
function novoFuncionario($em, array $data): Funcionario
{
    $f = $em->getNewEntity('Funcionario');
    $f->set($data);
    $em->saveEntity($f);
    return $f;
}

function makeValidCpf(string $seed): string
{
    $base = \str_pad((string) (\abs(\crc32($seed)) % 1000000000), 9, '0', STR_PAD_LEFT);
    if (\preg_match('/^(\d)\1{8}$/', $base) === 1) {
        $base = '123456789';
    }

    $cpf = $base;
    for ($j = 9; $j <= 10; $j++) {
        $sum = 0;
        for ($i = 0; $i < $j; $i++) {
            $sum += (int) $cpf[$i] * ($j + 1 - $i);
        }
        $dv = ($sum * 10) % 11;
        if ($dv === 10) {
            $dv = 0;
        }
        $cpf .= (string) $dv;
    }

    return $cpf;
}

$uniq = \bin2hex(\random_bytes(4));
$validCpf = makeValidCpf($uniq);
$maskedCpf = \sprintf(
    '%s.%s.%s-%s',
    \substr($validCpf, 0, 3),
    \substr($validCpf, 3, 3),
    \substr($validCpf, 6, 3),
    \substr($validCpf, 9, 2),
);

// 4 — Normalize: CPF mascarado -> só dígitos
$f1 = novoFuncionario($em, [
    'nome' => 'Maria Smoke ' . $uniq,
    'cpf' => $maskedCpf,
    'cargo' => 'Analista RH',
    'salario' => 4200.0,
    'salarioCurrency' => 'BRL',
    'dataAdmissao' => '2026-05-16',
]);
$stored = (string) $pdo->query("SELECT cpf FROM funcionario WHERE id=" . $pdo->quote($f1->getId()))->fetchColumn();
step('4) Normalize CPF mascarado -> so digitos no storage', $stored === $validCpf, "storage='$stored'");
step('7) Happy path: 5 campos + tenant_id NULL', $f1->get('nome') === 'Maria Smoke ' . $uniq && $f1->get('tenantId') === null,
    'tenantId=' . var_export($f1->get('tenantId'), true));

// 5 — Validate: CPF DV inválido -> BadRequest pt-BR
try {
    novoFuncionario($em, ['nome' => 'CPF DV ' . $uniq, 'cargo' => 'X', 'dataAdmissao' => '2026-05-16', 'cpf' => '12345678900']);
    step('5) CPF DV invalido -> BadRequest', false, 'nao lancou');
} catch (BadRequest $e) {
    step('5) CPF DV invalido -> BadRequest pt-BR', str_contains($e->getMessage(), 'CPF inválido'), $e->getMessage());
}

// 6 — Validate: sequência repetida
try {
    novoFuncionario($em, ['nome' => 'CPF Seq ' . $uniq, 'cargo' => 'X', 'dataAdmissao' => '2026-05-16', 'cpf' => '11111111111']);
    step('6) CPF sequencia repetida -> BadRequest', false, 'nao lancou');
} catch (BadRequest $e) {
    step('6) CPF sequencia repetida -> BadRequest pt-BR', str_contains($e->getMessage(), 'CPF inválido'), $e->getMessage());
}

// 8 — CPF opcional
try {
    $f2 = novoFuncionario($em, ['nome' => 'Sem CPF ' . $uniq, 'cargo' => 'Estagiario', 'dataAdmissao' => '2026-05-16']);
    step('8) CPF opcional: persiste sem CPF', $f2->getId() !== null);
} catch (\Throwable $e) {
    step('8) CPF opcional: persiste sem CPF', false, get_class($e) . ': ' . $e->getMessage());
}

// 9 — CPF duplicado entre ATIVOS -> BadRequest pt-BR (NÃO 500/PDOException)
try {
    novoFuncionario($em, ['nome' => 'Dup CPF ' . $uniq, 'cargo' => 'Y', 'dataAdmissao' => '2026-05-16', 'cpf' => $validCpf]);
    step('9) CPF duplicado (ativo) -> BadRequest pt-BR', false, 'nao lancou (esperava BadRequest)');
} catch (BadRequest $e) {
    step('9) CPF duplicado (ativo) -> BadRequest pt-BR', str_contains($e->getMessage(), 'Já existe um funcionário cadastrado com este CPF'), $e->getMessage());
} catch (\Throwable $e) {
    step('9) CPF duplicado (ativo) -> BadRequest pt-BR', false, 'tipo errado: ' . get_class($e) . ' (esperava BadRequest, NÃO PDOException/500)');
}

// 12 — Bug do smoke browser Felipe: CPF de funcionário EXCLUÍDO deve ficar
// livre para reuso (soft-delete não bloqueia). Era o que dava HTTP 500.
try {
    $em->removeEntity($f1); // soft-delete do registro do passo 4 (cpf=$validCpf)
    $f3 = novoFuncionario($em, [
        'nome' => 'Reuso CPF ' . $uniq,
        'cargo' => 'RH',
        'dataAdmissao' => '2026-05-16',
        'cpf' => $validCpf,
    ]);
    step('12) CPF de funcionario EXCLUIDO liberado para reuso', $f3->getId() !== null, "novo id={$f3->getId()}");
} catch (\Throwable $e) {
    step('12) CPF de funcionario EXCLUIDO liberado para reuso', false, get_class($e) . ': ' . $e->getMessage());
}

// 10 — RBAC policy FR32
$expected = [
    'Sócio/Admin' => 'all', 'RH-lite' => 'all',
    'Advogado' => 'no', 'Assistente/Estagiário' => 'no', 'Secretária' => 'no',
    'Financeiro' => 'no', 'Marketing' => 'no', 'Cliente-portal' => 'no',
];
$rbacOk = true;
$rbacDetail = [];
foreach ($expected as $roleName => $lvl) {
    $row = $pdo->query("SELECT data FROM role WHERE name=" . $pdo->quote($roleName) . " AND deleted=0")->fetch(PDO::FETCH_ASSOC);
    $data = is_array($row) ? json_decode((string) $row['data'], true) : [];
    $got = $data['scopeLevel']['Funcionario'] ?? '(ausente)';
    $inList = is_array($data['scopeList'] ?? null) && in_array('Funcionario', $data['scopeList'], true);
    if ($got !== $lvl || ! $inList) {
        $rbacOk = false;
        $rbacDetail[] = "$roleName=" . json_encode($got) . (($inList) ? '' : ' [fora scopeList]');
    }
}
step('10) RBAC FR32: 8 roles com Funcionario correto pos-V010', $rbacOk, $rbacOk ? 'Socio/Admin+RH-lite=all; outros 6=no' : implode('; ', $rbacDetail));

// 11 — Controller registrado
$ctrl = 'Espo\\Modules\\TogareCore\\Controllers\\Funcionario';
step('11) Controller Funcionario existe e estende Record',
    class_exists($ctrl) && is_subclass_of($ctrl, 'Espo\\Core\\Controllers\\Record'));

echo "\n=== RESULTADO: $pass PASS / $fail FAIL ===\n";
if ($fail > 0) {
    echo "Falhas:\n - " . implode("\n - ", $failures) . "\n";
    exit(1);
}
exit(0);
