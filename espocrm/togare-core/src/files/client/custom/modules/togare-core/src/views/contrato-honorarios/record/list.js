/**
 * ContratoHonorarios record/list view (Story 6.1 — Discovery #2 retro Epic 5).
 *
 * Override de actionDownload + actionRemove para:
 *  - download real via browser: usa URL direta para preservar fluxo de arquivo.
 *  - remove → confirm dialog pt-BR pattern Documento.
 *
 * Plugado via `clientDefs.ContratoHonorarios.recordViews.list`.
 *
 * Não cria row view custom — vanilla list view + relationship-with-download
 * row-actions (em panel) basta.
 *
 * Pattern literal de Documento list.js.
 */
import ListView from "views/record/list";

export default class ContratoHonorariosListView extends ListView {
    setup() {
        super.setup();
        // Não sobrescrever `rowActionsView` se o panel pai (Cliente/Processo) já
        // passou uma row-actions view custom via
        // `clientDefs.<Pai>.relationshipPanels.contratosHonorarios.rowActionsView`.
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
     * Override do action [Baixar].
     * Endpoint canônico Espo: GET /api/v1/ContratoHonorarios/action/download?id=<id>.
     */
    actionDownload(data) {
        if (!data || !data.id) {
            return;
        }

        this._openDownloadUrl(
            "api/v1/ContratoHonorarios/action/download?id=" + encodeURIComponent(data.id),
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

    /**
     * Override do action [Remover] com confirm dialog pt-BR.
     */
    actionQuickRemove(data) {
        return this.actionRemove(data);
    }

    actionRemove(data) {
        const id = data && data.id;
        if (!id) {
            return;
        }

        const confirmMsg =
            this.translate("removeConfirm", "messages", "ContratoHonorarios") ||
            "Remover este contrato? O PDF irá para a lixeira por 30 dias.";

        Espo.Ui.confirm(
            confirmMsg,
            {
                confirmText: this.translate("Remove"),
                cancelText: this.translate("Cancel"),
            },
            () => {
                Espo.Ajax.deleteRequest("ContratoHonorarios/" + id)
                    .then(() => {
                        Espo.Ui.success(
                            this.translate("removeSuccess", "messages", "ContratoHonorarios") ||
                                "Contrato removido.",
                        );
                        this.collection.fetch();
                    })
                    .catch((xhr) => {
                        let detail = "";
                        try {
                            if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                                detail = String(xhr.responseJSON.message);
                            } else if (xhr && xhr.getResponseHeader) {
                                detail = xhr.getResponseHeader("X-Status-Reason") || "";
                            }
                        } catch (e) {
                            // ignore
                        }
                        const fallback =
                            this.translate("purgeFailed", "messages", "ContratoHonorarios") ||
                            "Não foi possível remover o arquivo do contrato agora. Tente novamente em alguns minutos.";
                        Espo.Ui.error(detail || fallback);
                    });
            },
        );
    }
}
