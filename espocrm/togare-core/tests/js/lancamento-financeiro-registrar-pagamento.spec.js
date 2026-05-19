/**
 * Testes da view RegistrarPagamentoModalView (Story 6.3 — T9.1).
 *
 * Cobre: pré-fixação faturaId/clienteId/processoId/saldoSugerido/tipoSugerido,
 * default dataMovimento=hoje, afterSave dispara window.togare:fatura.updated
 * CustomEvent + toast.
 */

import { describe, it, expect, beforeEach, vi } from "vitest";
import RegistrarPagamentoModalView from "togare-core:views/lancamento-financeiro/modals/registrar-pagamento";

const makeView = (options = {}) => {
    const view = new RegistrarPagamentoModalView(options);
    view.translate = (key) => {
        if (key === "Registrar pagamento") return "Registrar pagamento";
        if (key === "registrarSucesso") return "Lançamento registrado.";
        if (key === "pagamentoSucesso") return "Pagamento de {valor} registrado.";
        return key;
    };
    return view;
};

beforeEach(() => {
    global.Espo = global.Espo || {};
    global.Espo.Ui = {
        success: vi.fn(),
        warning: vi.fn(),
        error: vi.fn(),
    };
    if (typeof window !== "undefined") {
        window.dispatchEvent = vi.fn();
    }
});

describe("RegistrarPagamentoModalView - setup", () => {
    it("scope fixo em LancamentoFinanceiro", () => {
        const view = makeView({ faturaId: "fat-001" });
        view.setup();
        expect(view.scope).toBe("LancamentoFinanceiro");
        expect(view.options.entityType).toBe("LancamentoFinanceiro");
    });

    it("pré-fixa faturaId em attributes", () => {
        const view = makeView({ faturaId: "fat-001" });
        view.setup();
        expect(view.options.attributes.faturaId).toBe("fat-001");
    });

    it("pré-fixa clienteId + clienteName", () => {
        const view = makeView({
            faturaId: "fat-001",
            clienteId: "cli-001",
            clienteName: "Acme Ltda",
        });
        view.setup();
        expect(view.options.attributes.clienteId).toBe("cli-001");
        expect(view.options.attributes.clienteName).toBe("Acme Ltda");
    });

    it("pré-fixa processoId + processoName quando informado", () => {
        const view = makeView({
            faturaId: "fat-001",
            processoId: "proc-001",
            processoName: "0001234-56.2026.8.26.0001",
        });
        view.setup();
        expect(view.options.attributes.processoId).toBe("proc-001");
        expect(view.options.attributes.processoName).toBe(
            "0001234-56.2026.8.26.0001",
        );
    });

    it("usa saldoSugerido como valor inicial quando >0", () => {
        const view = makeView({ faturaId: "fat-001", saldoSugerido: 500.0 });
        view.setup();
        expect(view.options.attributes.valor).toBe(500.0);
    });

    it("não seta valor quando saldoSugerido <= 0", () => {
        const view = makeView({ faturaId: "fat-001", saldoSugerido: 0 });
        view.setup();
        expect(view.options.attributes.valor).toBeUndefined();
    });

    it("tipo default = pagamento_total quando tipoSugerido não informado", () => {
        const view = makeView({ faturaId: "fat-001" });
        view.setup();
        expect(view.options.attributes.tipo).toBe("pagamento_total");
    });

    it("tipo respeita tipoSugerido informado", () => {
        const view = makeView({
            faturaId: "fat-001",
            tipoSugerido: "pagamento_parcial",
        });
        view.setup();
        expect(view.options.attributes.tipo).toBe("pagamento_parcial");
    });

    it("default dataMovimento = hoje formato ISO YYYY-MM-DD", () => {
        const view = makeView({ faturaId: "fat-001" });
        view.setup();
        expect(view.options.attributes.dataMovimento).toMatch(
            /^\d{4}-\d{2}-\d{2}$/,
        );
    });

    it("respeita dataMovimento se passado explicitamente", () => {
        const view = makeView({
            faturaId: "fat-001",
            attributes: { dataMovimento: "2026-01-15" },
        });
        view.setup();
        expect(view.options.attributes.dataMovimento).toBe("2026-01-15");
    });

    it("header label pt-BR setado", () => {
        const view = makeView({ faturaId: "fat-001" });
        view.setup();
        expect(view.headerText).toBe("Registrar pagamento");
        expect(view.headerHtml).toBeNull();
    });
});

describe("RegistrarPagamentoModalView - afterSave", () => {
    it("dispara window.togare:fatura.updated CustomEvent com faturaId", () => {
        const view = makeView({ faturaId: "fat-001" });
        view.setup();
        view.model = {
            get: (k) => ({ tipo: "pagamento_total", valor: 1000 })[k],
        };
        view.afterSave();
        expect(window.dispatchEvent).toHaveBeenCalled();
        const event = window.dispatchEvent.mock.calls[0][0];
        expect(event.type).toBe("togare:fatura.updated");
        expect(event.detail.faturaId).toBe("fat-001");
    });

    it("dispara toast Espo.Ui.success com mensagem formatada BRL para pagamento", () => {
        const view = makeView({ faturaId: "fat-001" });
        view.setup();
        view.model = {
            get: (k) => ({ tipo: "pagamento_total", valor: 1500 })[k],
        };
        view.afterSave();
        expect(global.Espo.Ui.success).toHaveBeenCalled();
        const msg = global.Espo.Ui.success.mock.calls[0][0];
        expect(msg).toContain("Pagamento de");
        expect(msg).toContain("R$");
    });

    it("dispara toast genérico para lançamento avulso (não-pagamento)", () => {
        const view = makeView({});
        view.setup();
        view.model = {
            get: (k) => ({ tipo: "despesa_interna", valor: 100 })[k],
        };
        view.afterSave();
        expect(global.Espo.Ui.success).toHaveBeenCalledWith(
            "Lançamento registrado.",
        );
    });
});
