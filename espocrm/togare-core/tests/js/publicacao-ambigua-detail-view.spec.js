import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import PublicacaoAmbiguaDetailView from "../../src/files/client/custom/modules/togare-core/src/views/publicacao-ambigua/record/detail.js";

/**
 * Story 4b.1c — UX C9 QueueNavegavel (header injetado em afterRender).
 *
 * Cobre AC3 (header "Item N de M" + ←/→ + dropdown bulk + aria-live polite +
 * keyboard shortcuts + _fetchQueue chamando getRequest com boolFilterList
 * precisaSuaLeitura).
 */

function makeModel(id = "pub-1", overrides = {}) {
    const data = {
        id,
        candidatos: JSON.stringify([
            {
                processoId: "proc-a",
                numeroCnj: "12345678920248260001",
                clienteNome: "João Silva",
                parteContrariaNome: "Maria Souza",
                dataDistribuicao: "2024-03-15",
                area: "civel",
                fase: "conhecimento",
                codigoCor: "azul",
            },
            {
                processoId: "proc-b",
                numeroCnj: "23456789020248260001",
                clienteNome: "João Silva",
                parteContrariaNome: "Pedro Costa",
                dataDistribuicao: "2024-07-02",
                area: "civel",
                fase: "conhecimento",
                codigoCor: "laranja",
            },
        ]),
        ...overrides,
    };
    return {
        id,
        attributes: data,
        get(k) {
            return data[k];
        },
        on() {},
    };
}

function buildView({ id = "pub-1", router = null, queueIds = null, readonly = false } = {}) {
    const view = new PublicacaoAmbiguaDetailView({ model: makeModel(id) });
    view.el = document.createElement("div");
    const middle = document.createElement("div");
    middle.className = "middle";
    view.el.appendChild(middle);
    document.body.appendChild(view.el);
    view.getRouter = () => router;
    view.getAcl = () => ({ check: () => !readonly });
    view.createView = vi.fn();
    if (Array.isArray(queueIds)) view._queueIds = queueIds;
    return view;
}

