/**
 * Handler para ação "Anexar documento" nos painéis de relacionamento
 * de Processo e Cliente (Story 5.2 — fix B-5.2-1).
 *
 * EspoCRM 9.x despacha ações do painel de relacionamento para o panel view
 * via Espo.Utils.handleAction. Quando o action item tem "handler", o handler
 * é instanciado com new Handler(panelView) e o método correspondente é
 * chamado diretamente no handler (method = "action" + upperCaseFirst(action)).
 *
 * Por isso este handler tem actionAnexarDocumento() e NÃO handle().
 * this.view = o panel view (relationship.js), que tem this.view.model = record model.
 */
import ActionHandler from 'action-handler';

export default class DocumentoPanelActionHandler extends ActionHandler {
    actionAnexarDocumento(data, event) {
        const panelView = this.view;
        const model = panelView.model;
        if (!model) {
            return;
        }

        const entityType = model.entityType;
        const recordId = model.get('id');
        if (!recordId) {
            return;
        }

        const options = {};
        if (entityType === 'Processo') {
            options.processoId = recordId;
            options.processoName =
                model.get('numeroCnj') ||
                model.get('numeroProcessoOriginal') ||
                model.get('name') ||
                '';
        } else if (entityType === 'Cliente') {
            options.clienteId = recordId;
            options.clienteName = model.get('name') || '';
        } else if (entityType === 'Prazo') {
            options.prazoId = recordId;
            options.prazoName =
                model.get('atoCodigo') ||
                model.get('referenciaLegal') ||
                model.get('numeroProcessoOriginal') ||
                '';
        } else {
            return;
        }

        panelView.createView(
            'documentoUpload',
            'togare-core:views/document/upload-modal',
            options,
            (view) => {
                view.render();
                panelView.listenToOnce(view, 'after:save', () => {
                    if (typeof panelView.actionRefresh === 'function') {
                        panelView.actionRefresh();
                    }
                });
            },
        );
    }
}
