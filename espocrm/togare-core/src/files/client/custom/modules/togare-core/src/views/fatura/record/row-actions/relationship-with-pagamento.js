/**
 * Row actions view custom para o painel "Faturas" em Cliente/Processo/
 * ContratoHonorarios.
 *
 * Story 6.3 — T8.3. Pattern literal de ContratoHonorarios
 * relationship-with-download.js + Documento equivalent (Discovery #2 retro
 * Epic 5).
 *
 * Injeta item "Registrar pagamento" no menu da row, logo após "Edit" (quickEdit).
 * Click dispara `actionRegistrarPagamento(data)` que está em
 * `togare-core:views/fatura/record/list`.
 *
 * Apenas mostra o item quando user tem ACL create em LancamentoFinanceiro
 * E a fatura não está cancelada/paga.
 *
 * Plugado via `clientDefs.<Cliente|Processo|ContratoHonorarios>.relationshipPanels.faturas.rowActionsView`.
 */
import RelationshipRowActionsView from "views/record/row-actions/relationship";

export default class FaturaRelationshipRowActionsView extends RelationshipRowActionsView {
    getActionList() {
        const list = super.getActionList();

        const status = this.model && this.model.get ? this.model.get("status") : null;
        const isOpen = status !== "cancelada" && status !== "paga";

        const canCreate =
            this.getAcl && typeof this.getAcl === "function"
                ? this.getAcl().check("LancamentoFinanceiro", "create")
                : true;

        if (!isOpen || !canCreate) {
            return list;
        }

        const insertAfter = list.findIndex((item) => item && item.action === "quickEdit");
        const pagamentoItem = {
            action: "registrarPagamento",
            label:
                this.getLanguage && typeof this.getLanguage === "function"
                    ? this.getLanguage().translate("Registrar pagamento", "labels", "Fatura") ||
                      "Registrar pagamento"
                    : "Registrar pagamento",
            data: { id: this.model.id },
            groupIndex: 0,
        };

        if (insertAfter >= 0) {
            list.splice(insertAfter + 1, 0, pagamentoItem);
        } else {
            const viewIdx = list.findIndex((item) => item && item.action === "quickView");
            list.splice(viewIdx >= 0 ? viewIdx + 1 : 0, 0, pagamentoItem);
        }

        return list;
    }
}
