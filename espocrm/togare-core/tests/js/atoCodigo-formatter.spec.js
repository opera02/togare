import { describe, it, expect } from "vitest";
import {
  ATO_CODIGO_LABELS,
  formatAtoCodigo,
} from "../../src/files/client/custom/modules/togare-core/src/helpers/atoCodigo-formatter.js";

describe("formatAtoCodigo — Story 4a.4 F1.2 (label pt-BR para atoCodigo)", () => {
  const fixtures = [
    ["impugnacao_cumprimento", "Impugnação ao cumprimento de sentença"],
    ["cumprimento_sentenca", "Cumprimento de sentença"],
    ["embargos_declaracao", "Embargos de Declaração"],
    ["agravo_instrumento", "Agravo de Instrumento"],
    ["agravo_interno", "Agravo Interno"],
    ["quesitos_pericia", "Quesitos de perícia"],
    ["replica", "Réplica"],
    ["recurso_apelacao", "Recurso de Apelação"],
    ["contestacao", "Contestação"],
    ["manifestacao_geral_intimacao", "Manifestação geral / intimação"],
    ["manifestacao_generica", "Manifestação genérica"],
  ];

  it.each(fixtures)("%s → %s", (codigo, expected) => {
    expect(formatAtoCodigo(codigo)).toBe(expected);
  });

  it("dictionary tem exatamente os 11 valores do DjenAtoClassifier (Dev Notes §2)", () => {
    expect(Object.keys(ATO_CODIGO_LABELS).sort()).toEqual(
      fixtures.map(([codigo]) => codigo).sort(),
    );
  });

  it("graceful fallback — atoCodigo desconhecido retorna o input cru", () => {
    expect(formatAtoCodigo("ato_que_nao_existe")).toBe("ato_que_nao_existe");
  });

  it("null / undefined / string vazia → string vazia", () => {
    expect(formatAtoCodigo(null)).toBe("");
    expect(formatAtoCodigo(undefined)).toBe("");
    expect(formatAtoCodigo("")).toBe("");
  });

  it("ATO_CODIGO_LABELS é frozen (immutable — single source of truth)", () => {
    expect(Object.isFrozen(ATO_CODIGO_LABELS)).toBe(true);
  });
});
