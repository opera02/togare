import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import PublicacaoAmbiguaListView from "../../src/files/client/custom/modules/togare-core/src/views/publicacao-ambigua/record/list.js";

/**
 * Story 4b.1c — list view custom com mass-action `bulkIgnoreProcesso`
 * (AC2 / AC12).
 */

function makeRowModel(processoIds = [], selected = false) {
    const candidatos = processoIds.map((pid, i) => ({
        processoId: pid,
        numeroCnj: `${pid}-cnj`,
        clienteNome: `Cliente ${i}`,
        parteContrariaNome: `Parte ${i}`,
        codigoCor: ["azul", "laranja", "verde", "roxo", "vermelho"][i] || "azul",
    }));
    const data = {
        id: `pub-${processoIds.join("-")}`,
        candidatos: JSON.stringify(candidatos),
    };
    return {
        id: data.id,
        _selected: selected,
        attributes: data,
        get(k) {
            return data[k];
        },
    };
}

function buildView({ rows = [] } = {}) {
    const collection = {
        models: rows,
        fetch: vi.fn(),
    };
    return new PublicacaoAmbiguaListView({ collection });
}

describe("PublicacaoAmbiguaListView (AC2)", () => {
    let originalEspo;
    let postSpy;

    beforeEach(() => {
        postSpy = vi.fn(() => Promise.resolve({ count: 5 }));
        originalEspo = window.Espo;
        window.Espo = {
            Ajax: { postRequest: postSpy },
            Ui: {
                Dialog: vi.fn().mockImplementation(function (opts) {
                    this.opts = opts;
                    this.show = vi.fn(() => {
                        const first = (opts && opts.buttonList && opts.buttonList[0]) || null;
                        if (first && typeof first.onClick === "function") first.onClick(this);
                    });
                    this.close = vi.fn();
                    this.$el = [document.createElement("div")];
                }),
                success: vi.fn(),
                warning: vi.fn(),
                error: vi.fn(),
            },
        };
    });

    afterEach(() => {
        window.Espo = originalEspo;
    });

    it("setup() registra mass-action `bulkIgnoreProcesso` no massActionList", () => {
        const v = buildView();
        v.setup();
        expect(v.massActionList).toContain("bulkIgnoreProcesso");
    });

    it("setup() é idempotente (não duplica entry se chamado 2x)", () => {
        const v = buildView();
        v.setup();
        v.setup();
        const occurrences = v.massActionList.filter((x) => x === "bulkIgnoreProcesso");
        expect(occurrences.length).toBe(1);
    });

    it("_intersectProcessoIds — 2 rows com 1 processoId em comum", () => {
        const r1 = makeRowModel(["p-x", "p-y"], true);
        const r2 = makeRowModel(["p-x", "p-z"], true);
        const v = buildView({ rows: [r1, r2] });
        const intersect = v._intersectProcessoIds([r1, r2]);
        expect(intersect).toEqual(["p-x"]);
    });

    it("_intersectProcessoIds — 2 rows sem processoId comum retorna []", () => {
        const r1 = makeRowModel(["p-a", "p-b"], true);
        const r2 = makeRowModel(["p-c", "p-d"], true);
        const v = buildView({ rows: [r1, r2] });
        expect(v._intersectProcessoIds([r1, r2])).toEqual([]);
    });

    it("massActionBulkIgnoreProcesso — 1 processoId comum dispara dialog + POST", async () => {
        const r1 = makeRowModel(["p-x", "p-y"], true);
        const r2 = makeRowModel(["p-x", "p-z"], true);
        const v = buildView({ rows: [r1, r2] });
        v.setup();
        v.massActionBulkIgnoreProcesso();
        expect(window.Espo.Ui.Dialog).toHaveBeenCalled();
        // Auto-confirm em Dialog.show → POST roda
        await Promise.resolve();
        await Promise.resolve();
        expect(postSpy).toHaveBeenCalledTimes(1);
        const [path, body] = postSpy.mock.calls[0];
        expect(path).toBe("TogareDjenPublicacaoAmbigua/action/bulkIgnoreProcesso");
        expect(body).toEqual({ processoId: "p-x" });
    });

    it("massActionBulkIgnoreProcesso — 2 processoIds comuns abre chooser e posta escolhido", async () => {
        window.Espo.Ui.Dialog.mockImplementationOnce(function (opts) {
            this.opts = opts;
            const root = document.createElement("div");
            root.innerHTML = opts.body;
            this.$el = [root];
            this.close = vi.fn();
            this.show = vi.fn(() => {
                const first = (opts && opts.buttonList && opts.buttonList[0]) || null;
                if (first && typeof first.onClick === "function") first.onClick(this);
            });
        });
        const r1 = makeRowModel(["p-x", "p-y"], true);
        const r2 = makeRowModel(["p-x", "p-y"], true);
        const v = buildView({ rows: [r1, r2] });
        v.setup();
        v.massActionBulkIgnoreProcesso();
        expect(window.Espo.Ui.Dialog).toHaveBeenCalled();
        const dialogOpts = window.Espo.Ui.Dialog.mock.calls[0][0];
        expect(dialogOpts.body).toContain("p-x");
        expect(dialogOpts.body).toContain("p-y");
        await Promise.resolve();
        await Promise.resolve();
        expect(postSpy).toHaveBeenCalledWith(
            "TogareDjenPublicacaoAmbigua/action/bulkIgnoreProcesso",
            { processoId: "p-x" },
        );
    });

    it("massActionBulkIgnoreProcesso bloqueia selected-all e mantém escopo linhas visíveis", () => {
        const r1 = makeRowModel(["p-x"], true);
        const v = buildView({ rows: [r1] });
        v.setup();
        v.allResultSelected = true;
        v.massActionBulkIgnoreProcesso();
        expect(window.Espo.Ui.warning).toHaveBeenCalled();
        expect(postSpy).not.toHaveBeenCalled();
    });

    it("massActionBulkIgnoreProcesso sem seleção exibe Espo.Ui.warning", () => {
        const v = buildView({ rows: [] });
        v.setup();
        v.massActionBulkIgnoreProcesso();
        expect(window.Espo.Ui.warning).toHaveBeenCalled();
        expect(postSpy).not.toHaveBeenCalled();
    });
});
