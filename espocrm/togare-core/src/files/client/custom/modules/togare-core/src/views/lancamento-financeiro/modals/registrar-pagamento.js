/**
 * RegistrarPagamentoModalView — modal "Registrar pagamento" (Story 6.3 — T9.1).
 *
 * Estende `views/modals/edit` stock do EspoCRM 9.x. Pré-fixa por opener:
 *  - faturaId / clienteId / processoId — vinculação.
 *  - saldoSugerido — pré-popula campo `valor`.
 *  - tipoSugerido — pré-seleciona `tipo` (pagamento_total se valor==saldo,
 *    pagamento_parcial caso contrário). User pode editar.
 *  - dataMovimento — default hoje.
 *
 * DynamicLogic do clientDefs/LancamentoFinanceiro.json já cuida de:
 *  - mostrar/esconder + required de `fatura` conforme tipo.
 *  - mostrar/esconder + required de `formaPagamento` conforme tipo.
 *
 * Após save, dispara:
 *  - Trigger view `after:save` (caller usa para fechar/refetch fatura).
 *  - CustomEvent global `togare:fatura.updated` com faturaId (detail view
 *    da Fatura escuta e re-fetch automaticamente).
 *
 * Pattern análogo a ContratoHonorariosUploadModalView (Story 6.1).
 */
import ModalsEditView from "views/modals/edit";

export default class RegistrarPagamentoModalView extends ModalsEditView {
    setup() {
        this.scope = "LancamentoFinanceiro";
        this.options = this.options || {};
        this.options.scope = "LancamentoFinanceiro";
        this.options.entityType = "LancamentoFinanceiro";

        this.options.attributes = this.options.attributes || {};

        // Pré-fixa fatura/cliente/processo conforme contexto.
        if (this.options.faturaId) {
            this.options.attributes.faturaId = this.options.faturaId;
        }
        if (this.options.clienteId) {
            this.options.attributes.clienteId = this.options.clienteId;
            if (this.options.clienteName) {
                this.options.attributes.clienteName = this.options.clienteName;
            }
        }
        if (this.options.processoId) {
            this.options.attributes.processoId = this.options.processoId;
            if (this.options.processoName) {
                this.options.attributes.processoName = this.options.processoName;
            }
        }

        // Valor sugerido = saldo atual da fatura (ou vazio se sem saldo).
        if (typeof this.options.saldoSugerido === "number" && this.options.saldoSugerido > 0) {
            this.options.attributes.valor = this.options.saldoSugerido;
        }

        // Tipo sugerido: pagamento_total se valor == saldo; senão pagamento_parcial.
        this.options.attributes.tipo = this.options.tipoSugerido || "pagamento_total";

        // dataMovimento default = hoje.
        if (!this.options.attributes.dataMovimento) {
            const today = new Date();
            const y = today.getFullYear();
            const m = String(today.getMonth() + 1).padStart(2, "0");
            const d = String(today.getDate()).padStart(2, "0");
            this.options.attributes.dataMovimento = `${y}-${m}-${d}`;
        }

        super.setup();

        const label =
            (typeof this.translate === "function" &&
                this.translate("Registrar pagamento", "labels", "LancamentoFinanceiro")) ||
            "Registrar pagamento";
        this.headerText = label;
        this.headerHtml = null;
    }

    /**
     * Após save bem-sucedido, dispara CustomEvent global para a detail view
     * da Fatura re-fetch automaticamente.
     */
    afterSave() {
        if (typeof super.afterSave === "function") {
            super.afterSave();
        }

        const faturaId = this.options && this.options.faturaId;
        if (faturaId && typeof window !== "undefined") {
            const event = new CustomEvent("togare:fatura.updated", {
                detail: { faturaId: faturaId },
            });
            window.dispatchEvent(event);
        }

        // Toast amigável.
        const tipo = this.model ? this.model.get("tipo") : null;
        const valor = this.model ? this.model.get("valor") : null;
        let msg = this.translate("registrarSucesso", "messages", "LancamentoFinanceiro") || "Lançamento registrado.";
        if (tipo === "pagamento_total" || tipo === "pagamento_parcial") {
            const valorFmt = this._formatBRL(valor);
            const tmpl = this.translate("pagamentoSucesso", "messages", "LancamentoFinanceiro") || "Pagamento de {valor} registrado.";
            msg = tmpl.replace("{valor}", valorFmt);
        }
        Espo.Ui.success(msg);
    }

    _formatBRL(value) {
        const num = parseFloat(value || 0);
        try {
            return new Intl.NumberFormat("pt-BR", { style: "currency", currency: "BRL" }).format(num);
        } catch (e) {
            return "R$ " + num.toFixed(2).replace(".", ",");
        }
    }
}
