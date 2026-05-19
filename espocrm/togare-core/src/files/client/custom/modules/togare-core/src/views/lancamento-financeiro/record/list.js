/**
 * LancamentoFinanceiro record/list view (Story 6.3 — T9.2).
 *
 * Override de afterRender para aplicar badge colorido na coluna `tipo`
 * (Decisão #14 da spec) via DOM patch (memória feedback_espocrm_field_view_dom_patch.md).
 *
 * Cores semânticas (WCAG AA contraste):
 *  - verde (#155724 sobre #d4edda)   → pagamento_total | pagamento_parcial | receita_avulsa
 *  - vermelho (#721c24 sobre #f8d7da) → despesa_interna
 *  - amarelo (#856404 sobre #fff3cd) → acerto
 *  - laranja (#804000 sobre #ffd8b1) → estorno
 *
 * Plugado via `clientDefs.LancamentoFinanceiro.recordViews.list`.
 */
import ListView from "views/record/list";

const TIPO_COR_MAP = {
    pagamento_total: "verde",
    pagamento_parcial: "verde",
    receita_avulsa: "verde",
    despesa_interna: "vermelho",
    acerto: "amarelo",
    estorno: "laranja",
};

export default class LancamentoFinanceiroRecordListView extends ListView {
    setup() {
        super.setup();
        if (
            this.options &&
            typeof this.options.rowActionsView !== "undefined" &&
            this.options.rowActionsView
        ) {
            return;
        }
        this.rowActionsView = "views/record/row-actions/default";
    }

    afterRender() {
        if (typeof super.afterRender === "function") {
            super.afterRender();
        }
        this._decorateTipoBadges();
    }

    /**
     * DOM patch — encontra cada célula `[data-name="tipo"]` e aplica classe
     * `togare-row__tipo--<cor>` correspondente ao valor do tipo.
     * Idempotente: re-aplicação remove classes anteriores antes de adicionar.
     */
    _decorateTipoBadges() {
        if (!this.collection || !this.collection.models) {
            return;
        }
        const $el = this.$el;
        if (!$el || typeof $el.find !== "function") {
            return;
        }

        this.collection.models.forEach((model) => {
            const tipo = model.get("tipo");
            const cor = TIPO_COR_MAP[tipo];
            if (!cor) {
                return;
            }
            const $row = $el.find(`.list-row[data-id="${model.id}"]`);
            if (!$row.length) {
                return;
            }
            const $cell = $row.find('[data-name="tipo"]');
            if (!$cell.length) {
                return;
            }
            // Remove classes anteriores e aplica nova
            $cell.removeClass(
                "togare-row__tipo--verde togare-row__tipo--vermelho togare-row__tipo--amarelo togare-row__tipo--laranja",
            );
            $cell.addClass(`togare-row__tipo--${cor}`);
            $cell.attr("aria-label", `Lançamento: ${this._translateTipo(tipo)}`);
        });
    }

    _translateTipo(tipo) {
        if (typeof this.translate === "function") {
            return this.translate(tipo, "options.tipo", "LancamentoFinanceiro") || tipo;
        }
        return tipo;
    }
}
