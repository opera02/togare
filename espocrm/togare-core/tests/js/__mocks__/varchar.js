/**
 * Mock minimal de `views/fields/varchar` do EspoCRM para testes Vitest.
 *
 * Cobre só o suficiente para as field views customs do togare-core
 * (cpf-br, cnpj-br, cep-br, telefone-br, cnj) renderizarem em jsdom e
 * exercitarem o ciclo `getValueForDisplay()` + `afterRender()` no MODE_EDIT.
 *
 * Convenções:
 * - `mode` é uma string mutável (default 'detail'); seta para `MODE_EDIT`
 *   antes do render quando o teste quer auto-format do input.
 * - `model.get/set` é Backbone-like simplificado.
 * - `$el` é wrapper jQuery-like com `find()` retornando elementos com
 *   `length`, `val()`, `on()`. Suficiente para o listener `input`.
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

    attr(name, value) {
        if (!this._el) return this;
        if (value === undefined) {
            return this._el.getAttribute(name);
        }
        this._el.setAttribute(name, value);
        return this;
    }
}

export default class VarcharFieldView {
    constructor(options = {}) {
        this.options = options;
        this.name = options.name || "value";
        this.model = options.model || {
            _data: {},
            get(k) {
                return this._data[k];
            },
            set(k, v) {
                this._data[k] = v;
            },
        };
        this.mode = options.mode || "detail";
        this.MODE_EDIT = "edit";
        this.MODE_DETAIL = "detail";
        this.MODE_LIST = "list";
        this.el = options.el || null;
        this.$el = new FakeJqElement(this.el);
    }

    setElement(el) {
        this.el = el;
        this.$el = new FakeJqElement(el);
    }

    afterRender() {
        // Stub — subclasses chamam super.afterRender() e em seguida fazem
        // sua própria lógica.
    }
}
