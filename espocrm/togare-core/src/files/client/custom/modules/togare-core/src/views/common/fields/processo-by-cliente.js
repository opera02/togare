/**
 * Campo `processo` (N:1, singular) filtrado pelo Cliente escolhido.
 *
 * Story 6.3 Decisão #6 — variante singular do `processos-by-cliente.js`.
 * Para campos LinkFieldView (N:1), não LinkMultipleFieldView.
 *
 * Consumidor primário: LancamentoFinanceiro.processo (entityDefs aponta para
 * esta view via `togare-core:views/common/fields/processo-by-cliente`).
 *
 * Filtra processo autocomplete + modal "Selecionar..." para mostrar apenas
 * processos do cliente já escolhido. Quando cliente muda, limpa processo
 * selecionado (proteção contra cross-cliente).
 *
 * Backend continua fail-closed via ValidateLancamentoFieldsHook (cross-cliente
 * check); esta view é apenas UX preventiva.
 */
import LinkFieldView from "views/fields/link";

export default class ProcessoByClienteFieldView extends LinkFieldView {
    setup() {
        super.setup();

        if (this.model && typeof this.listenTo === "function") {
            this.listenTo(this.model, "change:clienteId", () => {
                this.clearProcesso();
            });
        }
    }

    /**
     * Modal "Selecionar..." passa pelo getSelectFilters.
     */
    getSelectFilters() {
        const base =
            typeof super.getSelectFilters === "function"
                ? super.getSelectFilters()
                : null;
        const filters = base ? { ...base } : {};
        const clienteId = this.getClienteId();

        if (!clienteId) {
            filters._clienteMissing = {
                type: "isNull",
                attribute: "id",
            };

            return filters;
        }

        filters.clientes = {
            type: "linkedWith",
            attribute: "clientes",
            value: clienteId,
        };

        return filters;
    }

    /**
     * Autocomplete inline (digitar texto) usa getAutocompleteUrl, NÃO
     * getSelectFilters. Pattern espelhado do processos-by-cliente.js plural.
     */
    getAutocompleteUrl(q) {
        const baseUrl = super.getAutocompleteUrl(q);
        const clienteId = this.getClienteId();

        return Promise.resolve(baseUrl).then((url) => {
            const sep = url.includes("?") ? "&" : "?";
            if (!clienteId) {
                return (
                    url +
                    sep +
                    $.param({
                        where: [{ type: "isNull", attribute: "id" }],
                    })
                );
            }
            return (
                url +
                sep +
                $.param({
                    where: [
                        {
                            type: "linkedWith",
                            attribute: "clientes",
                            value: [clienteId],
                        },
                    ],
                })
            );
        });
    }

    getClienteId() {
        if (!this.model) {
            return null;
        }

        const value =
            typeof this.model.get === "function"
                ? this.model.get("clienteId")
                : this.model.attributes && this.model.attributes.clienteId;

        return typeof value === "string" && value !== "" ? value : null;
    }

    clearProcesso() {
        const currentId =
            this.model && typeof this.model.get === "function"
                ? this.model.get(this.idName)
                : null;

        if (!currentId) {
            return;
        }

        if (this.model && typeof this.model.set === "function") {
            this.model.set(
                {
                    [this.idName]: null,
                    [this.nameName]: null,
                },
                { ui: true },
            );
        }

        if (typeof this.reRender === "function") {
            this.reRender();
        }
    }
}
