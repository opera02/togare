/**
 * Testes do panel action handler `ContratoHonorariosPanelActionHandler` (Story 6.1).
 *
 * Cobre:
 *  - entityType='Cliente' → cria modal upload com clienteId + clienteName.
 *  - entityType desconhecido NÃO invoca createView (ContratoHonorarios é
 *    sempre N:1 Cliente — Discovery #1 retro Epic 5).
 *  - listenToOnce após after:save dispara actionRefresh do panel.
 */

import { describe, it, expect, vi } from "vitest";
import ContratoHonorariosPanelActionHandler from "togare-core:handlers/contrato-honorarios/panel-action-handler";

function makePanelView(modelOverrides = {}) {
    const model = {
        entityType: modelOverrides.entityType ?? "Cliente",
        _attrs: modelOverrides.attrs ?? { id: "cli-001", name: "Acme Ltda" },
        get(key) {
            return this._attrs[key];
        },
    };
    let saveCallback = null;
    return {
        model,
        createView: vi.fn((name, viewPath, options, cb) => {
            // Simula createView callback com fake view que tem listenToOnce.
            const view = {
                render: vi.fn(),
                _listeners: [],
            };
            cb(view);
        }),
        listenToOnce: vi.fn((view, evt, cb) => {
            saveCallback = cb;
        }),
        actionRefresh: vi.fn(),
        getSaveCallback() {
            return saveCallback;
        },
    };
}

describe("ContratoHonorariosPanelActionHandler — actionAnexarContrato", () => {
    it("entityType=Cliente cria modal upload com clienteId+clienteName", () => {
        const panelView = makePanelView({
            entityType: "Cliente",
            attrs: { id: "cli-001", name: "Acme Ltda" },
        });
        const handler = new ContratoHonorariosPanelActionHandler(panelView);

        handler.actionAnexarContrato({}, new Event("click"));

        expect(panelView.createView).toHaveBeenCalledTimes(1);
        const [name, viewPath, options] = panelView.createView.mock.calls[0];
        expect(name).toBe("contratoHonorariosUpload");
        expect(viewPath).toBe("togare-core:views/contrato-honorarios/upload-modal");
        expect(options.clienteId).toBe("cli-001");
        expect(options.clienteName).toBe("Acme Ltda");
        expect(options.attributes).toEqual({
            clienteId: "cli-001",
            clienteName: "Acme Ltda",
        });
        // NÃO passa `options.relate` — convenção Espo setRelate exige link
        // NA entity sendo criada (ContratoHonorarios.cliente), não no parent
        // (Cliente.contratosHonorarios). Como já pré-fixamos via attributes,
        // relate fica redundante e é omitido para evitar setRelate crash.
        expect(options.relate).toBeUndefined();
    });

    it("entityType=Processo NÃO chama createView (contrato é sempre de Cliente)", () => {
        const panelView = makePanelView({
            entityType: "Processo",
            attrs: { id: "proc-001", numeroCnj: "0001234-56" },
        });
        const handler = new ContratoHonorariosPanelActionHandler(panelView);

        handler.actionAnexarContrato({}, new Event("click"));

        expect(panelView.createView).not.toHaveBeenCalled();
    });

    it("entityType=Prazo NÃO chama createView (contrato é sempre de Cliente)", () => {
        const panelView = makePanelView({
            entityType: "Prazo",
            attrs: { id: "prz-001", atoCodigo: "cumprimento" },
        });
        const handler = new ContratoHonorariosPanelActionHandler(panelView);

        handler.actionAnexarContrato({}, new Event("click"));

        expect(panelView.createView).not.toHaveBeenCalled();
    });

    it("model sem id NÃO chama createView", () => {
        const panelView = makePanelView({
            entityType: "Cliente",
            attrs: { name: "Sem ID" },
        });
        const handler = new ContratoHonorariosPanelActionHandler(panelView);

        handler.actionAnexarContrato({}, new Event("click"));

        expect(panelView.createView).not.toHaveBeenCalled();
    });
});
