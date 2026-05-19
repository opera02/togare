/**
 * Testes da view ContratoHonorariosUploadModalView (Story 6.1).
 *
 * Cobre apenas o que a subclasse customiza: scope fixo, pre-fixacao de
 * clienteId/clienteName via options.attributes, processoId via linkMultiple
 * processos, e header label pt-BR.
 */

import { describe, it, expect } from "vitest";
import ContratoHonorariosUploadModalView from "togare-core:views/contrato-honorarios/upload-modal";

const makeView = (options = {}) => {
    const view = new ContratoHonorariosUploadModalView(options);
    view.translate = (key, _category, _scope) => {
        if (key === "Anexar contrato") return "Anexar contrato";
        return key;
    };
    return view;
};

describe("ContratoHonorariosUploadModalView - setup", () => {
    it("scope fixo em ContratoHonorarios", () => {
        const view = makeView({ clienteId: "cli-001" });
        view.setup();
        expect(view.scope).toBe("ContratoHonorarios");
    });

    it("pre-fixa clienteId em options.attributes quando options.clienteId presente", () => {
        const view = makeView({ clienteId: "cli-acme" });
        view.setup();
        expect(view.options.attributes).toBeDefined();
        expect(view.options.attributes.clienteId).toBe("cli-acme");
        expect(view.model.attributes.clienteId).toBe("cli-acme");
    });

    it("pre-fixa clienteName em options.attributes quando options.clienteName presente", () => {
        const view = makeView({ clienteId: "cli-acme", clienteName: "Acme Ltda" });
        view.setup();
        expect(view.options.attributes.clienteName).toBe("Acme Ltda");
        expect(view.model.attributes.clienteName).toBe("Acme Ltda");
    });

    it("preserva attributes recebidos e adiciona clienteId sem sobrescrever o restante", () => {
        const view = makeView({
            clienteId: "cli-acme",
            attributes: { modalidade: "fixo" },
        });
        view.setup();
        expect(view.model.attributes).toMatchObject({
            modalidade: "fixo",
            clienteId: "cli-acme",
        });
    });

    it("pre-fixa processoId em processosIds/processosNames quando informado pelo GateBanner", () => {
        const view = makeView({
            clienteId: "cli-acme",
            processoId: "proc-001",
            processoName: "0001234-56.2026.8.26.0001",
        });
        view.setup();
        expect(view.options.attributes.processosIds).toEqual(["proc-001"]);
        expect(view.options.attributes.processosNames).toEqual({
            "proc-001": "0001234-56.2026.8.26.0001",
        });
        expect(view.model.attributes.processosIds).toEqual(["proc-001"]);
        expect(view.model.attributes.processosNames).toEqual({
            "proc-001": "0001234-56.2026.8.26.0001",
        });
    });

    it("preserva processosIds existentes ao adicionar processoId do GateBanner", () => {
        const view = makeView({
            processoId: "proc-002",
            processoName: "Processo 2",
            attributes: {
                processosIds: ["proc-001"],
                processosNames: { "proc-001": "Processo 1" },
            },
        });
        view.setup();
        expect(view.model.attributes.processosIds).toEqual(["proc-001", "proc-002"]);
        expect(view.model.attributes.processosNames).toEqual({
            "proc-001": "Processo 1",
            "proc-002": "Processo 2",
        });
    });

    it("header label pt-BR setado para 'Anexar contrato'", () => {
        const view = makeView({ clienteId: "cli-001" });
        view.setup();
        expect(view.headerText).toBe("Anexar contrato");
    });

    it("headerHtml e null pra garantir que headerText prevalece sobre default scope", () => {
        const view = makeView({ clienteId: "cli-001" });
        view.setup();
        expect(view.headerHtml).toBeNull();
    });

    it("setup sem clienteId nao quebra", () => {
        const view = makeView({});
        expect(() => view.setup()).not.toThrow();
        expect(view.scope).toBe("ContratoHonorarios");
    });
});
