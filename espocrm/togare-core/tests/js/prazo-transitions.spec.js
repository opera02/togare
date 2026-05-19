import { describe, it, expect } from "vitest";
import {
  PRAZO_TRANSITIONS,
  STATUSES_REQUIRING_MOTIVO,
  STATUSES_REQUIRING_CONFIRMATION,
  MOTIVO_REAGENDAMENTO_MIN_LEN,
  getValidTransitions,
  requiresMotivo,
  requiresConfirmation,
  validateMotivo,
} from "../../src/files/client/custom/modules/togare-core/src/helpers/prazo-transitions.js";

describe("PRAZO_TRANSITIONS — Story 4a.4 Dev Notes §1 (single source of truth)", () => {
  it("declara as 9 chaves canônicas (espelha entityDefs/Prazo.json::status options)", () => {
    expect(Object.keys(PRAZO_TRANSITIONS).sort()).toEqual([
      "acompanhamento",
      "aguardando_cliente",
      "aguardando_correcao",
      "atrasado_reagendado",
      "ciencia_renuncia",
      "descartado",
      "pendente",
      "protocolado",
      "rascunho",
    ]);
  });

  it("rascunho → pendente / acompanhamento / descartado (transições iniciais ricas)", () => {
    expect(PRAZO_TRANSITIONS.rascunho).toEqual([
      "pendente",
      "acompanhamento",
      "descartado",
    ]);
  });

  it("pendente → 6 destinos (estado central operacional)", () => {
    expect(PRAZO_TRANSITIONS.pendente).toContain("atrasado_reagendado");
    expect(PRAZO_TRANSITIONS.pendente).toContain("aguardando_cliente");
    expect(PRAZO_TRANSITIONS.pendente).toContain("aguardando_correcao");
    expect(PRAZO_TRANSITIONS.pendente).toContain("protocolado");
    expect(PRAZO_TRANSITIONS.pendente).toContain("ciencia_renuncia");
    expect(PRAZO_TRANSITIONS.pendente).toContain("descartado");
    expect(PRAZO_TRANSITIONS.pendente).toHaveLength(6);
  });

  it("descartado é terminal (sem destinos saindo) — UX-2 reverter via Admin → Trash", () => {
    expect(PRAZO_TRANSITIONS.descartado).toEqual([]);
  });

  it("protocolado e ciencia_renuncia revertem para pendente (D9 — sem restrição role MVP)", () => {
    expect(PRAZO_TRANSITIONS.protocolado).toEqual(["pendente"]);
    expect(PRAZO_TRANSITIONS.ciencia_renuncia).toEqual(["pendente"]);
  });

  it("acompanhamento converge para pendente ou protocolado", () => {
    expect(PRAZO_TRANSITIONS.acompanhamento).toEqual(["pendente", "protocolado"]);
  });

  it("aguardando_cliente / aguardando_correcao / atrasado_reagendado têm caminhos para protocolado", () => {
    expect(PRAZO_TRANSITIONS.aguardando_cliente).toContain("protocolado");
    expect(PRAZO_TRANSITIONS.aguardando_correcao).toContain("protocolado");
    expect(PRAZO_TRANSITIONS.atrasado_reagendado).toContain("protocolado");
  });

  it("nenhuma transição inclui o status corrente (sem self-loop sem-sentido)", () => {
    for (const [from, destinos] of Object.entries(PRAZO_TRANSITIONS)) {
      expect(destinos).not.toContain(from);
    }
  });

  it("nenhuma transição inclui status fora dos 9 canônicos (validação de domínio)", () => {
    const validos = new Set(Object.keys(PRAZO_TRANSITIONS));
    for (const destinos of Object.values(PRAZO_TRANSITIONS)) {
      for (const d of destinos) {
        expect(validos.has(d)).toBe(true);
      }
    }
  });

  it("PRAZO_TRANSITIONS é frozen (immutable — usado como single source of truth)", () => {
    expect(Object.isFrozen(PRAZO_TRANSITIONS)).toBe(true);
  });
});

