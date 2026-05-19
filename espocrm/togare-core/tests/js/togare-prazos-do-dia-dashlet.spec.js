/**
 * Testes do dashlet `togare-prazos-do-dia.js` (Story 4a.5, T4).
 *
 * Cobertura:
 *  - `_renderHeadline()` injeta `<div class="togare-briefing-headline">`
 *    antes de `.list-container` quando ainda não existe (insertAdjacentHTML).
 *  - `_renderHeadline()` substitui via outerHTML quando já existe (idempotência).
 *  - listener `sync` re-renderiza headline ao mudar `collection.total`.
 *  - `_renderHeadline()` lida defensivamente com `collection=null` sem throw.
 *  - `_readCollectionTotal()` prefere `collection.total` sobre `collection.length`.
 *  - `afterRender` aciona `_wireUpHeadline` em microtask (setTimeout 0).
 */

import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import TogarePrazosDoDiaDashletView from "../../src/files/client/custom/modules/togare-core/src/views/dashlets/togare-prazos-do-dia.js";
import { __resetEscStackForTests } from "../../src/files/client/custom/modules/togare-core/src/views/common/toast-togare.js";

/** Cria um collection mock minimal Backbone-like (on/off/total/length). */
function makeCollection({ total, length = null } = {}) {
    const listeners = {};
    return {
        total: total,
        length: length === null ? (total ?? 0) : length,
        on(event, cb) {
            (listeners[event] = listeners[event] || []).push(cb);
        },
        off(event, cb) {
            if (!listeners[event]) return;
            listeners[event] = listeners[event].filter((c) => c !== cb);
        },
        trigger(event, ...args) {
            (listeners[event] || []).forEach((cb) => cb(...args));
        },
    };
}

/** Constrói uma instância do dashlet com DOM jsdom + collection injetados. */
function buildDashlet({ total, html = '<div class="list-container"></div>' } = {}) {
    const root = document.createElement("div");
    root.innerHTML = html;
    document.body.appendChild(root);

    const view = new TogarePrazosDoDiaDashletView({});
    view.element = root;
    view.$el = [root];
    view.collection = makeCollection({ total });

    return { view, root };
}

describe("TogarePrazosDoDiaDashletView / _renderHeadline", () => {
    beforeEach(() => {
        // jsdom isolado por teste.
        document.body.innerHTML = "";
    });

    afterEach(() => {
        document.body.innerHTML = "";
    });

    it("injeta headline com count antes da .list-container quando não existe", () => {
        const { view, root } = buildDashlet({ total: 3 });

        view._renderHeadline();

        const headline = root.querySelector(".togare-briefing-headline");
        expect(headline).not.toBeNull();
        expect(headline.outerHTML).toContain("3 prazos pendentes");

        // Verifica que veio ANTES da .list-container.
        const list = root.querySelector(".list-container");
        expect(headline.nextElementSibling).toBe(list);
    });

    it("substitui via outerHTML quando headline já existe (idempotência)", () => {
        const { view, root } = buildDashlet({ total: 1 });

        view._renderHeadline();
        const first = root.querySelector(".togare-briefing-headline");
        expect(first).not.toBeNull();

        // Atualiza total e re-renderiza.
        view.collection.total = 7;
        view._renderHeadline();

        const headlines = root.querySelectorAll(".togare-briefing-headline");
        expect(headlines).toHaveLength(1); // Só 1, não duplicou.
        expect(headlines[0].outerHTML).toContain("7 prazos pendentes");
    });

    it("renderiza estado calmo quando count=0", () => {
        const { view, root } = buildDashlet({ total: 0 });

        view._renderHeadline();

        const headline = root.querySelector(".togare-briefing-headline");
        expect(headline).not.toBeNull();
        expect(headline.classList.contains("togare-briefing-headline--zero")).toBe(true);
        expect(headline.outerHTML).toContain("aproveita o café");
        expect(headline.outerHTML).not.toContain("togare-briefing-cta");
    });

    it("não quebra quando collection é null (defensivo)", () => {
        const { view, root } = buildDashlet({ total: 0 });
        view.collection = null;

        expect(() => view._renderHeadline()).not.toThrow();

        // Ainda renderiza o estado vazio (count=0 default).
        const headline = root.querySelector(".togare-briefing-headline");
        expect(headline).not.toBeNull();
        expect(headline.classList.contains("togare-briefing-headline--zero")).toBe(true);
    });

    it("não quebra quando element é null (defensivo)", () => {
        const view = new TogarePrazosDoDiaDashletView({});
        view.collection = makeCollection({ total: 5 });
        view.element = null;
        view.$el = null;

        expect(() => view._renderHeadline()).not.toThrow();
    });

    it("não injeta nada quando .list-container não existe no root", () => {
        const root = document.createElement("div");
        root.innerHTML = "<div>vazio</div>";
        document.body.appendChild(root);

        const view = new TogarePrazosDoDiaDashletView({});
        view.element = root;
        view.collection = makeCollection({ total: 3 });

        view._renderHeadline();

        // Sem .list-container e sem headline pré-existente → nada injetado.
        const headline = root.querySelector(".togare-briefing-headline");
        expect(headline).toBeNull();
    });
});

