/**
 * Campo `processos` filtrado pelo Cliente escolhido — view N:N (linkMultiple).
 *
 * Story 6.3 Decisão #6 (renomeada de views/contrato-honorarios/fields/
 * processos-by-cliente.js para reuso entre múltiplas entities — ContratoHonorarios
 * e potencialmente outras no futuro).
 *
 * O relacionamento é N:N, mas juridicamente o contrato (ou entity consumidora)
 * só pode apontar para processos do mesmo Cliente. O backend continua fail-closed;
 * esta view evita que o autocomplete/modal ofereça processos de outro Cliente.
 *
 * Para campos N:1 (link singular), use processo-by-cliente.js (variante singular).
 */
import LinkMultipleFieldView from "views/fields/link-multiple";

export default class ProcessosByClienteFieldView extends LinkMultipleFieldView {
    setup() {
        super.setup();

        if (this.model && typeof this.listenTo === "function") {
            this.listenTo(this.model, "change:clienteId", () => {
                this.clearSelectedProcessos();
            });
        }
    }

    /**
     * getSelectFilters cobre APENAS o modal "Selecionar..." (botão Select de
     * panelDefs.selectHandler). Mantido por completude — quando o usuário clica
     * em "Selecionar..." em vez de digitar inline.
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
     * Espo 9.x usa caminho DIFERENTE para autocomplete inline (digitar texto no
     * campo) vs modal "Selecionar..." (botão):
     *
     *  - Modal "Selecionar...": passa por _getSelectFilters() → consome getSelectFilters().
     *  - Autocomplete inline: chama getAutocompleteUrl(q) e NÃO consulta getSelectFilters().
     *
     * Como o autocomplete inline é o caminho padrão da UX (Discovery #1 retro
     * Epic 5 — memória feedback_ux_autocomplete_links.md), sobrescrevemos
     * getAutocompleteUrl para injetar o filtro linkedWith.clientes na URL
     * direto.
     *
     * @param {string} [q] query do usuário (texto digitado)
     * @returns {string|Promise<string>}
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

    clearSelectedProcessos() {
        const ids =
            this.model && typeof this.model.get === "function"
                ? this.model.get(this.idsName) || []
                : this.ids || [];

        if (!Array.isArray(ids) || ids.length === 0) {
            return;
        }

        this.ids = [];
        this.nameHash = {};

        if (this.model && typeof this.model.set === "function") {
            this.model.set(
                {
                    [this.idsName]: [],
                    [this.nameHashName]: {},
                },
                { ui: true },
            );
        }

        if (typeof this.reRender === "function") {
            this.reRender();
        }
    }
}
