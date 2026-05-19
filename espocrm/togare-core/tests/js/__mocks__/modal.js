/**
 * Mock minimal de `views/modal` do EspoCRM para testes Vitest (Story 5.2).
 *
 * Cobre só o suficiente para `DocumentoUploadModalView` ser carregada em
 * jsdom — testes do upload-modal.spec.js só exercitam a constante exportada
 * `ACCEPT_ATTRIBUTE`, então o mock não precisa renderizar.
 */

import View from "view";

export default class ModalView extends View {
  constructor(options = {}) {
    super(options);
    this.headerText = "";
    this.buttonList = [];
  }

  disableButton(_name) {}
  enableButton(_name) {}
  close() {}
}
