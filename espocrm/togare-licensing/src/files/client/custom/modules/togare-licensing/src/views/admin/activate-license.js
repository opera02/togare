/**
 * Admin Tool — Ativar Licença Togare.
 *
 * Form mínimo: textarea pra colar o JWT + botão "Ativar". Ao submit, faz
 * POST /api/v1/TogareLicensing/action/activateKey e exibe Toast de
 * sucesso/erro reusando o ToastTogareView do togare-core (Story 1a.6b).
 *
 * Copy em Resources/i18n/pt_BR/ActivateLicense.json (UX-DR5).
 */

import View from "view";

export default class ActivateLicenseView extends View {
  template = "togare-licensing:admin/activate-license";

  events = {
    "click [data-action=\"activate\"]": "actionActivate",
  };

  setup() {
    super.setup();
    this.isSubmitting = false;
  }

  data() {
    const t = (key) =>
      this.getLanguage().translate(key, "labels", "TogareLicensing");
    return {
      title: t("activateLicenseTitle"),
      description: t("activateLicenseDescription"),
      placeholder: t("activateLicensePlaceholder"),
      buttonLabel: t("activateLicenseButton"),
      warningTitle: t("activateLicenseWarningTitle"),
      warningText: t("activateLicenseWarningText"),
    };
  }

  actionActivate() {
    if (this.isSubmitting) {
      return;
    }

    const $textarea = this.$el.find("textarea[name=\"jwtKey\"]");
    const key = ($textarea.val() || "").trim();

    if (!key) {
      this.showToast("warning", "activateLicenseEmpty");
      return;
    }

    this.isSubmitting = true;
    const $btn = this.$el.find("button[data-action=\"activate\"]");
    $btn.prop("disabled", true).text(this.translateInline("activateLicenseSubmitting"));

    Espo.Ajax.postRequest("TogareLicensing/action/activateKey", { key })
      .then((response) => {
        const modules = (response && response.modulesActivated) || [];
        const expiresAt = (response && response.expiresAt) || "";
        this.showToast(
          "success",
          "activateLicenseSuccess",
          { modules: modules.join(", "), expiresAt }
        );
        $textarea.val("");
      })
      .catch((xhr) => {
        const reason = this.extractReason(xhr);
        const messageKey = "activateLicenseError_" + reason;
        const fallbackKey = "activateLicenseErrorGeneric";
        const tryMessage = this.getLanguage().translate(
          messageKey,
          "messages",
          "TogareLicensing",
        );
        const useKey = (typeof tryMessage === "string" && tryMessage !== messageKey)
          ? messageKey
          : fallbackKey;
        this.showToast("error", useKey);
      })
      .finally(() => {
        this.isSubmitting = false;
        $btn.prop("disabled", false).text(this.translateInline("activateLicenseButton"));
      });
  }

  extractReason(xhr) {
    // EspoCRM entrega mensagem de BadRequest no header X-Status-Reason (body costuma vir vazio).
    // Fallback: responseText caso algum proxy esconda o header.
    try {
      let payload = "";
      if (xhr && typeof xhr.getResponseHeader === "function") {
        payload = xhr.getResponseHeader("X-Status-Reason") || "";
      }
      if (!payload && xhr && xhr.responseText) {
        payload = xhr.responseText;
      }
      const start = payload.indexOf("{");
      const end = payload.lastIndexOf("}");
      if (start !== -1 && end !== -1 && end > start) {
        const parsed = JSON.parse(payload.substring(start, end + 1));
        if (parsed && parsed.reason) {
          return parsed.reason;
        }
      }
    } catch (e) {
      // ignora — fallback genérico
    }
    return "generic";
  }

  translateInline(key) {
    return this.getLanguage().translate(key, "labels", "TogareLicensing");
  }

  showToast(variant, messageKey, params = {}) {
    const message = this.getLanguage().translateOption
      ? this.getLanguage().translate(messageKey, "messages", "TogareLicensing")
      : messageKey;
    let resolved = typeof message === "string" ? message : messageKey;
    Object.keys(params).forEach((p) => {
      resolved = resolved.replace("{" + p + "}", params[p]);
    });
    Espo.Ui.notify(resolved, variant, 8000);
  }
}
