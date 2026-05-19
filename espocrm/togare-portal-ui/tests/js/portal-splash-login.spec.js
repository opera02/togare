/**
 * Testes do TogarePortalSplashLoginView (Story 7a.1).
 *
 * Cobre:
 *  - AC3: contexto CRM (sem portalId/path) → NÃO aplica branding (passthrough).
 *  - AC2: contexto Portal → aplica classe + frase + telefone.
 *  - AC1/AC6: frase usa config; vazia → default i18n; telefone vazio → placeholder i18n.
 *  - AC4/A2: copy vem de i18n/config, nunca string inline.
 *  - getLogoSrc: portal+logoId → entry point; senão → super (stock).
 *  - onRemove limpa a classe do body.
 */

import { describe, it, expect, beforeEach } from "vitest";
import LoginView from "togare-portal-ui:views/portal/login";

const I18N = {
    PortalSplash: {
        messages: {
            splashDefault: "DEFAULT_WELCOME_FROM_I18N",
            phoneFallback: "Ligue: {telefone}",
            phonePlaceholder: "PLACEHOLDER_FROM_I18N",
        },
    },
};

const makeView = (config = {}) => {
    const element = document.createElement("div");
    document.body.appendChild(element);

    return new LoginView({ element, config, lang: I18N });
};

beforeEach(() => {
    document.body.className = "";
    document.body.style.removeProperty("--togare-portal-primary");
    document.body.innerHTML = "";
});

describe("AC3 — contexto CRM (passthrough)", () => {
    it("sem portalId e sem path /portal → não adiciona a classe do splash", () => {
        const view = makeView({});
        view.afterRender();

        expect(document.body.classList.contains("togare-portal-splash")).toBe(
            false,
        );
        expect(view._superAfterRenderCalled).toBe(true);
    });

    it("getLogoSrc fora do portal → logo stock", () => {
        const view = makeView({ togarePortalSplashLogoId: "abc" });
        expect(view.getLogoSrc()).toBe("STOCK_LOGO_SRC");
    });
});

describe("AC2/AC1 — contexto Portal", () => {
    it("portalId presente → aplica classe + cor + frase configurada", () => {
        const view = makeView({
            portalId: "p1",
            togarePortalSplashPrimaryColor: "#0d47a1",
            togarePortalSplashWelcome: "Bem-vindo, cliente!",
        });
        view.afterRender();

        expect(document.body.classList.contains("togare-portal-splash")).toBe(
            true,
        );
        expect(
            document.body.style.getPropertyValue("--togare-portal-primary"),
        ).toBe("#0d47a1");

        const welcome = view.element.querySelector(
            ".togare-portal-splash__welcome",
        );
        expect(welcome).not.toBeNull();
        expect(welcome.textContent).toBe("Bem-vindo, cliente!");
    });

    it("AC1 — frase vazia cai no default curado do i18n (não inline)", () => {
        const view = makeView({ portalId: "p1" });
        view.afterRender();

        const welcome = view.element.querySelector(
            ".togare-portal-splash__welcome",
        );
        expect(welcome.textContent).toBe("DEFAULT_WELCOME_FROM_I18N");
    });

    it("AC6 — telefone configurado aparece no template i18n", () => {
        const view = makeView({
            portalId: "p1",
            togarePortalSplashPhone: "(11) 4002-8922",
        });
        view.afterRender();

        const phone = view.element.querySelector(
            ".togare-portal-splash__phone",
        );
        expect(phone).not.toBeNull();
        expect(phone.textContent).toBe("Ligue: (11) 4002-8922");
    });

    it("AC6 — telefone vazio usa placeholder neutro do i18n (nunca vazio)", () => {
        const view = makeView({ portalId: "p1" });
        view.afterRender();

        const phone = view.element.querySelector(
            ".togare-portal-splash__phone",
        );
        expect(phone.textContent).toBe("Ligue: PLACEHOLDER_FROM_I18N");
        expect(phone.textContent).not.toContain("undefined");
    });

    it("idempotente — afterRender 2x não duplica frase/telefone", () => {
        const view = makeView({ portalId: "p1" });
        view.afterRender();
        view.afterRender();

        expect(
            view.element.querySelectorAll(".togare-portal-splash__welcome")
                .length,
        ).toBe(1);
        expect(
            view.element.querySelectorAll(".togare-portal-splash__phone")
                .length,
        ).toBe(1);
    });

    it("getLogoSrc com portalId+logoId → entry point público do módulo", () => {
        const view = makeView({
            portalId: "p1",
            togarePortalSplashLogoId: "att123",
        });
        expect(view.getLogoSrc()).toBe(
            "/?entryPoint=PortalSplashLogoImage&id=att123",
        );
    });

    it("getLogoSrc portal sem logoId → logo stock", () => {
        const view = makeView({ portalId: "p1" });
        expect(view.getLogoSrc()).toBe("STOCK_LOGO_SRC");
    });
});

describe("onRemove", () => {
    it("limpa a classe e a variável CSS do body", () => {
        const view = makeView({ portalId: "p1" });
        view.afterRender();
        expect(document.body.classList.contains("togare-portal-splash")).toBe(
            true,
        );

        view.onRemove();
        expect(document.body.classList.contains("togare-portal-splash")).toBe(
            false,
        );
        expect(view._superOnRemoveCalled).toBe(true);
    });
});
