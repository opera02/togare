/**
 * LancamentoFinanceiro record/detail view (Story 6.3 — T9.3).
 *
 * Override de afterRender para aplicar badge colorido no campo `tipo` da
 * detail page (Decisão #14 da spec) via DOM patch. Mesmo mapping do list.js.
 *
 * Plugado via `clientDefs.LancamentoFinanceiro.recordViews.detail`.
 */
import DetailView from "views/record/detail";

const TIPO_COR_MAP = {
    pagamento_total: "verde",
    pagamento_parcial: "verde",
    receita_avulsa: "verde",
    despesa_interna: "vermelho",
    acerto: "amarelo",
    estorno: "laranja",
};

export default class LancamentoFinanceiroRecordDetailView extends DetailView {
    afterRender() {
        if (typeof super.afterRender === "function") {
            super.afterRender();
        }
        this._decorateTipoBadge();
    }

    _decorateTipoBadge() {
        const $el = this.$el;
        if (!$el || typeof $el.find !== "function") {
            return;
        }

        const tipo = this.model ? this.model.get("tipo") : null;
        if (!tipo) {
            return;
        }
        const cor = TIPO_COR_MAP[tipo];
        if (!cor) {
            return;
        }

        const $cell = $el.find('.field[data-name="tipo"]');
        if (!$cell.length) {
            return;
        }
        $cell.removeClass(
            "togare-row__tipo--verde togare-row__tipo--vermelho togare-row__tipo--amarelo togare-row__tipo--laranja",
        );
        $cell.addClass(`togare-row__tipo--${cor}`);
    }
}
