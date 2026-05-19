/**
 * LembreteConfigPanel — view custom em Preferences (Story 4b.2, AC5).
 *
 * Renderiza 2 fieldsets com 6 checkboxes para o usuário configurar:
 *  - Canais ativos (popup, email)
 *  - Marcos ativos (D-7, D-3, D-1, status_dirigido)
 *
 * Persistência: campo `togareLembreteConfig` (jsonObject) em Preferences.
 * Defaults aplicados em runtime quando model retorna `null`.
 *
 * Acessibilidade WCAG AA (NFR28):
 *  - Cada checkbox tem `<label for=...>` associado.
 *  - Hint geral linkado via `aria-describedby="togare-lembrete-hint"` em todos
 *    os inputs.
 *  - Focus visível padrão do browser preservado.
 *  - Contraste preto/branco (sem cor sólida custom — herda tema EspoCRM).
 *
 * Pattern: extends BaseFieldView para cumprir o contrato real de field view
 * do EspoCRM. Layout detail.json aponta `togareLembreteConfig` para esta view.
 */
import BaseFieldView from "views/fields/base";
import { translateOrFallback } from "togare-core:helpers/translate-or-fallback";

// Story 4b.3 (Decisão #8) — D-0 entra como 4º marco visual antes de
// status_dirigido (ordem: D-7 → D-3 → D-1 → D-0 → status_dirigido).
// Default true (fail-safe para o marco mais crítico).
export const DEFAULT_CONFIG = Object.freeze({
  channels: { popup: true, email: true },
  marcos: { "D-7": true, "D-3": true, "D-1": true, "D-0": true, status_dirigido: true },
});

export const FIELDS = [
  { key: "channels.popup", id: "togare-lembrete-popup", labelKey: "lembreteConfig.popupLabel", fallback: "Notificação no Togare (pop-up)" },
  { key: "channels.email", id: "togare-lembrete-email", labelKey: "lembreteConfig.emailLabel", fallback: "E-mail" },
  { key: "marcos.D-7", id: "togare-lembrete-d7", labelKey: "lembreteConfig.D7Label", fallback: "7 dias úteis antes do vencimento" },
  { key: "marcos.D-3", id: "togare-lembrete-d3", labelKey: "lembreteConfig.D3Label", fallback: "3 dias úteis antes do vencimento" },
  { key: "marcos.D-1", id: "togare-lembrete-d1", labelKey: "lembreteConfig.D1Label", fallback: "1 dia útil antes do vencimento" },
  // Story 4b.3 — D-0 (alerta crítico VENCE HOJE — UX-DR10).
  { key: "marcos.D-0", id: "togare-lembrete-d0", labelKey: "lembreteConfig.D0Label", fallback: "Vence hoje (D-0) — alerta crítico" },
  { key: "marcos.status_dirigido", id: "togare-lembrete-status", labelKey: "lembreteConfig.statusDirigidoLabel", fallback: "Quando o status muda para Atrasado/Reagendado, Aguardando cliente ou Aguardando correção" },
];

const HINT_ID = "togare-lembrete-hint";

export default class LembreteConfigView extends BaseFieldView {
  type = "jsonObject";
  inlineEditDisabled = true;
  detailTemplateContent = "{{{panelHtml}}}";
  editTemplateContent = "{{{panelHtml}}}";
  listTemplateContent = "{{{panelHtml}}}";

  setup() {
    super.setup();
    this.fieldName = this.name || this.options.name || "togareLembreteConfig";

    if (typeof this.addHandler === "function") {
      this.addHandler("change", "input.togare-lembrete-checkbox", "_onChange");
      return;
    }

    this.events = {
      ...(this.events || {}),
      "change input.togare-lembrete-checkbox": "_onChange",
    };
  }

  /**
   * Lê config do model + merge com defaults. Idempotente.
   * @returns {{channels: {popup: boolean, email: boolean}, marcos: Record<string, boolean>}}
   */
  _readConfig() {
    const stored = this.model && typeof this.model.get === "function"
      ? this.model.get(this.fieldName)
      : null;
    return mergeWithDefaults(stored);
  }

  _t(key, fallback) {
    return translateOrFallback(this, key, "lembreteConfig", "Preferences", fallback);
  }

  data() {
    return {
      panelHtml: this._renderPanelHtml(),
    };
  }

  fetch() {
    return {
      [this.fieldName]: this._readConfigFromDom(),
    };
  }

