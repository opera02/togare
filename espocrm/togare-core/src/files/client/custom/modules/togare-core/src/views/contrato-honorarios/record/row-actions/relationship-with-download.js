/**
 * Row actions view custom para o painel "Contratos de honorários" em Cliente/Processo.
 *
 * Estende `views/record/row-actions/relationship` (View / Edit / Unlink /
 * Remove) e injeta o item **Baixar** logo após "Edit" (groupIndex=0) — fluxo
 * de download real via Controller `getActionDownload` (Story 6.1 — T6).
 *
 * O clique no item dispara `actionDownload(data)` que JÁ existe em
 * `togare-core:views/contrato-honorarios/record/list` (abre iframe oculto
 * apontando para `GET /api/v1/ContratoHonorarios/action/download?id=<id>`).
 *
 * Plugado via `clientDefs.<Cliente|Processo>.relationshipPanels.contratosHonorarios.rowActionsView`.
 *
 * Pattern literal de Documento row-actions/relationship-with-download.js (5.3
 * fix-pass) — Discovery #2 retro Epic 5 manda aplicar desde D0.
 */
import RelationshipRowActionsView from "views/record/row-actions/relationship";

export default class ContratoHonorariosRelationshipRowActionsView extends RelationshipRowActionsView {
    getActionList() {
        const list = super.getActionList();

        // Procura a posição do item "Edit" (action: quickEdit). Item "Baixar" entra
        // logo depois para ficar próximo das ações de manipulação do registro.
        const insertAfter = list.findIndex((item) => item && item.action === "quickEdit");
        const downloadItem = {
            action: "download",
            label: "Baixar",
            data: { id: this.model.id },
            groupIndex: 0,
        };

        if (insertAfter >= 0) {
            list.splice(insertAfter + 1, 0, downloadItem);
        } else {
            // Fallback: insere logo após "View" (quickView) ou no início.
            const viewIdx = list.findIndex((item) => item && item.action === "quickView");
            list.splice(viewIdx >= 0 ? viewIdx + 1 : 0, 0, downloadItem);
        }

        return list;
    }
}
