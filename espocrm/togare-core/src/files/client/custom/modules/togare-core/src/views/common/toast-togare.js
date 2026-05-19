/**
 * ToastTogare — Componente UX-DR1 C4 (fusão de ToastDesfazer + ToastSessaoExpirando).
 *
 * Toast flutuante não-bloqueante com 5 variants (undo, warning, success, error,
 * auto-link). Método estático `ToastTogare.show(options) → {dismiss}` gerencia
 * stack global — toasts sobrevivem a mudanças de rota e empilham verticalmente.
 *
 * Story 4b.0 (refactor 0.21.0):
 *  - Df11: `VARIANT_DEFAULTS` extraído para constante de módulo `Object.freeze`
 *    (era duplicado em `data()` e `static show()` — fonte de drift).
 *  - Df7: ESC fecha **só o toast mais recente** do stack global. Antes, cada
 *    toast registrava seu próprio listener — ESC apagava todos. Agora, um
 *    handler único registrado lazily no `document` consulta um stack LIFO
 *    interno (`_globalEscapeStack`) e invoca apenas o `dismiss` do topo.
 *
 * Uso:
 *   const handle = ToastTogare.show({
 *     variant: 'undo',
 *     message: 'Prazo confirmado',
 *     actionLabel: 'Desfazer',
 *     onAction: () => undoOperation(),
 *     duration: 10000,  // 10s; null = persistente
 *   });
 *
 *   // Cancelamento programático:
 *   handle.dismiss();
 */

import View from "view";

const STACK_ID = "togare-toast-stack";
const DEFAULT_DURATION = 10000;

const VALID_VARIANTS = ["undo", "warning", "success", "error", "auto-link"];
const DEFAULT_VARIANT = "success";

/**
 * Story 4b.0 (Df11): single source of truth dos defaults de cada variant.
 * Frozen no nível raiz E nos sub-objetos para impedir mutação acidental
 * (drift entre callers seria silencioso). Lido por `data()` da instância e
 * por `static show()` — uma única definição.
 */
const VARIANT_DEFAULTS = Object.freeze({
  "undo":      Object.freeze({ icon: "✓",  role: "status", defaultActionLabel: "Desfazer" }),
  "warning":   Object.freeze({ icon: "⚠",  role: "alert",  defaultActionLabel: "Continuar" }),
  "success":   Object.freeze({ icon: "✓",  role: "status", defaultActionLabel: null }),
  "error":     Object.freeze({ icon: "✗",  role: "status", defaultActionLabel: "Tentar de novo" }),
  "auto-link": Object.freeze({ icon: "🔗", role: "status", defaultActionLabel: "Editar" }),
});

/**
 * Story 4b.0 (Df7): stack LIFO de cleanups dos toasts ativos. ESC global
 * dispara apenas o cleanup do topo. Implementação interna — não exportar
 * como API pública.
 */
const _globalEscapeStack = []; // [{ id, dismiss: () => void }]
let _globalEscHandlerRegistered = false;

function _ensureGlobalEscHandler() {
  if (_globalEscHandlerRegistered) return;
  if (typeof document === "undefined") return;
  document.addEventListener("keydown", (e) => {
    if (!e || e.key !== "Escape") return;
    const top = _globalEscapeStack[_globalEscapeStack.length - 1];
    if (top && typeof top.dismiss === "function") {
      top.dismiss();
    }
  });
  _globalEscHandlerRegistered = true;
}

function _pushEsc(id, dismiss) {
  _ensureGlobalEscHandler();
  _globalEscapeStack.push({ id, dismiss });
}

function _popEsc(id) {
  const idx = _globalEscapeStack.findIndex((e) => e.id === id);
  if (idx >= 0) _globalEscapeStack.splice(idx, 1);
}

/**
 * Test helper — uso EXCLUSIVO em vitest `beforeEach` para garantir
 * isolamento entre cenários. NÃO documentar como API pública (underscore-
 * prefix sinaliza visibilidade interna).
 */
export function __resetEscStackForTests() {
  _globalEscapeStack.length = 0;
  // Não desregistra o handler — o teste roda em jsdom isolado por arquivo;
  // o handler único compartilhado entre `it()` é seguro porque o stack é
  // o que decide o que dismissar.
}

function ensureStack() {
  if (typeof document === "undefined") return null;
  let stack = document.getElementById(STACK_ID);
  if (!stack) {
    stack = document.createElement("div");
    stack.id = STACK_ID;
    stack.className = "togare-toast-stack";
    document.body.appendChild(stack);
  }
  return stack;
}

