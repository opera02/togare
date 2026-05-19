/**
 * Testes do TogarePortalAppearanceView (Story 7a.1, painel admin AC5).
 *
 * Cobre:
 *  - cor ruim (<7:1) → aviso pt-BR injetado (não-bloqueante).
 *  - cor boa (>=7:1) → sem aviso.
 *  - troca de cor re-renderiza o aviso (idempotente).
 *  - save() com cor ruim → window.Espo.Ui.warning chamado E super.save() chamado
 *    (NÃO bloqueia o save — AC5).
 */

import { describe, it, expect, beforeEach, vi } from "vitest";
import AppearanceView from "togare-portal-ui:views/admin/portal-appearance";

const I18N = {
    Settings: {
        messages: {
            portalSplashContrastWarning: "AVISO_CONTRASTE_PTBR",
        },
    },
};

/** Model stub mínimo (get/set/on/emit). */
const makeModel = (attrs = {}) => {
    const data = { ...attrs };
    const handlers = {};

    return {
        get: (k) => data[k],
        set(k, v) {
            data[k] = v;
            (handlers["change:" + k] || []).forEach((cb) => cb());
        },
        on(evt, cb) {
            (handlers[evt] = handlers[evt] || []).push(cb);
        },
    };
};

const makeView = (color) => {
    const element = document.createElement("div");
    document.body.appendChild(element);
    const model = makeModel({ togarePortalSplashPrimaryColor: color });

    return new AppearanceView({ element, model, lang: I18N });
};

beforeEach(() => {
    document.body.innerHTML = "";
    delete window.Espo;
});

describe("AC5 — aviso de contraste não-bloqueante", () => {
    it("cor ruim (#ffff00) → aviso pt-BR do i18n é injetado", () => {
        const view = makeView("#ffff00");
        view.afterRender();

        const notice = view.element.querySelector(
            "#togare-portal-appearance-contrast-notice",
        );
        expect(notice).not.toBeNull();
        expect(notice.textContent).toBe("AVISO_CONTRASTE_PTBR");
        expect(notice.getAttribute("role")).toBe("status");
    });

    it("cor boa (#0d47a1) → nenhum aviso", () => {
        const view = makeView("#0d47a1");
        view.afterRender();

        expect(
            view.element.querySelector(
                "#togare-portal-appearance-contrast-notice",
            ),
        ).toBeNull();
    });

    it("trocar cor ruim → boa remove o aviso (idempotente, sem duplicar)", () => {
        const view = makeView("#ffff00");
        view.setup();
        view.afterRender();
        expect(
            view.element.querySelectorAll(
                "#togare-portal-appearance-contrast-notice",
            ).length,
        ).toBe(1);

        view.model.set("togarePortalSplashPrimaryColor", "#0d47a1");
        expect(
            view.element.querySelector(
                "#togare-portal-appearance-contrast-notice",
            ),
        ).toBeNull();
    });

    it("save() com cor ruim → warning E super.save() (NÃO bloqueia)", async () => {
        const warning = vi.fn();
        window.Espo = { Ui: { warning } };

        const view = makeView("#ffff00");
        const result = await view.save({ foo: 1 });

        expect(warning).toHaveBeenCalledWith("AVISO_CONTRASTE_PTBR");
        expect(view._superSaveCalled).toBe(true);
        expect(result).toEqual({ foo: 1 });
    });

    it("save() com cor boa → sem warning, super.save() chamado", async () => {
        const warning = vi.fn();
        window.Espo = { Ui: { warning } };

        const view = makeView("#0d47a1");
        await view.save();

        expect(warning).not.toHaveBeenCalled();
        expect(view._superSaveCalled).toBe(true);
    });
});
