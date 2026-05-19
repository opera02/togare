import { describe, it, expect } from "vitest";
import ContratoHonorariosProcessosByClienteFieldView from "togare-core:views/common/fields/processos-by-cliente";

const makeModel = (attrs = {}) => ({
    attributes: { ...attrs },
    get(key) {
        return this.attributes[key];
    },
    set(values) {
        Object.assign(this.attributes, values);
    },
});

describe("ContratoHonorariosProcessosByClienteFieldView", () => {
    it("filtra Processos pelo Cliente vinculado ao contrato", () => {
        const model = makeModel({ clienteId: "cli-001" });
        const view = new ContratoHonorariosProcessosByClienteFieldView({
            model,
            baseFilters: {
                _id: { type: "notIn", attribute: "id", value: ["proc-002"] },
            },
        });
        view.setup();

        expect(view.getSelectFilters()).toEqual({
            _id: { type: "notIn", attribute: "id", value: ["proc-002"] },
            clientes: {
                type: "linkedWith",
                attribute: "clientes",
                value: "cli-001",
            },
        });
    });

    it("sem cliente selecionado retorna filtro vazio seguro", () => {
        const model = makeModel({ clienteId: "" });
        const view = new ContratoHonorariosProcessosByClienteFieldView({ model });
        view.setup();

        expect(view.getSelectFilters()).toEqual({
            _clienteMissing: {
                type: "isNull",
                attribute: "id",
            },
        });
    });

    it("limpa processos selecionados quando o Cliente muda", () => {
        const model = makeModel({
            clienteId: "cli-001",
            processosIds: ["proc-001"],
            processosNames: { "proc-001": "0000001-00.2026.8.26.0001" },
        });
        const view = new ContratoHonorariosProcessosByClienteFieldView({ model });
        view.setup();

        view._listeners["change:clienteId"]();

        expect(model.attributes.processosIds).toEqual([]);
        expect(model.attributes.processosNames).toEqual({});
        expect(view.ids).toEqual([]);
        expect(view.nameHash).toEqual({});
        expect(view.reRenderCount).toBe(1);
    });
});
