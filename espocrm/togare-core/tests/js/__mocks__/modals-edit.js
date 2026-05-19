/**
 * Mock minimal de `views/modals/edit` do EspoCRM 9.x (Story 6.1).
 *
 * Subclasses (ContratoHonoraiosUploadModalView) chamam super.setup() para
 * configurar scope/relate/attributes. Stock implementation gera o form a
 * partir do entityDefs do scope. Mock retorna o suficiente para verificar
 * que setup() roda sem throw e que campos esperados foram setados.
 */

export default class ModalsEditView {
    constructor(options = {}) {
        this.options = options;
        this.scope = options.scope || null;
        this.entityType = options.entityType || this.scope;
        this.model = null;
        this.headerText = null;
        this.headerHtml = null;
    }

    setup() {
        this.scope = this.scope || this.options.scope || this.options.entityType;
        this.entityType = this.options.entityType || this.scope;
        this.model = {
            attributes: {},
            relate: null,
            set(attrs) {
                Object.assign(this.attributes, attrs);
            },
            setRelate(relate) {
                this.relate = relate;
            },
        };

        if (this.options.relate) {
            this.model.setRelate(this.options.relate);
        }
        if (this.options.attributes) {
            this.model.set(this.options.attributes);
        }
    }

    translate(key, _category, _scope) {
        return key;
    }
}