describe("TogarePrazosDoDiaDashletView / _readCollectionTotal", () => {
    it("retorna collection.total quando number >= 0", () => {
        const view = new TogarePrazosDoDiaDashletView({});
        view.collection = { total: 12, length: 5 };
        expect(view._readCollectionTotal()).toBe(12);
    });

    it("retorna 0 quando total é undefined (pré-fetch — não usa collection.length)", () => {
        const view = new TogarePrazosDoDiaDashletView({});
        view.collection = { length: 4 };
        // AC2: count deve refletir collection.total do servidor, nunca collection.length.
        expect(view._readCollectionTotal()).toBe(0);
    });

    it("retorna 0 quando collection é null", () => {
        const view = new TogarePrazosDoDiaDashletView({});
        view.collection = null;
        expect(view._readCollectionTotal()).toBe(0);
    });

    it("retorna 0 quando total é negativo (defensivo — backend não deveria mas por garantia)", () => {
        const view = new TogarePrazosDoDiaDashletView({});
        view.collection = { total: -1, length: 3 };
        // total<0 falha o guard `total >= 0`; retorna 0 (sem fallback para length).
        expect(view._readCollectionTotal()).toBe(0);
    });
});

describe("TogarePrazosDoDiaDashletView / wire-up sync listener", () => {
    beforeEach(() => {
        document.body.innerHTML = "";
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
        document.body.innerHTML = "";
    });

    it("listener sync re-renderiza headline ao mudar collection.total", () => {
        const { view, root } = buildDashlet({ total: 2 });

        view.afterRender();
        // setTimeout(0) interno do _wireUpHeadline.
        vi.runAllTimers();

        let headline = root.querySelector(".togare-briefing-headline");
        expect(headline.outerHTML).toContain("2 prazos pendentes");

        // Backend retorna fetch novo com total maior.
        view.collection.total = 9;
        view.collection.trigger("sync");

        headline = root.querySelector(".togare-briefing-headline");
        expect(headline.outerHTML).toContain("9 prazos pendentes");
    });

    it("não quebra quando afterRender é chamado sem collection (retry depois desiste)", () => {
        const root = document.createElement("div");
        root.innerHTML = '<div class="list-container"></div>';
        document.body.appendChild(root);

        const view = new TogarePrazosDoDiaDashletView({});
        view.element = root;
        view.$el = [root];
        view.collection = null;

        expect(() => {
            view.afterRender();
            vi.advanceTimersByTime(500);
        }).not.toThrow();

        // Após retries (5x50ms = 250ms), desiste e renderiza estado vazio.
        const headline = root.querySelector(".togare-briefing-headline");
        expect(headline).not.toBeNull();
        expect(headline.classList.contains("togare-briefing-headline--zero")).toBe(true);
    });

    it("listener wireup é idempotente (não duplica em re-runs)", () => {
        const { view } = buildDashlet({ total: 1 });

        view.afterRender();
        vi.runAllTimers();

        // Re-chama afterRender (cenário: dashlet re-render manual).
        view.afterRender();
        vi.runAllTimers();

        // collection.on listeners — tem só 1 entry mesmo após 2 afterRender.
        view.collection.total = 5;
        view.collection.trigger("sync");

        // Se duplicou listener, _renderHeadline é chamado 2x mas resultado
        // visual é o mesmo (idempotente). Verificar via contagem de elementos.
        const headlines = document.querySelectorAll(".togare-briefing-headline");
        expect(headlines).toHaveLength(1);
    });
});