  _renderPanelHtml() {
    const cfg = this._readConfig();
    const channelsTitle = this._t("channelsTitle", "Canais ativos");
    const marcosTitle = this._t("marcosTitle", "Marcos ativos");
    const hint = this._t("hint", "Se você desativar todos os canais, não receberá nenhum lembrete. Mudanças também afetam lembretes pendentes que ainda não foram enviados.");

    return `
      <div class="togare-lembrete-panel">
        <fieldset role="group" aria-labelledby="togare-lembrete-channels-title">
          <legend id="togare-lembrete-channels-title">${escape(channelsTitle)}</legend>
          ${FIELDS.slice(0, 2).map((f) => {
            const checked = readPath(cfg, f.key) ? "checked" : "";
            const label = this._t(f.labelKey, f.fallback);
            return `<div class="togare-lembrete-row">
              <input type="checkbox" id="${f.id}" class="togare-lembrete-checkbox" data-key="${f.key}" aria-describedby="${HINT_ID}" ${checked}>
              <label for="${f.id}">${escape(label)}</label>
            </div>`;
          }).join("")}
        </fieldset>
        <fieldset role="group" aria-labelledby="togare-lembrete-marcos-title">
          <legend id="togare-lembrete-marcos-title">${escape(marcosTitle)}</legend>
          ${FIELDS.slice(2).map((f) => {
            const checked = readPath(cfg, f.key) ? "checked" : "";
            const label = this._t(f.labelKey, f.fallback);
            return `<div class="togare-lembrete-row">
              <input type="checkbox" id="${f.id}" class="togare-lembrete-checkbox" data-key="${f.key}" aria-describedby="${HINT_ID}" ${checked}>
              <label for="${f.id}">${escape(label)}</label>
            </div>`;
          }).join("")}
        </fieldset>
        <p id="${HINT_ID}" class="togare-lembrete-hint">${escape(hint)}</p>
      </div>
    `;
  }

  _readConfigFromDom() {
    const current = this._readConfig();

    if (!this.el || typeof this.el.querySelector !== "function") {
      return current;
    }

    for (const f of FIELDS) {
      const input = this.el.querySelector(`input[data-key="${f.key}"]`);
      if (input) {
        setPath(current, f.key, !!input.checked);
      }
    }

    return current;
  }

  _onChange(event) {
    const target = event.target;
    if (!target || target.tagName !== "INPUT") return;
    const key = target.getAttribute("data-key");
    if (!key) return;

    const current = this._readConfig();
    setPath(current, key, !!target.checked);

    if (this.model && typeof this.model.set === "function") {
      this.model.set(this.fieldName, current, {
        ui: true,
        fromView: this,
        fromField: this.fieldName,
        action: "ui",
      });
    }

    if (typeof this.trigger === "function") {
      this.trigger("change");
    }
  }
}

// ====== Helpers puros (exportados para testes) ======

export function mergeWithDefaults(stored) {
  const safe = stored && typeof stored === "object" ? stored : {};
  const channels = safe.channels && typeof safe.channels === "object" ? safe.channels : {};
  const marcos = safe.marcos && typeof safe.marcos === "object" ? safe.marcos : {};

  return {
    channels: {
      popup: Object.prototype.hasOwnProperty.call(channels, "popup")
        ? !!channels.popup
        : DEFAULT_CONFIG.channels.popup,
      email: Object.prototype.hasOwnProperty.call(channels, "email")
        ? !!channels.email
        : DEFAULT_CONFIG.channels.email,
    },
    marcos: {
      "D-7": Object.prototype.hasOwnProperty.call(marcos, "D-7") ? !!marcos["D-7"] : DEFAULT_CONFIG.marcos["D-7"],
      "D-3": Object.prototype.hasOwnProperty.call(marcos, "D-3") ? !!marcos["D-3"] : DEFAULT_CONFIG.marcos["D-3"],
      "D-1": Object.prototype.hasOwnProperty.call(marcos, "D-1") ? !!marcos["D-1"] : DEFAULT_CONFIG.marcos["D-1"],
      // Story 4b.3 — D-0.
      "D-0": Object.prototype.hasOwnProperty.call(marcos, "D-0") ? !!marcos["D-0"] : DEFAULT_CONFIG.marcos["D-0"],
      status_dirigido: Object.prototype.hasOwnProperty.call(marcos, "status_dirigido")
        ? !!marcos.status_dirigido
        : DEFAULT_CONFIG.marcos.status_dirigido,
    },
  };
}

export function readPath(obj, dottedKey) {
  const parts = dottedKey.split(".");
  let cursor = obj;
  for (const p of parts) {
    if (cursor === null || cursor === undefined) return undefined;
    cursor = cursor[p];
  }
  return cursor;
}

export function setPath(obj, dottedKey, value) {
  const parts = dottedKey.split(".");
  let cursor = obj;
  for (let i = 0; i < parts.length - 1; i++) {
    const p = parts[i];
    if (cursor[p] === null || typeof cursor[p] !== "object") cursor[p] = {};
    cursor = cursor[p];
  }
  cursor[parts[parts.length - 1]] = value;
  return obj;
}

function escape(str) {
  if (typeof str !== "string") return "";
  return str
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}
