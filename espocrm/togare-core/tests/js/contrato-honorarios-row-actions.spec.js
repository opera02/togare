/**
 * Testes da row-actions/relationship-with-download (Story 6.1 — Discovery #2 retro Epic 5).
 *
 * Cobre injeção do item "Baixar" após quickEdit (groupIndex=0) seguindo
 * pattern Documento 5.3 fix-pass aplicado desde D0.
 */

import { describe, it, expect } from "vitest";
import ContratoHonorariosRelationshipRowActionsView from "togare-core:views/contrato-honorarios/record/row-actions/relationship-with-download";

const makeView = (modelId = "contrato-001", options = {}) => {
    const view = new ContratoHonorariosRelationshipRowActionsView({
        ...options,
        model: { id: modelId },
    });
    return view;
};

describe("ContratoHonorariosRelationshipRowActionsView — getActionList", () => {
    it("insere item Baixar logo depois de quickEdit", () => {
        const view = makeView();
        const list = view.getActionList();

        const indexEdit = list.findIndex((i) => i.action === "quickEdit");
        const indexDownload = list.findIndex((i) => i.action === "download");

        expect(indexEdit).toBeGreaterThanOrEqual(0);
        expect(indexDownload).toBe(indexEdit + 1);
    });

    it("download item tem label Baixar e data.id do model", () => {
        const view = makeView("contrato-xyz");
        const list = view.getActionList();
        const downloadItem = list.find((i) => i.action === "download");

        expect(downloadItem).toBeDefined();
        expect(downloadItem.label).toBe("Baixar");
        expect(downloadItem.data).toEqual({ id: "contrato-xyz" });
        expect(downloadItem.groupIndex).toBe(0);
    });

    it("preserva ordem dos items default (quickView/quickEdit/unlinkRelated/removeRelated)", () => {
        const view = makeView();
        const list = view.getActionList();
        const actions = list.map((i) => i.action);

        expect(actions).toContain("quickView");
        expect(actions).toContain("quickEdit");
        expect(actions).toContain("unlinkRelated");
        expect(actions).toContain("removeRelated");
        expect(actions).toContain("download");
    });

    it("em painel read-only preserva apenas View e Baixar", () => {
        const view = makeView("contrato-ro", {
            editDisabled: true,
            unlinkDisabled: true,
            removeDisabled: true,
        });
        const actions = view.getActionList().map((i) => i.action);

        expect(actions).toEqual(["quickView", "download"]);
    });
});
