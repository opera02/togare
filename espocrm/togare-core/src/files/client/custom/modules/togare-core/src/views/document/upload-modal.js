/**
 * DocumentoUploadModalView — modal "Anexar documento" (Story 5.2).
 *
 * Pegadinha B-NEW-B1: EspoCRM 9.x `views/fields/file.js` exige entityDefs
 * field `type:file` (com `attachmentId` storable). Documento NÃO usa esse
 * pattern — usa `uploadedAttachmentId` notStorable + Attachment.id.
 * Solução: este modal usa `<input type="file">` raw e orquestra Attachment
 * manualmente via Espo.Ajax.postRequest('Attachment').
 *
 * Body do POST Attachment (multipart-style serializado em Espo native):
 *   {
 *     name: <filename>,
 *     type: <mimeType>,
 *     size: <bytes>,
 *     file: 'data:<mime>;base64,<...>',
 *     role: 'Attachment',
 *     parentType: 'Documento',
 *     field: 'uploadedAttachment'
 *   }
 *
 * Após Attachment criado, modal POST `/api/v1/Documento` com:
 *   { processoId|clienteId, uploadedAttachmentId, name }
 *
 * @example
 *   this.createView('upload', 'togare-core:views/document/upload-modal', {
 *     processoId: 'abc...',
 *   }, (view) => view.render());
 */
import ModalView from "views/modal";
import ToastTogareView from "togare-core:views/common/toast-togare";

const ALLOWED_EXTENSIONS = [
  ".pdf",
  ".docx",
  ".doc",
  ".xlsx",
  ".xls",
  ".png",
  ".jpg",
  ".jpeg",
  ".tiff",
  ".tif",
  ".txt",
];

const ALLOWED_MIMES = [
  "application/pdf",
  "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
  "application/msword",
  "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
  "application/vnd.ms-excel",
  "image/png",
  "image/jpeg",
  "image/tiff",
  "text/plain",
];

export const ACCEPT_ATTRIBUTE = [...ALLOWED_EXTENSIONS, ...ALLOWED_MIMES].join(",");

export default class DocumentoUploadModalView extends ModalView {
  template = "togare-core:document/upload-modal";

  setup() {
    this.processoId = this.options.processoId || null;
    this.clienteId = this.options.clienteId || null;
    this.prazoId = this.options.prazoId || null;
    this.processoName = this.options.processoName || "";
    this.clienteName = this.options.clienteName || "";
    this.prazoName = this.options.prazoName || "";

    const setCount = [this.processoId, this.clienteId, this.prazoId].filter(
      Boolean,
    ).length;
    if (setCount === 0) {
      throw new Error(
        "DocumentoUploadModalView: processoId, clienteId OU prazoId é obrigatório.",
      );
    }
    if (setCount >= 2) {
      throw new Error(
        "DocumentoUploadModalView: apenas um de processoId/clienteId/prazoId é permitido (XOR triplo).",
      );
    }

    this.headerText =
      this.translate("Anexar documento", "labels", "Documento") ||
      "Anexar documento";

    this.acceptAttribute = ACCEPT_ATTRIBUTE;
    this.selectedFile = null;
    this.isSubmitting = false;

    this.buttonList = [
      {
        name: "upload",
        label: this.translate("Save"),
        style: "primary",
        onClick: () => this.actionUpload(),
      },
      {
        name: "cancel",
        label: this.translate("Cancel"),
        onClick: () => this.close(),
      },
    ];
  }

  data() {
    let contextLabel = "";
    if (this.processoId) {
      contextLabel = this.processoName;
    } else if (this.clienteId) {
      contextLabel = this.clienteName;
    } else if (this.prazoId) {
      contextLabel = this.prazoName;
    }
    return {
      acceptAttribute: this.acceptAttribute,
      processoName: this.processoName,
      clienteName: this.clienteName,
      prazoName: this.prazoName,
      contextLabel: contextLabel,
    };
  }

  events = {
    "change input[name='togareDocumentoFile']": "onFileChange",
  };

  onFileChange(e) {
    const input = e.currentTarget;
    this.selectedFile =
      input && input.files && input.files[0] ? input.files[0] : null;
    const previewEl = this.$el.find(".togare-doc-upload-preview");
    if (previewEl && previewEl.length) {
      previewEl.text(this.selectedFile ? this.selectedFile.name : "");
    }
  }

