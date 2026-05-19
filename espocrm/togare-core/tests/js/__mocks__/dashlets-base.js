/**
 * Mock minimal de `views/dashlets/abstract/base` para testes vitest do
 * `togare-health-panel.js` (Story 10.2 / FR41).
 *
 * Cobre só o subset usado pela nossa view:
 *  - construtor guarda options;
 *  - `setup()` / `afterRender()` / `onRemove()` no-op (a subclasse chama
 *    `super.*` defensivamente — o mock só precisa existir);
 *  - `this.el` é provido pelos testes via jsdom (a view faz DOM patch em
 *    `.togare-health-root`, não depende de Handlebars).
 *
 * Pattern espelha `dashlets-record-list.js`.
 */

class DashletBaseMock {
  constructor(options = {}) {
    this.options = options;
    this.el = null;
  }

  setup() {}

  afterRender() {}

  onRemove() {}

  translate(_key, _category, _scope) {
    return undefined;
  }
}

export default DashletBaseMock;
