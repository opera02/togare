<?php

/**
 * Validador de convenções Togare (arquitetura Step 5).
 *
 * Regras aplicadas:
 *   R1. Classe PHP em espocrm/togare-<kebab>/src/files/custom/Espo/Modules/**
 *       deve ter namespace começando por "Togare\" e nome começando por "Togare".
 *   R2. Pasta espocrm/togare-<kebab>/ deve conter README.md no root.
 *   R3. Migration (**\/Migration/V*__*.php) não pode criar tabela sem prefixo "togare_".
 *   R4. Entidade (metadata/entityDefs/Togare*.json) deve ter label pt-BR em
 *       i18n/pt_BR/Togare*.json para cada atributo em fields.*.
 *
 * Uso:
 *   php tools/validate-togare-naming.php                       # full scan
 *   php tools/validate-togare-naming.php --staged <file>...    # só arquivos stage
 *
 * Saída:
 *   - Mensagens estilo compilador: "<arquivo>:<linha>: <ERRO>: <explicação>"
 *   - Exit 0 se zero violações; exit 1 se alguma encontrada.
 *
 * Este script NÃO usa composer nem pacotes externos — roda com PHP ≥8.2 puro.
 */

declare(strict_types=1);

const REPO_ROOT_MARK = 'composer.json';

$exitCode = 0;
$violations = [];

// -----------------------------------------------------------------------------
// Descobrir modo + lista de arquivos.
// -----------------------------------------------------------------------------

$repoRoot = findRepoRoot(__DIR__);
chdir($repoRoot);

[$mode, $files] = parseArgs($argv);

if ($mode === 'staged' && empty($files)) {
    // Nada staged ou nenhum relevante. Silenciosamente ok — evita falso-positivo
    // em commits que só afetam docs.
    exit(0);
}

$filesToCheck = $mode === 'staged' ? $files : discoverAllFiles($repoRoot);

// -----------------------------------------------------------------------------
// Regras.
// -----------------------------------------------------------------------------

// R1: prefixo de classe PHP.
foreach ($filesToCheck as $file) {
    if (! isTogareCustomPhpFile($file)) {
        continue;
    }
    if (! file_exists($file)) {
        continue; // deletado neste commit
    }
    checkPhpPrefix($file, $violations);
}

// R2: módulo togare-<kebab> sem README.md.
$togareModulesInvolved = collectTogareModules($filesToCheck);
foreach ($togareModulesInvolved as $moduleDir) {
    $readme = $moduleDir . '/README.md';
    if (! file_exists($readme)) {
        $violations[] = sprintf(
            "%s:1: ERRO R2: módulo '%s' sem README.md no root. Criar seguindo template fixo de 5 seções (arquitetura Step 5 / UX-DR19): '# Togare<Nome>', '## O que faz', '## Como instalar', '## Entidades expostas', '## Hooks disparados / consumidos', '## Como testar'.",
            $moduleDir,
            basename($moduleDir),
        );
    }
}

// R3: migration criando tabela sem prefixo togare_.
foreach ($filesToCheck as $file) {
    if (! isMigrationFile($file)) {
        continue;
    }
    if (! file_exists($file)) {
        continue;
    }
    checkMigrationTablePrefix($file, $violations);
}

// R4: entidade sem label pt-BR em i18n.
foreach ($filesToCheck as $file) {
    if (! isEntityDefFile($file)) {
        continue;
    }
    if (! file_exists($file)) {
        continue;
    }
    checkEntityLabelsPtBr($file, $violations);
}

// R5: proibir error_log() e $GLOBALS['log'] em código Togare (usar TogareLogger).
foreach ($filesToCheck as $file) {
    if (! isTogareCustomPhpFile($file)) {
        continue;
    }
    if (! file_exists($file)) {
        continue;
    }
    // O próprio TogareLogger é a implementação — usa fwrite(STDOUT/STDERR) mas
    // nunca error_log nem $GLOBALS['log']. Isentar por segurança pra não pegar
    // em refactor futuro.
    if (str_ends_with(str_replace('\\', '/', $file), 'Services/TogareLogger.php')) {
        continue;
    }
    checkErrorLogMisuse($file, $violations);
}

// R6: proibir INSERT direto em togare_queue_items fora do QueueService / migrations.
foreach ($filesToCheck as $file) {
    if (! isTogareCustomPhpFile($file)) {
        continue;
    }
    if (! file_exists($file)) {
        continue;
    }
    checkDirectQueueInsert($file, $violations);
}

// -----------------------------------------------------------------------------
// Saída.
// -----------------------------------------------------------------------------

if (empty($violations)) {
    if ($mode === 'full') {
        fwrite(STDOUT, "✓ validate-togare-naming: zero violações de convenção.\n");
    }
    exit(0);
}

