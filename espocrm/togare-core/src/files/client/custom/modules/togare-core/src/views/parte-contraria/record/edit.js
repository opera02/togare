/**
 * ParteContraria record/edit view (Story 3.2, FR6/FR7).
 *
 * Estende `views/record/edit.js` nativo do EspoCRM. Camada client-side da
 * validação BR dupla (UX-DR12) — mostra feedback inline no blur dos campos
 * cpf/cnpj/telefone E bloqueia o submit via `validate()` override (AC2).
 *
 * Diferenças vs Cliente edit.js:
 *  - 3 valores de tipoPessoa (pf | pj | desconhecida) em vez de 2.
 *  - tipo `desconhecida` limpa AMBOS cpf E cnpj.
 *  - Não tem cep/telefone2 — entidade enxuta.
 *  - Sem campos PF-only (rg, dataNascimento, estadoCivil, nacionalidade,
 *    profissao) ou PJ-only (razaoSocial, nomeFantasia, inscricaoEstadual)
 *    — ParteContraria mantém só `name` para identificação.
 *  - `validate()` override integra _validateBrField com pipeline nativo do
 *    EspoCRM — submit é BLOQUEADO se CPF/CNPJ/telefone forem inválidos
 *    (AC2: "Validação client-side bloqueia CPF/CNPJ inválidos"). Cliente
 *    (Story 3.1) só mostra mensagem inline; ParteContraria também bloqueia.
 *
 * Visibilidade dos campos cpf vs cnpj é controlada por `dynamicLogic` em
 * `Resources/metadata/clientDefs/ParteContraria.json` (declarativo, EspoCRM
 * resolve sem JS).
 *
 * Server (Hooks\ParteContraria\ValidateBrFieldsHook) valida novamente em
 * beforeSave e lança HTTP 400 se inválido — gate real continua no servidor
 * (architecture L581 anti-pattern: confiar só no client-side).
 */
import EditView from 'views/record/edit';
import {
    isValidCpf,
    isValidCnpj,
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
    telefone: {
        validate: isValidPhone,
        message: 'Telefone inválido — DDD entre 11 e 99; celular precisa do nono dígito.',
    },
};

export default class ParteContrariaEditView extends EditView {
    setup() {
        super.setup();
        this.listenTo(this.model, 'change:tipoPessoa', this._clearOpposingFields);
    }

    afterRender() {
        super.afterRender();
        this._attachBrValidators();
    }

    /**
     * Sobrescreve `validate()` nativo para integrar os validadores BR ao
     * pipeline do EspoCRM. Convenção do framework: retornar `true` se houver
     * erro (bloqueia submit), `false` se tudo válido. `_validateBrField`
     * já segue essa convenção. Mantém os feedback inline já mostrados pelos
     * listeners `change` em `_attachBrValidators` — agora também bloqueia
     * o save quando inválido (AC2).
     */
    validate() {
        let hasError = super.validate();
        Object.keys(FIELD_VALIDATORS).forEach((field) => {
            if (this._validateBrField(field)) {
                hasError = true;
            }
        });
        return hasError;
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
     * Quando muda tipoPessoa, limpa o campo do "outro lado" para evitar
     * combinações ilegais que o server hook rejeitaria.
     *  - pf  → limpa cnpj
     *  - pj  → limpa cpf
     *  - desconhecida → limpa AMBOS cpf E cnpj
     * Dynamic logic já oculta visualmente, mas o atributo permanece — limpar
     * no model garante consistência ao salvar.
     */
    _clearOpposingFields(model) {
        const tipo = model.get('tipoPessoa');
        if (tipo === 'pf') {
            if (model.get('cnpj')) {
                model.set('cnpj', null);
            }
        } else if (tipo === 'pj') {
            if (model.get('cpf')) {
                model.set('cpf', null);
            }
        } else if (tipo === 'desconhecida') {
            if (model.get('cpf')) {
                model.set('cpf', null);
            }
            if (model.get('cnpj')) {
                model.set('cnpj', null);
            }
        }
    }
}
