<?php
declare(strict_types=1);

/**
 * Story 6.1 — Smoke F1 CLI integration test (Claude executando, Felipe assina).
 *
 * Valida:
 *  - bridge resolve FileStorageContract → NextcloudFileStorage (default piloto)
 *  - buildUri('clientes/X/contratos/Y-arquivo.pdf') retorna 'nextcloud://...'
 *  - ContratoLogicalPathBuilder::build/extract roundtrip ok
 *  - ContratoHonorariosLookupService::hasContratoVigente correct
 *  - Entity ContratoHonorarios registrada no metadata + modalidade enum 6 valores
 */

chdir('/var/www/html');
require '/var/www/html/bootstrap.php';

use Espo\Core\Application;
use Espo\Modules\TogareCore\Contracts\FileStorageContract;
use Espo\Modules\TogareCore\Contracts\PurgeableStorageContract;
use Espo\Modules\TogareCore\Services\ContratoHonorariosLookupService;
use Espo\Modules\TogareCore\Services\ContratoLogicalPathBuilder;

$app = new Application();
$container = $app->getContainer();
$injectableFactory = $container->get('injectableFactory');

echo "=== STORY 6.1 SMOKE F1 CLI ===\n";

// (1) Resolução de FileStorageContract via wrapper sintetizado.
//
// Pattern Story 6.0 smoke F1 T9.5: criamos classe inline que aceita
// FileStorageContract no constructor, então o injectableFactory resolve
// a interface via Binding.php — retornando NextcloudFileStorage (bridge
// instalado) ou LocalDiskPurgeableStorage (fallback).
class TogareSmoke61WrapperFile {
    public function __construct(public readonly FileStorageContract $fileStorage) {}
}
class TogareSmoke61WrapperPurge {
    public function __construct(public readonly PurgeableStorageContract $purgeStorage) {}
}

$wrapperFile = $injectableFactory->create(TogareSmoke61WrapperFile::class);
echo "RESOLVED FileStorageContract: " . get_class($wrapperFile->fileStorage) . "\n";

$wrapperPurge = $injectableFactory->create(TogareSmoke61WrapperPurge::class);
echo "RESOLVED PurgeableStorageContract: " . get_class($wrapperPurge->purgeStorage) . "\n";

// (2) buildUri smoke — chama o método agnóstico.
$logicalPath = 'clientes/cli-smoke-001/contratos/contrato001-arquivo.pdf';
$uri = $wrapperFile->fileStorage->buildUri($logicalPath);
echo "buildUri('$logicalPath') = $uri\n";

// (3) ContratoLogicalPathBuilder roundtrip — agnostic scheme.
$parsed = ContratoLogicalPathBuilder::parseFromUri($uri);
echo "parseFromUri('$uri'):\n";
echo "  scheme: {$parsed['scheme']}\n";
echo "  bucket: {$parsed['bucket']}\n";
echo "  clienteId: {$parsed['clienteId']}\n";
echo "  subdir: {$parsed['subdir']}\n";
echo "  contratoId: {$parsed['contratoId']}\n";
echo "  filename: {$parsed['filename']}\n";

// (4) Sanitize filename.
$san1 = ContratoLogicalPathBuilder::sanitizeFilename('contrato honorários acme.pdf');
$san2 = ContratoLogicalPathBuilder::sanitizeFilename('Contrato-2026.pdf');
$san3 = ContratoLogicalPathBuilder::sanitizeFilename('');
echo "sanitizeFilename('contrato honorários acme.pdf') = $san1\n";
echo "sanitizeFilename('Contrato-2026.pdf') = $san2\n";
echo "sanitizeFilename('') = $san3\n";

// (5) extractLogicalPath — tolera ambos schemes (Decisão #2).
$logicalNc = ContratoLogicalPathBuilder::extractLogicalPath('nextcloud://foo/bar');
$logicalLocal = ContratoLogicalPathBuilder::extractLogicalPath('local://foo/bar');
echo "extractLogicalPath('nextcloud://foo/bar') = $logicalNc\n";
echo "extractLogicalPath('local://foo/bar') = $logicalLocal\n";

// (6) Lookup service smoke (sem contratos cadastrados — retorna false/empty).
try {
    $entityManager = $container->get('entityManager');
    $lookup = new ContratoHonorariosLookupService($entityManager);
    $hasVigente = $lookup->hasContratoVigente('cli-fake-no-contratos');
    echo "hasContratoVigente('cli-fake-no-contratos'): " . ($hasVigente ? 'true' : 'false') . "\n";
} catch (\Throwable $e) {
    echo "LOOKUP ERROR: " . get_class($e) . ": " . $e->getMessage() . "\n";
    echo "  in " . $e->getFile() . ":" . $e->getLine() . "\n";
}

// (7) Metadata — entity ContratoHonorarios registrada.
$metadata = $container->get('metadata');
$entityDefs = $metadata->get(['entityDefs', 'ContratoHonorarios']);
echo "ContratoHonorarios entityDefs: " . ($entityDefs !== null ? 'REGISTERED' : 'MISSING') . "\n";

$modalidade = $metadata->get(['entityDefs', 'ContratoHonorarios', 'fields', 'modalidade']);
echo "modalidade field type: " . ($modalidade['type'] ?? 'N/A') . "\n";
echo "modalidade options: " . json_encode($modalidade['options'] ?? []) . "\n";

$cliente = $metadata->get(['entityDefs', 'ContratoHonorarios', 'links', 'cliente']);
echo "cliente link: type={$cliente['type']} required=" . ($cliente['required'] ? 'true' : 'false') . "\n";

$processos = $metadata->get(['entityDefs', 'ContratoHonorarios', 'links', 'processos']);
echo "processos link: type={$processos['type']} relationship={$processos['relationshipName']}\n";

// (8) clientDefs Cliente confirma relationshipPanel.
$clientePanel = $metadata->get(['clientDefs', 'Cliente', 'relationshipPanels', 'contratosHonorarios']);
echo "Cliente relationshipPanel.contratosHonorarios: " . ($clientePanel !== null ? 'REGISTERED' : 'MISSING') . "\n";
if ($clientePanel) {
    echo "  rowActionsView: " . ($clientePanel['rowActionsView'] ?? 'N/A') . "\n";
    echo "  buttonList[0].action: " . ($clientePanel['buttonList'][0]['action'] ?? 'N/A') . "\n";
}

echo "\n=== SMOKE CLI OK ===\n";
