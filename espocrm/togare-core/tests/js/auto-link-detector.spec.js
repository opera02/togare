import { describe, it, expect } from "vitest";
import {
    detectAutoLink,
    formatAutoLinkMessage,
} from "../../src/files/client/custom/modules/togare-core/src/helpers/auto-link-detector.js";

describe("detectAutoLink — Story 4a.4 F1.9 / Decisão #3 / AC13", () => {
    it("AC13 cenário 1 — ambos auto-vinculados (1+1 paired) → variant='pair'", () => {
        const prev = { clienteId: null, parteContrariaId: null };
        const curr = {
            clienteId: "cli-001",
            parteContrariaId: "pc-001",
            cliente: { id: "cli-001", name: "João Silva" },
            parteContraria: { id: "pc-001", name: "Empresa X SA" },
            numeroProcessoOriginal: "10228312720208260001",
        };
        const out = detectAutoLink(prev, curr, new Set());
        expect(out.variant).toBe("pair");
        expect(out.clienteName).toBe("João Silva");
        expect(out.parteName).toBe("Empresa X SA");
        expect(out.cnj).toBe("10228312720208260001");
    });

    it("AC13 cenário 2 — só cliente auto-vinculado → variant='cliente_only'", () => {
        const prev = { clienteId: null, parteContrariaId: null };
        const curr = {
            clienteId: "cli-001",
            parteContrariaId: null,
            cliente: { id: "cli-001", name: "Maria" },
        };
        const out = detectAutoLink(prev, curr, new Set());
        expect(out.variant).toBe("cliente_only");
        expect(out.clienteName).toBe("Maria");
        expect(out.parteName).toBe(null);
    });

    it("AC13 cenário 3 — só parte auto-vinculada (sem cliente) → variant='none' (caso operacional improvável)", () => {
        const prev = { clienteId: null, parteContrariaId: null };
        const curr = {
            clienteId: null,
            parteContrariaId: "pc-001",
            parteContraria: { id: "pc-001", name: "Empresa X" },
        };
        const out = detectAutoLink(prev, curr, new Set());
        expect(out.variant).toBe("none");
    });

    it("AC13 cenário 4 — user editou clienteId manualmente → NÃO conta como auto-link", () => {
        const prev = { clienteId: null, parteContrariaId: null };
        const curr = {
            clienteId: "cli-001",
            parteContrariaId: "pc-001",
            cliente: { id: "cli-001", name: "Manual" },
            parteContraria: { id: "pc-001", name: "Empresa X" },
        };
        const touched = new Set(["clienteId"]);
        const out = detectAutoLink(prev, curr, touched);
        // só parte auto-vinculada (cliente foi user-touched) → variant='none'
        expect(out.variant).toBe("none");
    });

    it("user editou ambos manualmente → variant='none'", () => {
        const prev = { clienteId: null, parteContrariaId: null };
        const curr = { clienteId: "cli-001", parteContrariaId: "pc-001" };
        const touched = new Set(["clienteId", "parteContrariaId"]);
        expect(detectAutoLink(prev, curr, touched).variant).toBe("none");
    });

    it("ambos vazios no curr (Processo sem 1 cliente único OU sem parte) → variant='none'", () => {
        const prev = { clienteId: null, parteContrariaId: null };
        const curr = { clienteId: null, parteContrariaId: null };
        expect(detectAutoLink(prev, curr, new Set()).variant).toBe("none");
    });

    it("prev já tinha valor (não foi auto-vinculado nesta save — já existia) → variant='none'", () => {
        const prev = { clienteId: "cli-001", parteContrariaId: "pc-001" };
        const curr = { clienteId: "cli-001", parteContrariaId: "pc-001" };
        expect(detectAutoLink(prev, curr, new Set()).variant).toBe("none");
    });

    it("prev=undefined trata como vazio (caso de Backbone Model recém-criado)", () => {
        const prev = {};
        const curr = {
            clienteId: "cli-001",
            parteContrariaId: "pc-001",
            cliente: { name: "A" },
            parteContraria: { name: "B" },
        };
        expect(detectAutoLink(prev, curr, new Set()).variant).toBe("pair");
    });

    it("touched aceita Array (não só Set)", () => {
        const prev = { clienteId: null, parteContrariaId: null };
        const curr = { clienteId: "cli-001", parteContrariaId: "pc-001" };
        const out = detectAutoLink(prev, curr, ["clienteId"]);
        expect(out.variant).toBe("none");
    });

    it("link sem .name (só id) → clienteName=null (renderer usa fallback genérico)", () => {
        const prev = { clienteId: null, parteContrariaId: null };
        const curr = {
            clienteId: "cli-001",
            parteContrariaId: "pc-001",
            // cliente NÃO foi resolvido como objeto pelo backend
        };
        const out = detectAutoLink(prev, curr, new Set());
        expect(out.variant).toBe("pair");
        expect(out.clienteName).toBe(null);
        expect(out.parteName).toBe(null);
    });

    it("inputs nulos → variant='none' (nunca throws)", () => {
        expect(detectAutoLink(null, null, new Set()).variant).toBe("none");
        expect(detectAutoLink({}, null, new Set()).variant).toBe("none");
        expect(detectAutoLink(null, {}, new Set()).variant).toBe("none");
    });
});

