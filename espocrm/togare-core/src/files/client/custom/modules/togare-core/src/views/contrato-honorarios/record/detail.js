/**
 * ContratoHonorarios detail view (Story 6.1 — Discovery #2 retro Epic 5).
 *
 * Adiciona o item **Baixar** ao `dropdownItemList` (menu "...") da detail page
 * standalone do ContratoHonorarios (`#ContratoHonorarios/view/<id>`). Fluxo
 * principal de download continua sendo o painel relacional (Cliente/Processo)
 * onde o item já foi exposto via `relationship-with-download` row-actions.
 *
 * Plugado via `clientDefs.ContratoHonorarios.recordViews.detail`.
 *
 * Pattern literal de Documento detail.js (5.3 fix-pass).
 */
import DetailView from "views/record/detail";

export default class ContratoHonorariosDetailView extends DetailView {
    setupActionItems() {
        super.setupActionItems();

        if (!this.model || !this.getAcl().checkModel(this.model, "read")) {
            return;
        }

        this.dropdownItemList = this.dropdownItemList || [];

        // Evita duplicar o item caso `setupActionItems` rode mais de uma vez.
        const alreadyPresent = this.dropdownItemList.some(
            (item) => item && item.name === "download",
        );
        if (alreadyPresent) {
            return;
        }

        this.dropdownItemList.unshift({
            name: "download",
            label: "Baixar",
            action: "download",
            groupIndex: 0,
        });
    }

    /**
     * Override do action [Baixar].
     * Endpoint canônico Espo: GET /api/v1/ContratoHonorarios/action/download?id=<id>.
     */
    actionDownload() {
        const id = this.model && this.model.id;
        if (!id) {
            return;
        }

        this._openDownloadUrl(
            "api/v1/ContratoHonorarios/action/download?id=" + encodeURIComponent(id),
        );
    }

    _openDownloadUrl(url) {
        if (typeof document === "undefined" || !document.body) {
            if (typeof window !== "undefined" && window.location) {
                window.location.href = url;
            }
            return;
        }

        const iframe = document.createElement("iframe");
        iframe.setAttribute("aria-hidden", "true");
        iframe.style.display = "none";
        iframe.src = url;
        document.body.appendChild(iframe);

        if (typeof window !== "undefined" && typeof window.setTimeout === "function") {
            window.setTimeout(() => {
                if (iframe.parentNode) {
                    iframe.parentNode.removeChild(iframe);
                }
            }, 60 * 1000);
        }
    }
}
