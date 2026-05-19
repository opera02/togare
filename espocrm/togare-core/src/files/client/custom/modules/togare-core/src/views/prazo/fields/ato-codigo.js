/**
 * AtoCodigo field view (Story 4a.4 F1.2).
 *
 * Estende `views/fields/varchar` e renderiza o `atoCodigo` (snake_case interno
 * gerado pelo DjenAtoClassifier) com label pt-BR amigável via dictionary
 * compartilhado em `togare-core:helpers/atoCodigo-formatter`.
 *
 * Valor desconhecido (fora dos 11 do dictionary) renderiza cru — graceful
 * fallback alinhado a CnjFieldView (Story 3.4) e helpers de máscara BR.
 *
 * Aplicado via entityDefs/Prazo.json::atoCodigo.view.
 */

import VarcharFieldView from "views/fields/varchar";
import { formatAtoCodigo } from "togare-core:helpers/atoCodigo-formatter";

export default class AtoCodigoFieldView extends VarcharFieldView {
    getValueForDisplay() {
        const value = this.model ? this.model.get(this.name) : null;
        if (value === null || value === undefined || value === "") {
            return "";
        }
        return formatAtoCodigo(value);
    }
}
