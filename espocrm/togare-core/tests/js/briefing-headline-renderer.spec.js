/**
 * Testes do helper puro `briefing-headline-renderer.js` (Story 4a.5, AC2).
 *
 * Cobertura:
 *  - count=0 → modificador `--zero` + sem `<a>` CTA + texto "aproveita o café"
 *  - count=1 → "1 prazo pendente" (singular) + CTA presente
 *  - count=5 → "5 prazos pendentes" (plural com `{N}` substituído) + CTA
 *  - count=null/undefined/NaN/negativo → tratado como 0 (defensivo)
 *  - i18n function ausente → fallback strings literais pt-BR
 *  - i18n function lança exceção → fallback (graceful degradation)
 *  - count com HTML adversarial via i18n malicioso → escapado
 *  - href do CTA é literal `#Prazo?bool=meusPendentes` (v0.20.1: boolFilter original restaurado)
 */

import { describe, it, expect } from "vitest";
import {
  composeHeadlineHtml,
  CTA_HREF,
  FALLBACK_COPY,
} from "togare-core:helpers/briefing-headline-renderer";

describe("briefing-headline-renderer / composeHeadlineHtml", () => {
  describe("count=0 (estado calmo)", () => {
    it("aplica modificador --zero", () => {
      const html = composeHeadlineHtml(0, null);
      expect(html).toContain("togare-briefing-headline--zero");
      expect(html).not.toContain("togare-briefing-headline--active");
    });

    it("NÃO inclui CTA (sem <a>)", () => {
      const html = composeHeadlineHtml(0, null);
      expect(html).not.toContain("<a ");
      expect(html).not.toContain("togare-briefing-cta");
    });

    it("usa fallback pt-BR 'aproveita o café'", () => {
      const html = composeHeadlineHtml(0, null);
      expect(html).toContain("aproveita o café");
      expect(html).toContain("☕");
    });
  });

  describe("count=1 (singular)", () => {
    it("renderiza '1 prazo pendente' em <strong>", () => {
      const html = composeHeadlineHtml(1, null);
      expect(html).toContain("<strong>");
      expect(html).toContain("1 prazo pendente");
    });

    it("inclui CTA com texto e href corretos", () => {
      const html = composeHeadlineHtml(1, null);
      expect(html).toContain('class="btn btn-primary btn-sm togare-briefing-cta"');
      expect(html).toContain(`href="${CTA_HREF}"`);
      expect(html).toContain("Confira hoje");
      expect(html).toContain("↗");
    });

    it("aplica modificador --active", () => {
      const html = composeHeadlineHtml(1, null);
      expect(html).toContain("togare-briefing-headline--active");
      expect(html).not.toContain("--zero");
    });
  });

  describe("count>=2 (plural com substituição {N})", () => {
    it("substitui {N} pelo total em count=5", () => {
      const html = composeHeadlineHtml(5, null);
      expect(html).toContain("5 prazos pendentes");
      expect(html).not.toContain("{N}");
    });

    it("substitui {N} em count=42", () => {
      const html = composeHeadlineHtml(42, null);
      expect(html).toContain("42 prazos pendentes");
    });

    it("inclui CTA em count=5", () => {
      const html = composeHeadlineHtml(5, null);
      expect(html).toContain(`href="${CTA_HREF}"`);
    });
  });

  describe("inputs defensivos", () => {
    it("count=null trata como 0", () => {
      const html = composeHeadlineHtml(null, null);
      expect(html).toContain("--zero");
      expect(html).toContain("aproveita o café");
    });

    it("count=undefined trata como 0", () => {
      const html = composeHeadlineHtml(undefined, null);
      expect(html).toContain("--zero");
    });

    it("count=NaN trata como 0", () => {
      const html = composeHeadlineHtml(NaN, null);
      expect(html).toContain("--zero");
    });

    it("count negativo trata como 0", () => {
      const html = composeHeadlineHtml(-5, null);
      expect(html).toContain("--zero");
    });

    it("count fracional arredonda pra baixo (count=3.7 → 3)", () => {
      const html = composeHeadlineHtml(3.7, null);
      expect(html).toContain("3 prazos pendentes");
    });
  });

  describe("i18n integração", () => {
    it("usa i18n quando função fornecida e retorna string", () => {
      const i18n = (key) => {
        const map = {
          briefingHeadlineMany: "{N} pendências",
          briefingCtaConfiraHoje: "Ver agora",
        };
        return map[key] ?? null;
      };
      const html = composeHeadlineHtml(3, i18n);
      expect(html).toContain("3 pendências");
      expect(html).toContain("Ver agora");
    });

    it("fallback hardcoded quando i18n é null", () => {
      const html = composeHeadlineHtml(2, null);
      expect(html).toContain("2 prazos pendentes");
      expect(html).toContain("Confira hoje");
    });

    it("fallback quando i18n retorna empty string", () => {
      const i18n = () => "";
      const html = composeHeadlineHtml(1, i18n);
      expect(html).toContain("1 prazo pendente");
    });

    it("fallback quando i18n lança exceção (graceful degradation)", () => {
      const i18n = () => {
        throw new Error("language file not loaded yet");
      };
      const html = composeHeadlineHtml(2, i18n);
      // Não deve quebrar; retorna fallback hardcoded.
      expect(html).toContain("2 prazos pendentes");
      expect(html).toContain("Confira hoje");
    });
  });

  describe("XSS defense (i18n malicioso)", () => {
    it("escapa HTML adversarial em count=0", () => {
      const i18n = () => '<script>alert("xss")</script>';
      const html = composeHeadlineHtml(0, i18n);
      expect(html).not.toContain("<script>");
      expect(html).toContain("&lt;script&gt;");
    });

    it("escapa HTML adversarial em CTA", () => {
      const i18n = (key) => {
        if (key === "briefingCtaConfiraHoje")
          return '"><img src=x onerror=alert(1)>';
        return null;
      };
      const html = composeHeadlineHtml(2, i18n);
      // Aspas duplas escapadas (não fecha atributo).
      expect(html).not.toMatch(/<img\s+src=x/);
      expect(html).toContain("&quot;");
    });

    it("escapa HTML adversarial em mensagem com {N}", () => {
      const i18n = (key) => {
        if (key === "briefingHeadlineMany") return "{N} <b>injetado</b>";
        return null;
      };
      const html = composeHeadlineHtml(3, i18n);
      expect(html).not.toContain("<b>injetado</b>");
      expect(html).toContain("&lt;b&gt;injetado&lt;/b&gt;");
      expect(html).toContain("3");
    });
  });

  describe("constantes exportadas", () => {
    it("CTA_HREF é literal Backbone hash router", () => {
      // v0.20.1: filtro `meusPendentesPriorizados` removido — Orderer custom
      // `DataFatalPriorizado` cuida do desempate por prioridadeWeight DESC,
      // e o boolFilter volta a ser o `meusPendentes` original.
      expect(CTA_HREF).toBe("#Prazo?bool=meusPendentes");
    });

    it("FALLBACK_COPY tem 4 chaves canônicas", () => {
      expect(FALLBACK_COPY).toHaveProperty("briefingHeadlineZero");
      expect(FALLBACK_COPY).toHaveProperty("briefingHeadlineOne");
      expect(FALLBACK_COPY).toHaveProperty("briefingHeadlineMany");
      expect(FALLBACK_COPY).toHaveProperty("briefingCtaConfiraHoje");
    });
  });
});
