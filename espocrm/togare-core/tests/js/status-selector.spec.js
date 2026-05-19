import { describe, it, expect, beforeEach, vi } from "vitest";
import StatusSelectorFieldView from "../../src/files/client/custom/modules/togare-core/src/views/prazo/fields/status-selector.js";

/**
 * Helper: flusha todos os microtasks pendentes (Promise.resolve chains).
 */
async function flushPromises() {
    for (let i = 0; i < 20; i++) {
        // eslint-disable-next-line no-await-in-loop
        await Promise.resolve();
    }
}

/**
 * Helper: cria uma model fake compatível com o subset do Backbone usado pela
 * StatusSelector. Permite spy em set/save.
 */
function makeModel(initial = {}) {
    const state = { ...initial };
    return {
        _data: state,
        get(k) {
            return state[k];
        },
        set(kOrObj, vOrOpts) {
            const obj = (typeof kOrObj === "object")
                ? kOrObj
                : { [kOrObj]: vOrOpts };
            Object.assign(state, obj);
        },
        save: vi.fn(() => Promise.resolve()),
        previousAttributes() {
            return {};
        },
        on() {},
    };
}

/**
 * Cria a view com factories injetadas (toast, dialog, confirm).
 *
 * Importante: `view.translate` retorna o LABEL FALLBACK do _translateOrFallback,
 * NÃO a key — porque o método trata `v === key` como "não traduziu" e cai pro
 * fallback. Para os testes de AC7 que checam mensagem específica, asseramos
 * pelo texto pt-BR completo (que é o fallback).
 */
function buildView({
    initialStatus = "pendente",
    inline = true,
    toastShow,
    dialogFactory,
    confirmFactory,
    saveImpl,
    useDomDialog = false,
    useDomConfirm = false,
} = {}) {
    const model = makeModel({
        status: initialStatus,
        motivoReagendamento: null,
    });
    if (saveImpl) model.save = saveImpl;
    const toast = { show: toastShow || vi.fn() };
    const opts = {
        name: "status",
        mode: "detail",
        model,
        inline,
        toastFactory: toast,
    };
    if (!useDomDialog) {
        opts.dialogFactory = dialogFactory || ((o) =>
            o.onConfirm("Motivo de teste com mais de 10 chars"));
    }
    if (!useDomConfirm) {
        opts.confirmFactory = confirmFactory || ((o) => o.onConfirm());
    }
    const view = new StatusSelectorFieldView(opts);
    view.setup();
    // Stub translate: mapeia status options para labels pt-BR (espelha
    // i18n/pt_BR/Prazo.json::options.status). Para outras keys, retorna
    // undefined → força fallback (textos pt-BR hardcoded em
    // _confirmationLabels / _dialogLabels / _formatToastUndoMessage).
    const STATUS_LABELS = {
        rascunho: "Rascunho",
        pendente: "Pendente",
        atrasado_reagendado: "Atrasado/Reagendado",
        aguardando_cliente: "Aguardando cliente",
        aguardando_correcao: "Aguardando correção",
        protocolado: "Protocolado",
        ciencia_renuncia: "Ciência com renúncia",
        acompanhamento: "Acompanhamento",
        descartado: "Descartado",
    };
    view.translate = (key, category, scope) => {
        if (category === "options" && scope === "Prazo" && STATUS_LABELS[key]) {
            return STATUS_LABELS[key];
        }
        return undefined;
    };
    // Story 4a.4 fix-pass 0.19.4 (B11): _labelFor agora usa
    // `getLanguage().translateOption(value, fieldName, scope)`. Stub espelha
    // o pattern real do EspoCRM 9.x.
    view.getLanguage = () => ({
        translate: view.translate,
        translateOption: (value, fieldName, scope) => {
            if (fieldName === "status" && scope === "Prazo" && STATUS_LABELS[value]) {
                return STATUS_LABELS[value];
            }
            return value;
        },
    });
    const el = document.createElement("div");
    el.innerHTML = view.getValueForDisplay();
    view.setElement(el);
    view.afterRender();
    return { view, model, el, toast };
}

