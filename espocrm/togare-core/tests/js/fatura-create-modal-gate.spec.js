/**
 * Testes do comportamento de gate GateBanner no FaturaCreateModalView (Story 6.2).
 *
 * Arquivo separado de fatura-create-modal.spec.js (que cobre o setup base 6.3)
 * para não modificar o spec anterior (regra retro Epic 5 A4).
 *
 * Cobre ACs 1, 3, 4 e fail-open:
 *  - AC1: _gateActive=true quando hasContratoVigente=false
 *  - AC3: _gateActive=false após re-check bem-sucedido (toggle)
 *  - AC4: sem gate quando cliente tem contrato vigente
 *  - Fail-open: _gateActive=false quando endpoint rejeita (rede/500)
 *  - _checkGate não chama API quando clienteId vazio
 *
 * Estratégia: instancia FaturaCreateModalView com stubs de listenTo/once/
 * hasView/createView/clearView/$el e usa _ajaxPost como hook mockável
 * (detectado pelo _checkGate quando Espo global não existe).
 */

import { describe, it, expect, vi, beforeEach } from "vitest";
import FaturaCreateModalView from "togare-core:views/fatura/create-modal";

/**
 * Cria view com stubs mínimos para testar gate sem o runtime EspoCRM.
 * _ajaxPost é o hook injetável — retorna Promise resolvida/rejeitada pelo teste.
 */
const makeGateView = (options = {}) => {
    const view = new FaturaCreateModalView(options);

    // Stubs Backbone (não existem no mock modals-edit)
    view._viewListeners = {};
    view.listenTo = vi.fn((_target, event, cb) => {
        view._viewListeners[event] = cb;
    });
    view.listenToOnce = vi.fn();
    view.once = vi.fn();
    view.trigger = vi.fn();

    // Stubs de gestão de sub-views
    view._subViews = {};
    view.hasView = (name) => name in view._subViews;
    view.createView = vi.fn((name, _module, _opts, cb) => {
        const stub = { render: vi.fn(), $el: null, listenTo: vi.fn() };
        view._subViews[name] = stub;
        if (cb) cb(stub);
        return stub;
    });
    view.clearView = vi.fn((name) => {
        delete view._subViews[name];
    });

    // DOM real do modal-body — _showGateBanner/_hideGateBanner usam
    // this.el / this.$el[0] + querySelector/insertBefore/removeChild nativos.
    const modalBody = document.createElement("div");
    modalBody.className = "modal-body body";
    const btnEl = document.createElement("button");
    btnEl.setAttribute("data-name", "save");
    modalBody.appendChild(btnEl);
    view.el = modalBody;

    // Stub $el para _disableSubmit/_enableSubmit (jQuery-like) + [0]=DOM real.
    view.$el = {
        0: modalBody,
        find: (selector) => {
            const el = modalBody.querySelector(selector);
            return {
                prop: vi.fn().mockReturnThis(),
                addClass: vi.fn().mockReturnThis(),
                removeClass: vi.fn().mockReturnThis(),
                prepend: vi.fn(),
                first: vi.fn().mockReturnThis(),
                length: el ? 1 : 0,
            };
        },
    };

    // Stub de translate
    view.translate = (key) => key;

    return view;
};

