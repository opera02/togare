import { describe, it, expect } from "vitest";
import {
  escapeHtml,
  safeLink,
  renderGreeting,
  renderBadgeCard,
  renderBadges,
  renderEmptyState,
  renderPanel,
} from "togare-core:helpers/briefing-do-dia-renderer";

/**
 * Story 10.1 — helper puro do BriefingDoDia.
 */
describe("briefing-do-dia-renderer", () => {
  describe("escapeHtml — XSS-safe", () => {
    it("escapa < > & \" '", () => {
      expect(escapeHtml(`<img src=x onerror="a('b')">`)).toBe(
        "&lt;img src=x onerror=&quot;a(&#39;b&#39;)&quot;&gt;",
      );
    });
    it("null/undefined viram string vazia", () => {
      expect(escapeHtml(null)).toBe("");
      expect(escapeHtml(undefined)).toBe("");
    });
    it("número é convertido para string", () => {
      expect(escapeHtml(42)).toBe("42");
    });
  });

  describe("renderGreeting — saudação com data pt-BR", () => {
    it("inclui nome do usuário escapado", () => {
      const now = new Date(2026, 4, 17); // domingo, 17 mai
      const html = renderGreeting("Felipe", now);
      expect(html).toContain("Olá, Felipe.");
      expect(html).toContain("togare-briefing__greeting");
    });

    it("sem nome: 'Olá.' sem vírgula", () => {
      const now = new Date(2026, 4, 17);
      const html = renderGreeting("", now);
      expect(html).toContain("Olá.");
      expect(html).not.toContain("Olá, .");
    });

    it("XSS no nome é escapado", () => {
      const now = new Date(2026, 4, 17);
      const html = renderGreeting("<script>alert(1)</script>", now);
      expect(html).not.toContain("<script>");
      expect(html).toContain("&lt;script&gt;");
    });

    it("inclui dia do mês como número 2 dígitos", () => {
      const now = new Date(2026, 0, 5); // 5 jan
      const html = renderGreeting("X", now);
      expect(html).toContain("05");
    });
  });

  describe("renderBadgeCard — card de badge normal", () => {
    it("conta ≥1 gera link visível sem classe --empty", () => {
      const html = renderBadgeCard({ key: "prazo", title: "Prazos", count: 3, cta: "Ver", link: "#Prazo/list" });
      expect(html).toContain("togare-briefing-card");
      expect(html).not.toContain("togare-briefing-card--empty");
      expect(html).toContain("3");
      expect(html).toContain("Prazos");
      expect(html).toContain('href="#Prazo/list"');
      expect(html).toContain("Ver");
    });

    it("count=0 gera classe --empty", () => {
      const html = renderBadgeCard({ key: "x", title: "T", count: 0, cta: "Ver", link: "#" });
      expect(html).toContain("togare-briefing-card--empty");
      expect(html).toContain("0");
    });

    it("count ausente cai para 0 (--empty)", () => {
      const html = renderBadgeCard({ key: "x", title: "T", cta: "Ver", link: "#" });
      expect(html).toContain("togare-briefing-card--empty");
    });

    it("XSS no título e CTA são escapados", () => {
      const html = renderBadgeCard({
        key: "x",
        title: "<script>",
        count: 1,
        cta: "<b>click</b>",
        link: "#",
      });
      expect(html).not.toContain("<script>");
      expect(html).not.toContain("<b>click</b>");
      expect(html).toContain("&lt;script&gt;");
    });

    it("card é um <a> com tabindex=0 (AC4 — Tab+Enter nativo)", () => {
      const html = renderBadgeCard({ key: "x", title: "T", count: 1, cta: "Ver", link: "#" });
      expect(html).toMatch(/<a /);
      expect(html).toContain('tabindex="0"');
      expect(html).toContain('aria-label=');
    });
  });

  describe("renderBadgeCard — health type", () => {
    it("type=health ok: classe --health-ok", () => {
      const html = renderBadgeCard({ type: "health", title: "Saúde", healthStatus: "ok", alertCount: 0, cta: "Ver", link: "#" });
      expect(html).toContain("togare-briefing-card--health-ok");
      expect(html).toContain("OK");
    });

    it("type=health lento: classe --health-atencao com alertas", () => {
      const html = renderBadgeCard({ type: "health", title: "Saúde", healthStatus: "lento", alertCount: 2, cta: "Ver", link: "#" });
      expect(html).toContain("togare-briefing-card--health-atencao");
      expect(html).toContain("2 alerta(s)");
    });

    it("type=health offline: classe --health-critico", () => {
      const html = renderBadgeCard({ type: "health", title: "Saúde", healthStatus: "offline", alertCount: 3, cta: "Ver", link: "#" });
      expect(html).toContain("togare-briefing-card--health-critico");
      expect(html).toContain("3 falha(s)");
    });

    it("healthStatus desconhecido cai para critico", () => {
      const html = renderBadgeCard({ type: "health", title: "Saúde", healthStatus: "xxx", alertCount: 1, cta: "Ver", link: "#" });
      expect(html).toContain("togare-briefing-card--health-critico");
    });
  });

  describe("renderBadges — grid de cards", () => {
    it("lista vazia → string vazia", () => {
      expect(renderBadges([])).toBe("");
      expect(renderBadges(null)).toBe("");
    });

    it("renderiza todos os cards", () => {
      const badges = [
        { key: "a", title: "A", count: 1, cta: "Ver", link: "#" },
        { key: "b", title: "B", count: 0, cta: "Ver", link: "#" },
      ];
      const html = renderBadges(badges);
      expect(html).toContain("A");
      expect(html).toContain("B");
    });
  });

  describe("renderEmptyState — por role (AC3)", () => {
    it("advogado → mensagem específica", () => {
      const html = renderEmptyState("advogado");
      expect(html).toContain("Tudo em dia");
      expect(html).toContain("togare-briefing__empty-state");
    });

    it("financeiro → mensagem específica", () => {
      expect(renderEmptyState("financeiro")).toContain("Sem faturas");
    });

    it("rh-lite → mensagem específica", () => {
      expect(renderEmptyState("rh-lite")).toContain("Nenhum funcionário");
    });

    it("socio-admin → mensagem específica", () => {
      expect(renderEmptyState("socio-admin")).toContain("Sistema saudável");
    });

    it("role desconhecido → mensagem genérica calma", () => {
      const html = renderEmptyState("desconhecido");
      expect(html).toContain("Nada pendente");
    });
  });

  describe("renderPanel — painel completo", () => {
    it("com badges: exibe greeting + grid de cards", () => {
      const payload = {
        badges: [{ key: "p", title: "Prazos", count: 5, cta: "Ver", link: "#" }],
        role: "advogado",
        generatedAt: "2026-05-17T00:00:00Z",
      };
      const html = renderPanel(payload, "Felipe");
      expect(html).toContain("togare-briefing__header");
      expect(html).toContain("togare-briefing__grid");
      expect(html).toContain("Prazos");
      expect(html).toContain("Olá, Felipe.");
    });

    it("com itens pendentes: exibe badge de notificação 🔔", () => {
      const payload = {
        badges: [
          { key: "p", title: "Prazos", count: 3, cta: "Ver", link: "#" },
          { key: "q", title: "Pub", count: 2, cta: "Ver", link: "#" },
        ],
        role: "advogado",
        generatedAt: "",
      };
      const html = renderPanel(payload, "X");
      expect(html).toContain("togare-briefing__notif");
      expect(html).toContain("🔔 5");
    });

    it("badges vazios: exibe EmptyStateCalmo por role", () => {
      const payload = { badges: [], role: "advogado", generatedAt: "" };
      const html = renderPanel(payload, "X");
      expect(html).toContain("Tudo em dia");
      expect(html).not.toContain("togare-briefing__notif");
    });

    it("payload null não lança", () => {
      expect(() => renderPanel(null, "X")).not.toThrow();
      const html = renderPanel(null, "X");
      expect(html).toContain("togare-briefing__header");
    });

    it("soma alertCount de health no total do notif badge", () => {
      const payload = {
        badges: [
          { type: "health", key: "h", title: "Saúde", healthStatus: "lento", alertCount: 2, cta: "Ver", link: "#" },
          { key: "p", title: "Prazos", count: 1, cta: "Ver", link: "#" },
        ],
        role: "socio-admin",
        generatedAt: "",
      };
      const html = renderPanel(payload, "X");
      // alertCount(2) + count(1) = 3
      expect(html).toContain("🔔 3");
    });

    it("7 roles têm configuração EmptyState distinta (sem janela.Espo)", () => {
      const roles = ["socio-admin", "advogado", "assistente", "secretaria", "financeiro", "marketing", "rh-lite"];
      // Verifica que o módulo não usa window.Espo/window.globalThis.Espo de forma
      // que quebre em ambiente puro (vitest jsdom sem EspoCRM).
      for (const role of roles) {
        expect(() => renderEmptyState(role)).not.toThrow();
        expect(renderEmptyState(role)).toBeTruthy();
      }
    });
  });

  // ===================================================================
  // Guardas de regressão — bmad-code-review G2 JS (2026-05-18)
  // ===================================================================
  describe("G2-P1 — safeLink: whitelist de scheme", () => {
    it("bloqueia javascript: e data: → fallback", () => {
      expect(safeLink("javascript:alert(1)", "#")).toBe("#");
      expect(safeLink("data:text/html,<script>", "#X")).toBe("#X");
      expect(safeLink(" JavaScript:alert(1)", "#")).toBe("#");
    });
    it("permite hash-route, path relativo e http(s)", () => {
      expect(safeLink("#Admin/ModuleStatus", "#")).toBe("#Admin/ModuleStatus");
      expect(safeLink("/portal/x", "#")).toBe("/portal/x");
      expect(safeLink("https://a.b/c", "#")).toBe("https://a.b/c");
    });
    it("null/vazio → fallback", () => {
      expect(safeLink(null, "#fb")).toBe("#fb");
      expect(safeLink("   ", "#fb")).toBe("#fb");
    });
    it("renderBadgeCard com link javascript: não emite o scheme", () => {
      const html = renderBadgeCard({ key: "x", title: "T", count: 1, cta: "Ver", link: "javascript:alert(1)" });
      expect(html).not.toContain("javascript:");
      expect(html).toContain('href="#"');
    });
  });

  describe("G2-P4 — card de Licença (key=licenca)", () => {
    it("vencida: --health-critico + 'Vencida há N dia(s)' + glyph", () => {
      const html = renderBadgeCard({ key: "licenca", title: "Licença", count: null, licencaStatus: "vencida", dayDiff: -2330, cta: "Ver status", link: "#Admin/ModuleStatus" });
      expect(html).toContain("togare-briefing-card--health-critico");
      expect(html).toContain("togare-briefing-card--license");
      expect(html).toContain("Vencida há 2330 dia(s)");
      expect(html).toContain('togare-briefing-card__glyph" aria-hidden="true"');
      expect(html).not.toContain("togare-briefing-card--empty");
    });
    it("expirando: --health-atencao + 'Expira em N dia(s)'", () => {
      const html = renderBadgeCard({ key: "licenca", title: "Licença", licencaStatus: "expirando", dayDiff: 12 });
      expect(html).toContain("togare-briefing-card--health-atencao");
      expect(html).toContain("Expira em 12 dia(s)");
    });
    it("expirando dayDiff<=0 → 'Expira hoje'", () => {
      const html = renderBadgeCard({ key: "licenca", licencaStatus: "expirando", dayDiff: 0 });
      expect(html).toContain("Expira hoje");
    });
    it("valida: --health-ok + 'Válida'", () => {
      const html = renderBadgeCard({ key: "licenca", licencaStatus: "valida", dayDiff: 200 });
      expect(html).toContain("togare-briefing-card--health-ok");
      expect(html).toContain("Válida");
    });
  });

  describe("G2-P5 — glyph de estado no card health (AR-5)", () => {
    it("health ok inclui glyph aria-hidden", () => {
      const html = renderBadgeCard({ type: "health", title: "Saúde", healthStatus: "ok", alertCount: 0 });
      expect(html).toContain('togare-briefing-card__glyph" aria-hidden="true"');
    });
  });

  describe("G2-P3 — total do notif coage count numérico", () => {
    it("count como string não concatena (Number)", () => {
      const payload = {
        badges: [
          { key: "a", title: "A", count: "3", cta: "Ver", link: "#" },
          { key: "b", title: "B", count: 2, cta: "Ver", link: "#" },
        ],
        role: "advogado",
      };
      const html = renderPanel(payload, "X");
      expect(html).toContain("🔔 5");
      expect(html).not.toContain("🔔 32");
    });
  });

  describe("G2-P7 — tudo-zero → EmptyStateCalmo (AC3)", () => {
    it("badges só de contagem, todos 0 → EmptyState, sem cards '0'", () => {
      const payload = {
        badges: [
          { key: "prazo", title: "Prazos", count: 0, cta: "Ver", link: "#" },
          { key: "pub", title: "Publicações", count: 0, cta: "Ver", link: "#" },
        ],
        role: "advogado",
      };
      const html = renderPanel(payload, "X");
      expect(html).toContain("Tudo em dia");
      expect(html).not.toContain("togare-briefing-card--empty");
      expect(html).not.toContain("togare-briefing__notif");
    });
    it("com health presente NÃO força EmptyState (painel de estado)", () => {
      const payload = {
        badges: [
          { type: "health", title: "Saúde", healthStatus: "ok", alertCount: 0 },
          { key: "prazo", title: "Prazos", count: 0, cta: "Ver", link: "#" },
        ],
        role: "socio-admin",
      };
      const html = renderPanel(payload, "X");
      expect(html).toContain("togare-briefing-card--health-ok");
      expect(html).not.toContain("Sistema saudável. Nada pendente");
    });
    it("ao menos 1 badge com count>0 → cards normais", () => {
      const payload = {
        badges: [
          { key: "prazo", title: "Prazos", count: 0, cta: "Ver", link: "#" },
          { key: "pub", title: "Publicações", count: 4, cta: "Ver", link: "#" },
        ],
        role: "advogado",
      };
      const html = renderPanel(payload, "X");
      expect(html).toContain("Publicações");
      expect(html).not.toContain("Tudo em dia");
    });
  });
});
