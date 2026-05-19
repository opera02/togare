/**
 * SystemStatusBannerView — Story 4b.4 (FR18, NFR19, ADR 0009).
 *
 * Banner amarelo persistente exibido quando a integração DJEN está
 * operacionalmente indisponível há ≥30 min. O backend mantém esse relógio
 * separado do cooldown técnico de 10min do circuit breaker.
 *
 * Texto literal pt-BR (UX-DR5 — Discovery #1 da retro Epic 4a):
 *   "Sync DJEN pausada há {N}min. Próxima tentativa às {HH:MM}."
 * Sem link clicável "Ver status" (HealthPanel é Epic 10 — followup).
 *
 * Polling REST a cada 60s do endpoint
 * `GET /api/v1/TogareDjenStatus/action/snapshot`. Pause automaticamente
 * quando aba está hidden (`document.visibilityState`); resume + fetch
 * imediato ao voltar para visible.
 *
 * Usage (mount em surface de prazo):
 *   this.createView('systemStatusBanner', 'togare-core:views/common/system-status-banner')
 *     .then(view => view.render());
 *
 * Cleanup automático: `onRemove()` cancela timer + remove listener
 * `visibilitychange` (sem leak).
 */

import View from "view";

const POLL_INTERVAL_MS = 60_000;
const MIN_MINUTES_TO_SHOW = 30;
const SNAPSHOT_ENDPOINT = "TogareDjenStatus/action/snapshot";

export default class SystemStatusBannerView extends View {
  template = "togare-core:common/system-status-banner";
  _snapshot = null;
  _timerId = null;

  constructor(options) {
    super(options);
    // Bind handler no constructor para que removeEventListener encontre a
    // mesma referência no onRemove(). Registro do listener fica em setup()
    // (chamado pelo factory do EspoCRM em runtime; em testes vitest deve ser
    // chamado explicitamente — `view.setup()` antes de `view.render()`).
    this._visibilityHandler = () => this._handleVisibilityChange();
  }

  setup() {
    super.setup();
    if (typeof document !== "undefined" && typeof document.addEventListener === "function") {
      document.addEventListener("visibilitychange", this._visibilityHandler);
    }
  }

  data() {
    const visible = this._isVisible();
    return {
      visible: visible ? "true" : "false",
      message: visible ? this._buildMessage() : "",
    };
  }

  afterRender() {
    if (typeof super.afterRender === "function") {
      super.afterRender();
    }
    // Primeiro fetch imediato + agenda próximo tick.
    this._pollSnapshot();
  }

  onRemove() {
    this._cancelTimer();
    if (typeof document !== "undefined" && typeof document.removeEventListener === "function") {
      document.removeEventListener("visibilitychange", this._visibilityHandler);
    }
    if (typeof super.onRemove === "function") {
      super.onRemove();
    }
  }

  /**
   * Dispara fetch + reagenda timer para próximo poll de 60s. Idempotente:
   * cancela timer pendente antes de criar o novo.
   */
  _pollSnapshot() {
    this._cancelTimer();
    const ajax = this._resolveAjax();
    if (ajax === null) {
      // Ambiente sem Espo.Ajax (testes vitest sem stub) — sai silenciosamente.
      return;
    }
    ajax(SNAPSHOT_ENDPOINT)
      .then((response) => this._handleSnapshotSuccess(response))
      .catch((err) => this._handleSnapshotError(err));
    this._scheduleNextPoll();
  }

  _scheduleNextPoll() {
    if (typeof window === "undefined" || typeof window.setTimeout !== "function") return;
    if (this._isHidden()) return; // pause em aba hidden
    this._timerId = window.setTimeout(() => this._pollSnapshot(), POLL_INTERVAL_MS);
  }

  _cancelTimer() {
    if (this._timerId !== null && typeof window !== "undefined" && typeof window.clearTimeout === "function") {
      window.clearTimeout(this._timerId);
    }
    this._timerId = null;
  }

  _handleSnapshotSuccess(response) {
    this._snapshot = response || null;
    this._renderFromState();
  }

  _handleSnapshotError(err) {
    if (typeof console !== "undefined" && console.warn) {
      console.warn("[togare-core/SystemStatusBanner] snapshot failed:", err);
    }
    // Fail-safe: mantém invisível em caso de erro (não derruba a página).
    this._snapshot = null;
    this._renderFromState();
  }

  _renderFromState() {
    if (!this.el) return;
    const visible = this._isVisible();
    const root = this.el.querySelector(".togare-system-status-banner");
    if (!root) return;
    root.setAttribute("data-visible", visible ? "true" : "false");
    const textEl = root.querySelector(".togare-system-status-banner__text");
    if (textEl) {
      textEl.textContent = visible ? this._buildMessage() : "";
    }
  }

  _isVisible() {
    if (this._snapshot === null) return false;
    if (this._snapshot.cbOpen !== true) return false;
    const minutesOpen = Number(this._snapshot.minutesOpen);
    if (!Number.isFinite(minutesOpen)) return false;
    return minutesOpen >= MIN_MINUTES_TO_SHOW;
  }

  _buildMessage() {
    const minutesOpen = Number(this._snapshot && this._snapshot.minutesOpen) || 0;
    const nextRetryHint = (this._snapshot && this._snapshot.nextRetryHint) || "";
    const template = this._resolveI18nMessage();
    return template
      .replace(/\{N\}/g, String(minutesOpen))
      .replace(/\{HH:MM\}/g, nextRetryHint);
  }

  _resolveI18nMessage() {
    const fallback = "Sync DJEN pausada há {N}min. Próxima tentativa às {HH:MM}.";
    if (typeof this.getLanguage !== "function") return fallback;
    const lang = this.getLanguage();
    if (!lang || typeof lang.translate !== "function") return fallback;
    const translated = lang.translate("djenUnavailable", "messages", "SystemStatusBanner");
    if (typeof translated === "string" && translated !== "" && translated !== "djenUnavailable") {
      return translated;
    }
    return fallback;
  }

  _handleVisibilityChange() {
    if (this._isHidden()) {
      this._cancelTimer();
    } else {
      // Voltou para visible: fetch imediato + reagenda.
      this._pollSnapshot();
    }
  }

  _isHidden() {
    if (typeof document === "undefined") return false;
    return document.visibilityState === "hidden";
  }

  /**
   * Resolve `Espo.Ajax.getRequest` (existe no runtime EspoCRM 9.x).
   * Em testes vitest, retorna `globalThis.__togareAjaxStub` se setado.
   * Retorna `null` se nada disponível — caller fail-safe.
   */
  _resolveAjax() {
    if (typeof globalThis !== "undefined" && typeof globalThis.__togareAjaxStub === "function") {
      return globalThis.__togareAjaxStub;
    }
    if (typeof window !== "undefined" && window.Espo && window.Espo.Ajax && typeof window.Espo.Ajax.getRequest === "function") {
      return (endpoint) => window.Espo.Ajax.getRequest(endpoint);
    }
    if (typeof Espo !== "undefined" && Espo.Ajax && typeof Espo.Ajax.getRequest === "function") {
      // eslint-disable-next-line no-undef
      return (endpoint) => Espo.Ajax.getRequest(endpoint);
    }
    return null;
  }
}
