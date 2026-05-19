import { describe, it, expect } from "vitest";
import AtoCodigoFieldView from "../../src/files/client/custom/modules/togare-core/src/views/prazo/fields/ato-codigo.js";

function makeView(value) {
    return new AtoCodigoFieldView({
        name: "atoCodigo",
        model: {
            _data: { atoCodigo: value },
            get(k) {
                return this._data[k];
            },
        },
    });
}

describe("AtoCodigoFieldView (Story 4a.4 F1.2)", () => {
    it("valor mapeado → label pt-BR", () => {
        expect(makeView("manifestacao_generica").getValueForDisplay()).toBe(
            "Manifestação genérica",
        );
        expect(makeView("contestacao").getValueForDisplay()).toBe("Contestação");
        expect(makeView("recurso_apelacao").getValueForDisplay()).toBe(
            "Recurso de Apelação",
        );
    });

    it("valor desconhecido → input cru (graceful fallback)", () => {
        expect(makeView("ato_inexistente_xyz").getValueForDisplay()).toBe(
            "ato_inexistente_xyz",
        );
    });

    it("null / undefined / empty → string vazia", () => {
        expect(makeView(null).getValueForDisplay()).toBe("");
        expect(makeView(undefined).getValueForDisplay()).toBe("");
        expect(makeView("").getValueForDisplay()).toBe("");
    });
});
