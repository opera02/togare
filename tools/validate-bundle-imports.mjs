#!/usr/bin/env node
/**
 * validate-bundle-imports.mjs — Story 4b.0 (A1).
 *
 * Bloqueia commits que introduzam import/re-export estático cujo módulo NÃO
 * existe em runtime EspoCRM 9.x. Defende contra o cenário do incidente 4a.4
 * fix-pass 0.19.1: bundler aceita o import; vitest/PHPUnit/lint verdes; em
 * runtime `mapBundleDependencies` falha ao resolver e quebra o bundle inteiro
 * do togare-core (listas vazias em todas as entities).
 *
 * Estratégia: whitelist + blacklist curados (não-runtime — pre-commit precisa
 * rodar em <2s sem container EspoCRM up). Whitelist baseado nos módulos
 * confirmados como `define("...")` em `client/lib/espo-main.js` em EspoCRM 9.3.
 *
 * Uso:
 *   node tools/validate-bundle-imports.mjs                       # scan completo do monorepo
 *   node tools/validate-bundle-imports.mjs <file1.js> <file2.js> # modo lefthook (staged_files)
 *
 * Exit:
 *   0 — sem violações (ou nenhum .js custom para validar)
 *   1 — ≥1 violação encontrada
 *
 * Para atualizar o whitelist: confirmar via container que o módulo existe
 * (`docker exec <espocrm> grep -oE 'define\\("<path>"' /var/www/html/client/lib/espo-main.js`)
 * e adicionar à constante `KNOWN_ESPOCRM_MODULES` abaixo. Documentar no PR.
 *
 * Origem: retrospectiva Epic 4a (2026-05-06) — Balde A item A1 +
 * `feedback_extension_bundled_pattern.md` regra v0.19.1.
 */

import { readFile } from "node:fs/promises";
import { existsSync, statSync, readdirSync } from "node:fs";
import { join, relative, resolve, sep } from "node:path";
import { fileURLToPath } from "node:url";

const REPO_ROOT = resolve(fileURLToPath(import.meta.url), "..", "..");
const CUSTOM_MODULES_GLOB_ROOT = join(REPO_ROOT, "espocrm");

/**
 * Módulos EspoCRM 9.x importáveis em runtime — confirmados como `define("...")`
 * em `client/lib/espo-main.js`. Crescer este set ao introduzir novo import
 * legítimo após confirmação manual via container.
 */
const KNOWN_ESPOCRM_MODULES = new Set([
  // Genéricos
  "view",
  "model",
  "collection",

  // Record views (estendíveis via class extension)
  "views/record/edit",
  "views/record/detail",
  "views/record/list",
  "views/record/list-with-categories",
  "views/record/kanban",

  // Field views
  "views/fields/base",
  "views/fields/varchar",
  "views/fields/enum",
  "views/fields/text",
  "views/fields/date",
  "views/fields/datetime",
  "views/fields/int",
  "views/fields/float",
  "views/fields/bool",
  "views/fields/link",
  "views/fields/link-multiple",
  "views/fields/email",
  "views/fields/phone",
  "views/fields/url",
  "views/fields/password",
  "views/fields/foreign",

  // Dashlets
  "views/dashlets/abstract/record-list",
  "views/dashlets/abstract/base",

  // Modals
  "views/modal",
  "views/modals/edit",
  "views/modals/detail",
  "views/modals/select-records",

  // Row actions (Story 5.3 — DocumentoRelationshipRowActionsView estende
  // o relationship row-actions para injetar item "Baixar" no painel
  // Documentos de Processo/Cliente/Prazo).
  "views/record/row-actions/relationship",
  "views/record/row-actions/default",

  // Story 7a.1 (togare-portal-ui) — login custom branded do Portal.
  // Confirmado em runtime: `define("views/login"` em
  // /var/www/html/client/lib/espo-main.js (bundle eager, carregado
  // inclusive na página de login pré-auth). É o módulo que o
  // TogarePortalSplashLoginView estende.
  "views/login",

  // Story 7a.1 — painel admin "Portal → Aparência" (Settings-backed).
  // Confirmado em runtime: `define("views/settings/record/edit"` em
  // /var/www/html/client/lib/espo-admin.js (chunk admin, carregado no
  // contexto Admin — exatamente onde o recordView roda; é o módulo que
  // o próprio core importa em views/admin/notifications etc.).
  "views/settings/record/edit",
]);

/**
 * Módulos blacklisted: parecem plausíveis mas NÃO existem como classes ES6
 * importáveis em EspoCRM 9.x. Cada entry tem motivo + sugestão custom.
 */