function generateId() {
  return "tgt-" + Math.random().toString(36).slice(2, 10);
}

export default class ToastTogareView extends View {
  template = "togare-core:common/toast-togare";

  events = {
    "click [data-action=\"toast-do\"]": "onActionClick",
    "click [data-action=\"toast-close\"]": "onCloseClick",
  };

  setup() {
    super.setup();

    const requested = this.options.variant || DEFAULT_VARIANT;
    this.variant = VALID_VARIANTS.includes(requested) ? requested : DEFAULT_VARIANT;

    this.message = this.options.message || "";
    this.actionLabel = this.options.actionLabel ?? null;
    this.onAction = typeof this.options.onAction === "function" ? this.options.onAction : null;
    this.onDismiss = typeof this.options.onDismiss === "function" ? this.options.onDismiss : null;
    this.duration = this.options.duration === null
      ? null
      : Number(this.options.duration) || DEFAULT_DURATION;
    this.id = this.options.id || generateId();

    this._actionTaken = false;
    this._dismissed = false;
    this._timer = null;
  }

  data() {
    // Story 4a.4 fix-pass 0.19.7 (B17): ToastTogareView é instanciada via
    // `new ToastTogareView(opts)` direto (sem passar por viewFactory),
    // então `_helper` NÃO é injetado e `this.getLanguage()` retorna null.
    // Defaults dos 5 variants ficam na constante de módulo VARIANT_DEFAULTS;
    // tenta i18n com try/catch (best-effort, NUNCA quebra render).
    let v = VARIANT_DEFAULTS[this.variant] || VARIANT_DEFAULTS.success;
    try {
      if (typeof this.getLanguage === "function") {
        const lang = this.getLanguage();
        if (lang && typeof lang.translate === "function") {
          const variantMap = lang.translate(
            this.variant,
            "variants",
            "ToastTogare",
            "TogareCore",
          );
          if (typeof variantMap === "object" && variantMap !== null) {
            v = Object.assign({}, v, variantMap);
          }
        }
      }
    } catch (_) {
      // ignore — usa defaults da constante de módulo.
    }

    const effectiveActionLabel = this.actionLabel !== null
      ? this.actionLabel
      : (v.defaultActionLabel || null);

    return {
      id: this.id,
      variant: this.variant,
      cssClass: `togare-toast togare-toast--${this.variant}`,
      role: v.role || "status",
      icon: v.icon || "",
      message: this.message,
      actionLabel: effectiveActionLabel,
      showProgress: this.duration !== null && this.onAction !== null,
      durationMs: this.duration,
    };
  }

  afterRender() {
    if (this.duration !== null) {
      this._timer = setTimeout(() => this.dismissNow("timeout"), this.duration);
    }
    // Story 4b.0 (Df7): registra dismiss no stack global LIFO em vez de
    // listener próprio — assim ESC fecha só o topo do stack.
    _pushEsc(this.id, () => this.dismissNow("escape"));
  }

  onActionClick(e) {
    if (e && typeof e.preventDefault === "function") e.preventDefault();
    if (this._actionTaken) return;
    this._actionTaken = true;
    if (this.onAction) this.onAction();
    this.dismissNow("action");
  }

  onCloseClick(e) {
    if (e && typeof e.preventDefault === "function") e.preventDefault();
    this.dismissNow("close");
  }

  dismissNow(reason = "programmatic") {
    if (this._dismissed) return;
    this._dismissed = true;
    if (this._timer !== null) {
      clearTimeout(this._timer);
      this._timer = null;
    }
    // Story 4b.0 (Df7): remove do stack global pelo id.
    _popEsc(this.id);
    if (this.el?.classList) {
      this.el.classList.add("togare-toast--leaving");
    }
    if (this.onDismiss) {
      try {
        this.onDismiss(reason);
      } catch (_) {
        // ignore - cleanup do toast nao deve quebrar o fluxo do caller.
      }
    }
    // Pequeno delay pra animação de saída antes de remover do DOM.
    setTimeout(() => this.remove(), 300);
  }

