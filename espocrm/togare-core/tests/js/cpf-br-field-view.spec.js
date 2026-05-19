import { describe, it, expect, beforeEach, vi } from "vitest";
import CpfBrFieldView from "../../src/files/client/custom/modules/togare-core/src/views/fields/cpf-br.js";

function buildView({ value = null, mode = "detail", el = null } = {}) {
    const model = {
        _data: { cpf: value },
        _lastSetOptions: null,
        get(k) {
            return this._data[k];
        },
        set(k, v, options) {
            this._data[k] = v;
            this._lastSetOptions = options || null;
        },
    };

    return new CpfBrFieldView({ name: "cpf", model, mode, el });
}

describe("CpfBrFieldView.getValueForDisplay", () => {
    it("11 digits -> XXX.XXX.XXX-XX", () => {
        const v = buildView({ value: "12345678909" });
        expect(v.getValueForDisplay()).toBe("123.456.789-09");
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
        const v = buildView({ value: "123456789" });
        expect(v.getValueForDisplay()).toBe("123456789");
    });
});

describe("CpfBrFieldView.afterRender in MODE_EDIT", () => {
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

        inputEl.value = "12345678909";
        inputEl.dispatchEvent(new Event("input", { bubbles: true }));

        expect(inputEl.value).toBe("123.456.789-09");
        expect(v.model.get("cpf")).toBe("12345678909");
        expect(v.model._lastSetOptions).toMatchObject({
            ui: true,
            fromField: "cpf",
            action: "ui",
        });
        expect(v.model._lastSetOptions.fromView).toBe(v);
    });

    it("truncates beyond 11 digits", () => {
        const v = buildView({ mode: "edit", el: container });
        v.afterRender();

        inputEl.value = "12345678909abc99";
        inputEl.dispatchEvent(new Event("input", { bubbles: true }));

        expect(v.model.get("cpf")).toBe("12345678909");
        expect(inputEl.value).toBe("123.456.789-09");
    });

    it("masks an existing initial value on render", () => {
        const v = buildView({ value: "52998224725", mode: "edit", el: container });
        v.afterRender();

        expect(inputEl.value).toBe("529.982.247-25");
    });

    it("fetch() returns only digits when the DOM input is masked", () => {
        const v = buildView({ mode: "edit", el: container });
        v.afterRender();

        inputEl.value = "529.982.247-25";

        expect(v.fetch()).toEqual({ cpf: "52998224725" });
    });

    it("fetch() returns null for an empty DOM input", () => {
        const v = buildView({ mode: "edit", el: container });
        v.afterRender();

        inputEl.value = "";

        expect(v.fetch()).toEqual({ cpf: null });
    });

    it("sets inputmode='numeric' in MODE_EDIT", () => {
        const v = buildView({ mode: "edit", el: container });
        v.afterRender();

        expect(inputEl.getAttribute("inputmode")).toBe("numeric");
    });

    it("MODE_DETAIL does not attach the input listener", () => {
        const v = buildView({ mode: "detail", el: container });
        v.afterRender();

        inputEl.value = "12345678909";
        inputEl.dispatchEvent(new Event("input", { bubbles: true }));

        expect(v.model.get("cpf")).toBeNull();
    });
});

describe("CpfBrFieldView.validate", () => {
    it("blocks invalid CPF with inline validation message", () => {
        const v = buildView({ value: "12345678900" });
        v.showValidationMessage = vi.fn();

        expect(v.validate()).toBe(true);
        expect(v.showValidationMessage).toHaveBeenCalledWith("CPF inválido — confira o número e tente de novo.");
    });

    it("allows valid CPF", () => {
        const v = buildView({ value: "52998224725" });
        v.showValidationMessage = vi.fn();

        expect(v.validate()).toBe(false);
        expect(v.showValidationMessage).not.toHaveBeenCalled();
    });

    it("allows empty CPF because the field is optional for Funcionario", () => {
        const v = buildView({ value: null });
        v.showValidationMessage = vi.fn();

        expect(v.validate()).toBe(false);
        expect(v.showValidationMessage).not.toHaveBeenCalled();
    });
});
