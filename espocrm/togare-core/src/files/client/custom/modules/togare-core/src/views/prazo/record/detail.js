/**
 * Prazo detail view — espelha PrazoEditView para inline-edit no detail
 * (Story 4a.4 F1.9).
 *
 * EspoCRM 9.x permite inline-edit em fields no detail; o Backbone Model é o
 * mesmo, então mesma lógica de detecção de auto-link aplica. Pequena
 * duplicação aceitável vs criar mixin (mantém estrutura simples).
 *
 * Aplicado via clientDefs/Prazo.json::views.detail.
 */

import DetailRecordView from "views/record/detail";
import ToastTogareView from "togare-core:views/common/toast-togare";
import {
    detectAutoLink,
    formatAutoLinkMessage,
} from "togare-core:helpers/auto-link-detector";
import { translateOrFallback } from "togare-core:helpers/translate-or-fallback";
import {
    ensureSystemStatusBannerMount,
    mountSystemStatusBanner,
} from "togare-core:helpers/system-status-banner-mount";

const TRACKED_FIELDS = ["clienteId", "parteContrariaId"];

export default class PrazoDetailView extends DetailRecordView {
    setup() {
        super.setup();
        this._userTouchedFields = new Set();
        this._preSaveSnapshot = {};
        this._captureSnapshot();
        for (const field of TRACKED_FIELDS) {
            this.listenTo(this.model, `change:${field}`, (model, value, options) => {
                if (options && options.ui === true) {
                    this._userTouchedFields.add(field);
                }
            });
        }
        this._autoLinkBannerFiredRecently = false;
        const handleAfterSave = () => {
            if (this._autoLinkBannerFiredRecently) return;
            this._autoLinkBannerFiredRecently = true;
            setTimeout(() => {
                this._autoLinkBannerFiredRecently = false;
            }, 100);
            this._maybeShowAutoLinkBanner();
            this._userTouchedFields.clear();
            this._captureSnapshot();
        };
        this.listenTo(this, "after:save", handleAfterSave);
        this.listenTo(this.model, "sync", handleAfterSave);
    }

    afterRender() {
        if (typeof super.afterRender === "function") {
            super.afterRender();
        }
        this._mountSystemStatusBanner();
    }

    _captureSnapshot() {
        if (!this.model || !this.model.attributes) return;
        for (const f of TRACKED_FIELDS) {
            this._preSaveSnapshot[f] = this.model.attributes[f] ?? null;
        }
    }

    _maybeShowAutoLinkBanner() {
        if (!this.model || !this.model.attributes) {
            return;
        }
        const prev = this._preSaveSnapshot || {};
        const curr = this.model.attributes || {};
        const descriptor = detectAutoLink(prev, curr, this._userTouchedFields);
        if (descriptor.variant === "none") return;

        const i18n = {
            pair: translateOrFallback(
                this,
                "toastAutoLinkClienteParte",
                "messages",
                "Prazo",
                "Cliente {nomeCliente} e Parte {nomeParte} herdados do Processo {cnj}.",
            ),
            cliente_only: translateOrFallback(
                this,
                "toastAutoLinkSoCliente",
                "messages",
                "Prazo",
                "Cliente {nomeCliente} herdado do Processo {cnj}.",
            ),
        };
        const formatCnj =
            typeof window !== "undefined" &&
            window.TogareCore &&
            window.TogareCore.formatters &&
            typeof window.TogareCore.formatters.formatCnj === "function"
                ? window.TogareCore.formatters.formatCnj
                : null;
        const message = formatAutoLinkMessage(descriptor, i18n, formatCnj);

        // Fix-pass 0.19.3: ToastTogareView importado direto (não window global).
        const Toast = ToastTogareView;
        if (!Toast || typeof Toast.show !== "function") return;

        // Story 4a.4 fix-pass 0.19.8: toast informativo puro sem botão Editar.
        Toast.show({
            variant: "auto-link",
            message,
            actionLabel: null,
            duration: 8000,
        });
    }

    _mountSystemStatusBanner() {
        const mount = ensureSystemStatusBannerMount(
            this.el || (this.$el && this.$el[0]) || null,
            [".detail", ".middle", ".panel"],
        );
        mountSystemStatusBanner(this, mount, "_systemStatusBanner");
    }
}
