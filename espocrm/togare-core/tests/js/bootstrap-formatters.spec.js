import { describe, it, expect, beforeAll } from "vitest";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";

/**
 * Spec do bootstrap-formatters.js (Story 4a.4 T1).
 *
 * O bootstrap-formatters é IIFE plain JS (não ES6 module) — copiado literal
 * para o zip e carregado pelo EspoCRM via scriptList em metadata/app/client.json.
 * Não pode ser importado via `import` direto. Estratégia: ler o arquivo como
 * texto e avaliar via `new Function(src).call(window)` em jsdom; em seguida
 * testar via `window.TogareCore.formatters.*`.
 *
 * Cobre os helpers novos da Story 4a.4: formatAtoCodigo, daysUntil,
 * prioridadeIcon, truncate.
 */

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);
const BOOTSTRAP_PATH = resolve(
    __dirname,
    "../../src/files/client/custom/modules/togare-core/js/bootstrap-formatters.js",
);

describe("bootstrap-formatters.js — IIFE global helpers (Story 4a.4)", () => {
    let formatters;

    beforeAll(() => {
        // jsdom já está ativo via vitest config. Reset window.TogareCore para
        // evitar contaminação entre suites.
        delete window.TogareCore;
        const src = readFileSync(BOOTSTRAP_PATH, "utf8");
        // IIFE auto-executa; o spec só precisa avaliá-lo em window scope.
        // eslint-disable-next-line no-new-func
        new Function(src).call(window);
        formatters = window.TogareCore.formatters;
    });

    it("expõe os 9 helpers em window.TogareCore.formatters", () => {
        expect(formatters).toBeDefined();
        expect(typeof formatters.formatCpf).toBe("function");
        expect(typeof formatters.formatCnpj).toBe("function");
        expect(typeof formatters.formatCep).toBe("function");
        expect(typeof formatters.formatPhone).toBe("function");
        expect(typeof formatters.formatCnj).toBe("function");
        expect(typeof formatters.formatAtoCodigo).toBe("function");
        expect(typeof formatters.daysUntil).toBe("function");
        expect(typeof formatters.prioridadeIcon).toBe("function");
        expect(typeof formatters.truncate).toBe("function");
    });

    describe("formatAtoCodigo (F1.2)", () => {
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

        it.each(fixtures)("%s → %s (sincronizado com helpers/atoCodigo-formatter)", (codigo, expected) => {
            expect(formatters.formatAtoCodigo(codigo)).toBe(expected);
        });

        it("desconhecido → input cru", () => {
            expect(formatters.formatAtoCodigo("foo_bar")).toBe("foo_bar");
        });

        it("null / undefined / empty → string vazia", () => {
            expect(formatters.formatAtoCodigo(null)).toBe("");
            expect(formatters.formatAtoCodigo(undefined)).toBe("");
            expect(formatters.formatAtoCodigo("")).toBe("");
        });
    });

    describe("daysUntil (D10 — diff calendário simples)", () => {
        it("data futura → diff positivo em dias civis", () => {
            const ref = new Date("2026-05-05T12:00:00Z");
            // 20 dias à frente (calendário, não úteis).
            const target = new Date("2026-05-25T12:00:00Z");
            expect(formatters.daysUntil(target, ref)).toBe(20);
        });

        it("data passada → diff negativo", () => {
            const ref = new Date("2026-05-05T00:00:00Z");
            const target = new Date("2026-05-01T00:00:00Z");
            expect(formatters.daysUntil(target, ref)).toBe(-4);
        });

        it("hoje (mesma data, hora diferente) → 0", () => {
            const ref = new Date("2026-05-05T08:00:00Z");
            const target = new Date("2026-05-05T22:30:00Z");
            expect(formatters.daysUntil(target, ref)).toBe(0);
        });

        it("aceita string ISO", () => {
            const ref = new Date("2026-05-05T00:00:00Z");
            expect(formatters.daysUntil("2026-05-15", ref)).toBe(10);
        });

        it("input inválido → null", () => {
            expect(formatters.daysUntil(null)).toBe(null);
            expect(formatters.daysUntil(undefined)).toBe(null);
            expect(formatters.daysUntil("")).toBe(null);
            expect(formatters.daysUntil("not-a-date")).toBe(null);
        });
    });

    describe("prioridadeIcon (F1.11 — chip do CardDePrazo)", () => {
        it("retorna ícone Unicode por prioridade", () => {
            expect(formatters.prioridadeIcon("baixa")).toBe("▾");
            expect(formatters.prioridadeIcon("normal")).toBe("•");
            expect(formatters.prioridadeIcon("alta")).toBe("▴");
            expect(formatters.prioridadeIcon("urgente")).toBe("🔥");
        });

        it("desconhecido / null → string vazia (não throws)", () => {
            expect(formatters.prioridadeIcon("super-urgente")).toBe("");
            expect(formatters.prioridadeIcon(null)).toBe("");
            expect(formatters.prioridadeIcon(undefined)).toBe("");
            expect(formatters.prioridadeIcon("")).toBe("");
        });
    });

    describe("truncate (Pattern 11 Progressive Disclosure)", () => {
        it("string ≤ len → retorna inalterado", () => {
            expect(formatters.truncate("curto", 10)).toBe("curto");
        });

        it('string > len → primeiros N chars + " ..."', () => {
            const s = "a".repeat(250);
            const out = formatters.truncate(s, 200);
            expect(out).toHaveLength(200 + 4);
            expect(out.endsWith(" ...")).toBe(true);
        });

        it("len default = 200 quando ausente", () => {
            const s = "a".repeat(250);
            const out = formatters.truncate(s);
            expect(out).toHaveLength(200 + 4);
        });

        it("null / undefined → string vazia", () => {
            expect(formatters.truncate(null)).toBe("");
            expect(formatters.truncate(undefined)).toBe("");
        });

        it("len ≤ 0 ou inválido → fallback default 200", () => {
            const s = "a".repeat(250);
            expect(formatters.truncate(s, 0)).toHaveLength(204);
            expect(formatters.truncate(s, -5)).toHaveLength(204);
            expect(formatters.truncate(s, "abc")).toHaveLength(204);
        });
    });
});
