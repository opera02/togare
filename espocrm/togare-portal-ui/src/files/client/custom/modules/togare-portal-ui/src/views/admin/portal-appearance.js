/**
 * Painel admin "Portal → Aparência" (Story 7a.1, AC1/AC5/AC6).
 *
 * Sócio/Admin configura logo + cor primária + frase de boas-vindas +
 * telefone de fallback do PortalSplash. Storage = config global via
 * Settings (params togarePortalSplash*), entregues pré-auth pelo canal
 * getAllNonInternalData() do EspoCRM.
 *
 * AC5: ao mudar a cor primária, calcula o contraste do texto branco do
 * splash contra a cor escolhida e exibe AVISO NÃO-BLOQUEANTE em pt-BR se
 * ficar abaixo de AAA 7:1 (rota crítica #1). O save NUNCA é bloqueado —
 * o gate automatizado que falha o build é da Story 7a.6.
 *
 * Copy 100% via i18n (Settings scope) — zero string visível inline.
 *
 * Checklist A3 (2 traps ES module EspoCRM 9.x):
 *  (a) window.Espo usado explicitamente (não assume global no escopo do módulo).
 *  (b) Sem createView com {el} aqui — esta view não monta sub-view custom;
 *      o aviso de contraste é injeção DOM idempotente no afterRender.
 */

import SettingsEditRecordView from "views/settings/record/edit";
import { meetsAaaOnBackground } from "togare-portal-ui:helpers/contrast";

const NOTICE_ID = "togare-portal-appearance-contrast-notice";

export default class TogarePortalAppearanceView extends SettingsEditRecordView {
    layoutName = "portalAppearance";

    saveAndContinueEditingAction = false;

    setup() {
        super.setup();

        this.listenTo(this.model, "change:togarePortalSplashPrimaryColor", () => {
            this._renderContrastNotice();
        });
    }

    afterRender() {
        super.afterRender();

        this._renderContrastNotice();
    }

    /**
     * Avalia o contraste da cor escolhida e injeta/atualiza o aviso
     * pt-BR não-bloqueante. Idempotente (remove o aviso anterior antes).
     * @private
     */
    _renderContrastNotice() {
        const root = this.element;

        if (!root) {
            return;
        }

        const existing = root.querySelector("#" + NOTICE_ID);

        if (existing) {
            existing.remove();
        }

        const color = this.model.get("togarePortalSplashPrimaryColor");

        if (!color) {
            return;
        }

        if (meetsAaaOnBackground(color)) {
            return;
        }

        const message = this.translate(
            "portalSplashContrastWarning",
            "messages",
            "Settings",
        );

        const notice = document.createElement("div");

        notice.id = NOTICE_ID;
        notice.className = "alert alert-warning togare-portal-appearance__contrast-notice";
        notice.setAttribute("role", "status");
        notice.setAttribute("aria-live", "polite");
        notice.textContent = message;

        const container =
            root.querySelector(".record") || root.querySelector(".panel") || root;

        container.insertBefore(notice, container.firstChild);
    }

    /**
     * Save não-bloqueante: se o contraste for insuficiente, mostra o
     * aviso pt-BR via window.Espo.Ui mas PROSSEGUE com o save (AC5).
     * @return {*}
     */
    save(options) {
        const color = this.model.get("togarePortalSplashPrimaryColor");

        if (color && !meetsAaaOnBackground(color)) {
            const message = this.translate(
                "portalSplashContrastWarning",
                "messages",
                "Settings",
            );

            if (window.Espo && window.Espo.Ui && window.Espo.Ui.warning) {
                window.Espo.Ui.warning(message);
            }
        }

        return super.save(options);
    }
}
