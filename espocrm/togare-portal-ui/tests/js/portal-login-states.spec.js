/**
 * Story 7a.2 — estado de erro do login do Portal (AC6, C5).
 *
 * REGRESSÃO 2026-05-17 (smoke browser Felipe): a 1ª implementação
 * sobrescrevia `proceed()`/`login()` (métodos do CAMINHO DE SUCESSO da
 * autenticação) → quebrava o boot do CRM no navegador (bounce pós-login),
 * invisível a vitest/CLI (classe saga GateBanner 6.2). Fix: override
 * SOMENTE de `onFail()` — funil de FALHA de login do stock, nunca roda no
 * sucesso. Estes testes travam o novo contrato:
 *  - contexto Portal → onFail mostra a copy acolhedora `senhaErrada`
 *    (nunca "Credenciais inválidas"), marca has-error, foca o campo,
 *    NÃO chama super.onFail.
 *  - contexto CRM → onFail delega 100% ao super (AC3, zero efeito).
 *
 * Baseline 27/27 da 7a.1 (demais specs) preservado — aditivo.
 */

import { describe, it, expect, beforeEach } from "vitest";
import LoginView from "togare-portal-ui:views/portal/login";

const I18N = {
    PortalSplash: {
        messages: {
            splashDefault: "WELCOME",
            phoneFallback: "Ligue: {telefone}",
            phonePlaceholder: "PLACEHOLDER",
            senhaErrada:
                "Não conseguimos entrar com esses dados. Verifique a senha que você criou. Se esqueceu, ligue para o escritório.",
        },
    },
};

let uiErrors;

const makeView = (config = {}) => {
    const element = document.createElement("div");
    element.innerHTML =
        '<div id="login"><div class="form-group">' +
        '<input name="username" /><input name="password" /></div></div>';
    document.body.appendChild(element);

    return new LoginView({ element, config, lang: I18N });
};

beforeEach(() => {
    document.body.innerHTML = "";
    uiErrors = [];
    window.Espo = { Ui: { error: (m) => uiErrors.push(m) } };
});

describe("AC3 — contexto CRM (passthrough total)", () => {
    it("onFail fora do Portal delega ao super (sem tocar UI/DOM)", () => {
        const view = makeView({}); // sem portalId, path "/"
        view.onFail("wrongUsernamePassword");

        expect(view._superOnFailCalled).toBe("wrongUsernamePassword");
        expect(uiErrors).toHaveLength(0);
    });

    it("não há override de proceed/login (fluxo de auth 100% stock)", () => {
        const view = makeView({});
        // Os métodos do caminho de sucesso devem ser os HERDADOS do stock,
        // não redefinidos pela subclasse (proteção anti-regressão CRM).
        const proto = Object.getPrototypeOf(view);
        expect(Object.prototype.hasOwnProperty.call(proto, "proceed")).toBe(
            false,
        );
        expect(Object.prototype.hasOwnProperty.call(proto, "login")).toBe(
            false,
        );
    });
});

describe("AC6 — contexto Portal (copy acolhedora)", () => {
    it("onFail mostra senhaErrada, marca has-error, foca campo, não chama super", () => {
        const view = makeView({ portalId: "p1" });
        view.onFail("wrongUsernamePassword");

        expect(uiErrors).toContain(I18N.PortalSplash.messages.senhaErrada);
        expect(uiErrors.join(" ")).not.toMatch(/credenciais/i);
        expect(uiErrors.join(" ")).not.toMatch(/acesso negado/i);
        expect(view._superOnFailCalled).toBeUndefined();

        const cell = view.element.querySelector(".form-group");
        expect(cell.classList.contains("has-error")).toBe(true);
        expect(document.activeElement).toBe(
            view.element.querySelector('input[name="password"]'),
        );
    });

    it("qualquer msg de falha no Portal vira a copy acolhedora única", () => {
        const view = makeView({ portalId: "p1" });
        view.onFail("loginError");
        expect(uiErrors).toEqual([I18N.PortalSplash.messages.senhaErrada]);
    });
});
