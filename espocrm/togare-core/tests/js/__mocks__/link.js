/**
 * Mock minimal de `views/fields/link` do EspoCRM (Story 4b.1c).
 *
 * Cobre só o suficiente para `LinkAutocompleteFieldView` exercitar
 * setup() + verificar overrides declarativos `selectAction = null` +
 * `createDisabled = true` + `autocompleteDisabled = false`.
 *
 * Validado contra `tools/validate-bundle-imports.mjs` (regra v0.19.1) —
 * `views/fields/link` consta no whitelist confirmado em runtime.
 */

export default class LinkFieldView {
    constructor(options = {}) {
        this.options = options;
        this.name = options.name || "value";
        this.model = options.model || null;
        this.mode = options.mode || "detail";
        this.MODE_EDIT = "edit";
        this.MODE_DETAIL = "detail";
        this.MODE_LIST = "list";

        // Defaults stock que a subclasse `link-autocomplete` sobrescreve.
        this.selectAction = "createSelect";
        this.createDisabled = false;
        this.autocompleteDisabled = false;
    }

    setup() {
        // Stub
    }
}
