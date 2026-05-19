export default class LinkMultipleFieldView {
    constructor(options = {}) {
        this.options = options;
        this.model = options.model || null;
        this.ids = [];
        this.nameHash = {};
        this.baseFilters = options.baseFilters || null;
        this.reRenderCount = 0;
        this._listeners = {};
    }

    setup() {
        this.name = this.options.name || "processos";
        this.idsName = `${this.name}Ids`;
        this.nameHashName = `${this.name}Names`;
        if (this.model && typeof this.model.get === "function") {
            this.ids = this.model.get(this.idsName) || [];
            this.nameHash = this.model.get(this.nameHashName) || {};
        }
    }

    getSelectFilters() {
        return this.baseFilters;
    }

    listenTo(_model, event, callback) {
        this._listeners[event] = callback;
    }

    reRender() {
        this.reRenderCount += 1;
    }
}
