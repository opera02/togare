/**
 * Mock minimal de `views/record/edit` do EspoCRM para testes Vitest
 * (Story 4a.4 — usado por PrazoEditView).
 *
 * Cobre só o ciclo necessário pelo PrazoEditView:
 *  - constructor com `model` injetado.
 *  - setup() / afterSave() chamáveis (no-op default).
 *  - listenTo(model, evt, cb): repassa para `model.on`.
 *  - getFieldView(name): null default; testes podem injetar override.
 */

export default class EditRecordView {
    constructor(options = {}) {
        this.options = options;
        this.model = options.model || null;
    }

    setup() {}

    afterSave() {}

    listenTo(model, evt, cb) {
        if (model && typeof model.on === "function") {
            model.on(evt, cb);
        }
    }

    listenToOnce(model, evt, cb) {
        if (model && typeof model.on === "function") {
            let fired = false;
            model.on(evt, (...args) => {
                if (fired) return;
                fired = true;
                cb(...args);
            });
        }
    }

    getFieldView(_name) {
        return null;
    }
}
