/**
 * Mock minimal de `views/record/detail` do EspoCRM (Story 4a.4 —
 * PrazoDetailView). Idêntico ao record-edit em estrutura.
 */

export default class DetailRecordView {
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