describe("TogarePrazosDoDiaDashletView / i18n integração", () => {
    beforeEach(() => {
        document.body.innerHTML = "";
        __resetEscStackForTests();
    });

    it("usa view.translate quando disponível", () => {
        const { view, root } = buildDashlet({ total: 3 });
        view.translate = (key, _category, _scope) => {
            if (key === "briefingHeadlineMany") return "{N} ações";
            if (key === "briefingCtaConfiraHoje") return "Ver";
            return undefined;
        };

        view._renderHeadline();

        const headline = root.querySelector(".togare-briefing-headline");
        expect(headline.outerHTML).toContain("3 ações");
        expect(headline.outerHTML).toContain("Ver");
    });

    it("graceful degradation se translate lança", () => {
        const { view, root } = buildDashlet({ total: 1 });
        view.translate = () => {
            throw new Error("language file missing");
        };

        expect(() => view._renderHeadline()).not.toThrow();

        const headline = root.querySelector(".togare-briefing-headline");
        expect(headline.outerHTML).toContain("1 prazo pendente"); // fallback pt-BR
    });
});

// ====== Story 4b.3 — UX-DR10 toast persistente D-0 ======

/** Cria collection mock com `models` array (cada model tem `get(name)`). */
function makeCollectionWithModels(modelsAttrs, total = null) {
    const listeners = {};
    const models = modelsAttrs.map((attrs) => ({
        get: (name) => attrs[name],
    }));
    return {
        models,
        total: total === null ? models.length : total,
        length: models.length,
        on(event, cb) { (listeners[event] = listeners[event] || []).push(cb); },
        off(event, cb) {
            if (!listeners[event]) return;
            listeners[event] = listeners[event].filter((c) => c !== cb);
        },
        trigger(event, ...args) { (listeners[event] || []).forEach((cb) => cb(...args)); },
    };
}

/** Helper — descobre BRT YMD de hoje sem mockar Date (just-in-time). */
function todayBrtYmd() {
    return new Intl.DateTimeFormat("en-CA", {
        timeZone: "America/Sao_Paulo",
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
    }).format(new Date());
}

