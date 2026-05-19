import { describe, it, expect, beforeEach, vi } from "vitest";
import PrazoEditView from "../../src/files/client/custom/modules/togare-core/src/views/prazo/record/edit.js";

/**
 * Cria model fake compatível: previousAttributes() + attributes + on/trigger.
 */
function makeModel(initial = {}, prev = {}) {
    const state = { ...initial };
    const prevState = { ...prev };
    const listeners = {};
    return {
        attributes: state,
        previousAttributes() {
            return prevState;
        },
        on(evt, cb) {
            if (!listeners[evt]) listeners[evt] = [];
            listeners[evt].push(cb);
        },
        trigger(evt, ...args) {
            if (listeners[evt]) for (const cb of listeners[evt]) cb(...args);
        },
        // Helper de teste: simula user editando via UI.
        _userEdit(field, value) {
            state[field] = value;
            const evt = `change:${field}`;
            if (listeners[evt]) {
                for (const cb of listeners[evt]) cb(this, value, { ui: true });
            }
        },
        // Helper de teste: simula AutoLinkClientHook server retornando valor.
        // NÃO conta como user-touched.
        _serverSet(field, value) {
            state[field] = value;
            const evt = `change:${field}`;
            if (listeners[evt]) {
                for (const cb of listeners[evt]) cb(this, value, {});
            }
        },
        // Helper de teste: simula model.save() bem-sucedido — Backbone
        // dispara `sync` event. Em fix-pass 0.19.2 isto é o gancho real
        // do AutoLinkBanner (substitui afterSave() que NÃO existe em
        // EspoCRM 9.x views/record/edit).
        _triggerSync() {
            if (listeners["sync"]) {
                for (const cb of listeners["sync"]) cb();
            }
        },
    };
}

describe("PrazoEditView — Story 4a.4 T8 / AC13 (AutoLinkBanner)", () => {
    let originalTogareCore;
    let toastShow;

    beforeEach(() => {
        originalTogareCore = window.TogareCore;
        toastShow = vi.fn();
        window.TogareCore = {
            ToastTogare: { show: toastShow },
            formatters: {
                formatCnj: (v) => {
                    if (typeof v !== "string" || v.length !== 20) return v;
                    return v.slice(0, 7) + "-" + v.slice(7, 9) + "." + v.slice(9, 13) +
                        "." + v.slice(13, 14) + "." + v.slice(14, 16) + "." + v.slice(16, 20);
                },
            },
        };
    });

    afterEach(() => {
        window.TogareCore = originalTogareCore;
    });

    function buildView(model) {
        // Fix-pass 0.19.3: passa toastFactory explícito para o spy do test
        // (antes vinha via window.TogareCore.ToastTogare, agora ToastTogareView
        // é importado direto como ES6 module — view aceita override via options).
        const view = new PrazoEditView({
            model,
            toastFactory: { show: toastShow },
        });
        view.setup();
        view.translate = () => undefined;
        return view;
    }

    it("AC13 cenário 1 — ambos auto-vinculados → toast variant='auto-link' com mensagem paired", () => {
        // Setup: model recém-criado com cliente/parte vazios (snapshot pré-save).
        const model = makeModel({
            clienteId: null,
            parteContrariaId: null,
            numeroProcessoOriginal: "10228312720208260001",
        });
        const view = buildView(model);
        // Servidor vincula automaticamente (AutoLinkClientHook PHP).
        model.attributes.clienteId = "cli-001";
        model.attributes.parteContrariaId = "pc-001";
        model.attributes.cliente = { id: "cli-001", name: "João Silva" };
        model.attributes.parteContraria = { id: "pc-001", name: "Empresa X SA" };
        // Backbone dispara sync após save bem-sucedido.
        model._triggerSync();
        expect(toastShow).toHaveBeenCalledTimes(1);
        const args = toastShow.mock.calls[0][0];
        expect(args.variant).toBe("auto-link");
        expect(args.duration).toBe(8000);
        expect(args.message).toContain("João Silva");
        expect(args.message).toContain("Empresa X SA");
        expect(args.message).toContain("1022831-27.2020.8.26.0001");
    });

    it("AC13 cenário 2 — só cliente auto-vinculado → mensagem cliente-only (sem 'Parte')", () => {
        const model = makeModel({
            clienteId: null,
            parteContrariaId: null,
            numeroProcessoOriginal: "10228312720208260001",
        });
        const view = buildView(model);
        model.attributes.clienteId = "cli-001";
        model.attributes.cliente = { id: "cli-001", name: "Maria Souza" };
        model._triggerSync();
        expect(toastShow).toHaveBeenCalledTimes(1);
        const args = toastShow.mock.calls[0][0];
        expect(args.message).toContain("Maria Souza");
        expect(args.message).not.toContain("Parte");
    });

    it("AC13 cenário 3 — user editou cliente manualmente → toast NÃO aparece", () => {
        const model = makeModel({
            clienteId: null,
            parteContrariaId: null,
            numeroProcessoOriginal: "x",
        });
        const view = buildView(model);
        // User edita cliente via UI (touched).
        model._userEdit("clienteId", "cli-manual");
        // Server auto-vincula só a parte (NÃO foi user).
        model.attributes.parteContrariaId = "pc-auto";
        model._triggerSync();
        // Só parte auto-vinculada (sem cliente paired) → none.
        expect(toastShow).not.toHaveBeenCalled();
    });

    it("AC13 cenário 4 — ambos múltiplos (sem auto-link) → toast NÃO aparece", () => {
        const model = makeModel({ clienteId: null, parteContrariaId: null });
        const view = buildView(model);
        // Sem mudanças nos campos — Processo tem múltiplos, hook deixou null.
        model._triggerSync();
        expect(toastShow).not.toHaveBeenCalled();
    });

    it("AC13 cenário 5 — user editou AMBOS manualmente → toast NÃO aparece", () => {
        const model = makeModel({
            clienteId: null,
            parteContrariaId: null,
            numeroProcessoOriginal: "x",
        });
        const view = buildView(model);
        model._userEdit("clienteId", "manual1");
        model._userEdit("parteContrariaId", "manual2");
        model._triggerSync();
        expect(toastShow).not.toHaveBeenCalled();
    });

    it("_userTouchedFields é resetado após cada save (não vaza entre saves)", () => {
        const model = makeModel({});
        const view = buildView(model);
        model._userEdit("clienteId", "x");
        expect(view._userTouchedFields.has("clienteId")).toBe(true);
        model._triggerSync();
        expect(view._userTouchedFields.size).toBe(0);
    });
});
