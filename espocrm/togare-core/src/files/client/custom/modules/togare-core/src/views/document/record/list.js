/**
 * Documento record/list view (Story 5.2).
 *
 * Override de actionDownload + actionRemove para:
 *  - download real via browser (Story 5.3): usa URL direta para preservar fluxo de arquivo.
 *  - remove → confirm dialog "Remover este documento? Ele será movido para a lixeira do Nextcloud por 30 dias."
 *
 * Não cria row view custom (Decisão Story 4a.4 — vanilla list view + cell views basta).
 */
import ListView from "views/record/list";

export default class DocumentoListView extends ListView {
  setup() {
    super.setup();
    // Story 5.3 fix-pass — não sobrescrever `rowActionsView` se o panel pai
    // (Processo/Cliente/Prazo) já passou uma row-actions view custom via
    // `clientDefs.<Pai>.relationshipPanels.documentos.rowActionsView`. Sem essa
    // checagem, o painel cairia no row-actions default (Ver/Editar/Remover) e
    // perderia tanto o item "Remover ligação" quanto o novo "Baixar" da 5.3.
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
   * Endpoint canônico Espo: GET /api/v1/Documento/action/download?id=<id>.
   */
  actionDownload(data) {
    if (!data || !data.id) {
      return;
    }

    this._openDownloadUrl(
      "api/v1/Documento/action/download?id=" + encodeURIComponent(data.id),
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
      this.translate("removeConfirm", "messages", "Documento") ||
      "Remover este documento? Ele será movido para a lixeira do Nextcloud por 30 dias.";

    Espo.Ui.confirm(
      confirmMsg,
      {
        confirmText: this.translate("Remove"),
        cancelText: this.translate("Cancel"),
      },
      () => {
        Espo.Ajax.deleteRequest("Documento/" + id)
          .then(() => {
            Espo.Ui.success(
              this.translate("removeSuccess", "messages", "Documento") ||
                "Documento removido.",
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
              this.translate("purgeFailed", "messages", "Documento") ||
              "Não foi possível mover o arquivo para a lixeira do Nextcloud agora. Tente novamente em alguns minutos.";
            Espo.Ui.error(detail || fallback);
          });
      },
    );
  }
}
