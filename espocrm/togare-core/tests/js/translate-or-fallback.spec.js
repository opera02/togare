import { describe, it, expect } from "vitest";
import { translateOrFallback } from "../../src/files/client/custom/modules/togare-core/src/helpers/translate-or-fallback.js";

/**
 * Cobertura do helper consolidado da Story 4b.0 (Df12) — substitui as 3
 * cópias divergentes de `_translateOrFallback` que viviam em
 * status-selector.js + prazo/record/{detail,edit}.js.
 */
describe("translateOrFallback", () => {
    it("view ausente → retorna fallback", () => {
        expect(translateOrFallback(null, "k", "messages", "Prazo", "FB")).toBe("FB");
        expect(translateOrFallback(undefined, "k", "messages", "Prazo", "FB")).toBe("FB");
    });

    it("view sem translate nem getLanguage → retorna fallback", () => {
        const view = {};
        expect(translateOrFallback(view, "k", "messages", "Prazo", "FB")).toBe("FB");
    });

    it("view.translate retorna string ≠ key → essa string", () => {
        const view = {
            translate: (key, _cat, _sc) => (key === "k" ? "TRADUZIDO" : key),
        };
        expect(translateOrFallback(view, "k", "messages", "Prazo", "FB")).toBe("TRADUZIDO");
    });

    it("view.translate retorna a própria key → tenta getLanguage", () => {
        const view = {
            translate: (key) => key, // não traduz
            getLanguage: () => ({
                translate: (key) => (key === "k" ? "VIA_LANG" : key),
            }),
        };
        expect(translateOrFallback(view, "k", "messages", "Prazo", "FB")).toBe("VIA_LANG");
    });

    it("getLanguage().translate retorna string ≠ key → essa string", () => {
        const view = {
            // sem .translate na view; só getLanguage()
            getLanguage: () => ({
                translate: (key) => (key === "k" ? "VIA_LANG_DIRETO" : key),
            }),
        };
        expect(translateOrFallback(view, "k", "messages", "Prazo", "FB")).toBe("VIA_LANG_DIRETO");
    });

    it("ambos retornam a key → fallback", () => {
        const view = {
            translate: (key) => key,
            getLanguage: () => ({ translate: (key) => key }),
        };
        expect(translateOrFallback(view, "k", "messages", "Prazo", "FB")).toBe("FB");
    });

    it("view.translate lança Exception → catch e fallback (graceful)", () => {
        const view = {
            translate: () => {
                throw new Error("i18n boom");
            },
            getLanguage: () => ({ translate: () => "irrelevante" }),
        };
        // O try-catch envolve AMBAS as tentativas; quando a primeira lança,
        // pula direto pro fallback (graceful degradation).
        expect(translateOrFallback(view, "k", "messages", "Prazo", "FB")).toBe("FB");
    });

    it("view.translate retorna objeto (não-string) → fallback (path do antigo subCategory simplificado)", () => {
        const view = {
            translate: () => ({ k: "valor-aninhado" }), // EspoCRM às vezes retorna objeto inteiro de category
        };
        // Helper consolidado da 4b.0 NÃO mais faz lookup nested via subCategory.
        // Comportamento: ignora o objeto e cai no fallback.
        expect(translateOrFallback(view, "k", "messages", "Prazo", "FB")).toBe("FB");
    });
});
