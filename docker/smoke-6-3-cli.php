<?php
declare(strict_types=1);

/**
 * Story 6.3 — Smoke F1 CLI integration test.
 *
 * Cobre 12 passos (T13.4):
 *  1) Resolve services FaturaSaldoService + FaturaLookupService
 *  2) Metadata: Fatura + LancamentoFinanceiro registradas
 *  3) Setup: cliente + contrato vigente (criação se não houver)
 *  4) Gate FR23 v1: fatura sem contrato → BadRequest
 *  5) Gate FR23 v2: fatura com contrato não vigente → BadRequest
 *  6) Happy path: fatura emitida ok
 *  7) Pagamento parcial → status parcialmente_paga, saldo decrementa
 *  8) Pagamento total → status paga, saldo 0
 *  9) Estorno → volta para parcialmente_paga
 * 10) Cancelamento via Service → status cancelada
 * 11) Matrix validation: pagamento_total SEM faturaId → BadRequest
 * 12) Audit logs: togare_fatura_log + togare_lancamento_financeiro_log existem
 */

chdir('/var/www/html');
require '/var/www/html/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\TogareCore\Entities\ContratoHonorarios;
use Espo\Modules\TogareCore\Entities\Fatura;
use Espo\Modules\TogareCore\Entities\LancamentoFinanceiro;
use Espo\Modules\TogareCore\Services\FaturaLookupService;
use Espo\Modules\TogareCore\Services\FaturaSaldoService;

$app = new Application();
$app->setupSystemUser();
$container = $app->getContainer();
$injectableFactory = $container->get('injectableFactory');
$em = $container->get('entityManager');
$pdo = $em->getPDO();

$pass = 0;
$fail = 0;
$failures = [];

function step(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $failures;
    if ($ok) {
        $pass++;
        echo "[PASS] $label" . ($detail ? "  → $detail" : '') . "\n";
    } else {
        $fail++;
        $failures[] = $label . ($detail ? " — $detail" : '');
        echo "[FAIL] $label" . ($detail ? "  → $detail" : '') . "\n";
    }
}

echo "=== STORY 6.3 SMOKE F1 CLI ===\n\n";

// ─────────────────────────────────────────────────────────────────────────
// PASSO 1 — Resolução dos services
// ─────────────────────────────────────────────────────────────────────────
echo "[STEP 1] Resolução dos services\n";
class Smoke63WrapperSaldo {
    public function __construct(public readonly FaturaSaldoService $svc) {}
}
class Smoke63WrapperLookup {
    public function __construct(public readonly FaturaLookupService $svc) {}
}
try {
    $saldoSvc = $injectableFactory->create(Smoke63WrapperSaldo::class)->svc;
    step('FaturaSaldoService resolvido', true, get_class($saldoSvc));
} catch (\Throwable $e) {
    step('FaturaSaldoService resolvido', false, $e->getMessage());
    $saldoSvc = null;
}
try {
    $lookupSvc = $injectableFactory->create(Smoke63WrapperLookup::class)->svc;
    step('FaturaLookupService resolvido', true, get_class($lookupSvc));
} catch (\Throwable $e) {
    step('FaturaLookupService resolvido', false, $e->getMessage());
    $lookupSvc = null;
}

// ─────────────────────────────────────────────────────────────────────────
// PASSO 2 — Metadata registradas
// ─────────────────────────────────────────────────────────────────────────
echo "\n[STEP 2] Metadata Fatura + LancamentoFinanceiro\n";
$metadata = $container->get('metadata');
$faturaDefs = $metadata->get(['entityDefs', 'Fatura']);
step('Fatura entityDefs registrada', $faturaDefs !== null);
$lancDefs = $metadata->get(['entityDefs', 'LancamentoFinanceiro']);
step('LancamentoFinanceiro entityDefs registrada', $lancDefs !== null);

$statusOpts = $metadata->get(['entityDefs', 'Fatura', 'fields', 'status', 'options']);
step(
    'Fatura.status options correto',
    is_array($statusOpts) && count($statusOpts) === 5,
    'options=' . json_encode($statusOpts)
);

$tipoOpts = $metadata->get(['entityDefs', 'LancamentoFinanceiro', 'fields', 'tipo', 'options']);
step(
    'LancamentoFinanceiro.tipo options correto',
    is_array($tipoOpts) && count($tipoOpts) === 6,
    'options=' . json_encode($tipoOpts)
);

