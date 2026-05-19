/**
 * CPF field view (Story 3-A).
 *
 * Storage permanece só dígitos (architecture L457 + NormalizeBrFieldsHook
 * server-side defensivo). Display detail/list aplica a máscara
 * XXX.XXX.XXX-XX. Edit aplica auto-format enquanto digita, mas o model
 * recebe sempre SÓ DÍGITOS (single source of truth).
 *
 * Input inválido (≠11 dígitos após digitsOnly) passa-through sem mascarar
 * — preserva a investigação visual de dados meio-cadastrados, em sintonia
 * com o contrato dos helpers em hbFormatters.js.
 */
import VarcharFieldView from 'views/fields/varchar';
import { formatCpf } from 'togare-core:helpers/hbFormatters';
import { digitsOnly, isValidCpf } from 'togare-core:helpers/brValidators';

const MAX_DIGITS = 11;
const INVALID_CPF_MESSAGE = 'CPF inválido — confira o número e tente de novo.';

export default class CpfBrFieldView extends VarcharFieldView {
    getValueForDisplay() {
        const value = this.model.get(this.name);
        if (value === null || value === undefined || value === '') {
            return '';
        }
        return formatCpf(value);
    }

    fetch() {
        const $input = this.$el.find('input').first();
        const value = $input.length ? $input.val() : this.model.get(this.name);
        const digits = digitsOnly(value).slice(0, MAX_DIGITS);

        return {
            [this.name]: digits || null,
        };
    }

    validate() {
        const baseHasError = typeof super.validate === 'function' ? super.validate() : false;
        const value = this.model.get(this.name);
        const digits = digitsOnly(value);

        if (!digits) {
            return baseHasError;
        }

        if (!isValidCpf(digits)) {
            this.showValidationMessage?.(INVALID_CPF_MESSAGE);
            return true;
        }

        return baseHasError;
    }

    afterRender() {
        super.afterRender();
        if (this.mode !== this.MODE_EDIT) {
            return;
        }
        const $input = this.$el.find('input').first();
        if (!$input.length) {
            return;
        }

        // inputmode='numeric' → teclado numérico simples em mobile.
        $input.attr('inputmode', 'numeric');

        const initial = this.model.get(this.name);
        if (initial) {
            $input.val(formatCpf(digitsOnly(initial)));
        }

        $input.on('input', (e) => {
            const digits = digitsOnly(e.target.value).slice(0, MAX_DIGITS);
            const masked = formatCpf(digits);
            if (e.target.value !== masked) {
                e.target.value = masked;
            }
            this.model.set(this.name, digits, {
                ui: true,
                fromView: this,
                fromField: this.name,
                action: 'ui',
            });
        });
    }
}