describe("TogarePrazosDoDiaDashletView / Story 4b.3 _renderD0Toast (UX-DR10)", () => {
    beforeEach(() => {
        document.body.innerHTML = "";
        __resetEscStackForTests();
    });

    afterEach(() => {
        document.body.innerHTML = "";
    });

    it("AC9 — collection com 2 prazos D-0 pendentes → ToastTogare warning persistente é criado", () => {
        const today = todayBrtYmd();
        const root = document.createElement("div");
        root.innerHTML = '<div class="list-container"></div>';
        document.body.appendChild(root);

        const view = new TogarePrazosDoDiaDashletView({});
        view.element = root;
        view.$el = [root];
        view.collection = makeCollectionWithModels([
            { dataFatal: today, status: "pendente" },
            { dataFatal: today, status: "atrasado_reagendado" },
            { dataFatal: "2099-01-01", status: "pendente" }, // não-D0
        ]);

        view._renderD0Toast();

        // Toast persistente injetado no DOM (ToastTogare.show cria #togare-toast-stack).
        const stack = document.getElementById("togare-toast-stack");
        expect(stack).not.toBeNull();
        const toast = stack.querySelector(".togare-toast--warning");
        expect(toast).not.toBeNull();
        expect(toast.textContent).toContain("VENCE HOJE: 2 prazo(s)");
        // duration=null → sem barra de progresso.
        expect(stack.querySelector(".togare-toast__progress")).toBeNull();
        // actionLabel=null → sem botão.
        expect(stack.querySelector(".togare-toast__action")).toBeNull();
    });

    it("AC9 — collection sem prazos D-0 → ToastTogare NÃO é criado", () => {
        const root = document.createElement("div");
        root.innerHTML = '<div class="list-container"></div>';
        document.body.appendChild(root);

        const view = new TogarePrazosDoDiaDashletView({});
        view.element = root;
        view.$el = [root];
        view.collection = makeCollectionWithModels([
            { dataFatal: "2099-01-01", status: "pendente" },
            { dataFatal: "2099-01-02", status: "atrasado_reagendado" },
        ]);

        view._renderD0Toast();

        // Sem prazos D-0 → stack pode até ser criado por outras chamadas, mas
        // não tem toast warning visível.
        const stack = document.getElementById("togare-toast-stack");
        if (stack) {
            const warning = stack.querySelector(".togare-toast--warning");
            expect(warning).toBeNull();
        }
    });

    it("AC9 — count constante entre re-renders → toast NÃO duplica (idempotência)", () => {
        const today = todayBrtYmd();
        const root = document.createElement("div");
        root.innerHTML = '<div class="list-container"></div>';
        document.body.appendChild(root);

        const view = new TogarePrazosDoDiaDashletView({});
        view.element = root;
        view.$el = [root];
        view.collection = makeCollectionWithModels([
            { dataFatal: today, status: "pendente" },
        ]);

        view._renderD0Toast();
        view._renderD0Toast();
        view._renderD0Toast();

        const stack = document.getElementById("togare-toast-stack");
        expect(stack).not.toBeNull();
        const warnings = stack.querySelectorAll(".togare-toast--warning");
        expect(warnings.length).toBe(1);
    });

    it("AC9 — count cai para 0 → toast some (programaticamente)", () => {
        const today = todayBrtYmd();
        const root = document.createElement("div");
        root.innerHTML = '<div class="list-container"></div>';
        document.body.appendChild(root);

        const view = new TogarePrazosDoDiaDashletView({});
        view.element = root;
        view.$el = [root];
        view.collection = makeCollectionWithModels([
            { dataFatal: today, status: "pendente" },
        ]);

        view._renderD0Toast();
        const stackInitial = document.getElementById("togare-toast-stack");
        expect(stackInitial.querySelector(".togare-toast--warning")).not.toBeNull();

        // User protocolou — collection muda.
        view.collection = makeCollectionWithModels([
            { dataFatal: today, status: "protocolado" },
        ]);
        view._renderD0Toast();

        // Toast deve sumir após dismiss programático.
        // (300ms timeout interno do ToastTogare antes do remove — mas
        // _renderD0Toast já chamou dismiss() que adiciona classe --leaving).
        // Verifica que internamente o estado foi resetado.
        expect(view._d0ToastHandle).toBeNull();
        expect(view._d0ToastLastCount).toBe(0);
    });

    it("AC9 - dismiss programatico nao ativa fadiga e permite reaparecer", () => {
        const today = todayBrtYmd();
        const root = document.createElement("div");
        root.innerHTML = '<div class="list-container"></div>';
        document.body.appendChild(root);

        const view = new TogarePrazosDoDiaDashletView({});
        view.element = root;
        view.$el = [root];
        view.collection = makeCollectionWithModels([
            { dataFatal: today, status: "pendente" },
        ]);

        view._renderD0Toast();
        view.collection = makeCollectionWithModels([
            { dataFatal: today, status: "protocolado" },
        ]);
        view._renderD0Toast();

        expect(view._d0ToastDismissedManually).not.toBe(true);

        view.collection = makeCollectionWithModels([
            { dataFatal: today, status: "pendente" },
        ]);
        view._renderD0Toast();

        const live = Array.from(document.querySelectorAll(".togare-toast--warning"))
            .filter((el) => !el.classList.contains("togare-toast--leaving"));
        expect(live.length).toBe(1);
    });

    it("AC9 — fadiga: user dismissou manualmente → próximas renders NÃO re-criam", () => {
        const today = todayBrtYmd();
        const root = document.createElement("div");
        root.innerHTML = '<div class="list-container"></div>';
        document.body.appendChild(root);

        const view = new TogarePrazosDoDiaDashletView({});
        view.element = root;
        view.$el = [root];
        view.collection = makeCollectionWithModels([
            { dataFatal: today, status: "pendente" },
        ]);

        view._renderD0Toast();
        // Simula user pressionando ESC (dismiss manual via stack global).
        document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }));
        expect(view._d0ToastDismissedManually).toBe(true);

        // Próxima render NÃO recria toast (mesmo com count > 0).
        view.collection = makeCollectionWithModels([
            { dataFatal: today, status: "pendente" },
            { dataFatal: today, status: "atrasado_reagendado" },
        ]);
        view._renderD0Toast();

        const stack = document.getElementById("togare-toast-stack");
        const warningsAfterFadiga = stack
            ? Array.from(stack.querySelectorAll(".togare-toast--warning"))
                .filter((el) => !el.classList.contains("togare-toast--leaving"))
            : [];
        expect(warningsAfterFadiga.length).toBe(0);
    });

    it("AC9 — count muda entre renders → recria toast com message atualizada", () => {
        const today = todayBrtYmd();
        const root = document.createElement("div");
        root.innerHTML = '<div class="list-container"></div>';
        document.body.appendChild(root);

        const view = new TogarePrazosDoDiaDashletView({});
        view.element = root;
        view.$el = [root];
        view.collection = makeCollectionWithModels([
            { dataFatal: today, status: "pendente" },
        ]);

        view._renderD0Toast();
        const initialMessage = document.querySelector(".togare-toast--warning .togare-toast__message");
        expect(initialMessage.textContent).toContain("1 prazo(s)");

        // Auto-refresh trouxe mais prazos D-0.
        view.collection = makeCollectionWithModels([
            { dataFatal: today, status: "pendente" },
            { dataFatal: today, status: "pendente" },
            { dataFatal: today, status: "atrasado_reagendado" },
        ]);
        view._renderD0Toast();

        // Toast atualizado — pega o mais recente (não --leaving).
        const live = Array.from(document.querySelectorAll(".togare-toast--warning"))
            .filter((el) => !el.classList.contains("togare-toast--leaving"));
        expect(live.length).toBe(1);
        expect(live[0].querySelector(".togare-toast__message").textContent)
            .toContain("3 prazo(s)");
    });
});