// ─────────────────────────────────────────────────────────────────────────
// PASSO 3 — Setup: cliente + contrato vigente
// ─────────────────────────────────────────────────────────────────────────
echo "\n[STEP 3] Setup: cliente + contrato vigente\n"; flush();
try {
    $clienteRepo = $em->getRDBRepository('Cliente');
    $contratoRepo = $em->getRDBRepository('ContratoHonorarios');
    $faturaRepo = $em->getRDBRepository('Fatura');
    $lancRepo = $em->getRDBRepository('LancamentoFinanceiro');
    echo "  [debug] repos resolvidos\n"; flush();
} catch (\Throwable $e) {
    echo "  [debug] FAIL repos: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    exit(2);
}

$smokeClienteName = 'Smoke 6.3 Cliente';
try {
    $clienteSmoke = $clienteRepo->where(['name' => $smokeClienteName])->findOne();
    echo "  [debug] busca cliente: " . ($clienteSmoke ? 'encontrado id=' . $clienteSmoke->getId() : 'não existe') . "\n"; flush();
} catch (\Throwable $e) {
    echo "  [debug] FAIL busca cliente: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    exit(3);
}
if (!$clienteSmoke) {
    try {
        $clienteSmoke = $em->getNewEntity('Cliente');
        $clienteSmoke->set([
            'name' => $smokeClienteName,
            'tipoPessoa' => 'pf',
            'cpf' => '111.444.777-35',
            'email' => 'smoke63@togare.test',
        ]);
        echo "  [debug] cliente set ok, salvando…\n"; flush();
        $em->saveEntity($clienteSmoke);
        echo "  [debug] cliente salvo\n"; flush();
    } catch (\Throwable $e) {
        echo "  [debug] FAIL save cliente: " . get_class($e) . ': ' . $e->getMessage() . "\n";
        echo "  " . $e->getFile() . ':' . $e->getLine() . "\n";
        exit(4);
    }
}
$clienteId = $clienteSmoke->getId();
step('Cliente smoke criado/encontrado', true, "id=$clienteId");

// Contrato vigente (vigencia_inicio passado, vigencia_fim futuro)
try {
    $contratoSmoke = $contratoRepo
        ->where(['clienteId' => $clienteId, 'modalidade' => 'fixo'])
        ->order('createdAt', 'DESC')
        ->findOne();
    echo "  [debug] busca contrato: " . ($contratoSmoke ? 'encontrado id=' . $contratoSmoke->getId() . ' vigenciaFim=' . $contratoSmoke->get('vigenciaFim') : 'não existe') . "\n"; flush();
} catch (\Throwable $e) {
    echo "  [debug] FAIL busca contrato: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    exit(5);
}

if (!$contratoSmoke || $contratoSmoke->get('vigenciaFim') < date('Y-m-d')) {
    // Cria contrato direto via SQL pra bypass dos hooks de upload PDF de Story 6.1.
    // Não é o objeto do smoke 6.3 testar ValidateContratoFieldsHook.
    try {
        $contratoId = 'ctr' . substr(md5(uniqid()), 0, 14);
        $pdo = $em->getPDO();
        $stmt = $pdo->prepare(
            "INSERT INTO contrato_honorarios " .
            "(id, deleted, modalidade, valor, valor_currency, data_assinatura, vigencia_inicio, vigencia_fim, " .
            "file_storage_uri, filename, mime_type, size_bytes, cliente_id, created_at, modified_at) " .
            "VALUES (:id, 0, 'fixo', 5000.0, 'BRL', :assin, :ini, :fim, " .
            ":uri, 'contrato.pdf', 'application/pdf', 1024, :cli, NOW(), NOW())"
        );
        $stmt->execute([
            ':id' => $contratoId,
            ':assin' => date('Y-m-d'),
            ':ini' => date('Y-m-d', strtotime('-30 days')),
            ':fim' => date('Y-m-d', strtotime('+90 days')),
            ':uri' => 'local://smoke63/contrato.pdf',
            ':cli' => $clienteId,
        ]);
        echo "  [debug] contrato inserido via SQL id=$contratoId\n"; flush();

        // Recarrega como entidade via repo
        $contratoSmoke = $contratoRepo->getById($contratoId);
        if (!$contratoSmoke) {
            echo "  [debug] FAIL recarregar contrato\n";
            exit(6);
        }
    } catch (\Throwable $e) {
        echo "  [debug] FAIL inserir contrato SQL: " . get_class($e) . ': ' . $e->getMessage() . "\n";
        exit(6);
    }
}
$contratoId = $contratoSmoke->getId();
step('Contrato vigente smoke criado/encontrado', true, "id=$contratoId vigenciaFim=" . $contratoSmoke->get('vigenciaFim'));

