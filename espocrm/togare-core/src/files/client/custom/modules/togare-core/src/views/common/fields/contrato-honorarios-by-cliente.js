/**
 * Campo `contratoHonorarios` (N:1) filtrado pelo Cliente escolhido.
 *
 * Story 6.3 fix-pass B2 — variante do `processo-by-cliente.js` para o link
 * ContratoHonorarios (belongsTo Cliente via `cliente`, FK `clienteId`).
 *
 * Consumidor: Fatura.contratoHonorarios (entityDefs aponta para esta view via
 * `togare-core:views/common/fields/contrato-honorarios-by-cliente`).
 *
 * Filtra autocomplete inline + modal "Selecionar..." para mostrar apenas
 * contratos do cliente já escolhido. Quando o cliente muda, limpa o contrato
 * selecionado (proteção contra cross-cliente). Backend continua fail-closed
 * via ValidateFaturaFieldsHook (gate FR23 + cross-cliente); esta view é
 * apenas UX preventiva.
 */
import LinkFieldView from "views/fields/link";

export default class ContratoHonorariosByClienteFieldView extends LinkFieldView {
    setup() {
        super.setup();

        if (this.model && typeof this.listenTo === "function") {
            this.listenTo(this.model, "change:clienteId", () => {
                this.clearContrato();
            });
        }
    }

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

        filters.clienteId = {
            type: "equals",
            attribute: "clienteId",
            value: clienteId,
        };

        return filters;
    }

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
                            type: "equals",
                            attribute: "clienteId",
                            value: clienteId,
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

    clearContrato() {
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
