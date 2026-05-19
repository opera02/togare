/**
 * Mock minimal de `views/fields/text` do EspoCRM para testes Vitest
 * (Story 4a.4 — usado por PayloadAccordion field view).
 *
 * Mesmo pattern do mock `varchar.js` — assinatura mínima para ciclo
 * `getValueForDisplay()` + `setup()` + `model.get/set` + `mode`.
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
}

export default class TextFieldView {
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

    setup() {}

    setElement(el) {
        this.el = el;
        this.$el = new FakeJqElement(el);
    }

    afterRender() {}
}
