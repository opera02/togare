/**
 * Handler da ação "Liberar acesso ao Portal" no header da detail de
 * `Cliente` (Story 7a.2, AC1).
 *
 * Registrado via clientDefs/Cliente.json → menu.detail.dropdown (merge
 * aditivo entre módulos — NÃO sobrescreve o `views.detail` do togare-core;
 * menor superfície de regressão sobre Épicos 3/5/6).
 *
 * Fluxo: confirma com o Sócio/Admin → POST /TogarePortalUi/PortalAccess/
 * provision {clienteId} → o backend cria/reusa o usuário de Portal, gera o
 * PasswordChangeRequest nativo e dispara o e-mail pt-BR best-effort. A
 * resposta diz se o e-mail saiu; a copy reflete isso com honestidade
 * (acolhedora; nunca "erro" cru pro operador).
 *
 * Checklist A3 (2 traps ES module EspoCRM 9.x):
 *  (a) `window.Espo` referenciado explicitamente (não assume global no
 *      escopo do módulo transpilado) — memória
 *      feedback_espocrm_window_espo_module_scope.
 *  (b) Sem createView({el}): este handler não monta sub-view custom (usa
 *      o confirm nativo da view). A 2ª trap não se aplica aqui.
 *
 * Copy 100% via i18n (scope PortalAccess) — zero string visível inline.
 */

import ActionHandler from "action-handler";

export default class PortalAccessHandler extends ActionHandler {
    /** @private @return {*} */
    _espo() {
        return (typeof window !== "undefined" && window.Espo) || Espo;
    }

    /**
     * Ação do menu (clientDefs actionFunction: "provision").
     * @return {Promise<void>}
     */
    async provision() {
        const view = this.view;
        const model = view && view.model;

        if (!model) {
            return;
        }

        const clienteId = model.get("id");
        const nome = model.get("name") || "";

        if (!clienteId) {
            return;
        }

        const Espo_ = this._espo();

        const confirmMsg = view
            .translate("provisionConfirm", "messages", "PortalAccess")
            .replace("{nome}", nome);

        try {
            await view.confirm({
                message: confirmMsg,
                confirmText: view.translate(
                    "provisionConfirmText",
                    "messages",
                    "PortalAccess",
                ),
            });
        } catch (e) {
            // Cancelado pelo operador — no-op.
            return;
        }

        Espo_.Ui.notifyWait();

        try {
            const response = await Espo_.Ajax.postRequest(
                "TogarePortalUi/PortalAccess/provision",
                { clienteId: clienteId },
            );

            Espo_.Ui.notify(false);

            const ok = response && response.emailSent;
            const userName =
                response && response.userName ? response.userName : "";

            const msg = view
                .translate(
                    ok
                        ? "provisionSuccessEmailSent"
                        : "provisionSuccessEmailFailed",
                    "messages",
                    "PortalAccess",
                )
                .replace("{nome}", nome)
                .replace("{userName}", userName);

            if (ok) {
                Espo_.Ui.success(msg);
            } else {
                Espo_.Ui.warning(msg);
            }
        } catch (xhr) {
            Espo_.Ui.notify(false);

            const detail =
                (xhr &&
                    xhr.responseText &&
                    String(xhr.responseText).trim()) ||
                "";

            const msg = view
                .translate("provisionError", "messages", "PortalAccess")
                .replace("{detalhe}", detail);

            Espo_.Ui.error(msg);
        }
    }
}
