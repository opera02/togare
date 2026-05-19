/**
 * Testes da view ContratoHonorariosListView (Story 6.1).
 *
 * Cobre: actionDownload (download real), actionRemove (confirm + deleteRequest
 * + collection.fetch), actionQuickRemove (delega a actionRemove).
 *
 * Pattern literal de document-list.spec.js (5.3) com endpoint trocado.
 */

import { describe, it, expect, vi, beforeEach } from "vitest";

const flushPromises = () => new Promise((resolve) => setTimeout(resolve, 0));
import ContratoHonorariosListView from "togare-core:views/contrato-honorarios/record/list";

const makeCollection = () => ({
    fetch: vi.fn(),
    models: [],
});

const makeView = (overrides = {}) => {
    const view = Object.create(ContratoHonorariosListView.prototype);
    view.collection = makeCollection();
    view.translate = vi.fn((k) => k);
    Object.assign(view, overrides);
    return view;
};

const mockEspo = (ajax = {}, ui = {}) => {
    globalThis.Espo = {
        Ajax: {
            getRequest: vi.fn().mockResolvedValue({}),
            deleteRequest: vi.fn().mockResolvedValue({}),
            ...ajax,
        },
        Ui: {
            warning: vi.fn(),
            success: vi.fn(),
            error: vi.fn(),
            confirm: vi.fn(),
            ...ui,
        },
    };
};

beforeEach(() => {
    mockEspo();
});

describe("ContratoHonorariosListView — actionDownload", () => {
    it("abre iframe com endpoint canônico de download", () => {
        const opened = [];
        const view = makeView({
            _openDownloadUrl: vi.fn((url) => opened.push(url)),
        });

        view.actionDownload({ id: "contrato-001" });

        expect(view._openDownloadUrl).toHaveBeenCalledTimes(1);
        expect(opened[0]).toBe("api/v1/ContratoHonorarios/action/download?id=contrato-001");
    });

    it("não faz nada quando data.id está ausente", () => {
        const view = makeView({ _openDownloadUrl: vi.fn() });
        view.actionDownload({});
        view.actionDownload(null);
        view.actionDownload(undefined);
        expect(view._openDownloadUrl).not.toHaveBeenCalled();
    });

    it("codifica id antes de montar URL", () => {
        const view = makeView({ _openDownloadUrl: vi.fn() });
        view.actionDownload({ id: "contrato 1/2" });
        expect(view._openDownloadUrl).toHaveBeenCalledWith(
            "api/v1/ContratoHonorarios/action/download?id=contrato%201%2F2",
        );
    });
});

describe("ContratoHonorariosListView — actionRemove", () => {
    it("não faz nada quando data.id está ausente", () => {
        const view = makeView();
        view.actionRemove({});
        view.actionRemove(null);
        expect(globalThis.Espo.Ui.confirm).not.toHaveBeenCalled();
    });

    it("chama Espo.Ui.confirm com mensagem sobre lixeira 30 dias", () => {
        const view = makeView();
        view.translate = vi.fn((k) => {
            if (k === "removeConfirm") return "Remover este contrato? PDF irá para lixeira 30 dias.";
            return k;
        });
        view.actionRemove({ id: "contrato-x" });
        const confirmCall = globalThis.Espo.Ui.confirm.mock.calls[0];
        expect(confirmCall[0]).toContain("lixeira");
    });

    it("chama deleteRequest e collection.fetch após confirmação", async () => {
        globalThis.Espo.Ui.confirm = vi.fn((msg, opts, cb) => cb());
        globalThis.Espo.Ajax.deleteRequest = vi.fn().mockResolvedValue({});
        const view = makeView();
        view.translate = vi.fn((k) => (k === "removeConfirm" ? "Confirma?" : k));
        view.actionRemove({ id: "contrato-99" });
        expect(globalThis.Espo.Ajax.deleteRequest).toHaveBeenCalledWith("ContratoHonorarios/contrato-99");
        await flushPromises();
        expect(view.collection.fetch).toHaveBeenCalled();
    });

    it("exibe purgeFailed em caso de erro no deleteRequest", async () => {
        globalThis.Espo.Ui.confirm = vi.fn((msg, opts, cb) => cb());
        globalThis.Espo.Ajax.deleteRequest = vi.fn().mockRejectedValue({ responseJSON: null });
        const view = makeView();
        view.translate = vi.fn((k) => (k === "removeConfirm" ? "Confirma?" : k));
        view.actionRemove({ id: "contrato-99" });
        await flushPromises();
        expect(globalThis.Espo.Ui.error).toHaveBeenCalled();
    });
});

describe("ContratoHonorariosListView — actionQuickRemove", () => {
    it("delega para actionRemove", () => {
        const view = makeView();
        view.actionRemove = vi.fn();
        const data = { id: "qr-contrato-1" };
        view.actionQuickRemove(data);
        expect(view.actionRemove).toHaveBeenCalledWith(data);
    });
});
