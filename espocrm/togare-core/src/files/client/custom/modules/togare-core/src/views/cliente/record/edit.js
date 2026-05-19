/**
 * Cliente record/edit view (Story 3.1, FR6).
 *
 * Estende `views/record/edit.js` nativo do EspoCRM. Camada client-side da
 * validação BR dupla (UX-DR12) — mostra feedback inline no blur dos campos
 * cpf/cnpj/cep/telefone/telefone2 sem bypass do servidor.
 *
 * Visibilidade dos campos PF vs PJ é controlada por `dynamicLogic` em
 * `Resources/metadata/clientDefs/Cliente.json` (declarativo, EspoCRM
 * resolve sem JS).
 *
 * Server (Hooks\Cliente\ValidateBrFieldsHook) valida novamente em beforeSave
 * e lança HTTP 400 se inválido — gate real continua no servidor (architecture
 * L581 anti-pattern: confiar só no client-side).
 */
import EditView from 'views/record/edit';
import {
    isValidCpf,
    isValidCnpj,
    isValidCep,
    isValidPhone,
} from 'togare-core:helpers/brValidators';

const FIELD_VALIDATORS = {
    cpf: {
        validate: isValidCpf,
        message: 'CPF inválido — confira o número e tente de novo.',
    },
    cnpj: {
        validate: isValidCnpj,
        message: 'CNPJ inválido — confira o número e tente de novo.',
    },
    cep: {
        validate: isValidCep,
        message: 'CEP inválido — devem ser exatamente 8 dígitos.',
    },
    telefone: {
        validate: isValidPhone,
        message: 'Telefone inválido — DDD entre 11 e 99; celular precisa do nono dígito.',
    },
    telefone2: {
        validate: isValidPhone,
        message: 'Telefone inválido — DDD entre 11 e 99; celular precisa do nono dígito.',
    },
};

export default class ClienteEditView extends EditView {
    setup() {
        super.setup();
        this.listenTo(this.model, 'change:tipoPessoa', this._clearOpposingFields);
    }

    afterRender() {
        super.afterRender();
        this._attachBrValidators();
    }

    _attachBrValidators() {
        Object.keys(FIELD_VALIDATORS).forEach((field) => {
            const fieldView = this.getFieldView(field);
            if (!fieldView) {
                return;
            }
            this.listenTo(fieldView, 'change', () => {
                this._validateBrField(field);
            });
        });
    }

    _validateBrField(field) {
        const fieldView = this.getFieldView(field);
        if (!fieldView) {
            return false;
        }

        const value = this.model.get(field);
        if (value === null || value === undefined || value === '') {
            return false;
        }

        const rule = FIELD_VALIDATORS[field];
        if (!rule.validate(value)) {
            fieldView.showValidationMessage(rule.message);
            return true;
        }
        return false;
    }

    /**
     * Quando muda PF↔PJ, limpa o campo do "outro lado" para evitar combinações
     * ilegais (e.g. PF com cnpj preenchido) que o server hook rejeitaria.
     * Dynamic logic já oculta visualmente, mas o atributo permanece — limpar
     * no model garante consistência ao salvar.
     */
    _clearOpposingFields(model) {
        const tipo = model.get('tipoPessoa');
        if (tipo === 'pf') {
            ['cnpj', 'razaoSocial', 'nomeFantasia', 'inscricaoEstadual'].forEach((f) => {
                if (model.get(f)) {
                    model.set(f, null);
                }
            });
        } else if (tipo === 'pj') {
            ['cpf', 'rg', 'dataNascimento', 'estadoCivil', 'nacionalidade', 'profissao'].forEach((f) => {
                if (model.get(f)) {
                    model.set(f, null);
                }
            });
        }
    }
}
