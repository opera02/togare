/**
 * Mock minimal de `views/record/row-actions/relationship` (Story 6.1).
 *
 * Subclasses (ContratoHonorariosRelationshipRowActionsView, Documento
 * equivalente) chamam super.getActionList() para obter a lista padrão
 * (View / Edit / Unlink / Remove) e injetam itens custom.
 *
 * Para fins de teste, retornamos uma lista mínima representativa que
 * permite verificar o ordering relativo aos items quickView e quickEdit.
 */

export default class RelationshipRowActionsView {
    constructor(options = {}) {
        this.options = options;
        this.options.acl = this.options.acl || { edit: true, delete: true };
        this.model = options.model || { id: "test-id" };
    }

    getAdditionalActionList() {
        return [];
    }

    getActionList() {
        const list = [
            { action: "quickView", label: "View", groupIndex: 0 },
        ];

        if (this.options.acl.edit && !this.options.editDisabled) {
            list.push({ action: "quickEdit", label: "Edit", groupIndex: 0 });
        }
        if (!this.options.unlinkDisabled) {
            list.push({ action: "unlinkRelated", label: "Unlink", groupIndex: 0 });
        }
        this.getAdditionalActionList().forEach((item) => list.push(item));
        if (this.options.acl.delete && !this.options.removeDisabled) {
            list.push({ action: "removeRelated", label: "Remove", groupIndex: 0 });
        }

        return list;
    }
}