fwrite(STDERR, "❌ validate-togare-naming: " . count($violations) . " violação(ões) de convenção Togare.\n\n");
foreach ($violations as $v) {
    fwrite(STDERR, $v . "\n");
}
fwrite(STDERR, "\n→ Detalhes das convenções: _bmad-output/planning-artifacts/architecture.md (Step 5 + Naming).\n");
fwrite(STDERR, "→ Para pular em caso legítimo: git commit --no-verify (adicionar '[skip hooks: <motivo>]' no final da mensagem).\n");
exit(1);

// =============================================================================
// Helpers
// =============================================================================

function findRepoRoot(string $start): string
{
    $dir = $start;
    while ($dir && $dir !== dirname($dir)) {
        if (file_exists($dir . DIRECTORY_SEPARATOR . REPO_ROOT_MARK)) {
            return $dir;
        }
        $dir = dirname($dir);
    }
    throw new RuntimeException("Repo root não encontrado (marcador: " . REPO_ROOT_MARK . ")");
}

/**
 * @return array{0:string,1:list<string>} [mode, files]
 */
function parseArgs(array $argv): array
{
    array_shift($argv); // remove nome do script

    if (empty($argv)) {
        return ['full', []];
    }

    if ($argv[0] === '--staged') {
        array_shift($argv);
        // Normaliza separadores para forward slash (lefthook no Git Bash já passa /,
        // mas prevenimos quebras se invocado por outro lugar).
        $files = array_map(
            static fn (string $f): string => str_replace('\\', '/', $f),
            $argv,
        );
        return ['staged', array_values($files)];
    }

    fwrite(STDERR, "Uso: validate-togare-naming.php [--staged <files>...]\n");
    exit(2);
}

/**
 * @return list<string>
 */
function discoverAllFiles(string $root): array
{
    $patterns = [
        'espocrm/togare-*/src/files/custom/Espo/Modules/*/*.php',
        'espocrm/togare-*/src/files/custom/Espo/Modules/*/**/*.php',
        'espocrm/togare-*/src/files/custom/Espo/Modules/*/Migration/V*__*.php',
        'espocrm/togare-*/src/files/custom/Espo/Modules/*/Resources/metadata/entityDefs/Togare*.json',
        'espocrm/togare-*/README.md',
        'espocrm/togare-*',
    ];

    $found = [];
    foreach ($patterns as $pattern) {
        $matches = glob($root . '/' . $pattern, GLOB_BRACE | GLOB_NOSORT) ?: [];
        foreach ($matches as $m) {
            $rel = ltrim(substr(str_replace('\\', '/', $m), strlen(str_replace('\\', '/', $root))), '/');
            $found[$rel] = true;
        }
    }
    return array_keys($found);
}

function isTogareCustomPhpFile(string $file): bool
{
    if (! str_ends_with($file, '.php')) {
        return false;
    }
    // Qualquer PHP custom sob espocrm/togare-<kebab>/src/files/custom/Espo/Modules/
    if (! preg_match('#^espocrm/togare-[a-z0-9-]+/src/files/custom/Espo/Modules/#', $file)) {
        return false;
    }
    // Ignorar testes (podem ter test doubles sem prefixo) e vendor.
    if (preg_match('#/(tests|vendor)/#', $file)) {
        return false;
    }
    // Ignorar migrations — convenção própria 'V<N>__<descricao>', checada pela R3.
    if (isMigrationFile($file)) {
        return false;
    }
    return true;
}

function isMigrationFile(string $file): bool
{
    return (bool) preg_match('#/Migration/V\d+__[A-Za-z0-9_]+\.php$#', $file);
}

function isEntityDefFile(string $file): bool
{
    return (bool) preg_match('#/metadata/entityDefs/Togare[A-Za-z0-9]+\.json$#', $file);
}

/**
 * @param list<string> $files
 * @return list<string> módulos únicos "espocrm/togare-<kebab>"
 */
function collectTogareModules(array $files): array
{
    $modules = [];
    foreach ($files as $f) {
        if (preg_match('#^(espocrm/togare-[a-z0-9-]+)(/|$)#', $f, $m)) {
            $modules[$m[1]] = true;
        }
    }
    return array_keys($modules);
}

