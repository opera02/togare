/**
 * Testes do helper puro de contraste WCAG (Story 7a.1).
 * Reusado pelo gate automatizado da Story 7a.6 — contrato estável.
 */

import { describe, it, expect } from "vitest";
import {
    hexToRgb,
    relativeLuminance,
    contrastRatio,
    meetsAaaOnBackground,
    AAA_NORMAL_TEXT,
} from "togare-portal-ui:helpers/contrast";

describe("hexToRgb", () => {
    it("aceita #RRGGBB", () => {
        expect(hexToRgb("#0d47a1")).toEqual({ r: 13, g: 71, b: 161 });
    });

    it("aceita #RGB curto", () => {
        expect(hexToRgb("#fff")).toEqual({ r: 255, g: 255, b: 255 });
    });

    it("aceita sem # e com espaços", () => {
        expect(hexToRgb("  0d47a1 ")).toEqual({ r: 13, g: 71, b: 161 });
    });

    it("rejeita hex inválido → null", () => {
        expect(hexToRgb("#zzz")).toBeNull();
        expect(hexToRgb("azul")).toBeNull();
        expect(hexToRgb(null)).toBeNull();
        expect(hexToRgb(123)).toBeNull();
    });
});

describe("relativeLuminance", () => {
    it("preto = 0, branco = 1", () => {
        expect(relativeLuminance({ r: 0, g: 0, b: 0 })).toBeCloseTo(0, 5);
        expect(relativeLuminance({ r: 255, g: 255, b: 255 })).toBeCloseTo(1, 5);
    });
});

describe("contrastRatio", () => {
    it("preto×branco = 21", () => {
        expect(contrastRatio("#000000", "#ffffff")).toBeCloseTo(21, 1);
    });

    it("cor default curada #0d47a1 sobre branco >= 7:1 (AAA)", () => {
        const r = contrastRatio("#ffffff", "#0d47a1");
        expect(r).toBeGreaterThanOrEqual(AAA_NORMAL_TEXT);
    });

    it("hex inválido → null", () => {
        expect(contrastRatio("#zzz", "#fff")).toBeNull();
    });
});

describe("meetsAaaOnBackground", () => {
    it("default curado #0d47a1 atende AAA com texto branco", () => {
        expect(meetsAaaOnBackground("#0d47a1")).toBe(true);
    });

    it("amarelo #ffff00 NÃO atende AAA com texto branco", () => {
        expect(meetsAaaOnBackground("#ffff00")).toBe(false);
    });

    it("azul claro #2563eb (5.17:1) NÃO atende AAA", () => {
        expect(meetsAaaOnBackground("#2563eb")).toBe(false);
    });

    it("hex inválido → false (conservador)", () => {
        expect(meetsAaaOnBackground("nope")).toBe(false);
    });
});
