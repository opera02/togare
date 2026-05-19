/**
 * Field view link com autocomplete inline (search-as-you-type) — Story 4b.1c
 * (Decisão #6 da spec-mãe 4b.1).
 *
 * Stock `views/fields/link` em mode=edit já traz autocomplete via
 * `autoCompleteFieldUrl`, mas exibe um botão "Selecionar" que abre modal
 * de busca em paralelo (regra A6 — modal-3-cliques é fricção em piloto).
 * Esta subclasse remove o modal e força exclusivamente o autocomplete inline.
 *
 * Storage / validation / audit não mudam — só display + input.
 *
 * Uso em entityDefs:
 *   "processo": {
 *     "type": "link",
 *     "view": "togare-core:views/fields/link-autocomplete"
 *   }
 *
 * Story 4b.1c entrega esta view como side-product reutilizável SEM aplicar
 * transversalmente. Aplicação concreta a Cliente / ParteContraria / Processo /
 * Audiencia / Prazo edit forms = Story 4b.1-followup ou Epic 10 housekeeping.
 */

import LinkFieldView from "views/fields/link";

export default class LinkAutocompleteFieldView extends LinkFieldView {
  // Override declarativo: NÃO renderiza botão "Selecionar" (modal trigger
  // do stock link view).
  selectAction = null;

  // Remove "+ Criar" inline (criação fica em UI dedicada da entity-alvo).
  createDisabled = true;

  setup() {
    super.setup();
    // Garante que o autocomplete inline (search-as-you-type) está ativo.
    // Default do stock `views/fields/link` já é true; reforçamos defensivamente
    // para o caso de subclasse intermediária ter desabilitado.
    this.autocompleteDisabled = false;
  }

  /**
   * Hook de extensão para customizar tamanho da lista de sugestões.
   * Default 10 (UX-DR2: lista enxuta). Subclasses podem overridar.
   * @returns {number}
   */
  getAutocompleteMaxCount() {
    return 10;
  }
}