describe("formatAutoLinkMessage — i18n + CNJ formatter", () => {
    function fakeFormatCnj(v) {
        if (typeof v !== "string" || v.length !== 20) return v;
        return v.slice(0, 7) + "-" + v.slice(7, 9) + "." + v.slice(9, 13) +
            "." + v.slice(13, 14) + "." + v.slice(14, 16) + "." + v.slice(16, 20);
    }

    it("variant='pair' → mensagem completa com CNJ formatado", () => {
        const msg = formatAutoLinkMessage(
            {
                variant: "pair",
                clienteName: "João Silva",
                parteName: "Empresa X SA",
                cnj: "10228312720208260001",
            },
            {
                pair: "Cliente {nomeCliente} e Parte {nomeParte} herdados do Processo {cnj}.",
                cliente_only: "Cliente {nomeCliente} herdado do Processo {cnj}.",
            },
            fakeFormatCnj,
        );
        expect(msg).toContain("João Silva");
        expect(msg).toContain("Empresa X SA");
        expect(msg).toContain("1022831-27.2020.8.26.0001");
    });

    it("variant='cliente_only' usa template específico", () => {
        const msg = formatAutoLinkMessage(
            { variant: "cliente_only", clienteName: "Maria", cnj: null },
            null,
            null,
        );
        expect(msg).toContain("Maria");
        expect(msg).not.toContain("Parte");
    });

    it("variant='none' → string vazia", () => {
        expect(formatAutoLinkMessage({ variant: "none" }, null, null)).toBe("");
    });

    it("clienteName=null → fallback 'vinculado'", () => {
        const msg = formatAutoLinkMessage(
            { variant: "cliente_only", clienteName: null, cnj: "x" },
            null,
            null,
        );
        expect(msg).toContain("vinculado");
    });

    it("P4 — descriptor=null → string vazia (nunca throws TypeError)", () => {
        expect(formatAutoLinkMessage(null, null, null)).toBe("");
        expect(formatAutoLinkMessage(undefined, null, null)).toBe("");
    });

    it("P5 — placeholder repetido no template é substituído globalmente", () => {
        const msg = formatAutoLinkMessage(
            { variant: "cliente_only", clienteName: "João", cnj: null },
            { cliente_only: "Cliente {nomeCliente} — confirme {nomeCliente}." },
            null,
        );
        expect(msg).toBe("Cliente João — confirme João.");
        expect(msg).not.toContain("{nomeCliente}");
    });
});
