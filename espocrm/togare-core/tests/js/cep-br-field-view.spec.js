import { describe, it, expect, beforeEach } from "vitest";
import CepBrFieldView from "../../src/files/client/custom/modules/togare-core/src/views/fields/cep-br.js";

function buildView({ value = null, mode = "detail", el = null } = {}) {
    const model = {
        _data: { cep: value },
        _lastSetOptions: null,
        get(k) {
            return this._data[k];
        },
        set(k, v, options) {
            this._data[k] = v;
            this._lastSetOptions = options || null;
        },
    };

    return new CepBrFieldView({ name: "cep", model, mode, el });
}

describe("CepBrFieldView.getValueForDisplay", () => {
    it("8 digits -> XXXXX-XXX", () => {
        const v = buildView({ value: "01310100" });
        expect(v.getValueForDisplay()).toBe("01310-100");
    });

    it("empty string -> ''", () => {
        const v = buildView({ value: "" });
        expect(v.getValueForDisplay()).toBe("");
    });

    it("invalid legacy value passes through", () => {
        const v = buildView({ value: "0131010" });
        expect(v.getValueForDisplay()).toBe("0131010");
    });
});

describe("CepBrFieldView.afterRender in MODE_EDIT", () => {
    let container;
    let inputEl;

    beforeEach(() => {
        container = document.createElement("div");
        inputEl = document.createElement("input");
        inputEl.type = "text";
        container.appendChild(inputEl);
        document.body.appendChild(container);
    });

    it("auto-formats input and keeps the model canonical", () => {
        const v = buildView({ mode: "edit", el: container });
        v.afterRender();

        inputEl.value = "01310100";
        inputEl.dispatchEvent(new Event("input", { bubbles: true }));

        expect(inputEl.value).toBe("01310-100");
        expect(v.model.get("cep")).toBe("01310100");
        expect(v.model._lastSetOptions).toMatchObject({
            ui: true,
            fromField: "cep",
            action: "ui",
        });
        expect(v.model._lastSetOptions.fromView).toBe(v);
    });

    it("truncates beyond 8 digits", () => {
        const v = buildView({ mode: "edit", el: container });
        v.afterRender();

        inputEl.value = "01310100abc99";
        inputEl.dispatchEvent(new Event("input", { bubbles: true }));

        expect(v.model.get("cep")).toBe("01310100");
    });

    it("fetch() returns only digits when the DOM input is masked", () => {
        const v = buildView({ mode: "edit", el: container });
        v.afterRender();

        inputEl.value = "01310-100";

        expect(v.fetch()).toEqual({ cep: "01310100" });
    });

    it("sets inputmode='numeric' in MODE_EDIT", () => {
        const v = buildView({ mode: "edit", el: container });
        v.afterRender();

        expect(inputEl.getAttribute("inputmode")).toBe("numeric");
    });
});
