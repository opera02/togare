/**
 * Processo record/edit view (Story 3.4, FR7/FR8).
 *
 * Estende `views/record/edit.js` nativo do EspoCRM. Camada client-side da
 * validação CNJ (UX-DR12) — mostra feedback inline no blur do campo numeroCnj
 * E bloqueia o submit via `validate()` override (AC2).
 *
 * Validação dupla CNJ: client-side via `isValidCnj` do helper brValidators.js
 * (Story 1a.5) + server-side via `Hooks/Processo/NormalizeCnjNumberHook`
 * (BeforeSave) — gate real continua no servidor (architecture L573).
 *
 * Lookup TPU dos campos classeCodigo/assuntoCodigo/movimentoCodigo é
 * responsabilidade do field view custom `togare-tpu:fields/tpu-lookup`
 * (Story 3.4 Task 9 em togare-tpu) — wireado via clientDefs/Processo.json
 * `additionalFields` ou diretamente no setup da edit view. Para MVP usamos
 * o input nativo (int) + endpoint search exposto pelo controller
 * TogareTpuCatalog; o widget custom é injetado pelo módulo togare-tpu via
 * fieldType override no entityDefs/Processo.json (campo `classeCodigo` etc.
 * declaram `view: "togare-tpu:fields/tpu-lookup"`). Aqui mantemos o edit.js
 * leve.
 */
import EditView from 'views/record/edit';
import { isValidCnj } from 'togare-core:helpers/brValidators';

const FIELD_VALIDATORS = {
    numeroCnj: {
        validate: isValidCnj,
        message: 'Número CNJ inválido — confira o número e tente de novo.',
    },
};

export default class ProcessoEditView extends EditView {
    setup() {
        super.setup();
        this._togareInvalidFields = {};
    }

    afterRender() {
        super.afterRender();
        this._attachCnjValidator();
    }

    /**
     * Sobrescreve `validate()` nativo para integrar o validador CNJ ao
     * pipeline do EspoCRM. Convenção do framework: retornar `true` se houver
     * erro (bloqueia submit), `false` se tudo válido.
     */
    validate() {
        let hasError = super.validate();
        Object.keys(FIELD_VALIDATORS).forEach((field) => {
            const invalid = this._validateCnjField(field);
            this._setFieldInvalid(field, invalid);
            if (invalid) {
                hasError = true;
            }
        });
        return hasError;
    }

    _attachCnjValidator() {
        Object.keys(FIELD_VALIDATORS).forEach((field) => {
            const fieldView = this.getFieldView(field);
            if (!fieldView) {
                return;
            }
            const validate = () => {
                const invalid = this._validateCnjField(field);
                this._setFieldInvalid(field, invalid);
            };
            const $input = fieldView.$el.find('input, textarea').first();
            if ($input.length > 0) {
                $input.off('blur.togareCnj').on('blur.togareCnj', validate);
            }
            this.listenTo(fieldView, 'change', () => {
                window.setTimeout(validate, 0);
            });
        });
    }

    _validateCnjField(field) {
        const fieldView = this.getFieldView(field);
        if (!fieldView) {
            return false;
        }

        const value = this._getFieldValue(field, fieldView);
        if (value === null || value === undefined || value === '') {
            this._clearValidationMessage(fieldView);
            return false;
        }

        const rule = FIELD_VALIDATORS[field];
        if (!rule.validate(value)) {
            fieldView.showValidationMessage(rule.message);
            return true;
        }
        this._clearValidationMessage(fieldView);
        return false;
    }

    _getFieldValue(field, fieldView) {
        const $input = fieldView.$el.find(`[name="${field}"], input, textarea`).first();
        if ($input.length > 0) {
            return $input.val();
        }
        return this.model.get(field);
    }

    _clearValidationMessage(fieldView) {
        if (typeof fieldView.hideValidationMessage === 'function') {
            fieldView.hideValidationMessage();
            return;
        }
        if (fieldView.$el) {
            fieldView.$el.removeClass('has-error');
            fieldView.$el.find('.validation-message, .message').remove();
        }
    }

    _setFieldInvalid(field, invalid) {
        this._togareInvalidFields[field] = invalid;
        this._syncSaveButtonState();
    }

    _syncSaveButtonState() {
        const disabled = Object.values(this._togareInvalidFields).some(Boolean);
        const $scope = this.$el.closest('.modal-dialog, .record, .content');
        const $buttons = ($scope.length > 0 ? $scope : this.$el)
            .find('[data-action="save"], [name="save"]');

        $buttons
            .prop('disabled', disabled)
            .toggleClass('disabled', disabled)
            .attr('aria-disabled', disabled ? 'true' : 'false');
    }
}
