/**
 * Mock minimal de `views/fields/enum` do EspoCRM para testes Vitest
 * (Story 4a.4 — usado por StatusSelector field view).
 *
 * Mesmo pattern do mock `varchar.js` — assinatura mínima cobrindo
 * `getValueForDisplay()` + `setup()` + `model.get/set` + `mode` + `params.options`.
 *
 * O ciclo de listener `model.listenTo` em testes é simulado via Backbone-like
 * `_changeListeners` map: `listenTo(model, evt, cb)` registra; `model.trigger(evt, ...args)`
 * dispara. Suficiente para testar StatusSelector sem importar Backbone.
 */

class FakeJqElement {
    constructor(el) {
        this._el = el;
        this.length = el ? 1 : 0;
    }

    find(selector) {
        if (!this._el) return new FakeJqElement(null);
        const found = this._el.querySelector(selector);
        return new FakeJqElement(found);
    }

    first() {
        return this;
    }

    val(v) {
        if (v === undefined) {
            return this._el ? this._el.value : "";
        }
        if (this._el) this._el.value = v;
        return this;
    }

    on(eventName, handler) {
        if (this._el) {
            this._el.addEventListener(eventName, handler);
        }
        return this;
    }

    html(html) {
        if (this._el) this._el.innerHTML = html;
        return this;
    }

    append(html) {
        if (this._el) this._el.insertAdjacentHTML("beforeend", html);
        return this;
    }
}

export default class EnumFieldView {
    constructor(options = {}) {
        this.options = options;
        this.name = options.name || "value";
        this.model = options.model || {
            _data: {},
            get(k) {
                return this._data[k];
            },
            set(k, v, opts) {
                this._data[k] = v;
                this._fireChange(k, v, opts);
            },
            previousAttributes() {
                return this._prev || {};
            },
            save() {
                return Promise.resolve();
            },
            _changeListeners: {},
            _prev: {},
            _fireChange(k, v, opts) {
                const evt = `change:${k}`;
                if (this._changeListeners[evt]) {
                    for (const cb of this._changeListeners[evt]) {
                        cb(this, v, opts || {});
                    }
                }
            },
            on(evt, cb) {
                if (!this._changeListeners[evt]) this._changeListeners[evt] = [];
                this._changeListeners[evt].push(cb);
            },
        };
        this.params = options.params || { options: [] };
        this.mode = options.mode || "detail";
        this.MODE_EDIT = "edit";
        this.MODE_DETAIL = "detail";
        this.MODE_LIST = "list";
        this.el = options.el || null;
        this.$el = new FakeJqElement(this.el);
    }

    setup() {}

    setElement(el) {
        this.el = el;
        this.$el = new FakeJqElement(el);
    }

    afterRender() {}

    listenTo(model, evt, cb) {
        if (model && typeof model.on === "function") {
            model.on(evt, cb);
        }
    }

    getValueForDisplay() {
        return this.model.get(this.name) || "";
    }
}