describe("FaturaCreateModalView - gate FR23 (Story 6.2)", () => {
    let view;

    beforeEach(() => {
        view = makeGateView({ clienteId: "cli-001", clienteName: "Acme Ltda" });
        view.setup();
    });

    it("_gateActive começa como false após setup", () => {
        expect(view._gateActive).toBe(false);
    });

    it("AC4: _gateActive permanece false quando hasContratoVigente=true", async () => {
        view._ajaxPost = vi.fn().mockResolvedValue({ hasContratoVigente: true });
        await view._checkGate();
        expect(view._gateActive).toBe(false);
        expect(view.clearView).toHaveBeenCalledWith("gateBanner");
    });

    it("AC1: _gateActive=true quando hasContratoVigente=false", async () => {
        view._ajaxPost = vi.fn().mockResolvedValue({ hasContratoVigente: false });
        await view._checkGate();
        expect(view._gateActive).toBe(true);
        expect(view.createView).toHaveBeenCalledWith(
            "gateBanner",
            "togare-core:views/common/gate-banner",
            expect.objectContaining({ variant: "financeiro-sem-contrato" }),
            expect.any(Function),
        );
        // Novo padrão: createView recebe `el` como CSS selector string
        // (#<id> do placeholder) — fix "Could not set element" Round 4.
        const gateCall = view.createView.mock.calls.find((c) => c[0] === "gateBanner");
        expect(gateCall[2].el).toMatch(/^#togare-gate-banner-mount-/);
    });

    it("AC3: _gateActive=false após re-check com contrato vigente (toggle)", async () => {
        // Primeiro check: sem contrato → gate ativo
        view._ajaxPost = vi.fn().mockResolvedValue({ hasContratoVigente: false });
        await view._checkGate();
        expect(view._gateActive).toBe(true);

        // Segundo check: com contrato → gate desativado
        view._ajaxPost = vi.fn().mockResolvedValue({ hasContratoVigente: true });
        await view._checkGate();
        expect(view._gateActive).toBe(false);
        expect(view.clearView).toHaveBeenCalledWith("gateBanner");
    });

    it("Fail-open: _gateActive=false quando endpoint rejeita", async () => {
        view._ajaxPost = vi.fn().mockRejectedValue(new Error("network error"));
        await view._checkGate();
        expect(view._gateActive).toBe(false);
    });

    it("_checkGate não chama _ajaxPost quando clienteId vazio", async () => {
        const emptyView = makeGateView({});
        emptyView.setup();
        emptyView._ajaxPost = vi.fn();
        await emptyView._checkGate();
        expect(emptyView._ajaxPost).not.toHaveBeenCalled();
        expect(emptyView._gateActive).toBe(false);
    });

    it("_checkGate inclui processoId no payload quando disponível", async () => {
        const viewWithProcesso = makeGateView({
            clienteId: "cli-001",
            clienteName: "Acme",
            processoId: "proc-123",
        });
        viewWithProcesso.setup();
        viewWithProcesso._ajaxPost = vi.fn().mockResolvedValue({ hasContratoVigente: true });
        await viewWithProcesso._checkGate();
        expect(viewWithProcesso._ajaxPost).toHaveBeenCalledWith(
            expect.objectContaining({ clienteId: "cli-001", processoId: "proc-123" }),
        );
    });

    it("_checkGate não inclui processoId quando ausente", async () => {
        view._ajaxPost = vi.fn().mockResolvedValue({ hasContratoVigente: true });
        await view._checkGate();
        const payload = view._ajaxPost.mock.calls[0][0];
        expect(payload).not.toHaveProperty("processoId");
    });

    it("hasView('gateBanner') idempotente — createView não re-cria banner existente", async () => {
        view._ajaxPost = vi.fn().mockResolvedValue({ hasContratoVigente: false });
        await view._checkGate();
        const firstCallCount = view.createView.mock.calls.length;

        // Segundo _showGateBanner sem esconder entre meio — não deve criar de novo
        view._showGateBanner({ clienteId: "cli-001", processoId: null });
        expect(view.createView.mock.calls.length).toBe(firstCallCount); // sem nova chamada
    });

    it("CTA usa contexto mais recente mesmo quando banner existente nao e recriado", () => {
        view._showGateBanner({
            clienteId: "cli-antigo",
            processoId: "proc-antigo",
            processoName: "Processo antigo",
        });
        const firstCallCount = view.createView.mock.calls.length;

        view._showGateBanner({
            clienteId: "cli-novo",
            clienteName: "Cliente novo",
            processoId: "proc-novo",
            processoName: "Processo novo",
        });
        expect(view.createView.mock.calls.length).toBe(firstCallCount);

        view._viewListeners["cta:click:cadastrar-contrato"]();

        expect(view.createView).toHaveBeenLastCalledWith(
            "cadastrarContratoModal",
            "togare-core:views/contrato-honorarios/upload-modal",
            {
                clienteId: "cli-novo",
                clienteName: "Cliente novo",
                processoId: "proc-novo",
                processoName: "Processo novo",
            },
            expect.any(Function),
        );
    });
});
