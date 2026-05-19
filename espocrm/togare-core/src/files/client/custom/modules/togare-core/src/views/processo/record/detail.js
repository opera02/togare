/**
 * Processo record/detail view (Story 3.4).
 *
 * Estende `views/record/detail.js` nativo. Mínima — usa
 * layouts/Processo/detail.json + relationships.json e o Stream nativo do
 * EspoCRM (declarado via `"stream": true` em
 * `Resources/metadata/scopes/Processo.json`).
 *
 * Helper Handlebars `formatCnj` já registrado globalmente via
 * `js/bootstrap-formatters.js` (carregado por `metadata/app/client.json`
 * scriptList desde Story 1a.6) — disponível em qualquer template sem import.
 *
 * Painéis "Clientes" e "Partes Contrárias" renderizados via
 * relationships.json — N:N declarado em entityDefs/Processo.json com
 * relationshipName ClienteProcesso e ParteContrariaProcesso. Após
 * `php rebuild.php`, ORM cria as 2 join tables.
 */
import DetailView from 'views/record/detail';

export default class ProcessoDetailView extends DetailView {
    setup() {
        super.setup();
    }

    /**
     * Story 5.2 — Action handler do botão `[Anexar documento]` no relationship
     * panel `documentos` (clientDefs.relationshipPanels.documentos.actionList).
     */
    actionAnexarDocumento() {
        const processoId = this.model.get('id');
        const processoName = this.model.get('numeroCnj') ||
            this.model.get('numeroProcessoOriginal') ||
            this.model.get('name') ||
            '';
        if (!processoId) {
            return;
        }
        this.createView(
            'documentoUpload',
            'togare-core:views/document/upload-modal',
            {
                processoId: processoId,
                processoName: processoName,
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