describe("TogarePrazosDoDiaDashletView / Story 4b.3 _countD0PrazosInCollection", () => {
    it("conta apenas modelos com dataFatal=hoje + status ∈ família ainda em jogo", () => {
        const today = todayBrtYmd();
        const view = new TogarePrazosDoDiaDashletView({});
        view.collection = makeCollectionWithModels([
            { dataFatal: today, status: "pendente" },               // ✓
            { dataFatal: today, status: "atrasado_reagendado" },    // ✓
            { dataFatal: today, status: "aguardando_cliente" },     // ✓
            { dataFatal: today, status: "aguardando_correcao" },    // ✓
            { dataFatal: today, status: "protocolado" },            // ✗ status final
            { dataFatal: today, status: "descartado" },             // ✗ status final
            { dataFatal: today, status: "ciencia_renuncia" },       // ✗ status final
            { dataFatal: today, status: "acompanhamento" },         // ✗ status final
            { dataFatal: "2099-01-01", status: "pendente" },        // ✗ não-D0
            { dataFatal: null, status: "pendente" },                // ✗ sem dataFatal
        ]);

        expect(view._countD0PrazosInCollection()).toBe(4);
    });

    it("retorna 0 quando collection é null/sem models", () => {
        const view = new TogarePrazosDoDiaDashletView({});
        view.collection = null;
        expect(view._countD0PrazosInCollection()).toBe(0);

        view.collection = { models: undefined };
        expect(view._countD0PrazosInCollection()).toBe(0);

        view.collection = { models: "not-array" };
        expect(view._countD0PrazosInCollection()).toBe(0);
    });
});

