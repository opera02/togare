/**
 * Handler para ação "Emitir fatura" no painel de relacionamento de
 * Cliente / ContratoHonorarios (Story 6.3 — T8.5).
 *
 * Pattern literal de `handlers/contrato-honorarios/panel-action-handler.js`
 * (Story 6.1) — `actionEmitirFatura` é chamado diretamente pelo panel via
 * Espo.Utils.handleAction quando action item tem `handler`.
 *
 * Contextos suportados:
 *  - entityType === 'Cliente': pré-fixa clienteId/clienteName (deixa user
 *    selecionar contratoHonorariosId via autocomplete).
 *  - entityType === 'ContratoHonorarios': pré-fixa contratoHonorariosId/
 *    contratoHonorariosName + clienteId/clienteName (já vem do contrato).
 *
 * Modal: `togare-core:views/fatura/create-modal`.
 *
 * Após save, dispara `actionRefresh` no panel pai (re-fetch da collection
 * Faturas).
 */
import ActionHandler from "action-handler";

export default class FaturaPanelActionHandler extends ActionHandler {
    actionEmitirFatura(data, event) {
        const panelView = this.view;
        const model = panelView.model;
        if (!model) {
            return;
        }

        const entityType = model.entityType;
        const recordId = model.get("id");
        if (!recordId) {
            return;
        }

        const options = {
            attributes: {},
        };

        if (entityType === "Cliente") {
            options.clienteId = recordId;
            options.clienteName = model.get("name") || "";
            options.attributes.clienteId = recordId;
            options.attributes.clienteName = model.get("name") || "";
        } else if (entityType === "ContratoHonorarios") {
            const clienteId = model.get("clienteId");
            const clienteName = model.get("clienteName");
            options.contratoHonorariosId = recordId;
            options.contratoHonorariosName = model.get("modalidade") || "";
            options.clienteId = clienteId;
            options.clienteName = clienteName;
            options.attributes.contratoHonorariosId = recordId;
            options.attributes.contratoHonorariosName = model.get("modalidade") || "";
            if (clienteId) {
                options.attributes.clienteId = clienteId;
                options.attributes.clienteName = clienteName;
            }
        } else {
            // Outros entityTypes não disparam emissão de fatura.
            return;
        }

        panelView.createView(
            "faturaCreate",
            "togare-core:views/fatura/create-modal",
            options,
            (view) => {
                view.render();
                panelView.listenToOnce(view, "after:save", () => {
                    if (typeof panelView.actionRefresh === "function") {
                        panelView.actionRefresh();
                    }
                });
            },
        );
    }
}