const BLACKLISTED_ESPOCRM_MODULES = new Map([
  [
    "views/record/row",
    {
      reason: "views/record/row NÃO existe como classe ES6 em EspoCRM 9.x (regra v0.19.1).",
      suggestion:
        "customizar row via template + buildRow override em views/record/list, NÃO via class extension.",
    },
  ],
]);

// `^[ \t]*` (não `^\s*`) impede que a regex consuma `\n` de linhas em branco
// anteriores e desloque o `match.index` pra linha errada. A linha reportada no
// erro precisa ser a do keyword `import`/`export`.
//
// Cobre imports estáticos com `from`, imports bare side-effect e re-exports:
//   import View from "view";
//   import "views/record/row";
//   export { x } from "views/foo/bar";
//   export * as X from "views/foo/bar";
const MODULE_REFERENCE_REGEX =
  /^[ \t]*(?:(import\s+(?:(?:[^"';]+?)\s+from\s+)?["']([^"']+)["'])|(export\s+(?:(?:\{[\s\S]*?\}|\*\s*(?:as\s+[A-Za-z_$][\w$]*\s*)?)\s+from\s+)["']([^"']+)["']))/gm;

function isTogareNamespacedImport(source) {
  return /^togare-[a-z0-9-]+:/.test(source);
}

function isRelativeImport(source) {
  return source.startsWith("./") || source.startsWith("../") || source.startsWith("/");
}

function looksLikeEspoCrmPath(source) {
  // Heurística: imports core do EspoCRM começam com "view", "model", "collection",
  // "views/", "model/", "ui/", etc — sem prefixo de módulo (`togare-X:`).
  return (
    source === "view" ||
    source === "model" ||
    source === "collection" ||
    source.startsWith("views/") ||
    source.startsWith("model/") ||
    source.startsWith("ui/")
  );
}

/**
 * Extrai todos os imports/re-exports estáticos com module specifier string de
 * um conteúdo JS. Tolerante a múltiplas declarações no arquivo. Ignora imports
 * dinâmicos (`import("...")`) e require (CommonJS) — fora do escopo deste
 * validador.
 *
 * @param {string} content
 * @returns {Array<{ name: string, source: string, line: number, statement: string }>}
 */
export function parseImportsFromFile(content) {
  const results = [];
  MODULE_REFERENCE_REGEX.lastIndex = 0;
  let match;
  while ((match = MODULE_REFERENCE_REGEX.exec(content)) !== null) {
    const rawStatement = match[1] || match[3];
    const statement = rawStatement.replace(/\s+/g, " ").trim();
    const source = match[2] || match[4];
    const idx = match.index;
    const line = content.slice(0, idx).split("\n").length;
    results.push({ name: statement, source, line, statement });
  }
  return results;
}

/**
 * Valida um único import source. Retorna `{ ok: true }` ou
 * `{ ok: false, reason, suggestion }`.
 *
 * Regras (em ordem):
 *   1. Imports `togare-X:` → SEMPRE OK (resolvidos pelo bundle init.js).
 *   2. Imports relativos (`./...`, `../...`, `/...`) → SEMPRE OK (resolvidos em build).
 *   3. Match em BLACKLISTED_ESPOCRM_MODULES → ERRO com motivo+sugestão custom.
 *   4. Match em KNOWN_ESPOCRM_MODULES → OK.
 *   5. Parece path EspoCRM (views/* model/* ui/* ou view/model/collection puros)
 *      mas não está em nenhum set → ERRO genérico (instrui a atualizar whitelist).
 *   6. Outros (npm packages como "vitest", "vue", etc) → OK silently — fora do
 *      escopo deste validador (esses nem são empacotados pelo espo-extension-tools).
 */
export function validateImport(source) {
  if (isTogareNamespacedImport(source)) {
    return { ok: true };
  }
  if (isRelativeImport(source)) {
    return { ok: true };
  }
  if (BLACKLISTED_ESPOCRM_MODULES.has(source)) {
    const entry = BLACKLISTED_ESPOCRM_MODULES.get(source);
    return { ok: false, reason: entry.reason, suggestion: entry.suggestion };
  }
  if (KNOWN_ESPOCRM_MODULES.has(source)) {
    return { ok: true };
  }
  if (looksLikeEspoCrmPath(source)) {
    return {
      ok: false,
      reason: "módulo não está na whitelist KNOWN_ESPOCRM_MODULES.",
      suggestion: `confirme que \`${source}\` existe como define() em client/lib/espo-main.js (docker exec <container> grep -oE 'define\\("${source}"' /var/www/html/client/lib/espo-main.js); se sim, adicione ao whitelist em tools/validate-bundle-imports.mjs.`,
    };
  }
  return { ok: true };
}

/**
 * Walk síncrono retornando todos os arquivos `.js` em subárvore.
 * Usado quando o script roda sem args (scan completo).
 */
function findAllCustomJsFiles(rootDir) {
  const results = [];
  if (!existsSync(rootDir)) return results;
  const stack = [rootDir];
  while (stack.length > 0) {
    const dir = stack.pop();
    let entries;
    try {
      entries = readdirSync(dir, { withFileTypes: true });
    } catch {
      continue;
    }
    for (const entry of entries) {
      const full = join(dir, entry.name);
      if (entry.isDirectory()) {
        // Ignora node_modules / build / vendor / lib (artifacts, não fonte)
        if (
          entry.name === "node_modules" ||
          entry.name === "build" ||
          entry.name === "vendor" ||
          entry.name === "lib"
        ) {
          continue;
        }
        stack.push(full);
      } else if (entry.isFile() && entry.name.endsWith(".js")) {
        // Só considera arquivos sob `src/files/client/custom/modules/<mod>/src/`
        const rel = relative(REPO_ROOT, full).split(sep).join("/");
        if (
          rel.startsWith("espocrm/") &&
          rel.includes("/src/files/client/custom/modules/") &&
          rel.includes("/src/")
        ) {
          results.push(full);
        }
      }
    }
  }
  return results;
}

/**
 * Filtra a lista de paths recebida para incluir apenas arquivos `.js` em
 * `espocrm/<mod>/src/files/client/custom/modules/.../src/...`. Ignora paths
 * fora desse escopo (modo lefthook recebe TODOS os staged; queremos só os JS
 * custom).
 */
function filterCandidateFiles(paths) {
  const out = [];
  for (const p of paths) {
    if (!p.endsWith(".js")) continue;
    let abs;
    try {
      abs = resolve(p);
      if (!existsSync(abs) || !statSync(abs).isFile()) continue;
    } catch {
      continue;
    }
    const rel = relative(REPO_ROOT, abs).split(sep).join("/");
    if (
      rel.startsWith("espocrm/") &&
      rel.includes("/src/files/client/custom/modules/") &&
      rel.includes("/src/")
    ) {
      out.push(abs);
    }
  }
  return out;
}

/**
 * Valida uma lista de paths. Imprime erros em stderr; retorna nº de erros.
 */
async function validateFiles(filePaths) {
  let errorCount = 0;
  for (const filePath of filePaths) {
    let content;
    try {
      content = await readFile(filePath, "utf8");
    } catch (e) {
      // Arquivo deletado ou inacessível — não é erro nosso.
      continue;
    }
    const imports = parseImportsFromFile(content);
    for (const imp of imports) {
      const result = validateImport(imp.source);
      if (!result.ok) {
        errorCount += 1;
        const relPath = relative(REPO_ROOT, filePath).split(sep).join("/");
        process.stderr.write(
          `❌ Import bloqueado em ${relPath}:${imp.line}\n` +
            `   ${imp.statement};\n` +
            `   Razão: ${result.reason}\n` +
            `   Sugestão: ${result.suggestion}\n\n`,
        );
      }
    }
  }
  return errorCount;
}

export async function main(args) {
  let candidateFiles;
  if (args.length > 0) {
    candidateFiles = filterCandidateFiles(args);
    if (candidateFiles.length === 0) {
      // Lefthook passou args mas nenhum era .js custom — silently exit 0.
      return 0;
    }
  } else {
    candidateFiles = findAllCustomJsFiles(CUSTOM_MODULES_GLOB_ROOT);
  }
  const errorCount = await validateFiles(candidateFiles);
  if (errorCount === 0) {
    process.stdout.write(`✓ Validated ${candidateFiles.length} files, all imports resolve.\n`);
    return 0;
  }
  process.stderr.write(`\n❌ ${errorCount} import(s) bloqueado(s).\n`);
  return 1;
}

// Entry point: executa apenas se o script for chamado direto (não em import).
const isMain = (() => {
  if (typeof process === "undefined" || !process.argv[1]) return false;
  return resolve(process.argv[1]) === fileURLToPath(import.meta.url);
})();

if (isMain) {
  const args = process.argv.slice(2);
  main(args)
    .then((code) => process.exit(code))
    .catch((err) => {
      process.stderr.write(`Erro inesperado: ${err && err.stack ? err.stack : err}\n`);
      process.exit(2);
    });
}