// ====== Story 4b.3 fix-pass v0.27.1 (B26) — UX-DR10 redundância visual no dashlet ======
describe("TogarePrazosDoDiaDashletView / Story 4b.3 v0.27.1 _decorateD0Cards (B26)", () => {
    beforeEach(() => {
        document.body.innerHTML = "";
    });

    afterEach(() => {
        document.body.innerHTML = "";
    });

    /**
     * Helper — monta um root jsdom com `.list-row[data-id]` simulando o
     * markup stock do EspoCRM populado pelo Bullbone.
     */
    function buildDashletWithRows(modelsAttrs) {
        const root = document.createElement("div");
        const rowsHtml = modelsAttrs
            .map((m, i) => `<div class="list-row" data-id="${m.id || `prazo-${i}`}"><div class="cell">linha ${i}</div></div>`)
            .join("");
        root.innerHTML = `<div class="list-container">${rowsHtml}</div>`;
        document.body.appendChild(root);

        const view = new TogarePrazosDoDiaDashletView({});
        view.element = root;
        view.$el = [root];
        const modelsWithIds = modelsAttrs.map((m, i) => ({
            id: m.id || `prazo-${i}`,
            attrs: m,
        }));
        view.collection = {
            models: modelsWithIds.map((mw) => ({
                id: mw.id,
                get: (name) => mw.attrs[name],
            })),
        };
        return { view, root };
    }

    it("B26 — row com prazo D-0 + status pendente recebe modifier --d-zero + chip VENCE HOJE", () => {
        const today = todayBrtYmd();
        const { view, root } = buildDashletWithRows([
            { id: "p1", dataFatal: today, status: "pendente" },
        ]);

        view._decorateD0Cards();

        const row = root.querySelector('.list-row[data-id="p1"]');
        expect(row.classList.contains("togare-row--d-zero")).toBe(true);
        const chip = row.querySelector(".togare-row__d-zero-badge");
        expect(chip).not.toBeNull();
        expect(chip.classList.contains("togare-status-badge--vence-hoje")).toBe(true);
        expect(chip.getAttribute("aria-label")).toBe("VENCE HOJE — confirme ou adie");
        expect(chip.getAttribute("role")).toBe("status");
        expect(chip.textContent).toContain("VENCE HOJE");
        expect(chip.innerHTML).toContain("🔔");
    });

    it("B26 — row com status final (protocolado) NÃO recebe decoração mesmo com dataFatal=hoje (Decisão #4)", () => {
        const today = todayBrtYmd();
        const { view, root } = buildDashletWithRows([
            { id: "p1", dataFatal: today, status: "protocolado" },
        ]);

        view._decorateD0Cards();

        const row = root.querySelector('.list-row[data-id="p1"]');
        expect(row.classList.contains("togare-row--d-zero")).toBe(false);
        expect(row.querySelector(".togare-row__d-zero-badge")).toBeNull();
    });

    it("B26 — row com dataFatal futura NÃO recebe decoração", () => {
        const { view, root } = buildDashletWithRows([
            { id: "p1", dataFatal: "2099-01-01", status: "pendente" },
        ]);

        view._decorateD0Cards();

        const row = root.querySelector('.list-row[data-id="p1"]');
        expect(row.classList.contains("togare-row--d-zero")).toBe(false);
        expect(row.querySelector(".togare-row__d-zero-badge")).toBeNull();
    });

    it("B26 — múltiplos rows mistos: decora apenas D-0 elegíveis", () => {
        const today = todayBrtYmd();
        const { view, root } = buildDashletWithRows([
            { id: "p1", dataFatal: today, status: "pendente" },         // ✓ decora
            { id: "p2", dataFatal: today, status: "atrasado_reagendado" }, // ✓ decora
            { id: "p3", dataFatal: today, status: "protocolado" },      // ✗ status final
            { id: "p4", dataFatal: "2099-01-01", status: "pendente" },  // ✗ não-D0
        ]);

        view._decorateD0Cards();

        const decorated = root.querySelectorAll(".list-row.togare-row--d-zero");
        expect(decorated.length).toBe(2);
        const decoratedIds = Array.from(decorated).map((r) => r.getAttribute("data-id")).sort();
        expect(decoratedIds).toEqual(["p1", "p2"]);
    });

    it("B26 — idempotência: re-rodar não duplica chip", () => {
        const today = todayBrtYmd();
        const { view, root } = buildDashletWithRows([
            { id: "p1", dataFatal: today, status: "pendente" },
        ]);

        view._decorateD0Cards();
        view._decorateD0Cards();
        view._decorateD0Cards();

        const row = root.querySelector('.list-row[data-id="p1"]');
        const chips = row.querySelectorAll(".togare-row__d-zero-badge");
        expect(chips.length).toBe(1);
    });

    it("B26 — row deixa de ser D-0 (status mudou para protocolado) → undecorate remove chip + classe", () => {
        const today = todayBrtYmd();
        const { view, root } = buildDashletWithRows([
            { id: "p1", dataFatal: today, status: "pendente" },
        ]);

        view._decorateD0Cards();
        const row = root.querySelector('.list-row[data-id="p1"]');
        expect(row.classList.contains("togare-row--d-zero")).toBe(true);
        expect(row.querySelector(".togare-row__d-zero-badge")).not.toBeNull();

        // Auto-refresh trouxe o mesmo prazo agora protocolado.
        view.collection = {
            models: [{ id: "p1", get: (name) => ({ dataFatal: today, status: "protocolado" }[name]) }],
        };
        view._decorateD0Cards();

        expect(row.classList.contains("togare-row--d-zero")).toBe(false);
        expect(row.querySelector(".togare-row__d-zero-badge")).toBeNull();
    });

    it("B26 — row sem model correspondente na collection → undecora silenciosamente (defensivo)", () => {
        const today = todayBrtYmd();
        const { view, root } = buildDashletWithRows([
            { id: "p1", dataFatal: today, status: "pendente" },
        ]);

        // Decora primeiro
        view._decorateD0Cards();
        const row = root.querySelector('.list-row[data-id="p1"]');
        expect(row.classList.contains("togare-row--d-zero")).toBe(true);

        // Collection vazia (model removido) — chip deve ser removido.
        view.collection = { models: [] };
        view._decorateD0Cards();
        expect(row.classList.contains("togare-row--d-zero")).toBe(false);
        expect(row.querySelector(".togare-row__d-zero-badge")).toBeNull();
    });

    it("B26 — sem element/root → no-op silencioso", () => {
        const view = new TogarePrazosDoDiaDashletView({});
        view.element = null;
        view.$el = null;
        view.collection = { models: [] };

        expect(() => view._decorateD0Cards()).not.toThrow();
    });

    it("B26 — sem .list-row no DOM → no-op silencioso", () => {
        const root = document.createElement("div");
        root.innerHTML = '<div class="list-container"><div>vazio</div></div>';
        document.body.appendChild(root);

        const view = new TogarePrazosDoDiaDashletView({});
        view.element = root;
        view.$el = [root];
        view.collection = { models: [] };

        expect(() => view._decorateD0Cards()).not.toThrow();
        expect(root.querySelector(".togare-row__d-zero-badge")).toBeNull();
    });
});
