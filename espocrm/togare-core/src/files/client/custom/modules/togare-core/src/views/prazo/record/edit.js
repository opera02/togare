/**
 * Prazo edit view — wiring do AutoLinkBanner (Story 4a.4 F1.9 / Decisão #3).
 *
 * Estende `views/record/edit` nativo para:
 *  - Capturar quais fields o user editou explicitamente nesta sessão
 *    (`_userTouchedFields` Set populado em listener `change:clienteId/parteContrariaId`
 *    quando origin é UI — `options.ui === true`).
 *  - Após save bem-sucedido, comparar `model.previousAttributes()` vs
 *    `model.attributes` via `detectAutoLink()` para identificar fields
 *    que foram auto-vinculados pelo AutoLinkClientHook (PHP).
 *  - Disparar `ToastTogare.show({variant: 'auto-link', ...})` com mensagem
 *    contextualizada (1+1 paired ou só cliente; ambos múltiplos NÃO
 *    dispara — evita feedback genérico).
 *
 * Aplicado via clientDefs/Prazo.json::views.edit.
 */

import EditRecordView from "views/record/edit";
import ToastTogareView from "togare-core:views/common/toast-togare";
import {
    detectAutoLink,
    formatAutoLinkMessage,
} from "togare-core:helpers/auto-link-detector";
import { translateOrFallback } from "togare-core:helpers/translate-or-fallback";

const TRACKED_FIELDS = ["clienteId", "parteContrariaId"];

export default class PrazoEditView extends EditRecordView {
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

        const i18n = this._collectAutoLinkI18n();
        const formatCnj = this._resolveCnjFormatter();
        const message = formatAutoLinkMessage(descriptor, i18n, formatCnj);
        const Toast = this._buildToastFactory();
        if (!Toast || typeof Toast.show !== "function") return;

        // Story 4a.4 fix-pass 0.19.8: removido `actionLabel: "Editar"` —
        // após save da criação modal, o edit view interno é destruído e
        // navegamos pro detail. `getFieldView('cliente')` no edit view não
        // existe mais. UX simplificada: toast informativo puro sem botão.
        // Se user precisar editar cliente/parte, vai direto pelo detail.
        Toast.show({
            variant: "auto-link",
            message,
            actionLabel: null,
            duration: 8000,
        });
    }

    _collectAutoLinkI18n() {
        return {
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
    }

    _resolveCnjFormatter() {
        if (typeof window !== "undefined" && window.TogareCore && window.TogareCore.formatters && typeof window.TogareCore.formatters.formatCnj === "function") {
            return window.TogareCore.formatters.formatCnj;
        }
        return null;
    }

    _buildToastFactory() {
        // Tests podem injetar `options.toastFactory` (objeto com .show()).
        if (this.options && this.options.toastFactory) return this.options.toastFactory;
        // Story 4a.4 fix-pass 0.19.3: ToastTogareView importado direto como
        // ES6 module (não dependemos mais de window.TogareCore.ToastTogare
        // que nunca foi registrado). ToastTogareView tem método estático
        // `show()` que gerencia o stack global de toasts.
        if (typeof ToastTogareView === "function" && typeof ToastTogareView.show === "function") {
            return ToastTogareView;
        }
        return null;
    }

}
