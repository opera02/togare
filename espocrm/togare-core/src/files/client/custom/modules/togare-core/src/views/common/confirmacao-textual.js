/**
 * ConfirmacaoTextual — Componente UX-DR1 C11.
 *
 * Gate para ações destrutivas. CTA começa desabilitado; habilita só quando
 * o input bate exatamente com `expectedName` (case + accent sensitive, trim
 * apenas de espaços externos).
 *
 * ESC → onCancel + destrói view. Enter → onConfirm se match.
 *
 * Uso:
 *   new ConfirmacaoTextualView({
 *     expectedName: 'política RET-001',
 *     ctaLabel: 'Habilitar execução',
 *     onConfirm: () => { ... },
 *     onCancel: () => { ... },
 *     modal: true,               // renderiza com role="dialog" + aria-modal
 *     destructiveWarning: true,  // exibe HedgeBanner.action-destructive inline
 *   });
 */

import View from "view";

export default class ConfirmacaoTextualView extends View {
  template = "togare-core:common/confirmacao-textual";

  events = {
    "input [data-role=\"confirmacao-input\"]": "onInputChange",
    "click [data-action=\"confirm\"]": "onConfirmClick",
    "click [data-action=\"cancel\"]": "onCancelClick",
    "submit form": "onSubmit",
  };

  setup() {
    super.setup();

    if (!this.options.expectedName) {
      throw new Error(
        "ConfirmacaoTextual requer `expectedName` (nome exato esperado).",
      );
    }

    this.expectedName = this.options.expectedName;
    this.ctaLabel = this.options.ctaLabel || null;
    this.onConfirm = typeof this.options.onConfirm === "function" ? this.options.onConfirm : null;
    this.onCancel = typeof this.options.onCancel === "function" ? this.options.onCancel : null;
    this.modal = Boolean(this.options.modal);
    this.destructiveWarning = Boolean(this.options.destructiveWarning);

    this.currentInput = "";
    this._boundKeydown = this.onGlobalKeydown.bind(this);
  }

  data() {
    const labels = this.getLanguage().translate(
      "labels",
      "fields",
      "ConfirmacaoTextual",
      "TogareCore",
    );
    const l = typeof labels === "object" && labels !== null ? labels : {};

    return {
      instrucao: l.instrucao || "Digite o nome exato para confirmar:",
      placeholder: (l.placeholderPrefix || "Digite: ") + this.expectedName,
      ctaLabel: this.ctaLabel || l.ctaDefault || "Confirmar",
      cancelLabel: l.cancelLabel || "Cancelar",
      ariaDescription: l.ariaDescription || "",
      ariaDestructiveWarning: l.ariaDestructiveWarning || "",
      modal: this.modal,
      destructiveWarning: this.destructiveWarning,
      ctaDisabled: true,
    };
  }

  afterRender() {
    // Foco inicial no input.
    const input = this.el?.querySelector('[data-role="confirmacao-input"]');
    if (input) {
      input.focus();
    }
    // Listener global pra ESC.
    if (typeof document !== "undefined") {
      document.addEventListener("keydown", this._boundKeydown);
    }
  }

  remove() {
    if (typeof document !== "undefined") {
      document.removeEventListener("keydown", this._boundKeydown);
    }
    return super.remove();
  }

  /**
   * Retorna true se o input atual bate com o nome esperado.
   * Case + accent sensitive, trim apenas de espaços externos.
   */
  matches() {
    return this.currentInput.trim() === this.expectedName;
  }

  onInputChange(e) {
    this.currentInput = (e && e.target && typeof e.target.value === "string")
      ? e.target.value
      : "";
    this._updateCtaState();
  }

  _updateCtaState() {
    const cta = this.el?.querySelector('[data-action="confirm"]');
    if (!cta) return;
    if (this.matches()) {
      cta.removeAttribute("disabled");
    } else {
      cta.setAttribute("disabled", "disabled");
    }
  }

  onConfirmClick(e) {
    if (e && typeof e.preventDefault === "function") e.preventDefault();
    if (!this.matches()) return;
    if (this.onConfirm) this.onConfirm();
  }

  onCancelClick(e) {
    if (e && typeof e.preventDefault === "function") e.preventDefault();
    this._fireCancel();
  }

  onSubmit(e) {
    if (e && typeof e.preventDefault === "function") e.preventDefault();
    // Submit via Enter — só confirma se matches.
    if (this.matches() && this.onConfirm) {
      this.onConfirm();
    }
  }

  onGlobalKeydown(e) {
    if (!e || e.key !== "Escape") return;
    this._fireCancel();
  }

  _fireCancel() {
    if (this.onCancel) this.onCancel();
    this.remove();
  }
}
