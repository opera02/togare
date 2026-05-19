/**
 * Testes vitest do helper `d-zero-detector` (Story 4b.3, AC8 + AC9).
 */
import { describe, it, expect } from "vitest";
import { isVenceHoje, toBrtYmd } from "../../src/files/client/custom/modules/togare-core/src/helpers/d-zero-detector.js";

describe("d-zero-detector — toBrtYmd", () => {
  it("string YYYY-MM-DD pura é tratada como literal BRT (sem conversão de fuso)", () => {
    expect(toBrtYmd("2026-06-01")).toBe("2026-06-01");
    expect(toBrtYmd("2025-12-31")).toBe("2025-12-31");
  });

  it("retorna null para null/undefined/vazio/inválido", () => {
    expect(toBrtYmd(null)).toBe(null);
    expect(toBrtYmd(undefined)).toBe(null);
    expect(toBrtYmd("")).toBe(null);
    expect(toBrtYmd("not-a-date")).toBe(null);
  });

  it("Date object converte para BRT YMD via Intl", () => {
    // 2026-06-01T15:00:00 UTC = 2026-06-01T12:00:00 BRT (BRT é UTC-3).
    const d = new Date("2026-06-01T15:00:00Z");
    expect(toBrtYmd(d)).toBe("2026-06-01");
  });

  it("ISO string com sufixo Z normaliza para BRT correto", () => {
    // 2026-12-31T22:00:00Z = 2026-12-31T19:00:00 BRT (mesmo dia BRT).
    expect(toBrtYmd("2026-12-31T22:00:00Z")).toBe("2026-12-31");

    // 2026-01-01T01:00:00Z = 2025-12-31T22:00:00 BRT (DIA ANTERIOR em BRT).
    expect(toBrtYmd("2026-01-01T01:00:00Z")).toBe("2025-12-31");
  });

  it("transição UTC vs BRT — caso DST-aware (BRT removeu DST em 2019, fixo UTC-3)", () => {
    // 2026-06-01T03:00:00Z = 2026-06-01T00:00:00 BRT (mesmo dia BRT).
    expect(toBrtYmd("2026-06-01T03:00:00Z")).toBe("2026-06-01");
  });

  it("fallback sem Intl nao aplica timezoneOffset local duas vezes", () => {
    const originalDateTimeFormat = Intl.DateTimeFormat;
    const originalGetTimezoneOffset = Date.prototype.getTimezoneOffset;

    try {
      Intl.DateTimeFormat = function DateTimeFormatUnavailable() {
        throw new Error("Intl timezone unavailable");
      };
      Date.prototype.getTimezoneOffset = () => 180;

      expect(toBrtYmd("2026-01-01T01:00:00Z")).toBe("2025-12-31");
    } finally {
      Intl.DateTimeFormat = originalDateTimeFormat;
      Date.prototype.getTimezoneOffset = originalGetTimezoneOffset;
    }
  });
});

describe("d-zero-detector — isVenceHoje", () => {
  it("retorna true quando dataFatal === today (string YMD)", () => {
    const now = new Date("2026-06-01T15:00:00Z");
    expect(isVenceHoje("2026-06-01", now)).toBe(true);
  });

  it("retorna false quando dataFatal é ontem em BRT", () => {
    const now = new Date("2026-06-01T15:00:00Z");
    expect(isVenceHoje("2026-05-31", now)).toBe(false);
  });

  it("retorna false quando dataFatal é amanhã em BRT", () => {
    const now = new Date("2026-06-01T15:00:00Z");
    expect(isVenceHoje("2026-06-02", now)).toBe(false);
  });

  it("retorna false para dataFatal null/undefined/inválida", () => {
    const now = new Date("2026-06-01T15:00:00Z");
    expect(isVenceHoje(null, now)).toBe(false);
    expect(isVenceHoje(undefined, now)).toBe(false);
    expect(isVenceHoje("", now)).toBe(false);
    expect(isVenceHoje("not-a-date", now)).toBe(false);
  });

  it("usa now=new Date() default quando omitido — stable (não pode lançar)", () => {
    // Chamada com dataFatal ridiculamente futura (≠ hoje) → false determinístico.
    expect(isVenceHoje("3000-01-01")).toBe(false);
    // Chamada sem args → default false.
    expect(isVenceHoje()).toBe(false);
  });

  it("compara em BRT mesmo se now está em UTC late-night (cruza meia-noite)", () => {
    // now = 2026-06-02T01:00:00Z (00:00 - 01:00 UTC) = 2026-06-01T22:00 BRT.
    // dataFatal = "2026-06-01" → BRT-equal → true.
    const now = new Date("2026-06-02T01:00:00Z");
    expect(isVenceHoje("2026-06-01", now)).toBe(true);
    // dataFatal = "2026-06-02" → BRT day amanhã → false.
    expect(isVenceHoje("2026-06-02", now)).toBe(false);
  });
});
