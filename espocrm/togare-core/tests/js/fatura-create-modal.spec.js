/**
 * Testes da view FaturaCreateModalView (Story 6.3).
 *
 * Cobre o que a subclasse customiza: scope fixo, pré-fixação de contextos
 * (Cliente / ContratoHonorarios / Processo) via options.attributes, defaults
 * de dataEmissao/dataVencimento, e header label pt-BR.
 */

import { describe, it, expect } from "vitest";
import FaturaCreateModalView from "togare-core:views/fatura/create-modal";

const makeView = (options = {}) => {
    const view = new FaturaCreateModalView(options);
    view.translate = (key, _category, _scope) => {
        if (key === "Emitir Fatura") return "Emitir fatura";
        return key;
    };
    return view;
};

describe("FaturaCreateModalView - setup", () => {
    it("scope fixo em Fatura", () => {
        const view = makeView({ clienteId: "cli-001" });
        view.setup();
        expect(view.scope).toBe("Fatura");
        expect(view.options.entityType).toBe("Fatura");
    });

    it("pré-fixa clienteId quando vem do panel de Cliente", () => {
        const view = makeView({ clienteId: "cli-acme", clienteName: "Acme Ltda" });
        view.setup();
        expect(view.options.attributes.clienteId).toBe("cli-acme");
        expect(view.options.attributes.clienteName).toBe("Acme Ltda");
    });

    it("pré-fixa contratoHonorariosId quando vem do panel de Contrato", () => {
        const view = makeView({
            contratoHonorariosId: "contrato-001",
            contratoHonorariosName: "Êxito 20%",
            clienteId: "cli-acme",
            clienteName: "Acme Ltda",
        });
        view.setup();
        expect(view.options.attributes.contratoHonorariosId).toBe("contrato-001");
        expect(view.options.attributes.contratoHonorariosName).toBe("Êxito 20%");
        expect(view.options.attributes.clienteId).toBe("cli-acme");
    });

    it("pré-fixa processoId quando informado", () => {
        const view = makeView({
            processoId: "proc-001",
            processoName: "0001234-56.2026.8.26.0001",
            clienteId: "cli-acme",
        });
        view.setup();
        expect(view.options.attributes.processoId).toBe("proc-001");
        expect(view.options.attributes.processoName).toBe(
            "0001234-56.2026.8.26.0001",
        );
    });

    it("default dataEmissao = hoje (YYYY-MM-DD)", () => {
        const view = makeView({ clienteId: "cli-001" });
        view.setup();
        expect(view.options.attributes.dataEmissao).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    });

    it("default dataVencimento = hoje + 30d", () => {
        const view = makeView({ clienteId: "cli-001" });
        view.setup();
        const emissao = new Date(view.options.attributes.dataEmissao);
        const vencimento = new Date(view.options.attributes.dataVencimento);
        const deltaDias =
            (vencimento.getTime() - emissao.getTime()) / (1000 * 60 * 60 * 24);
        expect(deltaDias).toBe(30);
    });

    it("respeita dataEmissao/dataVencimento passados em options.attributes", () => {
        const view = makeView({
            clienteId: "cli-001",
            attributes: { dataEmissao: "2026-01-10", dataVencimento: "2026-02-15" },
        });
        view.setup();
        expect(view.options.attributes.dataEmissao).toBe("2026-01-10");
        expect(view.options.attributes.dataVencimento).toBe("2026-02-15");
    });

    it("header label pt-BR setado para 'Emitir fatura'", () => {
        const view = makeView({ clienteId: "cli-001" });
        view.setup();
        expect(view.headerText).toBe("Emitir fatura");
        expect(view.headerHtml).toBeNull();
    });
});