describe("STATUSES_REQUIRING_MOTIVO + STATUSES_REQUIRING_CONFIRMATION", () => {
  it("apenas atrasado_reagendado exige motivo (Decisão UX D4 + F1.7)", () => {
    expect(STATUSES_REQUIRING_MOTIVO).toEqual(["atrasado_reagendado"]);
  });

  it("protocolado / ciencia_renuncia / descartado exigem confirmation dialog leve", () => {
    expect(STATUSES_REQUIRING_CONFIRMATION).toEqual([
      "protocolado",
      "ciencia_renuncia",
      "descartado",
    ]);
  });

  it("MOTIVO_REAGENDAMENTO_MIN_LEN espelha Prazo::MOTIVO_REAGENDAMENTO_MIN_LEN PHP (=10)", () => {
    expect(MOTIVO_REAGENDAMENTO_MIN_LEN).toBe(10);
  });
});

describe("validateMotivo — D1 boundary cases (espelha ValidatePrazoFieldsHook::validateMotivoReagendamento)", () => {
  it("9 chars exatos → rejeita (abaixo do mínimo)", () => {
    expect(validateMotivo("123456789")).toBe(false);
  });

  it("10 chars exatos → aceita (mínimo exato)", () => {
    expect(validateMotivo("1234567890")).toBe(true);
  });

  it("9 chars reais + espaços padding → rejeita (trim é aplicado)", () => {
    expect(validateMotivo("   123456789   ")).toBe(false);
  });

  it("só espaços → rejeita (trim resulta em string vazia)", () => {
    expect(validateMotivo("          ")).toBe(false);
  });

  it("texto válido com espaços internos → aceita (trim só remove bordas)", () => {
    expect(validateMotivo("  Tribunal reagendou  ")).toBe(true);
  });

  it("non-string (null, undefined, number) → rejeita sem throws", () => {
    expect(validateMotivo(null)).toBe(false);
    expect(validateMotivo(undefined)).toBe(false);
    expect(validateMotivo(42)).toBe(false);
  });
});

describe("getValidTransitions / requiresMotivo / requiresConfirmation helpers", () => {
  it("getValidTransitions retorna lista correta para cada status", () => {
    expect(getValidTransitions("rascunho")).toEqual(PRAZO_TRANSITIONS.rascunho);
    expect(getValidTransitions("pendente")).toEqual(PRAZO_TRANSITIONS.pendente);
    expect(getValidTransitions("descartado")).toEqual([]);
  });

  it("P3 — getValidTransitions retorna cópia imutável (mutação não contamina PRAZO_TRANSITIONS)", () => {
    const result = getValidTransitions("pendente");
    result.push("status_fantasma");
    expect(PRAZO_TRANSITIONS.pendente).not.toContain("status_fantasma");
  });

  it("getValidTransitions retorna [] para status desconhecido (não throws)", () => {
    expect(getValidTransitions("inexistente")).toEqual([]);
    expect(getValidTransitions(null)).toEqual([]);
    expect(getValidTransitions(undefined)).toEqual([]);
    expect(getValidTransitions(42)).toEqual([]);
  });

  it("requiresMotivo true só para atrasado_reagendado", () => {
    expect(requiresMotivo("atrasado_reagendado")).toBe(true);
    expect(requiresMotivo("pendente")).toBe(false);
    expect(requiresMotivo("protocolado")).toBe(false);
  });

  it("requiresConfirmation true para protocolado, ciencia_renuncia, descartado", () => {
    expect(requiresConfirmation("protocolado")).toBe(true);
    expect(requiresConfirmation("ciencia_renuncia")).toBe(true);
    expect(requiresConfirmation("descartado")).toBe(true);
    expect(requiresConfirmation("pendente")).toBe(false);
    expect(requiresConfirmation("atrasado_reagendado")).toBe(false);
  });
});
