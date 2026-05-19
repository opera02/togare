/**
 * Mock minimal de `views/fields/base` para testes Vitest.
 *
 * Diferente do mock `view`, este simula o caminho de field view do EspoCRM:
 * a view fornece `templateContent` + `data()`, e o render usa esses dados
 * em vez de chamar um metodo custom inexistente no runtime.
 */
import View from "./view.js";

export default class BaseFieldView extends View {
  constructor(options = {}) {
    super(options);
    this.name = options.name || "value";
    this.model = options.model || null;
    this.mode = options.mode || "detail";
    this.MODE_DETAIL = "detail";
    this.MODE_EDIT = "edit";
    this.MODE_LIST = "list";
    this.inlineEditDisabled = false;
    this.events = {};
    this._listeners = {};
  }

  addHandler(eventName, selector, methodName) {
    this.events[`${eventName} ${selector}`] = methodName;
  }

  trigger(eventName, ...args) {
    for (const cb of this._listeners[eventName] || []) {
      cb(...args);
    }
  }

  on(eventName, cb) {
    if (!this._listeners[eventName]) this._listeners[eventName] = [];
    this._listeners[eventName].push(cb);
  }

  renderHtml(data) {
    const tpl =
      this.mode === this.MODE_EDIT
        ? this.editTemplateContent
        : this.mode === this.MODE_LIST
          ? this.listTemplateContent
          : this.detailTemplateContent;

    if (!tpl) return "";

    return tpl.replace(/\{\{\{panelHtml\}\}\}/g, data.panelHtml || "");
  }

  fetch() {
    return {};
  }
}
