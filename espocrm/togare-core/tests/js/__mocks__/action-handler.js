/**
 * Mock minimal de `action-handler` do EspoCRM para testes Vitest (Story 5.6).
 *
 * EspoCRM 9.x despacha ações de relationship panel para um handler instanciado
 * com `new Handler(panelView)`. O construtor recebe a panel view e atribui
 * `this.view = panelView`. Para os testes, basta replicar esse setup.
 */

export default class ActionHandler {
  constructor(view) {
    this.view = view;
  }
}