describe("PublicacaoAmbiguaDetailView — QueueNavegavel injection (AC3)", () => {
    let originalEspo;
    let getSpy;

    beforeEach(() => {
        originalEspo = window.Espo;
        getSpy = vi.fn(() => Promise.resolve({ list: [{ id: "pub-1" }, { id: "pub-2" }, { id: "pub-3" }] }));
        window.Espo = {
            Ajax: { getRequest: getSpy, postRequest: vi.fn() },
            Ui: {
                Dialog: vi.fn(),
                success: vi.fn(),
                warning: vi.fn(),
                error: vi.fn(),
            },
        };
    });

    afterEach(() => {
        window.Espo = originalEspo;
        document.body.innerHTML = "";
    });

    it("afterRender injeta header data-togare-queue-navegavel ANTES do .middle", () => {
        const v = buildView({ id: "pub-1", queueIds: ["pub-1", "pub-2", "pub-3"] });
        v.afterRender();
        const header = v.el.querySelector("[data-togare-queue-navegavel]");
        expect(header).toBeTruthy();
        const middle = v.el.querySelector(".middle");
        expect(header.compareDocumentPosition(middle) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });

    it('contador renderiza "Item N de M" via aria-live polite', () => {
        const v = buildView({ id: "pub-2", queueIds: ["pub-1", "pub-2", "pub-3"] });
        v.afterRender();
        const counter = v.el.querySelector('[data-role="queue-counter"]');
        expect(counter).toBeTruthy();
        expect(counter.getAttribute("aria-live")).toBe("polite");
        expect(counter.textContent).toMatch(/2/);
        expect(counter.textContent).toMatch(/3/);
    });

    it("← desabilitado no primeiro item, → desabilitado no último", () => {
        const v1 = buildView({ id: "pub-1", queueIds: ["pub-1", "pub-2", "pub-3"] });
        v1.afterRender();
        expect(v1.el.querySelector('[data-action="queue-prev"]').disabled).toBe(true);
        expect(v1.el.querySelector('[data-action="queue-next"]').disabled).toBe(false);

        const v3 = buildView({ id: "pub-3", queueIds: ["pub-1", "pub-2", "pub-3"] });
        v3.afterRender();
        expect(v3.el.querySelector('[data-action="queue-prev"]').disabled).toBe(false);
        expect(v3.el.querySelector('[data-action="queue-next"]').disabled).toBe(true);
    });

    it("_navigateQueue chama router.navigate com hash do próximo id", () => {
        const router = { navigate: vi.fn() };
        const v = buildView({ id: "pub-2", queueIds: ["pub-1", "pub-2", "pub-3"], router });
        v.afterRender();
        v._navigateQueue(1);
        expect(router.navigate).toHaveBeenCalledWith("#PublicacaoAmbigua/view/pub-3", { trigger: true });
        v._navigateQueue(-1);
        expect(router.navigate).toHaveBeenCalledWith("#PublicacaoAmbigua/view/pub-1", { trigger: true });
    });

    it("_fetchQueue chama Espo.Ajax.getRequest com boolFilterList=[precisaSuaLeitura] + maxSize 100", async () => {
        const v = buildView({ id: "pub-1" });
        await v._fetchQueue();
        expect(getSpy).toHaveBeenCalledTimes(1);
        const [path, params] = getSpy.mock.calls[0];
        expect(path).toBe("PublicacaoAmbigua");
        expect(params).toMatchObject({
            boolFilterList: ["precisaSuaLeitura"],
            maxSize: 100,
            select: "id",
            orderBy: "createdAt",
        });
        expect(v._queueIds).toEqual(["pub-1", "pub-2", "pub-3"]);
    });

    it("dropdown bulk-action lista 1 entry por candidato (Candidato A, Candidato B)", () => {
        const v = buildView({ id: "pub-1", queueIds: ["pub-1"] });
        v.afterRender();
        const items = v.el.querySelectorAll(".togare-pub-ambigua__bulk-menu-item");
        expect(items.length).toBe(2);
        expect(items[0].textContent).toMatch(/Candidato A/);
        expect(items[1].textContent).toMatch(/Candidato B/);
        expect(items[0].dataset.processoId).toBe("proc-a");
        expect(items[1].dataset.processoId).toBe("proc-b");
    });

    it("botões de navegação têm aria-label textual sem setas visuais", () => {
        const v = buildView({ id: "pub-1", queueIds: ["pub-1", "pub-2"] });
        v.afterRender();
        expect(v.el.querySelector('[data-action="queue-prev"]').getAttribute("aria-label")).toBe("Item anterior");
        expect(v.el.querySelector('[data-action="queue-next"]').getAttribute("aria-label")).toBe("Próximo item");
    });

    it("read-only desabilita bulk menu e impede POST pelo header", () => {
        const v = buildView({ id: "pub-1", queueIds: ["pub-1"], readonly: true });
        v.afterRender();
        const toggle = v.el.querySelector('[data-action="queue-bulk-toggle"]');
        expect(toggle.disabled).toBe(true);
        v._onBulkIgnoreCandidato("proc-a", "A", "12345678920248260001");
        expect(window.Espo.Ajax.postRequest).not.toHaveBeenCalled();
    });

    it("_redirectToNextOrList avança para o item após o atual na queue", async () => {
        const router = { navigate: vi.fn() };
        const v = buildView({ id: "pub-2", queueIds: ["pub-1", "pub-2", "pub-3"], router });
        await v.redirectToNextOrList();
        expect(router.navigate).toHaveBeenCalledWith("#PublicacaoAmbigua/view/pub-3", { trigger: true });
    });

    it("_redirectToNextOrList aguarda fetch pendente antes de decidir destino", async () => {
        const router = { navigate: vi.fn() };
        let resolveQueue;
        const v = buildView({ id: "pub-1", router });
        v._queuePromise = new Promise((resolve) => {
            resolveQueue = resolve;
        });
        const redirectPromise = v.redirectToNextOrList();
        expect(router.navigate).not.toHaveBeenCalled();
        v._queueIds = ["pub-1", "pub-2"];
        resolveQueue();
        await redirectPromise;
        expect(router.navigate).toHaveBeenCalledWith("#PublicacaoAmbigua/view/pub-2", { trigger: true });
    });
});
