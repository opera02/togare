/**
 * Mock mínimo Backbone-compatível da classe `View` do EspoCRM para testes Vitest.
 *
 * Preenche só o suficiente para as views do togare-core renderizarem e
 * processarem eventos em jsdom. Não reimplementa a API completa do EspoCRM.
 *
 * `events` dict { "event selector": "methodName" | fn } é registrado via
 * addEventListener no root $el. `data()` + `template` são combinados de forma
 * **muito** simplificada — a view pode sobrescrever `renderHtml()` se precisar.
 */

export default class View {
  constructor(options = {}) {
    this.options = options;
    this.el = null;
    this.$el = null;
    this._eventCleanups = [];
  }

  setup() {}

  data() {
    return {};
  }

  setElement(elOrSelector) {
    // Story 4a.4 fix-pass 0.19.4: Bullbone real aceita string CSS selector
    // OU HTMLElement direto. Mock espelha esse comportamento dual para os
    // tests do ToastTogareView.show() que agora passa selector.
    let el;
    if (typeof elOrSelector === "string") {
      el = document.querySelector(elOrSelector);
    } else {
      el = elOrSelector;
    }
    this.el = el;
    this.$el = {
      0: el,
      get: (i) => (i === 0 ? el : undefined),
      remove: () => el && el.remove(),
    };
  }

  async render() {
    if (!this.el) {
      const el = document.createElement("div");
      this.setElement(el);
    }
    const html = this.renderHtml(this.data());
    this.el.innerHTML = html;
    this._bindEvents();
    if (typeof this.afterRender === "function") {
      this.afterRender();
    }
    return this;
  }

  /**
   * Template rendering simplificado — a view real usa Handlebars compilado
   * pelo EspoCRM. Em testes, subclasses podem sobrescrever `renderHtml()`
   * para devolver HTML determinístico. Default: stub vazio.
   */
  renderHtml(_data) {
    return "<div></div>";
  }

  _bindEvents() {
    if (!this.events || !this.el) return;
    for (const key of Object.keys(this.events)) {
      const spaceIdx = key.indexOf(" ");
      const eventName = spaceIdx === -1 ? key : key.slice(0, spaceIdx);
      const selector = spaceIdx === -1 ? null : key.slice(spaceIdx + 1);
      const handlerName = this.events[key];
      const handler = typeof handlerName === "function"
        ? handlerName
        : this[handlerName]?.bind(this);
      if (!handler) continue;

      const listener = (e) => {
        if (selector) {
          const matches = this.el.querySelectorAll(selector);
          for (const node of matches) {
            if (node === e.target || node.contains(e.target)) {
              handler(e);
              break;
            }
          }
        } else {
          handler(e);
        }
      };
      this.el.addEventListener(eventName, listener, true);
      this._eventCleanups.push(() => {
        this.el?.removeEventListener(eventName, listener, true);
      });
    }
  }

  getLanguage() {
    // Stub — testes injetam via mock específico por caso (vi.spyOn ou
    // override direto). Default retorna string vazia / objeto vazio.
    return {
      translate: () => ({}),
    };
  }

  remove() {
    for (const cleanup of this._eventCleanups) cleanup();
    this._eventCleanups = [];
    if (this.el?.parentNode) {
      this.el.parentNode.removeChild(this.el);
    }
    this.el = null;
    this.$el = null;
  }
}
