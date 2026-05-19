/**
 * Fatura record/list view (Story 6.3 — T8.2).
 *
 * Override de `actionRegistrarPagamento` para row-actions (panel inline).
 * Acionado pelo row-actions/relationship-with-pagamento.js quando user clica
 * em "Registrar pagamento" no menu da row.
 *
 * Plugado via `clientDefs.Fatura.recordViews.list`.
 */
import ListView from "views/record/list";

export default class FaturaRecordListView extends ListView {
    setup() {
        super.setup();
        // Não sobrescrever rowActionsView se panel pai já passou um custom.
        if (
            this.options &&
            typeof this.options.rowActionsView !== "undefined" &&
            this.options.rowActionsView
        ) {
            return;
        }
        this.rowActionsView = "views/record/row-actions/default";
    }

    /**
     * Action [Registrar pagamento] disparado pela row-actions custom.
     * Carrega a fatura pelo id, depois abre modal de pagamento.
     */
    actionRegistrarPagamento(data) {
        if (!data || !data.id) {
            return;
        }

        const Model = this.getCollectionFactory
            ? this.getCollectionFactory().getEntityClass
            : null;

        // Busca a fatura no banco para pegar saldo atual.
        Espo.Ajax.getRequest("Fatura/" + encodeURIComponent(data.id))
            .then((fatura) => {
                if (!fatura) {
                    Espo.Ui.error("Fatura não encontrada.");
                    return;
                }
                if (fatura.status === "cancelada" || fatura.status === "paga") {
                    Espo.Ui.warning(
                        fatura.status === "cancelada"
                            ? this.translate("faturaJaCancelada", "messages", "Fatura") ||
                              "Esta fatura está cancelada."
                            : this.translate("statusJaPaga", "messages", "Fatura") ||
                              "Esta fatura já está paga.",
                    );
                    return;
                }

                const saldo = parseFloat(fatura.saldo || 0);
                const valorBruto = parseFloat(fatura.valorBruto || 0);
                const tipoInferido =
                    valorBruto > 0.001 && Math.abs(saldo - valorBruto) < 0.01
                        ? "pagamento_total"
                        : "pagamento_parcial";

                this.createView(
                    "registrarPagamentoModal",
                    "togare-core:views/lancamento-financeiro/modals/registrar-pagamento",
                    {
                        faturaId: fatura.id,
                        clienteId: fatura.clienteId,
                        clienteName: fatura.clienteName,
                        processoId: fatura.processoId,
                        saldoSugerido: saldo,
                        tipoSugerido: tipoInferido,
                    },
                    (view) => {
                        view.render();
                        view.listenToOnce(view, "after:save", () => {
                            this.collection.fetch();
                        });
                    },
                );
            })
            .catch(() => {
                Espo.Ui.error("Não foi possível carregar a fatura.");
            });
    }
}
