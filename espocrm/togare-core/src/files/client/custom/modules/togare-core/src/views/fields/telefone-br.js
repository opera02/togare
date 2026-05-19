/**
 * Telefone BR field view (Story 3-A).
 *
 * Storage permanece só dígitos (architecture L457 + NormalizeBrFieldsHook
 * server-side defensivo). Display detail/list aplica a máscara
 * (DD) XXXX-XXXX (10 dígitos — fixo) ou (DD) XXXXX-XXXX (11 dígitos —
 * celular com nono). Auto-detecção via formatPhone — sem param tipo
 * (Decisão #4 da Story 3-A). Edit aplica auto-format enquanto digita,
 * mas o model recebe sempre SÓ DÍGITOS (single source of truth).
 *
 * Input inválido (≠10 e ≠11 dígitos após digitsOnly) passa-through sem
 * mascarar.
 */
import VarcharFieldView from 'views/fields/varchar';
import { formatPhone } from 'togare-core:helpers/hbFormatters';
import { digitsOnly } from 'togare-core:helpers/brValidators';

const MAX_DIGITS = 11;

export default class TelefoneBrFieldView extends VarcharFieldView {
    getValueForDisplay() {
        const value = this.model.get(this.name);
        if (value === null || value === undefined || value === '') {
            return '';
        }
        return formatPhone(value);
    }

    fetch() {
        const $input = this.$el.find('input').first();
        const value = $input.length ? $input.val() : this.model.get(this.name);
        const digits = digitsOnly(value).slice(0, MAX_DIGITS);

        return {
            [this.name]: digits || null,
        };
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

        // inputmode='tel' → teclado de telefone em mobile (semântico —
        // permite +/-/(/) historicamente, mas digitsOnly remove máscara
        // de qualquer forma).
        $input.attr('inputmode', 'tel');

        const initial = this.model.get(this.name);
        if (initial) {
            $input.val(formatPhone(digitsOnly(initial)));
        }

        $input.on('input', (e) => {
            const digits = digitsOnly(e.target.value).slice(0, MAX_DIGITS);
            const masked = formatPhone(digits);
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
