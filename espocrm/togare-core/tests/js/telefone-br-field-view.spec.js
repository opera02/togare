import { describe, it, expect, beforeEach } from "vitest";
import TelefoneBrFieldView from "../../src/files/client/custom/modules/togare-core/src/views/fields/telefone-br.js";

function buildView({ value = null, mode = "detail", el = null } = {}) {
    const model = {
        _data: { telefone: value },
        _lastSetOptions: null,
        get(k) {
            return this._data[k];
        },
        set(k, v, options) {
            this._data[k] = v;
            this._lastSetOptions = options || null;
        },
    };

    return new TelefoneBrFieldView({ name: "telefone", model, mode, el });
}

describe("TelefoneBrFieldView.getValueForDisplay", () => {
    it("11 digits -> (DD) XXXXX-XXXX", () => {
        const v = buildView({ value: "11987654321" });
        expect(v.getValueForDisplay()).toBe("(11) 98765-4321");
    });

    it("10 digits -> (DD) XXXX-XXXX", () => {
        const v = buildView({ value: "1133331234" });
        expect(v.getValueForDisplay()).toBe("(11) 3333-1234");
    });

    it("empty string -> ''", () => {
        const v = buildView({ value: "" });
        expect(v.getValueForDisplay()).toBe("");
    });

    it("invalid legacy value passes through", () => {
        const v = buildView({ value: "113333123" });
        expect(v.getValueForDisplay()).toBe("113333123");
    });
});

describe("TelefoneBrFieldView.afterRender in MODE_EDIT", () => {
    let container;
    let inputEl;

    beforeEach(() => {
        container = document.createElement("div");
        inputEl = document.createElement("input");
        inputEl.type = "text";
        container.appendChild(inputEl);
        document.body.appendChild(container);
    });

    it("auto-formats an 11-digit phone and keeps the model canonical", () => {
        const v = buildView({ mode: "edit", el: container });
        v.afterRender();

        inputEl.value = "11987654321";
        inputEl.dispatchEvent(new Event("input", { bubbles: true }));

        expect(inputEl.value).toBe("(11) 98765-4321");
        expect(v.model.get("telefone")).toBe("11987654321");
        expect(v.model._lastSetOptions).toMatchObject({
            ui: true,
            fromField: "telefone",
            action: "ui",
        });
        expect(v.model._lastSetOptions.fromView).toBe(v);
    });

    it("auto-formats a 10-digit phone", () => {
        const v = buildView({ mode: "edit", el: container });
        v.afterRender();

        inputEl.value = "1133331234";
        inputEl.dispatchEvent(new Event("input", { bubbles: true }));

        expect(inputEl.value).toBe("(11) 3333-1234");
        expect(v.model.get("telefone")).toBe("1133331234");
    });

    it("truncates beyond 11 digits", () => {
        const v = buildView({ mode: "edit", el: container });
        v.afterRender();

        inputEl.value = "11987654321abc99";
        inputEl.dispatchEvent(new Event("input", { bubbles: true }));

        expect(v.model.get("telefone")).toBe("11987654321");
    });

    it("masks an existing 11-digit initial value on render", () => {
        const v = buildView({ value: "11987654321", mode: "edit", el: container });
        v.afterRender();

        expect(inputEl.value).toBe("(11) 98765-4321");
    });

    it("masks an existing 10-digit initial value on render", () => {
        const v = buildView({ value: "1133331234", mode: "edit", el: container });
        v.afterRender();

        expect(inputEl.value).toBe("(11) 3333-1234");
    });

    it("fetch() returns only digits when an 11-digit DOM input is masked", () => {
        const v = buildView({ mode: "edit", el: container });
        v.afterRender();

        inputEl.value = "(11) 98765-4321";

        expect(v.fetch()).toEqual({ telefone: "11987654321" });
    });

    it("fetch() returns only digits when a 10-digit DOM input is masked", () => {
        const v = buildView({ mode: "edit", el: container });
        v.afterRender();

        inputEl.value = "(11) 3333-1234";

        expect(v.fetch()).toEqual({ telefone: "1133331234" });
    });

    it("sets inputmode='tel' in MODE_EDIT", () => {
        const v = buildView({ mode: "edit", el: container });
        v.afterRender();

        expect(inputEl.getAttribute("inputmode")).toBe("tel");
    });
});
