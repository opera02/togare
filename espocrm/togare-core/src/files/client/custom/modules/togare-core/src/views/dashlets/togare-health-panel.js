/**
 * Dashlet "Saúde do Togare" — Story 10.2 (FR41, painel TogareHealth).
 *
 * Estende `views/dashlets/abstract/base` (whitelist espo-main.js — confirmado
 * em validate-bundle-imports.mjs `KNOWN_ESPOCRM_MODULES`). Toda a montagem de
 * HTML é delegada ao helper PURO `togare-core:helpers/health-panel-renderer`
 * (testado em vitest) — esta view só orquestra fetch + ciclo de vida + a11y.
 *
 * Comportamento:
 *  - Polling `GET TogareHealth/action/data` a cada 60s (pausa em aba hidden).
 *  - `aria-live="polite"` dispara APENAS em mudança de estado (AR-9) — NÃO no
 *    refresh de métrica estática de 60s. Detecção via `stateSignature()`.
 *  - 403 (role ≠ Sócio/Admin): render calmo vazio, sem loop (AC4 — blindagem;
 *    o gate duro é o 403 do backend, aqui só evitamos UI quebrada).
 *  - Falha de fetch: mantém último estado renderado / vazio; nunca derruba o
 *    dashboard (AC5).
 *
 * Pegadinhas ES module EspoCRM 9.x (checklist A3, regra de plataforma):
 *  - `window.Espo` NÃO é global no escopo ES do módulo — Ajax resolvido via
 *    `_resolveAjax()` (mesmo pattern de system-status-banner.js).
 *  - DOM patch idempotente no `afterRender` (não dependemos de templateContent
 *    para conteúdo dinâmico).
 */

import DashletBaseView from "views/dashlets/abstract/base";
import {
  composePanelHtml,
  stateSignature,
} from "togare-core:helpers/health-panel-renderer";

const POLL_INTERVAL_MS = 60_000;
const ENDPOINT = "TogareHealth/action/data";

class TogareHealthPanelDashletView extends DashletBaseView {
  template = "togare-core:dashlets/togare-health-panel";
  _payload = null;
  _timerId = null;
  _lastSignature = null;
  _forbidden = false;

  constructor(options) {
    super(options);
    this._visibilityHandler = () => this._handleVisibilityChange();
  }

  setup() {
    if (typeof super.setup === "function") super.setup();
    if (
      typeof document !== "undefined" &&
      typeof document.addEventListener === "function"
    ) {
      document.addEventListener("visibilitychange", this._visibilityHandler);
    }
  }

  afterRender() {
    if (typeof super.afterRender === "function") super.afterRender();
    this._poll();
  }

  onRemove() {
    this._cancelTimer();
    if (
      typeof document !== "undefined" &&
      typeof document.removeEventListener === "function"
    ) {
      document.removeEventListener("visibilitychange", this._visibilityHandler);
    }
    if (typeof super.onRemove === "function") super.onRemove();
  }

  _poll() {
    this._cancelTimer();
    const ajax = this._resolveAjax();
    if (ajax === null) return; // ambiente sem Espo.Ajax (testes) — silencioso
    ajax(ENDPOINT)
      .then((resp) => this._onSuccess(resp))
      .catch((err) => this._onError(err));
    this._scheduleNext();
  }

  _scheduleNext() {
    if (typeof window === "undefined" || typeof window.setTimeout !== "function")
      return;
    if (this._isHidden()) return;
    this._timerId = window.setTimeout(() => this._poll(), POLL_INTERVAL_MS);
  }

  _cancelTimer() {
    if (
      this._timerId !== null &&
      typeof window !== "undefined" &&
      typeof window.clearTimeout === "function"
    ) {
      window.clearTimeout(this._timerId);
    }
    this._timerId = null;
  }

  _onSuccess(resp) {
    this._forbidden = false;
    this._payload = resp || null;
    this._paint();
  }

  _onError(err) {
    const status =
      (err && (err.status || (err.xhr && err.xhr.status))) || 0;
    if (Number(status) === 403) {
      // Role sem acesso — render calmo vazio, sem novo polling (AC4).
      this._forbidden = true;
      this._cancelTimer();
      this._paint();
      return;
    }
    if (typeof console !== "undefined" && console.warn) {
      console.warn("[togare-core/HealthPanel] fetch falhou:", err);
    }
    // Mantém último payload (ou vazio). Não derruba o dashboard (AC5).
    this._paint();
  }

  _paint() {
    if (!this.el) return;
    const root = this.el.querySelector(".togare-health-root");
    if (!root) return;

    if (this._forbidden) {
      root.innerHTML = "";
      this._setLiveMessage("");
      return;
    }

    root.innerHTML = composePanelHtml(this._payload || {});
    this._wireHistoricoToggle(root);

    // AR-9: anuncia só quando o conjunto de estados MUDA (não a cada 60s).
    const sig = stateSignature(this._payload || {});
    if (this._lastSignature !== null && sig !== this._lastSignature) {
      this._setLiveMessage("A saúde do sistema mudou. Confira o painel.");
    } else {
      this._setLiveMessage("");
    }
    this._lastSignature = sig;
  }

  _wireHistoricoToggle(root) {
    const btn = root.querySelector(".togare-health-footer__historico-link");
    const box = root.querySelector(".togare-health-panel__historico");
    if (!btn || !box) return;
    btn.addEventListener("click", () => {
      const open = box.hasAttribute("hidden");
      if (open) {
        box.removeAttribute("hidden");
        btn.setAttribute("aria-expanded", "true");
      } else {
        box.setAttribute("hidden", "");
        btn.setAttribute("aria-expanded", "false");
      }
    });
  }

  _setLiveMessage(msg) {
    if (!this.el) return;
    const live = this.el.querySelector(".togare-health-live");
    if (live) live.textContent = msg;
  }

  _handleVisibilityChange() {
    if (this._isHidden()) {
      this._cancelTimer();
    } else if (!this._forbidden) {
      this._poll();
    }
  }

  _isHidden() {
    if (typeof document === "undefined") return false;
    return document.visibilityState === "hidden";
  }

  /**
   * Resolve `Espo.Ajax.getRequest` (runtime EspoCRM 9.x). Em testes vitest,
   * usa `globalThis.__togareAjaxStub`. `null` se nada disponível (fail-safe).
   */
  _resolveAjax() {
    if (
      typeof globalThis !== "undefined" &&
      typeof globalThis.__togareAjaxStub === "function"
    ) {
      return globalThis.__togareAjaxStub;
    }
    if (
      typeof window !== "undefined" &&
      window.Espo &&
      window.Espo.Ajax &&
      typeof window.Espo.Ajax.getRequest === "function"
    ) {
      return (endpoint) => window.Espo.Ajax.getRequest(endpoint);
    }
    if (typeof Espo !== "undefined" && Espo.Ajax && typeof Espo.Ajax.getRequest === "function") {
      // eslint-disable-next-line no-undef
      return (endpoint) => Espo.Ajax.getRequest(endpoint);
    }
    return null;
  }
}

export default TogareHealthPanelDashletView;