function checkPhpPrefix(string $file, array &$violations): void
{
    $code = file_get_contents($file);
    if ($code === false) {
        return;
    }

    $tokens = token_get_all($code);
    $namespace = null;
    $lineOfNamespace = 0;
    $seenClassLike = [];

    $i = 0;
    $n = count($tokens);
    while ($i < $n) {
        $t = $tokens[$i];
        if (is_array($t)) {
            [$id, , $line] = $t;

            if ($id === T_NAMESPACE) {
                // consume until ';' ou '{'
                $ns = '';
                $lineOfNamespace = $line;
                $i++;
                while ($i < $n) {
                    $tt = $tokens[$i];
                    if (is_string($tt) && ($tt === ';' || $tt === '{')) {
                        break;
                    }
                    if (is_array($tt)) {
                        $ns .= $tt[1];
                    }
                    $i++;
                }
                $namespace = trim($ns);
                $i++;
                continue;
            }

            if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT], true)) {
                // Pular anonymous class: T_CLASS precedido de T_NEW.
                $prev = findPrevSignificant($tokens, $i);
                if ($prev !== null && is_array($prev) && $prev[0] === T_NEW) {
                    $i++;
                    continue;
                }
                $next = findNextSignificant($tokens, $i);
                if ($next !== null && is_array($next) && $next[0] === T_STRING) {
                    $seenClassLike[] = ['name' => $next[1], 'line' => $line, 'kind' => tokenLabel($id)];
                }
            }
        }
        $i++;
    }

    // Validar namespace.
    // Aceita dois padrões documentados (arquitetura Step 5 / ADR 0003):
    //   - Togare\<Modulo>\<Subpath>           (convenção semântica, usada em tools/, CLI)
    //   - Espo\Modules\Togare<Modulo>\<Sub>   (convenção runtime EspoCRM via PSR-4)
    $namespaceOk = $namespace === null
        || preg_match('/^Togare(\\\\[A-Z][A-Za-z0-9_]*)+$/', $namespace) === 1
        // Root namespace sem sub-path é válido para Binding.php e equivalentes (convenção EspoCRM).
        || preg_match('/^Espo\\\\Modules\\\\Togare[A-Z][A-Za-z0-9_]*(\\\\[A-Z][A-Za-z0-9_]*)*$/', $namespace) === 1;

    if (! $namespaceOk) {
        $violations[] = sprintf(
            "%s:%d: ERRO R1: namespace '%s' não segue convenção Togare. Aceitos: 'Togare\\<Modulo>\\...' (semântico) ou 'Espo\\Modules\\Togare<Modulo>\\...' (runtime EspoCRM). Ver arquitetura Step 5 / ADR 0003.",
            $file,
            $lineOfNamespace,
            $namespace,
        );
    }

    // Validar nomes de classe/interface/trait. Regra: precisa ter prefixo Togare
    // OU estar dentro de um namespace Togare*/Espo\Modules\Togare* (nesse caso o prefixo
    // do módulo já garante a atribuição Togare). Mantém bloqueio para nomes vagos como
    // 'MauvaisService' dentro de namespace App\*.
    $namespaceEndorsesTogare = $namespace !== null && (
        str_starts_with($namespace, 'Togare\\') || $namespace === 'Togare'
        || str_starts_with($namespace, 'Espo\\Modules\\Togare')
    );

    foreach ($seenClassLike as $c) {
        $nameOk = str_starts_with($c['name'], 'Togare') || $namespaceEndorsesTogare;
        if (! $nameOk) {
            $violations[] = sprintf(
                "%s:%d: ERRO R1: %s '%s' sem prefixo 'Togare' e fora de namespace Togare*. Convenção: PascalCase com prefixo (ex.: TogareProcesso).",
                $file,
                $c['line'],
                $c['kind'],
                $c['name'],
            );
        }
    }
}

