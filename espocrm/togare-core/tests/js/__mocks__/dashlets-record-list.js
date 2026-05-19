/**
 * Mock minimal de `views/dashlets/abstract/record-list` para testes vitest
 * do `togare-prazos-do-dia.js` (Story 4a.5, T4).
 *
 * Cobre o subset usado pelo nosso dashlet:
 *  - `afterRender()` no-op (no runtime, o abstract cria a collection async via
 *    `getCollectionFactory()`; nos testes a fixture seta `this.collection`
 *    diretamente antes de chamar `afterRender`).
 *  - `listenTo(target, event, callback)` — Backbone-like; delega para
 *    `target.on(event, callback)`. Para disparar eventos nos testes, use
 *    `target.trigger(event)` diretamente na collection mock.
 *  - `translate(key, category, scope)` stub — retorna undefined por default
 *    (testes que querem i18n custom sobrescrevem via fixture).
 *  - `this.element` / `this.$el` — providos pelos testes via DOM jsdom.
 *
 * Pattern espelha os mocks 4a.4 (`record-edit.js`, `record-detail.js`).
 */

class RecordListDashletMock {
    constructor(options = {}) {
        this.options = options;
        this.collection = null;
        this.element = null;
        this.$el = null;
        this._listeners = [];
    }

    afterRender() {
        // No-op — testes setam this.collection antes de chamar afterRender da
        // subclasse, evitando o ciclo async do runtime real.
    }

    listenTo(target, event, callback) {
        this._listeners.push({ target, event, callback });
        if (target && typeof target.on === "function") {
            target.on(event, callback);
        }
    }

    listenToOnce(target, event, callback) {
        const wrap = (...args) => {
            this.stopListening(target, event, wrap);
            callback(...args);
        };
        this.listenTo(target, event, wrap);
    }

    stopListening(target, event, callback) {
        if (target && typeof target.off === "function") {
            target.off(event, callback);
        }
    }

    /** Stub para tradução — retorna undefined por default. */
    translate(_key, _category, _scope) {
        return undefined;
    }
}

export default RecordListDashletMock;
