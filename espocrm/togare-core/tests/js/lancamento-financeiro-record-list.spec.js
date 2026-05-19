/**
 * Testes da LancamentoFinanceiroRecordListView (Story 6.3 — T9.2).
 *
 * Cobre: afterRender aplica badge colorido `togare-row__tipo--{cor}` via
 * DOM patch conforme TIPO_COR_MAP (Decisão #14).
 */

import { describe, it, expect, vi } from "vitest";
import LancamentoFinanceiroRecordListView from "togare-core:views/lancamento-financeiro/record/list";

const makeView = (modelsAttrs = []) => {
    const view = new LancamentoFinanceiroRecordListView({});
    view.collection = {
        models: modelsAttrs.map((attrs, idx) => ({
            id: `lanc-${idx}`,
            get: (k) => attrs[k] ?? null,
        })),
    };
    view.translate = (key, _category, _scope) => {
        const map = {
            pagamento_total: "Pagamento total",
            pagamento_parcial: "Pagamento parcial",
            despesa_interna: "Despesa interna",
            receita_avulsa: "Receita avulsa",
            acerto: "Acerto",
            estorno: "Estorno",
        };
        return map[key] || key;
    };

    // Mock $el (jQuery-like find/removeClass/addClass/attr).
    const cellsByCell = {};
    const makeCell = () => ({
        length: 1,
        removeClass: vi.fn(function () {
            return this;
        }),
        addClass: vi.fn(function () {
            return this;
        }),
        attr: vi.fn(function () {
            return this;
        }),
    });

    const rows = view.collection.models.map((m) => {
        const $cell = makeCell();
        cellsByCell[m.id] = $cell;
        return {
            id: m.id,
            $row: {
                length: 1,
                find: (sel) => (sel === '[data-name="tipo"]' ? $cell : { length: 0 }),
            },
        };
    });

    view.$el = {
        find: (sel) => {
            const match = sel.match(/data-id="([^"]+)"/);
            if (!match) return { length: 0 };
            const id = match[1];
            const row = rows.find((r) => r.id === id);
            return row ? row.$row : { length: 0 };
        },
    };

    view._cells = cellsByCell;
    return view;
};

describe("LancamentoFinanceiroRecordListView - _decorateTipoBadges", () => {
    it("aplica classe verde para pagamento_total", () => {
        const view = makeView([{ tipo: "pagamento_total" }]);
        view._decorateTipoBadges();
        const $cell = view._cells["lanc-0"];
        expect($cell.addClass).toHaveBeenCalledWith("togare-row__tipo--verde");
    });

    it("aplica classe verde para pagamento_parcial e receita_avulsa", () => {
        const view = makeView([
            { tipo: "pagamento_parcial" },
            { tipo: "receita_avulsa" },
        ]);
        view._decorateTipoBadges();
        expect(view._cells["lanc-0"].addClass).toHaveBeenCalledWith(
            "togare-row__tipo--verde",
        );
        expect(view._cells["lanc-1"].addClass).toHaveBeenCalledWith(
            "togare-row__tipo--verde",
        );
    });

    it("aplica classe vermelho para despesa_interna", () => {
        const view = makeView([{ tipo: "despesa_interna" }]);
        view._decorateTipoBadges();
        expect(view._cells["lanc-0"].addClass).toHaveBeenCalledWith(
            "togare-row__tipo--vermelho",
        );
    });

    it("aplica classe amarelo para acerto", () => {
        const view = makeView([{ tipo: "acerto" }]);
        view._decorateTipoBadges();
        expect(view._cells["lanc-0"].addClass).toHaveBeenCalledWith(
            "togare-row__tipo--amarelo",
        );
    });

    it("aplica classe laranja para estorno", () => {
        const view = makeView([{ tipo: "estorno" }]);
        view._decorateTipoBadges();
        expect(view._cells["lanc-0"].addClass).toHaveBeenCalledWith(
            "togare-row__tipo--laranja",
        );
    });

    it("aria-label injetado com tipo traduzido", () => {
        const view = makeView([{ tipo: "pagamento_total" }]);
        view._decorateTipoBadges();
        expect(view._cells["lanc-0"].attr).toHaveBeenCalledWith(
            "aria-label",
            "Lançamento: Pagamento total",
        );
    });

    it("idempotente: removeClass anteriores antes de adicionar nova", () => {
        const view = makeView([{ tipo: "pagamento_total" }]);
        view._decorateTipoBadges();
        const removeCall = view._cells["lanc-0"].removeClass.mock.calls[0][0];
        expect(removeCall).toContain("togare-row__tipo--verde");
        expect(removeCall).toContain("togare-row__tipo--vermelho");
        expect(removeCall).toContain("togare-row__tipo--amarelo");
        expect(removeCall).toContain("togare-row__tipo--laranja");
    });

    it("tipo desconhecido é ignorado (no-op defensivo)", () => {
        const view = makeView([{ tipo: "tipo_invalido" }]);
        view._decorateTipoBadges();
        expect(view._cells["lanc-0"].addClass).not.toHaveBeenCalled();
    });
});