function checkMigrationTablePrefix(string $file, array &$violations): void
{
    $code = file_get_contents($file);
    if ($code === false) {
        return;
    }
    $lines = explode("\n", $code);
    foreach ($lines as $n => $line) {
        if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?([A-Za-z_][A-Za-z0-9_]*)[`"]?/i', $line, $m)) {
            $table = $m[1];
            if (! str_starts_with($table, 'togare_')) {
                $violations[] = sprintf(
                    "%s:%d: ERRO R3: tabela '%s' sem prefixo 'togare_'. Renomear para 'togare_%s' (arquitetura Step 5 / Naming).",
                    $file,
                    $n + 1,
                    $table,
                    $table,
                );
            }
        }
    }
}

function checkDirectQueueInsert(string $file, array &$violations): void
{
    // Isenções: QueueService é a implementação legítima; migrations criam o schema.
    $normalized = str_replace('\\', '/', $file);
    if (str_ends_with($normalized, 'Services/QueueService.php')) {
        return;
    }
    if (preg_match('#/Migration/V\d+__[A-Za-z0-9_]+\.php$#', $normalized)) {
        return;
    }

    $code = file_get_contents($file);
    if ($code === false) {
        return;
    }
    $lines = explode("\n", $code);
    foreach ($lines as $n => $line) {
        $trimmed = ltrim($line);
        if ($trimmed === ''
            || str_starts_with($trimmed, '//')
            || str_starts_with($trimmed, '#')
            || str_starts_with($trimmed, '*')
            || str_starts_with($trimmed, '/*')) {
            continue;
        }
        if (preg_match('/\bINSERT\s+INTO\s+togare_queue_items\b/i', $line)) {
            $violations[] = sprintf(
                "%s:%d: ERRO R6: INSERT direto em togare_queue_items proibido. Use QueueService::enqueue(queueName, payload, idempotencyKey) — único ponto autorizado (arquitetura Step 5 / ADR 0005).",
                $file,
                $n + 1,
            );
        }
    }
}

function checkErrorLogMisuse(string $file, array &$violations): void
{
    $code = file_get_contents($file);
    if ($code === false) {
        return;
    }
    $lines = explode("\n", $code);
    foreach ($lines as $n => $line) {
        // Escape hatch literal na mesma linha dispensa a violação.
        if (str_contains($line, '// escape hatch: bootstrap')) {
            continue;
        }

        // Pular linhas que são apenas comentário (evita match em docblock/inline
        // comments que mencionam a função por documentação).
        $trimmed = ltrim($line);
        if ($trimmed === ''
            || str_starts_with($trimmed, '//')
            || str_starts_with($trimmed, '#')
            || str_starts_with($trimmed, '*')
            || str_starts_with($trimmed, '/*')) {
            continue;
        }

        if (preg_match('/\berror_log\s*\(/', $line)) {
            $violations[] = sprintf(
                "%s:%d: ERRO R5: use TogareLogger::event() em vez de error_log(). Se for bootstrap antes do container DI, anotar '// escape hatch: bootstrap' na mesma linha.",
                $file,
                $n + 1,
            );
        }
        if (preg_match('/\$GLOBALS\s*\[\s*[\'"]log[\'"]\s*\]/', $line)) {
            $violations[] = sprintf(
                "%s:%d: ERRO R5: use TogareLogger::event() em vez de \$GLOBALS['log']. Sem correlationId/userId/event estruturado, log nativo perde rastreabilidade (NFR32).",
                $file,
                $n + 1,
            );
        }
    }
}

function checkEntityLabelsPtBr(string $file, array &$violations): void
{
    $def = json_decode(file_get_contents($file) ?: 'null', true);
    if (! is_array($def) || ! isset($def['fields']) || ! is_array($def['fields'])) {
        return;
    }

    // Derivar caminho esperado do i18n pt_BR.
    $entityName = basename($file, '.json');
    // entityDefs/TogareProcesso.json → i18n/pt_BR/TogareProcesso.json
    $moduleBase = preg_replace('#/Resources/metadata/entityDefs/.+$#', '', $file);
    $i18nPath = $moduleBase . '/Resources/i18n/pt_BR/' . $entityName . '.json';

    $labels = [];
    if (file_exists($i18nPath)) {
        $data = json_decode(file_get_contents($i18nPath) ?: 'null', true);
        if (is_array($data) && isset($data['fields']) && is_array($data['fields'])) {
            foreach ($data['fields'] as $field => $meta) {
                if (is_array($meta) && isset($meta['label']) && trim((string) $meta['label']) !== '') {
                    $labels[$field] = true;
                }
            }
        }
    }

    $missing = [];
    foreach (array_keys($def['fields']) as $field) {
        if (empty($labels[$field])) {
            $missing[] = $field;
        }
    }

    if ($missing !== []) {
        $violations[] = sprintf(
            "%s:1: ERRO R4: entidade '%s' com %d atributo(s) sem label pt-BR em '%s': %s",
            $file,
            $entityName,
            count($missing),
            $i18nPath,
            implode(', ', $missing),
        );
    }
}

/**
 * @param list<array{0:int,1:string,2:int}|string> $tokens
 */
function findPrevSignificant(array $tokens, int $index): array|string|null
{
    for ($i = $index - 1; $i >= 0; $i--) {
        $t = $tokens[$i];
        if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        return $t;
    }
    return null;
}

/**
 * @param list<array{0:int,1:string,2:int}|string> $tokens
 */
function findNextSignificant(array $tokens, int $index): array|string|null
{
    $n = count($tokens);
    for ($i = $index + 1; $i < $n; $i++) {
        $t = $tokens[$i];
        if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        return $t;
    }
    return null;
}

function tokenLabel(int $id): string
{
    return match ($id) {
        T_CLASS => 'class',
        T_INTERFACE => 'interface',
        T_TRAIT => 'trait',
        default => 'type',
    };
}