  /**
   * Método estático — ponto de entrada público.
   *
   * Story 4a.4 fix-pass 0.19.7 (B17): construção DOM PURA sem depender do
   * Bullbone render(). Quando ToastTogareView é instanciada via `new`
   * (não via viewFactory), `_helper` não é injetado e `getLanguage()` /
   * `template` quebram. Solução: construir DOM imperativamente aqui,
   * dispensando o ciclo template+render do Bullbone. Mantém compatibilidade
   * com tests vitest que mockavam render().
   *
   * Story 4b.0 (Df7+Df11):
   *  - Defaults vêm da constante de módulo VARIANT_DEFAULTS (era duplicado).
   *  - ESC global é stack LIFO — `cleanup` registrado via `_pushEsc(id, cleanup)`
   *    e desregistrado via `_popEsc(id)`. Handler único registrado lazily.
   *
   * @returns {{ dismiss: () => void, id: string }}
   */
  static show(options = {}) {
    const stack = ensureStack();
    if (!stack || typeof document === "undefined") {
      return { id: null, dismiss: () => {} };
    }

    const variant = VALID_VARIANTS.includes(options.variant) ? options.variant : DEFAULT_VARIANT;
    const v = VARIANT_DEFAULTS[variant] || VARIANT_DEFAULTS.success;
    const id = options.id || generateId();
    const message = options.message || "";
    const duration = options.duration === null ? null : (Number(options.duration) || DEFAULT_DURATION);
    // Story 4a.4 fix-pass 0.19.9: distinção explícita entre `undefined` (usa
    // default do variant) e `null` (sem botão). Forma if/else evita
    // colapso pra `??` pelo minifier (que confundia null vs undefined).
    let actionLabel;
    if (Object.prototype.hasOwnProperty.call(options, "actionLabel")) {
      actionLabel = options.actionLabel; // user explicitamente passou (pode ser null pra "sem botão")
    } else {
      actionLabel = v.defaultActionLabel; // ausente → default do variant
    }
    const onAction = typeof options.onAction === "function" ? options.onAction : null;
    const onDismiss = typeof options.onDismiss === "function" ? options.onDismiss : null;

    // Slot DOM imperativo.
    const slot = document.createElement("div");
    slot.id = `togare-toast-slot-${id}`;
    slot.className = "togare-toast-slot";

    const escapeHtml = (s) => String(s == null ? "" : s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");

    slot.innerHTML =
      `<div class="togare-toast togare-toast--${variant}" role="${v.role}" data-id="${escapeHtml(id)}" data-variant="${variant}">` +
      `<span class="togare-toast__icon">${escapeHtml(v.icon)}</span>` +
      `<span class="togare-toast__message">${escapeHtml(message)}</span>` +
      (actionLabel ? `<button type="button" class="togare-toast__action" data-action="toast-do">${escapeHtml(actionLabel)}</button>` : "") +
      `<button type="button" class="togare-toast__close" data-action="toast-close" aria-label="Fechar">&times;</button>` +
      (duration !== null && onAction !== null ? `<div class="togare-toast__progress" style="animation-duration:${duration}ms;"></div>` : "") +
      `</div>`;

    if (stack.firstChild) {
      stack.insertBefore(slot, stack.firstChild);
    } else {
      stack.appendChild(slot);
    }

    let actionTaken = false;
    let timer = null;
    let cleaned = false;
    const cleanup = (reason = "programmatic") => {
      if (cleaned) return;
      cleaned = true;
      if (timer !== null) {
        clearTimeout(timer);
        timer = null;
      }
      // Story 4b.0 (Df7): remove do stack global pelo id; handler único
      // global cuida de invocar este cleanup quando ESC dispara.
      _popEsc(id);
      const root = slot.firstChild;
      if (root && root.classList) {
        root.classList.add("togare-toast--leaving");
      }
      if (onDismiss) {
        try {
          onDismiss(reason);
        } catch (_) {
          // ignore - cleanup do toast nao deve quebrar o fluxo do caller.
        }
      }
      setTimeout(() => {
        if (slot.parentNode) slot.parentNode.removeChild(slot);
      }, 300);
    };

    const actionBtn = slot.querySelector('[data-action="toast-do"]');
    if (actionBtn) {
      actionBtn.addEventListener("click", (e) => {
        e.preventDefault();
        if (actionTaken) return;
        actionTaken = true;
        if (onAction) onAction();
        cleanup("action");
      });
    }

    if (duration !== null) {
      timer = setTimeout(() => cleanup("timeout"), duration);
    }

    const closeBtn = slot.querySelector('[data-action="toast-close"]');
    if (closeBtn) {
      closeBtn.addEventListener("click", (e) => {
        e.preventDefault();
        cleanup("close");
      });
    }

    // Story 4b.0 (Df7): registra dismiss no stack ANTES de retornar — assim
    // ESC global dispara cleanup do topo do stack.
    _pushEsc(id, () => cleanup("escape"));

    return {
      id,
      dismiss: cleanup,
    };
  }
}
