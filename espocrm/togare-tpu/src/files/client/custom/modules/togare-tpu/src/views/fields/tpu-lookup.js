/**
 * TPU lookup field view (Story 3.4 Task 9, FR8).
 *
 * Field view custom para campos `classeCodigo`, `assuntoCodigo`,
 * `movimentoCodigo` da entidade Processo (togare-core). Permite ao usuário
 * digitar o código diretamente OU clicar "Buscar..." para abrir um modal
 * com search por nome contra `/api/v1/TogareTpuCatalog/action/search{Tipo}`.
 *
 * Configuração em entityDefs/Processo.json é opcional — para MVP, basta
 * declarar `view: "togare-tpu:fields/tpu-lookup"` no campo que deseja
 * customizar (clientDefs override). Caso contrário o EspoCRM usa o
 * field nativo `int`. Em todo caso, o hook server-side valida (FR8 estrito).
 *
 * O parâmetro `tpuTipo` (classe/assunto/movimento) é declarado em
 * entityDefs.fields.<field>.tpuTipo e lido via `this.params.tpuTipo`.
 *
 * Estende `views/fields/int` para reusar render numérico nativo + adiciona
 * botão "Buscar..." que abre o modal `togare-tpu:modals/tpu-search`.
 */
import IntFieldView from 'views/fields/int';

export default class TpuLookupFieldView extends IntFieldView {
    setup() {
        super.setup();
        this.tpuTipo = this.params.tpuTipo || this.options.tpuTipo || 'classe';
    }

    afterRenderEdit() {
        if (super.afterRenderEdit) {
            super.afterRenderEdit();
        }
        this._injectSearchButton();
    }

    afterRender() {
        super.afterRender();
        if (this.mode === this.MODE_EDIT) {
            this._injectSearchButton();
        }
    }

    _injectSearchButton() {
        const $cell = this.$el.hasClass('field') ? this.$el : this.$el.find('.field').first();
        if ($cell.length === 0 || $cell.find('.tpu-search-btn').length > 0) {
            return;
        }
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-default btn-sm tpu-search-btn';
        button.style.marginLeft = '6px';
        button.textContent = 'Buscar...';
        button.addEventListener('click', () => this._openSearchModal());
        $cell[0].appendChild(button);
    }

    _openSearchModal() {
        const tipo = this.tpuTipo;
        const codigoFieldName = this.name;
        const nomeFieldName = this._deriveNomeFieldName();
        this.createView('tpuSearchModal', 'togare-tpu:modals/tpu-search', {
            tpuTipo: tipo,
            onSelect: (codigo, nome) => {
                this.model.set(codigoFieldName, codigo);
                if (nomeFieldName) {
                    this.model.set(nomeFieldName, nome);
                }
            },
        }, (view) => {
            view.render();
        });
    }

    _deriveNomeFieldName() {
        // classeCodigo → classeNome; assuntoCodigo → assuntoNome; movimentoCodigo → movimentoNome
        if (this.name.endsWith('Codigo')) {
            return this.name.slice(0, -'Codigo'.length) + 'Nome';
        }
        return null;
    }
}
