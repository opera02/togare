<?php
chdir('/var/www/html');
require '/var/www/html/bootstrap.php';
$app = new \Espo\Core\Application();
$app->setupSystemUser();
$container = $app->getContainer();
$em = $container->getByClass(\Espo\ORM\EntityManager::class);

$docId = $argv[1] ?? '';
if ($docId === '') {
    echo "Uso: php smoke-doc-purge.php <documentoId>\n";
    exit(1);
}

$doc = $em->getEntityById('Documento', $docId);
if ($doc === null) {
    echo "FAIL: Documento {$docId} nao encontrado\n";
    exit(1);
}
echo "Documento antes delete: id={$doc->getId()} uri={$doc->get('nextcloudUri')}\n";

echo "\n=== T10.10 AC#13 — Soft-purge no delete ===\n";
try {
    $em->removeEntity($doc);
    echo "  OK: Documento removido (soft-delete EspoCRM).\n";
} catch (\Throwable $e) {
    echo "  FAIL: " . get_class($e) . ": " . $e->getMessage() . "\n";
    exit(1);
}

// Verifica togare_documento_log
$pdo = $em->getPDO();
$stmt = $pdo->prepare("SELECT id, event, documento_id, payload FROM togare_documento_log WHERE documento_id=:id ORDER BY created_at DESC LIMIT 1");
$stmt->execute([':id' => $docId]);
$row = $stmt->fetch(\PDO::FETCH_ASSOC);
if ($row) {
    echo "  Log V018 row: event={$row['event']}\n";
    echo "  payload: {$row['payload']}\n";
} else {
    echo "  FAIL: sem row em togare_documento_log\n";
    exit(1);
}
