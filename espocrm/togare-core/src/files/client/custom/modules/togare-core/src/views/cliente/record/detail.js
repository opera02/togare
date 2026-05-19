/**
 * Cliente record/detail view (Story 3.1 + Story 5.2).
 *
 * Estende `views/record/detail.js` nativo. Story 5.2 adiciona action
 * `anexarDocumento` no relationship panel `documentos` — abre
 * `togare-core:views/document/upload-modal` com clienteId pré-fixado.
 *
 * Helpers Handlebars `formatCpf/Cnpj/Cep/Phone/Cnj` já registrados globalmente
 * via `js/bootstrap-formatters.js`.
 */
import DetailView from 'views/record/detail';

export default class ClienteDetailView extends DetailView {
    setup() {
        super.setup();
    }

    /**
     * Action handler do botão `[Anexar documento]` no relationship panel
     * `documentos` (clientDefs.relationshipPanels.documentos.actionList).
     */
    actionAnexarDocumento() {
        const clienteId = this.model.get('id');
        const clienteName = this.model.get('name') || '';
        if (!clienteId) {
            return;
        }
        this.createView(
            'documentoUpload',
            'togare-core:views/document/upload-modal',
            {
                clienteId: clienteId,
                clienteName: clienteName,
            },
            (view) => {
                view.render();
                this.listenToOnce(view, 'after:save', () => {
                    const docPanelView = this.getView('relationships') &&
                        this.getView('relationships').getView('documentos');
                    if (docPanelView && typeof docPanelView.actionRefresh === 'function') {
                        docPanelView.actionRefresh();
                    }
                });
            }
        );
    }
}