describe("StatusSelectorFieldView — Story 4a.4 T6", () => {
    beforeEach(() => {
        document.body.innerHTML = "";
    });

    describe("AC5 — dropdown com transições válidas por status atual", () => {
        const expected = {
            rascunho: ["pendente", "acompanhamento", "descartado"],
            pendente: [
                "atrasado_reagendado",
                "aguardando_cliente",
                "aguardando_correcao",
                "protocolado",
                "ciencia_renuncia",
                "descartado",
            ],
            atrasado_reagendado: ["pendente", "protocolado", "ciencia_renuncia", "descartado"],
            aguardando_cliente: ["pendente", "atrasado_reagendado", "protocolado", "descartado"],
            aguardando_correcao: ["pendente", "protocolado", "ciencia_renuncia", "descartado"],
            protocolado: ["pendente"],
            ciencia_renuncia: ["pendente"],
            acompanhamento: ["pendente", "protocolado"],
        };

        for (const [from, destinos] of Object.entries(expected)) {
            it(`status=${from} renderiza ${destinos.length} botões de transição`, () => {
                const { el } = buildView({ initialStatus: from });
                const items = el.querySelectorAll(".togare-status-selector__item");
                const targets = Array.from(items).map((b) => b.getAttribute("data-target"));
                expect(targets).toEqual(destinos);
            });
        }

        it("status=descartado (terminal) NÃO renderiza menu — só badge inline", () => {
            const { el } = buildView({ initialStatus: "descartado" });
            expect(el.querySelector(".togare-status-selector__menu")).toBe(null);
            expect(el.querySelector(".togare-status-selector--terminal")).not.toBe(null);
        });
    });

    describe("AC8 — save direto + ToastTogare undo (transições sem dialog)", () => {
        it("seleciona aguardando_cliente → save direto + toast undo", async () => {
            const toastShow = vi.fn();
            const { el, model, toast } = buildView({
                initialStatus: "pendente",
                toastShow,
            });
            const btn = el.querySelector('[data-target="aguardando_cliente"]');
            btn.click();
            await flushPromises();
            expect(model.get("status")).toBe("aguardando_cliente");
            expect(model.save).toHaveBeenCalledTimes(1);
            expect(model.save).toHaveBeenCalledWith(null, expect.objectContaining({ fromStatusSelector: true }));
            expect(toast.show).toHaveBeenCalledTimes(1);
            const args = toast.show.mock.calls[0][0];
            expect(args.variant).toBe("undo");
            expect(args.duration).toBe(10000);
            expect(args.message).toContain("Aguardando cliente");
        });

        it("undo no toast reverte status + motivoReagendamento clear + persiste no backend", async () => {
            const toastShow = vi.fn();
            const { el, model } = buildView({
                initialStatus: "pendente",
                toastShow,
            });
            el.querySelector('[data-target="aguardando_cliente"]').click();
            await flushPromises();
            expect(model.save).toHaveBeenCalledTimes(1);
            const toastArgs = toastShow.mock.calls[0][0];
            toastArgs.onAction();
            await flushPromises();
            expect(model.get("status")).toBe("pendente");
            expect(model.get("motivoReagendamento")).toBe(null);
            // Undo deve persistir no backend (2º save com fromUndo: true).
            expect(model.save).toHaveBeenCalledTimes(2);
            expect(model.save).toHaveBeenLastCalledWith(null, expect.objectContaining({ fromUndo: true }));
        });
    });

    describe("AC6 — dialog motivoReagendamento ≥10 chars (atrasado_reagendado)", () => {
        it("seleciona atrasado_reagendado → dialog abre com factory injetada", async () => {
            const dialogFactory = vi.fn((opts) => {
                opts.onConfirm("Tribunal reagendou audiência");
            });
            const { el, model } = buildView({
                initialStatus: "pendente",
                dialogFactory,
            });
            el.querySelector('[data-target="atrasado_reagendado"]').click();
            expect(dialogFactory).toHaveBeenCalledTimes(1);
            const args = dialogFactory.mock.calls[0][0];
            expect(args.minChars).toBe(10);
            expect(args.title).toBe(
                "Por que este prazo foi reagendado/atrasado?",
            );
            await flushPromises();
            expect(model.get("status")).toBe("atrasado_reagendado");
            expect(model.get("motivoReagendamento")).toBe(
                "Tribunal reagendou audiência",
            );
        });

        it("dialog cancelado (onConfirm NÃO chamado) → status NÃO muda", () => {
            const dialogFactory = vi.fn(() => {});
            const { el, model } = buildView({
                initialStatus: "pendente",
                dialogFactory,
            });
            el.querySelector('[data-target="atrasado_reagendado"]').click();
            expect(model.get("status")).toBe("pendente");
            expect(model.save).not.toHaveBeenCalled();
        });

        it("_dialogOpen guard: segundo clique enquanto dialog aberto é ignorado (P4)", () => {
            // factory abre mas NÃO chama onConfirm — simula dialog aguardando input do user.
            const dialogFactory = vi.fn(() => {});
            const { el } = buildView({
                initialStatus: "pendente",
                dialogFactory,
            });
            el.querySelector('[data-target="atrasado_reagendado"]').click();
            el.querySelector('[data-target="atrasado_reagendado"]').click(); // segundo clique
            expect(dialogFactory).toHaveBeenCalledTimes(1);
        });

        describe("dialog DOM real (fallback factory)", () => {
            it("renderiza textarea + counter + Confirmar disabled inicialmente", () => {
                // Não passa dialogFactory → fallback DOM real.
                const { el } = buildView({
                    initialStatus: "pendente",
                    useDomDialog: true,
                });
                el.querySelector('[data-target="atrasado_reagendado"]').click();
                const dlg = document.querySelector(".togare-status-selector__dialog");
                expect(dlg).not.toBe(null);
                expect(dlg.querySelector("textarea")).not.toBe(null);
                expect(dlg.querySelector('[data-action="confirm"]').disabled).toBe(true);
                dlg.remove();
            });

            it("9 chars → counter mostra 9/10 + Confirmar disabled + erro inline", () => {
                const { el } = buildView({
                    initialStatus: "pendente",
                    useDomDialog: true,
                });
                el.querySelector('[data-target="atrasado_reagendado"]').click();
                const dlg = document.querySelector(".togare-status-selector__dialog");
                const ta = dlg.querySelector("textarea");
                ta.value = "123456789";
                ta.dispatchEvent(new window.Event("input"));
                expect(dlg.querySelector('[data-action="confirm"]').disabled).toBe(true);
                expect(dlg.querySelector(".togare-status-selector__dialog-error").hidden).toBe(false);
                dlg.remove();
            });

            it("10 espaços (apenas whitespace) → trim valida → Confirmar disabled", () => {
                const { el } = buildView({
                    initialStatus: "pendente",
                    useDomDialog: true,
                });
                el.querySelector('[data-target="atrasado_reagendado"]').click();
                const dlg = document.querySelector(".togare-status-selector__dialog");
                const ta = dlg.querySelector("textarea");
                ta.value = "          ";
                ta.dispatchEvent(new window.Event("input"));
                expect(dlg.querySelector('[data-action="confirm"]').disabled).toBe(true);
                dlg.remove();
            });

            it("10 chars válidos → Confirmar habilita + click salva + dialog some", async () => {
                const { el, model } = buildView({
                    initialStatus: "pendente",
                    useDomDialog: true,
                });
                el.querySelector('[data-target="atrasado_reagendado"]').click();
                const dlg = document.querySelector(".togare-status-selector__dialog");
                const ta = dlg.querySelector("textarea");
                ta.value = "Tribunal reagendou audiência";
                ta.dispatchEvent(new window.Event("input"));
                const confirmBtn = dlg.querySelector('[data-action="confirm"]');
                expect(confirmBtn.disabled).toBe(false);
                confirmBtn.click();
                expect(document.querySelector(".togare-status-selector__dialog")).toBe(null);
                await flushPromises();
                expect(model.get("status")).toBe("atrasado_reagendado");
                expect(model.get("motivoReagendamento")).toBe("Tribunal reagendou audiência");
            });
        });
    });

    describe("AC7 — confirmation dialog leve (protocolado/ciencia_renuncia/descartado)", () => {
        const fixtures = [
            ["protocolado", "Protocolado"],
            ["ciencia_renuncia", "Ciência com renúncia"],
            ["descartado", "Descartar"],
        ];

        it.each(fixtures)("status %s aciona confirm com message contendo '%s'", (target, expectedSubstring) => {
            const confirmFactory = vi.fn();
            const { el } = buildView({
                initialStatus: "pendente",
                confirmFactory,
            });
            el.querySelector(`[data-target="${target}"]`).click();
            expect(confirmFactory).toHaveBeenCalledTimes(1);
            const opts = confirmFactory.mock.calls[0][0];
            expect(opts.message).toContain(expectedSubstring);
        });

        it("usuário cancela confirm → status NÃO muda", () => {
            const confirmFactory = vi.fn(() => {});
            const { el, model } = buildView({
                initialStatus: "pendente",
                confirmFactory,
            });
            el.querySelector('[data-target="protocolado"]').click();
            expect(model.get("status")).toBe("pendente");
            expect(model.save).not.toHaveBeenCalled();
        });
    });

    describe("erro backend reverte model", () => {
        it("save rejected → status volta para anterior + Espo.Ui.error chamado", async () => {
            const errorSpy = vi.fn();
            const originalEspo = window.Espo;
            window.Espo = { Ui: { error: errorSpy } };
            const { el, model } = buildView({
                initialStatus: "pendente",
                saveImpl: vi.fn(() =>
                    Promise.reject(new Error("Validação backend falhou")),
                ),
            });
            el.querySelector('[data-target="aguardando_cliente"]').click();
            await flushPromises();
            expect(model.get("status")).toBe("pendente");
            expect(errorSpy).toHaveBeenCalledWith("Validação backend falhou");
            window.Espo = originalEspo;
        });
    });

    describe("race condition — destino inválido após render é silenciosamente ignorado", () => {
        it("muda model.status entre render e click → click ignora", () => {
            const { el, model } = buildView({ initialStatus: "pendente" });
            // Outro caminho mudou status para descartado (sem usar StatusSelector)
            model.set("status", "descartado");
            const btn = el.querySelector('[data-target="aguardando_cliente"]');
            btn.click();
            expect(model.get("status")).toBe("descartado");
            expect(model.save).not.toHaveBeenCalled();
        });
    });

    describe("getValueForDisplay em modo edit (form completo) preserva enum nativo", () => {
        it("mode=edit + inline=false → delega para super (não renderiza dropdown custom)", () => {
            const model = makeModel({ status: "pendente" });
            const view = new StatusSelectorFieldView({
                name: "status",
                mode: "edit",
                model,
                inline: false,
            });
            view.setup();
            view.translate = () => undefined;
            const out = view.getValueForDisplay();
            // EnumFieldView mock retorna `model.get(name)` quando getValueForDisplay
            // não é overridden — pendente.
            expect(out).toBe("pendente");
        });
    });

    describe("Story 4a.4 fix-pass 0.19.2 — afterRender substitui DOM em mode=detail (B2)", () => {
        it("EspoCRM 9.x ignora getValueForDisplay no template enum/detail; afterRender força o dropdown", () => {
            // Simula que o template padrão do enum já renderizou a label simples ANTES.
            const model = makeModel({ status: "pendente", motivoReagendamento: null });
            const view = new StatusSelectorFieldView({
                name: "status",
                mode: "detail",
                model,
                toastFactory: { show: vi.fn() },
                dialogFactory: () => {},
                confirmFactory: () => {},
            });
            view.setup();
            view.translate = () => undefined;
            const el = document.createElement("div");
            // Estado inicial simula o template do enum (label estática, SEM dropdown).
            el.innerHTML = "<span class=\"label\">Pendente</span>";
            view.setElement(el);
            view.afterRender();
            // Após afterRender, o trigger e menu DEVEM existir (substituiu DOM).
            expect(el.querySelector(".togare-status-selector__trigger")).not.toBe(null);
            expect(el.querySelector(".togare-status-selector__menu")).not.toBe(null);
            // E os items refletem as transições válidas para `pendente`.
            const items = el.querySelectorAll(".togare-status-selector__item");
            expect(items.length).toBe(6);
        });
    });
});
