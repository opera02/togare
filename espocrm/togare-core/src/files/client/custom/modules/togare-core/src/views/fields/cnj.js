/**
 * CNJ field view (Story 3.4).
 *
 * Storage permanece só dígitos; detail/list renderizam a máscara canônica
 * NNNNNNN-DD.AAAA.J.TR.OOOO.
 */
import VarcharFieldView from 'views/fields/varchar';
import { formatCnj } from 'togare-core:helpers/hbFormatters';

export default class CnjFieldView extends VarcharFieldView {
    getValueForDisplay() {
        const value = this.model.get(this.name);
        if (value === null || value === undefined || value === '') {
            return '';
        }
        return formatCnj(value);
    }
}
