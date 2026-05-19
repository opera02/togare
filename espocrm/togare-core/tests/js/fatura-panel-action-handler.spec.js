/**
 * Testes do FaturaPanelActionHandler (Story 6.3 — T8.5).
 *
 * Cobre: actionEmitirFatura despacha modal de criação de Fatura com
 * pré-fixação de contexto baseada em entityType (Cliente vs ContratoHonorarios).
 */

import { describe, it, expect, vi } from "vitest";
import FaturaPanelActionHandler from "togare-core:handlers/fatura/panel-action-handler";

const makePanelView = (entityType, modelAttrs) => {
    const created = { viewName: null, options: null };
    return {
        viewName: "panel-view",
        model: {
            entityType,
            get: (k) => modelAttrs[k] ?? null,
        },
        createView: vi.fn((name, viewPath, options, cb) => {
            created.viewName = viewPath;
            created.options = options;
            const fakeView = {
                render: vi.fn(),
            };
            if (typeof cb === "function") cb(fakeView);
        }),
        listenToOnce: vi.fn(),
        actionRefresh: vi.fn(),
        _created: created,
    };
};

describe("FaturaPanelActionHandler - actionEmitirFatura", () => {
    it("não dispatch se model ausente", () => {
        const handler = new FaturaPanelActionHandler({ model: null });
        handler.actionEmitirFatura();
        // não throw
        expect(true).toBe(true);
    });

    it("pré-fixa clienteId + clienteName quando entityType=Cliente", () => {
        const panel = makePanelView("Cliente", { id: "cli-001", name: "Acme Ltda" });
        const handler = new FaturaPanelActionHandler(panel);
        handler.actionEmitirFatura();

        expect(panel.createView).toHaveBeenCalled();
        const options = panel._created.options;
        expect(options.clienteId).toBe("cli-001");
        expect(options.clienteName).toBe("Acme Ltda");
        expect(options.attributes.clienteId).toBe("cli-001");
        expect(options.attributes.clienteName).toBe("Acme Ltda");
    });

    it("pré-fixa contratoHonorariosId + clienteId herdado quando entityType=ContratoHonorarios", () => {
        const panel = makePanelView("ContratoHonorarios", {
            id: "contrato-001",
            modalidade: "exito",
            clienteId: "cli-acme",
            clienteName: "Acme Ltda",
        });
        const handler = new FaturaPanelActionHandler(panel);
        handler.actionEmitirFatura();

        expect(panel.createView).toHaveBeenCalled();
        const options = panel._created.options;
        expect(options.contratoHonorariosId).toBe("contrato-001");
        expect(options.clienteId).toBe("cli-acme");
        expect(options.attributes.contratoHonorariosId).toBe("contrato-001");
        expect(options.attributes.clienteId).toBe("cli-acme");
    });

    it("aponta para views/fatura/create-modal", () => {
        const panel = makePanelView("Cliente", { id: "cli-001", name: "Acme" });
        const handler = new FaturaPanelActionHandler(panel);
        handler.actionEmitirFatura();

        expect(panel._created.viewName).toBe(
            "togare-core:views/fatura/create-modal",
        );
    });

    it("não dispatch para entityType não suportado (ex.: Processo)", () => {
        const panel = makePanelView("Processo", { id: "proc-001" });
        const handler = new FaturaPanelActionHandler(panel);
        handler.actionEmitirFatura();

        expect(panel.createView).not.toHaveBeenCalled();
    });

    it("listenToOnce after:save → actionRefresh", () => {
        const panel = makePanelView("Cliente", { id: "cli-001", name: "Acme" });
        const handler = new FaturaPanelActionHandler(panel);
        handler.actionEmitirFatura();

        expect(panel.listenToOnce).toHaveBeenCalled();
        const cb = panel.listenToOnce.mock.calls[0][2];
        cb();
        expect(panel.actionRefresh).toHaveBeenCalled();
    });
});
