/**
 * Testes do `tools/validate-bundle-imports.mjs` (Story 4b.0 — A1).
 *
 * Roda via `node --test tools/tests/validate-bundle-imports.test.mjs`. Sem
 * deps NPM (`node:test` builtin do Node ≥18).
 */

import { describe, it } from "node:test";
import assert from "node:assert/strict";

import { parseImportsFromFile, validateImport } from "../validate-bundle-imports.mjs";

describe("validate-bundle-imports", () => {
  it("import válido — views/record/edit é whitelisted", () => {
    const result = validateImport("views/record/edit");
    assert.equal(result.ok, true);
  });

  it("import inválido — views/record/row é blacklisted com mensagem custom (regra v0.19.1)", () => {
    const result = validateImport("views/record/row");
    assert.equal(result.ok, false);
    assert.match(result.reason, /views\/record\/row.*NÃO existe.*EspoCRM 9\.x/);
    assert.match(result.suggestion, /buildRow override em views\/record\/list/);
  });

  it("import desconhecido — views/foo/bar parece path EspoCRM mas não está na whitelist → erro com sugestão de atualizar whitelist", () => {
    const result = validateImport("views/foo/bar");
    assert.equal(result.ok, false);
    assert.match(result.reason, /KNOWN_ESPOCRM_MODULES/);
    assert.match(result.suggestion, /grep -oE.*define.*views\/foo\/bar/);
    assert.match(result.suggestion, /adicione ao whitelist em tools\/validate-bundle-imports\.mjs/);
  });

  it("import togare-X: namespacing → SEMPRE ok (sem checagem)", () => {
    assert.equal(validateImport("togare-core:views/common/toast-togare").ok, true);
    assert.equal(validateImport("togare-djen:helpers/foo").ok, true);
    assert.equal(validateImport("togare-rbac:views/admin/bar").ok, true);
  });

  it("import relativo → SEMPRE ok (resolvido em build pelo bundler)", () => {
    assert.equal(validateImport("./helpers/x").ok, true);
    assert.equal(validateImport("../record/edit").ok, true);
    assert.equal(validateImport("/abs/path").ok, true);
  });

  it("arquivo sem imports → parseImportsFromFile retorna array vazio (e nada é validado)", () => {
    const content = `
// arquivo qualquer sem imports estáticos
const x = 1;
function foo() { return x; }
export default foo;
`;
    const imports = parseImportsFromFile(content);
    assert.deepEqual(imports, []);
  });

  it("parseImportsFromFile — extrai múltiplos imports + line numbers corretos", () => {
    const content =
      'import View from "view";\n' + // line 1
      'import EnumFieldView from "views/fields/enum";\n' + // line 2
      "// comentário\n" + // line 3
      'import ToastTogareView from "togare-core:views/common/toast-togare";\n' + // line 4
      "\n" + // line 5
      "import {\n" + // line 6
      "    getValidTransitions,\n" +
      "    requiresMotivo,\n" +
      '} from "togare-core:helpers/prazo-transitions";\n';

    const imports = parseImportsFromFile(content);
    assert.equal(imports.length, 4);
    assert.equal(imports[0].source, "view");
    assert.equal(imports[0].line, 1);
    assert.equal(imports[1].source, "views/fields/enum");
    assert.equal(imports[1].line, 2);
    assert.equal(imports[2].source, "togare-core:views/common/toast-togare");
    assert.equal(imports[2].line, 4);
    assert.equal(imports[3].source, "togare-core:helpers/prazo-transitions");
    assert.equal(imports[3].line, 6);
  });

  it("parseImportsFromFile — extrai import bare side-effect e re-exports estáticos", () => {
    const content =
      'import "views/record/row";\n' + // line 1
      'export { Foo } from "views/foo/bar";\n' + // line 2
      'export * as UiThing from "ui/thing";\n' + // line 3
      'const lazy = () => import("views/record/row");\n'; // dynamic import: fora do escopo

    const imports = parseImportsFromFile(content);
    assert.equal(imports.length, 3);
    assert.equal(imports[0].source, "views/record/row");
    assert.equal(imports[0].statement, 'import "views/record/row"');
    assert.equal(imports[0].line, 1);
    assert.equal(imports[1].source, "views/foo/bar");
    assert.equal(imports[1].statement, 'export { Foo } from "views/foo/bar"');
    assert.equal(imports[1].line, 2);
    assert.equal(imports[2].source, "ui/thing");
    assert.equal(imports[2].statement, 'export * as UiThing from "ui/thing"');
    assert.equal(imports[2].line, 3);

    assert.equal(validateImport(imports[0].source).ok, false);
    assert.equal(validateImport(imports[1].source).ok, false);
    assert.equal(validateImport(imports[2].source).ok, false);
  });

  it("npm packages (vitest, vue, etc) → ok silently (fora do escopo do bundler EspoCRM)", () => {
    // Estes caem no caso (6) do validateImport — não parecem path EspoCRM,
    // não são togare-*, não são relativos. NÃO erramos aqui porque o
    // espo-extension-tools nem tenta empacotá-los.
    assert.equal(validateImport("vitest").ok, true);
    assert.equal(validateImport("vue").ok, true);
    assert.equal(validateImport("@vue/runtime-core").ok, true);
    assert.equal(validateImport("lodash/fp").ok, true);
  });
});
