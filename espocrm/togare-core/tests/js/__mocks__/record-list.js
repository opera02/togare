/**
 * Mock minimal de `views/record/list` do EspoCRM (Story 4b.1c).
 *
 * Cobre só o suficiente para `PublicacaoAmbiguaListView` exercitar
 * `setup()` (mass-action registration) + `massActionBulkIgnoreProcesso`
 * em jsdom.
 *
 * Validado contra `tools/validate-bundle-imports.mjs` (regra v0.19.1) —
 * `views/record/list` consta no whitelist confirmado em runtime.
 *
 * Convenções:
 * - `massActionList` é array mutável (subclasse acrescenta entries).
 * - `collection` é stub Backbone-like com `models` + `fetch` + `getSelected`.
 * - `getSelected()` retorna apenas modelos com `_selected = true`.
 */

export default class ListRecordView {
    constructor(options = {}) {
        this.options = options;
        this.massActionList = Array.isArray(options.massActionList)
            ? options.massActionList.slice()
            : [];
        this.collection = options.collection || {
            models: [],
            fetch() {},
        };
    }

    setup() {}

    /** Retorna modelos marcados com `_selected = true` (helper de teste). */
    getSelected() {
        if (!this.collection || !Array.isArray(this.collection.models)) return [];
        return this.collection.models.filter((m) => m && m._selected === true);
    }

    listenTo(model, evt, cb) {
        if (model && typeof model.on === "function") model.on(evt, cb);
    }
}
