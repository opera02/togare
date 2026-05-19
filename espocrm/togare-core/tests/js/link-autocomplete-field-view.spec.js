import { describe, it, expect } from "vitest";
import LinkAutocompleteFieldView from "../../src/files/client/custom/modules/togare-core/src/views/fields/link-autocomplete.js";

/**
 * Story 4b.1c — Decisão D6 (regra A6 reutilizável SEM call site nesta story).
 *
 * Side-product disponível para Cliente / ParteContraria / Processo /
 * Audiencia / Prazo edit forms (Story 4b.1-followup ou Epic 10 housekeeping).
 */

function buildView() {
    return new LinkAutocompleteFieldView({ name: "decisionProcesso" });
}

describe("LinkAutocompleteFieldView (D6)", () => {
    it("instancia como subclasse de views/fields/link (mock)", () => {
        const v = buildView();
        expect(v).toBeTruthy();
        expect(v.name).toBe("decisionProcesso");
    });

    it("override `selectAction = null` (remove botão Selecionar do modal)", () => {
        const v = buildView();
        expect(v.selectAction).toBeNull();
    });

    it("override `createDisabled = true` (sem '+ Criar' inline)", () => {
        const v = buildView();
        expect(v.createDisabled).toBe(true);
    });

    it("setup() força `autocompleteDisabled = false` (autocomplete ativo)", () => {
        const v = buildView();
        v.autocompleteDisabled = true; // simula subclasse intermediária desabilitando
        v.setup();
        expect(v.autocompleteDisabled).toBe(false);
    });

    it("getAutocompleteMaxCount() retorna 10 default", () => {
        const v = buildView();
        expect(typeof v.getAutocompleteMaxCount).toBe("function");
        expect(v.getAutocompleteMaxCount()).toBe(10);
    });
});
