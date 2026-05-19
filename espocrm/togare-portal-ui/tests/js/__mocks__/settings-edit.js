/**
 * Mock mínimo de `views/settings/record/edit` para Vitest.
 *
 * Cobre o que o TogarePortalAppearanceView consome: options/model,
 * element, listenTo(model), translate(), afterRender()/save() encadeáveis.
 */

export default class SettingsEditRecordView {
    constructor(options = {}) {
        this.options = options;
        this.model = options.model || null;
        this.element = options.element || document.createElement("div");
        this._lang = options.lang || {};
        this._superSaveCalled = false;
    }

    setup() {}

    afterRender() {
        this._superAfterRenderCalled = true;
    }

    listenTo(model, evt, cb) {
        if (model && typeof model.on === "function") {
            model.on(evt, cb);
        }
    }

    translate(key, category, scope) {
        const dict = this._lang[scope] || {};
        const cat = dict[category] || {};

        return cat[key] !== undefined ? cat[key] : key;
    }

    save(options) {
        this._superSaveCalled = true;

        return Promise.resolve(options);
    }
}
