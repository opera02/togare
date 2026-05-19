/**
 * Testes do componente GateBannerView (Story 6.2).
 *
 * Cobre:
 *  - AC5 (copy nunca inline — classe i18n correta)
 *  - Variant válida → cssClass correto
 *  - Variant inválida → fallback 'financeiro-sem-contrato'
 *  - ctaLabel null → omitido em data()
 *  - Evento CTA click → trigger "cta:click:<target>"
 */

import { describe, it, expect, vi } from "vitest";
import GateBannerView from "togare-core:views/common/gate-banner";

/** Instancia GateBannerView com getLanguage stubado para retornar copy de teste. */
const makeView = (options = {}) => {
    const view = new GateBannerView(options);
    view.getLanguage = () => ({
        translate: (variant, category, scope) => {
            if (scope === "GateBanner" && category === "variants") {
                const map = {
                    "financeiro-sem-contrato": {
                        text: "Texto OAB Art. 22",
                        ctaLabel: "Cadastrar contrato agora",
                        ctaTarget: "cadastrar-contrato",
                    },
                    "licenca-expirada": {
                        text: "Licença expirada.",
                        ctaLabel: "Renovar licença",
                        ctaTarget: "renovar-licenca",
                    },
                    "pre-requisito-entidade": {
                        text: "Pré-requisito não preenchido.",
                        ctaLabel: null,
                        ctaTarget: null,
                    },
                };
                return map[variant] || {};
            }
            return "";
        },
    });
    return view;
};

describe("GateBannerView - setup", () => {
    it("variant válida 'financeiro-sem-contrato' é aceita", () => {
        const view = makeView({ variant: "financeiro-sem-contrato" });
        view.setup();
        expect(view.variant).toBe("financeiro-sem-contrato");
    });

    it("variant válida 'licenca-expirada' é aceita", () => {
        const view = makeView({ variant: "licenca-expirada" });
        view.setup();
        expect(view.variant).toBe("licenca-expirada");
    });

    it("variant válida 'pre-requisito-entidade' é aceita", () => {
        const view = makeView({ variant: "pre-requisito-entidade" });
        view.setup();
        expect(view.variant).toBe("pre-requisito-entidade");
    });

    it("variant inválida usa fallback 'financeiro-sem-contrato' e emite warning", () => {
        const warnSpy = vi.spyOn(console, "warn").mockImplementation(() => {});
        const view = makeView({ variant: "inexistente" });
        view.setup();
        expect(view.variant).toBe("financeiro-sem-contrato");
        expect(warnSpy).toHaveBeenCalledWith(
            expect.stringContaining("variant inválida"),
        );
        warnSpy.mockRestore();
    });

    it("sem variant → usa fallback 'financeiro-sem-contrato'", () => {
        const view = makeView({});
        view.setup();
        expect(view.variant).toBe("financeiro-sem-contrato");
    });
});

describe("GateBannerView - data()", () => {
    it("data() retorna cssClass com prefixo correto", () => {
        const view = makeView({ variant: "financeiro-sem-contrato" });
        view.setup();
        const d = view.data();
        expect(d.cssClass).toBe(
            "togare-gate-banner togare-gate-banner--financeiro-sem-contrato",
        );
    });

    it("data() retorna text do i18n (AC5: copy nunca inline)", () => {
        const view = makeView({ variant: "financeiro-sem-contrato" });
        view.setup();
        const d = view.data();
        expect(d.text).toBe("Texto OAB Art. 22");
        expect(d.text).not.toBe("");
    });

    it("data() retorna ctaLabel da variant com CTA", () => {
        const view = makeView({ variant: "financeiro-sem-contrato" });
        view.setup();
        const d = view.data();
        expect(d.ctaLabel).toBe("Cadastrar contrato agora");
        expect(d.ctaTarget).toBe("cadastrar-contrato");
    });

    it("data() retorna ctaLabel null para variant sem CTA", () => {
        const view = makeView({ variant: "pre-requisito-entidade" });
        view.setup();
        const d = view.data();
        expect(d.ctaLabel).toBeNull();
        expect(d.ctaTarget).toBeNull();
    });

    it("data() retorna variant como campo separado", () => {
        const view = makeView({ variant: "licenca-expirada" });
        view.setup();
        const d = view.data();
        expect(d.variant).toBe("licenca-expirada");
    });
});

describe("GateBannerView - events (CTA click)", () => {
    it("events map contém handler para .togare-gate-banner__cta", () => {
        const view = makeView({ variant: "financeiro-sem-contrato" });
        expect(typeof view.events).toBe("object");
        const hasCtaHandler = Object.keys(view.events).some((k) =>
            k.includes("togare-gate-banner__cta"),
        );
        expect(hasCtaHandler).toBe(true);
    });

    it("_onCtaClick dispara trigger 'cta:click:<target>'", () => {
        const view = makeView({ variant: "financeiro-sem-contrato" });
        view.setup();

        const triggered = [];
        view.trigger = (event) => triggered.push(event);

        const fakeEvent = {
            currentTarget: { dataset: { ctaTarget: "cadastrar-contrato" } },
        };
        view._onCtaClick(fakeEvent);

        expect(triggered).toContain("cta:click:cadastrar-contrato");
    });

    it("_onCtaClick sem dataset.ctaTarget não dispara trigger", () => {
        const view = makeView({ variant: "pre-requisito-entidade" });
        view.setup();

        const triggered = [];
        view.trigger = (event) => triggered.push(event);

        const fakeEvent = { currentTarget: { dataset: {} } };
        view._onCtaClick(fakeEvent);

        expect(triggered).toHaveLength(0);
    });
});
