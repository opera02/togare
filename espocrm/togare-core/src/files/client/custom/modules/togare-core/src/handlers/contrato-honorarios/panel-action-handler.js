/**
 * Handler para ação "Anexar contrato" no painel de relacionamento de Cliente (Story 6.1).
 *
 * EspoCRM 9.x despacha ações do painel de relacionamento para o panel view
 * via Espo.Utils.handleAction. Quando o action item tem "handler", o handler
 * é instanciado com new Handler(panelView) e o método correspondente é
 * chamado diretamente no handler (method = "action" + upperCaseFirst(action)).
 *
 * Por isso este handler tem actionAnexarContrato() e NÃO handle().
 * this.view = o panel view (relationship.js), que tem this.view.model = record model.
 *
 * Pattern literal do documento/panel-action-handler.js, com diferenças:
 *  - Só abre quando entityType === 'Cliente' (ContratoHonorarios é sempre de Cliente,
 *    N:1 obrigatório — Discovery #1 retro Epic 5).
 *  - Modal `togare-core:views/contrato-honorarios/upload-modal` (não documento).
 *  - Passa clienteId + clienteName (não processo/prazo).
 */
import ActionHandler from 'action-handler';

export default class ContratoHonorariosPanelActionHandler extends ActionHandler {
    actionAnexarContrato(data, event) {
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

        // ContratoHonorarios é sempre N:1 Cliente. Só Cliente abre o modal.
        if (entityType !== 'Cliente') {
            return;
        }

        // NÃO passar `options.relate` aqui. Espo `setRelate` faz
        // `this.defs.links[link].type` onde `this` é o modelo do ContratoHonorarios
        // (entity sendo criada). O link `'contratosHonorarios'` é da Cliente,
        // não existe no ContratoHonorarios — crash em `undefined.type`.
        //
        // Convenção Espo: `relate.link` deve ser o link NA entity sendo criada
        // que aponta de volta para o parent. Seria `cliente` (belongsTo Cliente
        // em ContratoHonorarios). Mas como já estamos pré-fixando o link via
        // `attributes.clienteId` + `attributes.clienteName`, o relate fica
        // redundante. Mantemos só attributes — simpler + zero risco de setRelate
        // path.
        const options = {
            clienteId: recordId,
            clienteName: model.get('name') || '',
            attributes: {
                clienteId: recordId,
                clienteName: model.get('name') || '',
            },
        };

        panelView.createView(
            'contratoHonorariosUpload',
            'togare-core:views/contrato-honorarios/upload-modal',
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
