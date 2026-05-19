import { describe, it, expect } from "vitest";
import {
  STATE_META,
  escapeHtml,
  composeTileHtml,
  stateSignature,
  composeLicencaHtml,
  composePanelHtml,
  composeHistoricoHtml,
} from "togare-core:helpers/health-panel-renderer";

/**
 * Story 10.2 / FR41 — helper puro do painel TogareHealth.
 */
describe("health-panel-renderer", () => {
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
  });

  describe("composeTileHtml — cor + ícone único + label (AR-5 colorblind)", () => {
    it("cada estado tem ícone DISTINTO", () => {
      const icons = new Set(
        ["ok", "lento", "offline", "nao_instalado"].map(
          (s) => STATE_META[s].icon,
        ),
      );
      expect(icons.size).toBe(4);
    });

    it("tile ok = verde, role=status, data-state, métrica", () => {
      const html = composeTileHtml({
        key: "mariadb",
        label: "MariaDB",
        state: "ok",
        message: "OK",
        detailLink: null,
      });
      expect(html).toContain("togare-health-tile--ok");
      expect(html).toContain('role="status"');
      expect(html).toContain('data-state="ok"');
      expect(html).toContain("MariaDB");
      expect(html).toContain(STATE_META.ok.icon);
      expect(html).toContain("Saudável:");
    });

    it("estado inválido cai para offline (nunca verde silencioso)", () => {
      const html = composeTileHtml({ key: "x", label: "X", state: "lixo", message: "" });
      expect(html).toContain("togare-health-tile--offline");
    });

    it("nao_instalado é cinza calmo, sem classe de erro (AC1)", () => {
      const html = composeTileHtml({
        key: "djen",
        label: "DJEN",
        state: "nao_instalado",
        message: "Não instalado",
      });
      expect(html).toContain("togare-health-tile--nao-instalado");
      expect(html).not.toContain("togare-health-tile--offline");
      expect(html).toContain("Não instalado");
    });

    it("detailLink vira link 'Ver detalhe' só quando presente", () => {
      const withLink = composeTileHtml({
        key: "backup",
        label: "Backup",
        state: "offline",
        message: "Backup atrasado — último há 30h. Ver log.",
        detailLink: "#Admin/jobs",
      });
      expect(withLink).toContain('href="#Admin/jobs"');
      expect(withLink).toContain("Ver detalhe");

      const noLink = composeTileHtml({
        key: "redis",
        label: "Redis",
        state: "ok",
        message: "OK",
        detailLink: null,
      });
      expect(noLink).not.toContain("Ver detalhe");
    });

    it("conteúdo dinâmico é escapado (XSS)", () => {
      const html = composeTileHtml({
        key: "x",
        label: "<script>",
        state: "ok",
        message: "<b>boom</b>",
        detailLink: null,
      });
      expect(html).not.toContain("<script>");
      expect(html).not.toContain("<b>boom</b>");
      expect(html).toContain("&lt;script&gt;");
    });
  });

  describe("stateSignature — base do aria-live (AR-9)", () => {
    it("muda quando um estado muda; igual quando só a métrica muda", () => {
      const a = {
        tiles: [
          { key: "djen", state: "ok" },
          { key: "redis", state: "ok" },
        ],
      };
      const bSameStates = {
        tiles: [
          { key: "djen", state: "ok", message: "há 5 min" },
          { key: "redis", state: "ok", message: "há 1 min" },
        ],
      };
      const cChanged = {
        tiles: [
          { key: "djen", state: "offline" },
          { key: "redis", state: "ok" },
        ],
      };
      expect(stateSignature(a)).toBe(stateSignature(bSameStates));
      expect(stateSignature(a)).not.toBe(stateSignature(cChanged));
    });
  });

  describe("composeLicencaHtml — rodapé (AC3)", () => {
    it("null/sem mensagem → string vazia (linha some)", () => {
      expect(composeLicencaHtml(null)).toBe("");
      expect(composeLicencaHtml({ state: "valida", message: "" })).toBe("");
    });
    it("expirando carrega data-licenca-state", () => {
      const html = composeLicencaHtml({
        state: "expirando",
        message: "Licença expira em 12 dias",
      });
      expect(html).toContain('data-licenca-state="expirando"');
      expect(html).toContain("Licença expira em 12 dias");
    });
  });

  describe("composePanelHtml — painel completo", () => {
    it("grid + header + footer; link histórico só com itens", () => {
      const html = composePanelHtml({
        tiles: [
          { key: "djen", label: "DJEN", state: "ok", message: "OK" },
          { key: "backup", label: "Backup", state: "lento", message: "Backup ainda não rodou. Ver log." },
        ],
        licenca: { state: "valida", message: "Licença válida até 21/10/2026" },
        historico: [{ occurredAt: "2026-05-17", event: "djen.x", message: "DJEN instável" }],
      });
      expect(html).toContain("Saúde do Togare");
      expect(html).toContain("togare-health-panel__grid");
      expect(html).toContain("Licença válida até 21/10/2026");
      expect(html).toContain("Ver histórico de incidentes");
    });

    it("sem histórico → sem link de histórico", () => {
      const html = composePanelHtml({ tiles: [], historico: [] });
      expect(html).not.toContain("Ver histórico de incidentes");
    });

    it("payload vazio não quebra", () => {
      expect(() => composePanelHtml(undefined)).not.toThrow();
      expect(composePanelHtml(undefined)).toContain("togare-health-panel");
    });
  });

  describe("composeHistoricoHtml", () => {
    it("vazio mostra mensagem calma", () => {
      expect(composeHistoricoHtml([])).toContain("Sem incidentes registrados.");
    });
    it("lista escapada", () => {
      const html = composeHistoricoHtml([
        { occurredAt: "2026-05-17", event: "e", message: "<x>" },
      ]);
      expect(html).toContain("togare-health-historico__item");
      expect(html).not.toContain("<x>");
      expect(html).toContain("&lt;x&gt;");
    });
  });
});
