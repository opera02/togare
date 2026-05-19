/**
 * Testes da FaturaRelationshipRowActionsView (Story 6.3 — T8.3).
 *
 * Cobre: injeção do item "Registrar pagamento" após quickEdit, condicional
 * a status open + ACL create LancamentoFinanceiro.
 */

import { describe, it, expect } from "vitest";
import FaturaRelationshipRowActionsView from "togare-core:views/fatura/record/row-actions/relationship-with-pagamento";

const makeView = (modelAttrs = {}, aclResult = true) => {
    const view = new FaturaRelationshipRowActionsView({
        acl: { edit: true, delete: true },
    });
    view.model = {
        id: "fat-001",
        get: (k) => modelAttrs[k] || null,
    };
    view.getAcl = () => ({ check: () => aclResult });
    view.getLanguage = () => ({
        translate: (key, _category, _scope) => {
            if (key === "Registrar pagamento") return "Registrar pagamento";
            return key;
        },
    });
    return view;
};

describe("FaturaRelationshipRowActionsView - getActionList", () => {
    it("injeta item 'Registrar pagamento' após quickEdit quando fatura está em aberto", () => {
        const view = makeView({ status: "emitida" });
        const list = view.getActionList();

        const pagamentoItem = list.find(
            (i) => i.action === "registrarPagamento",
        );
        expect(pagamentoItem).toBeDefined();
        expect(pagamentoItem.label).toBe("Registrar pagamento");
        expect(pagamentoItem.data.id).toBe("fat-001");
    });

    it("posiciona 'Registrar pagamento' imediatamente após quickEdit", () => {
        const view = makeView({ status: "parcialmente_paga" });
        const list = view.getActionList();

        const editIdx = list.findIndex((i) => i.action === "quickEdit");
        const pagamentoIdx = list.findIndex(
            (i) => i.action === "registrarPagamento",
        );
        expect(pagamentoIdx).toBe(editIdx + 1);
    });

    it("não injeta quando fatura está cancelada", () => {
        const view = makeView({ status: "cancelada" });
        const list = view.getActionList();

        const pagamentoItem = list.find(
            (i) => i.action === "registrarPagamento",
        );
        expect(pagamentoItem).toBeUndefined();
    });

    it("não injeta quando fatura está paga", () => {
        const view = makeView({ status: "paga" });
        const list = view.getActionList();

        const pagamentoItem = list.find(
            (i) => i.action === "registrarPagamento",
        );
        expect(pagamentoItem).toBeUndefined();
    });

    it("não injeta quando usuário não tem ACL create em LancamentoFinanceiro", () => {
        const view = makeView({ status: "emitida" }, false);
        const list = view.getActionList();

        const pagamentoItem = list.find(
            (i) => i.action === "registrarPagamento",
        );
        expect(pagamentoItem).toBeUndefined();
    });
});