// Confirma lookup hasContratoVigente
if ($lookupSvc) {
    $contratoLookupSvc = $injectableFactory->create(\Espo\Modules\TogareCore\Services\ContratoHonorariosLookupService::class);
    $hasVigente = $contratoLookupSvc->hasContratoVigente($clienteId);
    step('hasContratoVigente returns true', $hasVigente === true);
}

// ─────────────────────────────────────────────────────────────────────────
// PASSO 4 — Gate FR23 v1: fatura sem contrato → BadRequest
// ─────────────────────────────────────────────────────────────────────────
echo "\n[STEP 4] Gate FR23 v1 — fatura sem contratoHonorariosId\n";
try {
    $faturaSemContrato = $em->getNewEntity('Fatura');
    $faturaSemContrato->set([
        'descricao' => 'Smoke gate FR23 sem contrato',
        'dataEmissao' => date('Y-m-d'),
        'dataVencimento' => date('Y-m-d', strtotime('+30 days')),
        'valorBruto' => 1000.0,
        'clienteId' => $clienteId,
    ]);
    $em->saveEntity($faturaSemContrato);
    step('Gate FR23 v1 sem contrato → BadRequest', false, 'NÃO lançou exceção');
} catch (BadRequest $e) {
    step(
        'Gate FR23 v1 sem contrato → BadRequest',
        str_contains($e->getMessage(), 'Art. 22') || str_contains($e->getMessage(), 'OAB'),
        $e->getMessage()
    );
} catch (\Throwable $e) {
    step('Gate FR23 v1 sem contrato → BadRequest', false, get_class($e) . ': ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
// PASSO 5 — Gate FR23 v2: contrato não vigente
// ─────────────────────────────────────────────────────────────────────────
echo "\n[STEP 5] Gate FR23 v2 — contrato existe mas vigência expirada\n";
$contratoExpirado = $contratoRepo
    ->where(['clienteId' => $clienteId, 'modalidade' => 'fixo'])
    ->order('createdAt', 'DESC')
    ->limit(0, 1)
    ->findOne();

// Cliente isolado: cria via SQL direto (CPF NULL pra evitar UNIQ violation)
try {
    $clienteIsoladoId = 'cliIso' . substr(md5(uniqid()), 0, 11);
    $pdo->prepare(
        "INSERT INTO cliente (id, deleted, name, tipo_pessoa, created_at, modified_at) " .
        "VALUES (:id, 0, :name, 'pf', NOW(), NOW())"
    )->execute([
        ':id' => $clienteIsoladoId,
        ':name' => 'Smoke 6.3 Cliente Isolado ' . substr(uniqid(), -6),
    ]);
    $clienteIsolado = $clienteRepo->getById($clienteIsoladoId);
    if (!$clienteIsolado) {
        echo "  [debug] FAIL recarregar cliente isolado\n";
        exit(7);
    }
    echo "  [debug] cliente isolado id=" . $clienteIsolado->getId() . "\n"; flush();
} catch (\Throwable $e) {
    echo "  [debug] FAIL cliente isolado SQL: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    exit(7);
}

// Cria contrato expirado direto via SQL (bypass hooks)
$contratoExpiradoId = 'ctrEx' . substr(md5(uniqid()), 0, 12);
$pdo = $em->getPDO();
$pdo->prepare(
    "INSERT INTO contrato_honorarios " .
    "(id, deleted, modalidade, valor, valor_currency, data_assinatura, vigencia_inicio, vigencia_fim, " .
    "file_storage_uri, filename, mime_type, size_bytes, cliente_id, created_at, modified_at) " .
    "VALUES (:id, 0, 'fixo', 500.0, 'BRL', :assin, :ini, :fim, " .
    ":uri, 'exp.pdf', 'application/pdf', 512, :cli, NOW(), NOW())"
)->execute([
    ':id' => $contratoExpiradoId,
    ':assin' => date('Y-m-d', strtotime('-120 days')),
    ':ini' => date('Y-m-d', strtotime('-90 days')),
    ':fim' => date('Y-m-d', strtotime('-1 day')),
    ':uri' => 'local://smoke63/exp.pdf',
    ':cli' => $clienteIsolado->getId(),
]);
echo "  [debug] contrato expirado inserido id=$contratoExpiradoId\n"; flush();

try {
    $faturaNaoVigente = $em->getNewEntity('Fatura');
    $faturaNaoVigente->set([
        'descricao' => 'Smoke gate FR23 contrato expirado',
        'contratoHonorariosId' => $contratoExpiradoId,
        'clienteId' => $clienteIsolado->getId(),
        'dataEmissao' => date('Y-m-d'),
        'dataVencimento' => date('Y-m-d', strtotime('+30 days')),
        'valorBruto' => 500.0,
    ]);
    $em->saveEntity($faturaNaoVigente);
    step('Gate FR23 v2 contrato expirado → BadRequest', false, 'NÃO lançou exceção');
} catch (BadRequest $e) {
    step(
        'Gate FR23 v2 contrato expirado → BadRequest',
        str_contains(strtolower($e->getMessage()), 'vigente') || str_contains($e->getMessage(), 'Art. 22'),
        $e->getMessage()
    );
} catch (\Throwable $e) {
    step('Gate FR23 v2 contrato expirado → BadRequest', false, get_class($e) . ': ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
// PASSO 6 — Happy path: fatura emitida
// ─────────────────────────────────────────────────────────────────────────
echo "\n[STEP 6] Happy path — fatura emitida com contrato vigente\n";
try {
    $fatura = $em->getNewEntity('Fatura');
    $fatura->set([
        'descricao' => 'Smoke fatura ' . date('YmdHis'),
        'contratoHonorariosId' => $contratoId,
        'dataEmissao' => date('Y-m-d'),
        'dataVencimento' => date('Y-m-d', strtotime('+30 days')),
        'valorBruto' => 1000.0,
    ]);
    $em->saveEntity($fatura);
    $faturaId = $fatura->getId();
    step('Fatura emitida criada', $fatura->get('status') === 'emitida', "id=$faturaId status=" . $fatura->get('status'));
    step('Numero auto-gerado', !empty($fatura->get('numero')), 'numero=' . $fatura->get('numero'));
    step('ClienteId herdado do contrato', $fatura->get('clienteId') === $clienteId, 'clienteId=' . $fatura->get('clienteId'));
    step('valorPago inicial = 0', (float) $fatura->get('valorPago') === 0.0);
    step('Saldo inicial = valorBruto', (float) $fatura->get('saldo') === 1000.0);
} catch (\Throwable $e) {
    step('Fatura emitida criada', false, get_class($e) . ': ' . $e->getMessage());
    return;
}

// ─────────────────────────────────────────────────────────────────────────
// PASSO 7 — Pagamento parcial
// ─────────────────────────────────────────────────────────────────────────
echo "\n[STEP 7] Pagamento parcial 400.0\n";
try {
    $pagParcial = $em->getNewEntity('LancamentoFinanceiro');
    $pagParcial->set([
        'descricao' => 'Pagamento parcial smoke',
        'tipo' => 'pagamento_parcial',
        'valor' => 400.0,
        'dataMovimento' => date('Y-m-d'),
        'formaPagamento' => 'pix',
        'faturaId' => $faturaId,
        'clienteId' => $clienteId,
    ]);
    $em->saveEntity($pagParcial);
    $em->refreshEntity($fatura);
    step('Pagamento parcial → status parcialmente_paga', $fatura->get('status') === 'parcialmente_paga', 'status=' . $fatura->get('status'));
    step('valorPago = 400.0', (float) $fatura->get('valorPago') === 400.0, 'valorPago=' . $fatura->get('valorPago'));
    step('Saldo = 600.0', (float) $fatura->get('saldo') === 600.0, 'saldo=' . $fatura->get('saldo'));
} catch (\Throwable $e) {
    step('Pagamento parcial', false, get_class($e) . ': ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
// PASSO 8 — Pagamento total
// ─────────────────────────────────────────────────────────────────────────
echo "\n[STEP 8] Pagamento total 600.0\n";
try {
    $pagTotal = $em->getNewEntity('LancamentoFinanceiro');
    $pagTotal->set([
        'descricao' => 'Pagamento total smoke',
        'tipo' => 'pagamento_total',
        'valor' => 600.0,
        'dataMovimento' => date('Y-m-d'),
        'formaPagamento' => 'pix',
        'faturaId' => $faturaId,
        'clienteId' => $clienteId,
    ]);
    $em->saveEntity($pagTotal);
    $em->refreshEntity($fatura);
    step('Pagamento total → status paga', $fatura->get('status') === 'paga', 'status=' . $fatura->get('status'));
    step('valorPago = 1000.0', (float) $fatura->get('valorPago') === 1000.0, 'valorPago=' . $fatura->get('valorPago'));
    step('Saldo = 0', (float) $fatura->get('saldo') === 0.0, 'saldo=' . $fatura->get('saldo'));
} catch (\Throwable $e) {
    step('Pagamento total', false, get_class($e) . ': ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
// PASSO 9 — Estorno
// ─────────────────────────────────────────────────────────────────────────
echo "\n[STEP 9] Estorno 300.0\n";
try {
    $estorno = $em->getNewEntity('LancamentoFinanceiro');
    $estorno->set([
        'descricao' => 'Estorno smoke',
        'tipo' => 'estorno',
        'valor' => 300.0,
        'dataMovimento' => date('Y-m-d'),
        'formaPagamento' => 'pix',
        'faturaId' => $faturaId,
        'clienteId' => $clienteId,
    ]);
    $em->saveEntity($estorno);
    $em->refreshEntity($fatura);
    step('Estorno → status parcialmente_paga', $fatura->get('status') === 'parcialmente_paga', 'status=' . $fatura->get('status'));
    step('valorPago = 700.0', (float) $fatura->get('valorPago') === 700.0, 'valorPago=' . $fatura->get('valorPago'));
    step('Saldo = 300.0', (float) $fatura->get('saldo') === 300.0, 'saldo=' . $fatura->get('saldo'));
} catch (\Throwable $e) {
    step('Estorno', false, get_class($e) . ': ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
// PASSO 10 — Cancelamento via Service
// ─────────────────────────────────────────────────────────────────────────
echo "\n[STEP 10] Cancelamento via FaturaSaldoService::transitionStatus\n";
try {
    // Cria outra fatura para cancelar (sem mexer na que já está em jogo)
    $faturaCancelar = $em->getNewEntity('Fatura');
    $faturaCancelar->set([
        'descricao' => 'Smoke cancelar',
        'contratoHonorariosId' => $contratoId,
        'dataEmissao' => date('Y-m-d'),
        'dataVencimento' => date('Y-m-d', strtotime('+30 days')),
        'valorBruto' => 100.0,
    ]);
    $em->saveEntity($faturaCancelar);

    $saldoSvc->transitionStatus($faturaCancelar->getId(), 'cancelada', 'Smoke cancel reason');
    $em->refreshEntity($faturaCancelar);
    step('Cancelamento via Service → status cancelada', $faturaCancelar->get('status') === 'cancelada', 'status=' . $faturaCancelar->get('status'));
    step('motivoCancelamento preservado', $faturaCancelar->get('motivoCancelamento') === 'Smoke cancel reason', 'motivo=' . $faturaCancelar->get('motivoCancelamento'));

    // Tenta atualizar fatura cancelada → BadRequest
    try {
        $faturaCancelar->set('descricao', 'Tentativa de mudança pós-cancel');
        $em->saveEntity($faturaCancelar);
        step('Fatura cancelada bloqueada para edição', false, 'NÃO lançou');
    } catch (BadRequest $e) {
        step('Fatura cancelada bloqueada para edição', str_contains(strtolower($e->getMessage()), 'cancelada'), $e->getMessage());
    }
} catch (\Throwable $e) {
    step('Cancelamento via Service', false, get_class($e) . ': ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
// PASSO 11 — Matrix: pagamento_total SEM faturaId → BadRequest
// ─────────────────────────────────────────────────────────────────────────
echo "\n[STEP 11] Matrix tipo×fatura: pagamento_total sem faturaId\n";
try {
    $lancInvalido = $em->getNewEntity('LancamentoFinanceiro');
    $lancInvalido->set([
        'descricao' => 'Inválido — pagamento sem fatura',
        'tipo' => 'pagamento_total',
        'valor' => 100.0,
        'dataMovimento' => date('Y-m-d'),
        'formaPagamento' => 'pix',
        // sem faturaId
    ]);
    $em->saveEntity($lancInvalido);
    step('Matrix pagamento_total sem fatura → BadRequest', false, 'NÃO lançou');
} catch (BadRequest $e) {
    step('Matrix pagamento_total sem fatura → BadRequest', str_contains(strtolower($e->getMessage()), 'fatura'), $e->getMessage());
} catch (\Throwable $e) {
    step('Matrix pagamento_total sem fatura → BadRequest', false, get_class($e) . ': ' . $e->getMessage());
}

// Matrix v2: despesa_interna COM faturaId → BadRequest
echo "\n[STEP 11b] Matrix tipo×fatura: despesa_interna COM faturaId\n";
try {
    $lancInvalido2 = $em->getNewEntity('LancamentoFinanceiro');
    $lancInvalido2->set([
        'descricao' => 'Inválido — despesa com fatura',
        'tipo' => 'despesa_interna',
        'valor' => 100.0,
        'dataMovimento' => date('Y-m-d'),
        'faturaId' => $faturaId, // não pode
    ]);
    $em->saveEntity($lancInvalido2);
    step('Matrix despesa_interna com fatura → BadRequest', false, 'NÃO lançou');
} catch (BadRequest $e) {
    step('Matrix despesa_interna com fatura → BadRequest', str_contains(strtolower($e->getMessage()), 'fatura'), $e->getMessage());
} catch (\Throwable $e) {
    step('Matrix despesa_interna com fatura → BadRequest', false, get_class($e) . ': ' . $e->getMessage());
}

// Lançamento avulso (despesa_interna sem fatura) → OK
echo "\n[STEP 11c] Lançamento avulso despesa_interna OK\n";
try {
    $avulso = $em->getNewEntity('LancamentoFinanceiro');
    $avulso->set([
        'descricao' => 'Despesa avulsa smoke',
        'tipo' => 'despesa_interna',
        'valor' => 50.0,
        'dataMovimento' => date('Y-m-d'),
        'categoria' => 'aluguel',
        'clienteId' => $clienteId,
    ]);
    $em->saveEntity($avulso);
    step('Lançamento avulso despesa_interna criado', true, 'id=' . $avulso->getId());
} catch (\Throwable $e) {
    step('Lançamento avulso despesa_interna', false, get_class($e) . ': ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
// PASSO 12 — Audit logs existem
// ─────────────────────────────────────────────────────────────────────────
echo "\n[STEP 12] Audit logs (togare_fatura_log + togare_lancamento_financeiro_log)\n";
try {
    $pdo = $em->getPDO();

    $stmt = $pdo->query("SELECT COUNT(*) FROM togare_fatura_log WHERE fatura_id = '$faturaId'");
    $faturaLogCount = (int) $stmt->fetchColumn();
    step('togare_fatura_log tem linhas pra fatura smoke', $faturaLogCount > 0, "count=$faturaLogCount");

    $stmt = $pdo->query("SELECT COUNT(*) FROM togare_lancamento_financeiro_log");
    $lancLogCount = (int) $stmt->fetchColumn();
    step('togare_lancamento_financeiro_log tem linhas', $lancLogCount > 0, "count=$lancLogCount");

    $stmt = $pdo->query("SELECT event, COUNT(*) AS n FROM togare_fatura_log GROUP BY event");
    echo "  Events fatura: ";
    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        echo "{$row['event']}={$row['n']} ";
    }
    echo "\n";

    $stmt = $pdo->query("SELECT event, COUNT(*) AS n FROM togare_lancamento_financeiro_log GROUP BY event");
    echo "  Events lancamento: ";
    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        echo "{$row['event']}={$row['n']} ";
    }
    echo "\n";
} catch (\Throwable $e) {
    step('Audit logs', false, get_class($e) . ': ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
// SUMMARY
// ─────────────────────────────────────────────────────────────────────────
echo "\n=== SMOKE 6.3 CLI RESULTADO ===\n";
echo "PASS: $pass\n";
echo "FAIL: $fail\n";
if ($fail > 0) {
    echo "\n--- FALHAS ---\n";
    foreach ($failures as $f) {
        echo " · $f\n";
    }
    exit(1);
}
echo "\n=== SMOKE 6.3 CLI OK ===\n";
exit(0);
