import { describe, it, expect, beforeEach } from "vitest";
import CnpjBrFieldView from "../../src/files/client/custom/modules/togare-core/src/views/fields/cnpj-br.js";

function buildView({ value = null, mode = "detail", el = null } = {}) {
    const model = {
        _data: { cnpj: value },
        _lastSetOptions: null,
        get(k) {
            return this._data[k];
        },
        set(k, v, options) {
            this._data[k] = v;
            this._lastSetOptions = options || null;
        },
    };

    return new CnpjBrFieldView({ name: "cnpj", model, mode, el });
}

describe("CnpjBrFieldView.getValueForDisplay", () => {
    it("14 digits -> XX.XXX.XXX/XXXX-XX", () => {
        const v = buildView({ value: "11222333000181" });
        expect(v.getValueForDisplay()).toBe("11.222.333/0001-81");
    });

    it("empty string -> ''", () => {
        const v = buildView({ value: "" });
        expect(v.getValueForDisplay()).toBe("");
    });

    it("null -> ''", () => {
        const v = buildView({ value: null });
        expect(v.getValueForDisplay()).toBe("");
    });

    it("invalid legacy value passes through", () => {
        const v = buildView({ value: "1122233300018" });
        expect(v.getValueForDisplay()).toBe("1122233300018");
    });
});

describe("CnpjBrFieldView.afterRender in MODE_EDIT", () => {
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

        inputEl.value = "11222333000181";
        inputEl.dispatchEvent(new Event("input", { bubbles: true }));

        expect(inputEl.value).toBe("11.222.333/0001-81");
        expect(v.model.get("cnpj")).toBe("11222333000181");
        expect(v.model._lastSetOptions).toMatchObject({
            ui: true,
            fromField: "cnpj",
            action: "ui",
        });
        expect(v.model._lastSetOptions.fromView).toBe(v);
    });

    it("truncates beyond 14 digits", () => {
        const v = buildView({ mode: "edit", el: container });
        v.afterRender();

        inputEl.value = "1122233300018199";
        inputEl.dispatchEvent(new Event("input", { bubbles: true }));

        expect(v.model.get("cnpj")).toBe("11222333000181");
    });

    it("masks an existing initial value on render", () => {
        const v = buildView({ value: "11222333000181", mode: "edit", el: container });
        v.afterRender();

        expect(inputEl.value).toBe("11.222.333/0001-81");
    });

    it("fetch() returns only digits when the DOM input is masked", () => {
        const v = buildView({ mode: "edit", el: container });
        v.afterRender();

        inputEl.value = "11.222.333/0001-81";

        expect(v.fetch()).toEqual({ cnpj: "11222333000181" });
    });

    it("sets inputmode='numeric' in MODE_EDIT", () => {
        const v = buildView({ mode: "edit", el: container });
        v.afterRender();

        expect(inputEl.getAttribute("inputmode")).toBe("numeric");
    });
});
