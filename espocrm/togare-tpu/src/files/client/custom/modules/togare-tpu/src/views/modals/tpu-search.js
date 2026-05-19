/**
 * TPU search modal view (Story 3.4 Task 9).
 *
 * Modal de busca por nome no catálogo TPU. Recebe `tpuTipo` (classe |
 * assunto | movimento) e callback `onSelect(codigo, nome)`. Usa AJAX
 * debounced para `/api/v1/TogareTpuCatalog/action/search{Tipo}?q=...&limit=20`.
 *
 * Comportamento:
 *  - Input texto > debounce 300ms.
 *  - Search dispara quando user digita ≥3 chars.
 *  - Lista resultados clicáveis (até 20).
 *  - Cliente seleciona linha → callback + close.
 *  - Cancelar / ESC → fecha sem alterar.
 */
import ModalView from 'views/modal';

const DEBOUNCE_MS = 300;
const TIPO_TO_ACTION = {
    classe: 'searchClasses',
    assunto: 'searchAssuntos',
    movimento: 'searchMovimentos',
};

const TIPO_TO_LABEL = {
    classe: 'Classe',
    assunto: 'Assunto',
    movimento: 'Movimento',
};

export default class TpuSearchModalView extends ModalView {
    template = 'togare-tpu:modals/tpu-search';

    setup() {
        this.tpuTipo = this.options.tpuTipo || 'classe';
        this.onSelect = this.options.onSelect || (() => {});
        this.results = [];
        this.searching = false;

        this.headerText = 'Buscar ' + (TIPO_TO_LABEL[this.tpuTipo] || 'Classe');

        this.buttonList = [
            {
                name: 'cancel',
                label: 'Cancelar',
                onClick: () => this.close(),
            },
        ];

        this._debounceHandle = null;
    }

    data() {
        return {
            tipoLabel: TIPO_TO_LABEL[this.tpuTipo] || 'Classe',
            results: this.results,
            searching: this.searching,
        };
    }

    afterRender() {
        super.afterRender && super.afterRender();
        const input = this.$el.find('input.tpu-search-input');
        if (input.length > 0) {
            input.on('input', (e) => this._handleInput(e.target.value));
            input.focus();
        }
    }

    _handleInput(value) {
        if (this._debounceHandle) {
            clearTimeout(this._debounceHandle);
        }
        const q = (value || '').trim();
        this._debounceHandle = setTimeout(() => this._doSearch(q), DEBOUNCE_MS);
    }

    _doSearch(q) {
        if (q.length < 3) {
            this.results = [];
            this._renderResults();
            return;
        }
        const action = TIPO_TO_ACTION[this.tpuTipo] || 'searchClasses';
        const url = `TogareTpuCatalog/action/${action}?q=${encodeURIComponent(q)}&limit=20`;
        this.searching = true;
        this._renderResults();

        Espo.Ajax.getRequest(url)
            .then((rows) => {
                this.results = Array.isArray(rows) ? rows : [];
                this.searching = false;
                this._renderResults();
            })
            .catch(() => {
                this.results = [];
                this.searching = false;
                this._renderResults();
            });
    }

    _renderResults() {
        const $list = this.$el.find('.tpu-search-results');
        if ($list.length === 0) return;

        $list.empty();
        if (this.searching) {
            $list.append('<li class="text-muted">Buscando...</li>');
            return;
        }
        if (this.results.length === 0) {
            $list.append('<li class="text-muted">Nenhum resultado.</li>');
            return;
        }
        this.results.forEach((row) => {
            const $li = $(`<li class="tpu-search-row" style="cursor:pointer;padding:6px;"></li>`);
            $li.text(`${row.codigo} — ${row.nome}`);
            $li.on('click', () => {
                this.onSelect(row.codigo, row.nome);
                this.close();
            });
            $list.append($li);
        });
    }
}