  actionUpload() {
    if (this.isSubmitting) return;
    if (!this.selectedFile) {
      Espo.Ui.error(this.translate("Choose a file") || "Escolha um arquivo.");
      return;
    }
    this.isSubmitting = true;
    this.disableButton("upload");

    const file = this.selectedFile;
    const reader = new FileReader();
    reader.onload = (ev) => {
      const dataUrl = ev.target.result;
      const attachmentPayload = {
        name: file.name,
        type: file.type || "application/octet-stream",
        size: file.size,
        file: dataUrl,
        role: "Attachment",
        parentType: "Documento",
        field: "uploadedAttachment",
      };

      Espo.Ajax.postRequest("Attachment", attachmentPayload)
        .then((response) => {
          const attachmentId = response && response.id ? response.id : null;
          if (!attachmentId) {
            throw new Error("Attachment sem id.");
          }
          const docPayload = {
            uploadedAttachmentId: attachmentId,
            name: file.name,
          };
          if (this.processoId) {
            docPayload.processoId = this.processoId;
          }
          if (this.clienteId) {
            docPayload.clienteId = this.clienteId;
          }
          if (this.prazoId) {
            docPayload.prazoId = this.prazoId;
          }

          return Espo.Ajax.postRequest("Documento", docPayload).then(
            (created) => {
              const successMsg =
                this.translate("uploadSuccess", "messages", "Documento") ||
                "Documento anexado com sucesso.";
              // Story 5.7-followup gap (c) ROUND 3 — usar ToastTogareView
              // em vez de Espo.Ui.notify.
              //
              // Hist.: round 1 (setTimeout) e round 2 (Espo.Ui.notify direto)
              // ambos falharam em runtime real. Smoke do Felipe (2026-05-12)
              // capturou via monkey-patch do Espo.Ui.notify: meu success(5000)
              // era chamado, mas 1ms depois o trigger("after:save") fazia o
              // painel relacional re-renderizar, que chamava
              // Espo.Ui.notify(' ... ') (spinner "Loading"). Como
              // Espo.Ui.notify faz `$('#notification').remove()` antes de
              // criar o novo, meu toast era decapitado em ~1ms — invisível
              // ao usuário.
              //
              // Fix: usar ToastTogareView.show (do togare-core common)
              // que renderiza em #togare-toast-stack — container SEPARADO
              // de #notification, NÃO tocado pelo Espo.Ui.notify(remove +
              // create). Sobrevive ao spinner de refresh do painel.
              this.showUploadSuccess(successMsg);
              this.trigger("after:save", created);
              this.close();
            },
          );
        })
        .catch((xhr) => {
          this.isSubmitting = false;
          this.enableButton("upload");
          let message = "";
          try {
            if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
              message = String(xhr.responseJSON.message);
            } else if (xhr && xhr.getResponseHeader) {
              message = xhr.getResponseHeader("X-Status-Reason") || "";
            }
          } catch (e) {
            // ignore
          }
          const fallback =
            this.translate("uploadFailed", "messages", "Documento") ||
            "Não foi possível enviar o arquivo para o Nextcloud agora. Tente novamente em alguns minutos.";
          Espo.Ui.error(message || fallback);
        });
    };
    reader.onerror = () => {
      this.isSubmitting = false;
      this.enableButton("upload");
      Espo.Ui.error(this.translate("Error") || "Erro ao ler o arquivo.");
    };
    reader.readAsDataURL(file);
  }

  showUploadSuccess(message) {
    try {
      if (
        typeof ToastTogareView !== "undefined" &&
        typeof ToastTogareView.show === "function"
      ) {
        ToastTogareView.show({
          variant: "success",
          message: message,
          duration: 5000,
        });
        return;
      }
    } catch (e) {
      // Toast is cosmetic; the upload success flow must still refresh and close.
    }

    try {
      const ui = typeof Espo !== "undefined" && Espo.Ui ? Espo.Ui : null;
      if (ui && typeof ui.notify === "function") {
        ui.notify(message, "success", 5000);
      } else if (ui && typeof ui.success === "function") {
        ui.success(message);
      }
    } catch (e) {
      // Ignore notification failures to avoid converting success into error UI.
    }
  }
}
