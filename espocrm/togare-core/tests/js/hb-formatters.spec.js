import { describe, it, expect } from "vitest";
import {
    formatCpf,
    formatCnpj,
    formatCep,
    formatPhone,
    formatCnj,
} from "../../src/files/client/custom/modules/togare-core/src/helpers/hbFormatters.js";
import { digitsOnly } from "../../src/files/client/custom/modules/togare-core/src/helpers/brValidators.js";

/**
 * Cobertura ES6 explícita para helpers de máscara BR — antes da Story 3-A não
 * havia spec, helpers eram testados implicitamente pelo CnjFieldView (3.4) e
 * por uso runtime nos detail views. Esta suíte fixa o contrato:
 *   - Input válido → string mascarada determinística.
 *   - Input com máscara → idempotente (digitsOnly antes).
 *   - Input com tamanho errado → passa-through (preserva investigação visual).
 *   - Input vazio/null/undefined → preserva o tipo de entrada (sem throw).
 *
 * Decisão #3 da Story 3-A é o pilar: nunca devolver null/'' quando há valor
 * meio-cadastrado — devolver o valor original.
 */

describe("formatCpf", () => {
    it("11 dígitos válidos → XXX.XXX.XXX-XX", () => {
        expect(formatCpf("12345678909")).toBe("123.456.789-09");
    });

    it("idempotente: já mascarado → mantém máscara", () => {
        expect(formatCpf("123.456.789-09")).toBe("123.456.789-09");
    });

    it("10 dígitos → passa-through (input original)", () => {
        expect(formatCpf("1234567890")).toBe("1234567890");
    });

    it("string vazia → string vazia", () => {
        expect(formatCpf("")).toBe("");
    });

    it("null → null (preservado pelo guard digitsOnly)", () => {
        expect(formatCpf(null)).toBeNull();
    });
});

describe("formatCnpj", () => {
    it("14 dígitos válidos → XX.XXX.XXX/XXXX-XX", () => {
        expect(formatCnpj("11222333000181")).toBe("11.222.333/0001-81");
    });

    it("idempotente", () => {
        expect(formatCnpj("11.222.333/0001-81")).toBe("11.222.333/0001-81");
    });

    it("13 dígitos → passa-through", () => {
        expect(formatCnpj("1122233300018")).toBe("1122233300018");
    });

    it("string vazia → string vazia", () => {
        expect(formatCnpj("")).toBe("");
    });

    it("undefined → undefined", () => {
        expect(formatCnpj(undefined)).toBeUndefined();
    });
});

describe("formatCep", () => {
    it("8 dígitos → XXXXX-XXX", () => {
        expect(formatCep("01310100")).toBe("01310-100");
    });

    it("idempotente", () => {
        expect(formatCep("01310-100")).toBe("01310-100");
    });

    it("7 dígitos → passa-through", () => {
        expect(formatCep("0131010")).toBe("0131010");
    });

    it("string vazia → string vazia", () => {
        expect(formatCep("")).toBe("");
    });
});

describe("formatPhone", () => {
    it("10 dígitos (fixo) → (DD) XXXX-XXXX", () => {
        expect(formatPhone("1133331234")).toBe("(11) 3333-1234");
    });

    it("11 dígitos (celular com nono) → (DD) XXXXX-XXXX", () => {
        expect(formatPhone("11987654321")).toBe("(11) 98765-4321");
    });

    it("idempotente celular", () => {
        expect(formatPhone("(11) 98765-4321")).toBe("(11) 98765-4321");
    });

    it("9 dígitos → passa-through", () => {
        expect(formatPhone("113333123")).toBe("113333123");
    });

    it("12 dígitos → passa-through", () => {
        expect(formatPhone("119876543210")).toBe("119876543210");
    });

    it("string vazia → string vazia", () => {
        expect(formatPhone("")).toBe("");
    });
});

describe("formatCnj (regressão Story 3.4)", () => {
    it("20 dígitos → NNNNNNN-DD.AAAA.J.TR.OOOO", () => {
        expect(formatCnj("12345670620248260100")).toBe("1234567-06.2024.8.26.0100");
    });

    it("19 dígitos → passa-through", () => {
        expect(formatCnj("1234567062024826010")).toBe("1234567062024826010");
    });
});

describe("digitsOnly (sanitização base de todas as máscaras)", () => {
    it("'(11) 98765-4321' → '11987654321'", () => {
        expect(digitsOnly("(11) 98765-4321")).toBe("11987654321");
    });

    it("string vazia → ''", () => {
        expect(digitsOnly("")).toBe("");
    });

    it("null → ''", () => {
        expect(digitsOnly(null)).toBe("");
    });

    it("undefined → ''", () => {
        expect(digitsOnly(undefined)).toBe("");
    });

    it("'abc-123-xyz' → '123'", () => {
        expect(digitsOnly("abc-123-xyz")).toBe("123");
    });
});
