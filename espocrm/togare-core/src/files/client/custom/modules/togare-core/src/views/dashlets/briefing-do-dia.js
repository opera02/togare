/**
 * Dashlet "Briefing do Dia" — Story 10.1 (BriefingDoDia 7 roles).
 *
 * Estende `views/dashlets/abstract/base` (whitelisted em validate-bundle-imports).
 * Toda a montagem de HTML é delegada ao helper PURO `briefing-do-dia-renderer`
 * (testado em vitest) — esta view só orquestra fetch + ciclo de vida.
 *
 * Comportamento:
 *  - Polling `GET TogareBriefing/action/data` a cada 30min (pausa em aba hidden).
 *  - Qualquer erro (incl. 403): render EmptyStateCalmo, sem loop de retry (AC3).
 *  - Falha de fetch: mantém estado vazio; nunca derruba o dashboard.
 *
 * Pegadinhas ES module EspoCRM 9.x (regra A3):
 *  - `window.Espo` NÃO é global no escopo ES — Ajax via `_resolveAjax()`.
 *  - DOM patch idempotente no `afterRender` (não templateContent).
 */

import DashletBaseView from "views/dashlets/abstract/base";
import { renderPanel } from "togare-core:helpers/briefing-do-dia-renderer";

const POLL_INTERVAL_MS = 1_800_000; // 30 minutos
const ENDPOINT = "TogareBriefing/action/data";

// G3-P2 (code review 2026-05-18): `ROLE_CONFIGS`/`getConfigForRole` removidos.
// Eram dead code (só exercitados por testes) — a fonte viva de copy é o
// helper `briefing-do-dia-renderer` (empty-state) + as strings hardcoded no
// `TogareBriefingService` (PHP). architecture.md previa config-driven via
// getConfigForRole(); a implementação divergiu para PHP-hardcoded (variância
// consciente ratificada por Felipe — ver deferred-work / story Review G3).

class BriefingDoDiaDashletView extends DashletBaseView {
  template = "togare-core:dashlets/briefing-do-dia";

  _payload = null;
  _timerId = null;
  _userName = "";
  /**
   * Estado terminal (G2-P2, code review 2026-05-18). Setado em (a) 403 —
   * role sem acesso, AC3 "sem loop de retry"; (b) onRemove — evita paint em
   * DOM destacado e re-poll por visibilitychange. Sem isto, alternar de aba
   * (visibilitychange) re-disparava `_poll()` após um 403, derrotando AC3.
   */
  _stopped = false;

  constructor(options) {
    super(options);
    this._visibilityHandler = () => this._handleVisibilityChange();
  }

  setup() {
    if (typeof super.setup === "function") super.setup();
    try {
      if (typeof this.getUser === "function") {
        const user = this.getUser();
        if (user) {
          this._userName = String(user.get("name") || user.get("userName") || "");
        }
      }
    } catch (_) {
      // getUser indisponível (testes) — greeting sem nome
    }
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
    this._stopped = true;
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
    if (this._stopped) return;
    this._cancelTimer();
    const ajax = this._resolveAjax();
    if (ajax === null) return;
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
    if (this._stopped) return;
    this._payload = resp || null;
    this._paint();
  }

  _onError(err) {
    if (this._stopped) return;
    const status =
      (err && (err.status || (err.xhr && err.xhr.status))) || 0;
    if (Number(status) === 403) {
      // Role sem acesso ou não autenticado — render calmo vazio sem retry (AC3).
      this._stopped = true;
      this._cancelTimer();
    }
    if (typeof console !== "undefined" && console.warn) {
      console.warn("[togare-core/BriefingDoDia] fetch falhou:", err);
    }
    this._paint();
  }

  _paint() {
    if (!this.el) return;
    const root = this.el.querySelector(".togare-briefing-root");
    if (!root) return;
    root.innerHTML = renderPanel(this._payload, this._userName);
  }

  _handleVisibilityChange() {
    if (this._stopped) return;
    if (this._isHidden()) {
      this._cancelTimer();
    } else {
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
    if (
      typeof Espo !== "undefined" &&
      Espo.Ajax &&
      typeof Espo.Ajax.getRequest === "function"
    ) {
      // eslint-disable-next-line no-undef
      return (endpoint) => Espo.Ajax.getRequest(endpoint);
    }
    return null;
  }
}

export default BriefingDoDiaDashletView;
