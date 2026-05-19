import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import BriefingDoDiaDashletView from "togare-core:views/dashlets/briefing-do-dia";

/**
 * Story 10.1 — dashlet BriefingDoDia.
 *
 * Cobre: render após fetch, 403 = vazio sem loop (AC3), polling 30min,
 * getConfigForRole (7 configs), onRemove sem leak.
 *
 * Ajax stub via `globalThis.__togareAjaxStub` (mesmo padrão do TogareHealth).
 */

const PAYLOAD = {
  badges: [
    { key: "prazo-pendente", title: "Prazos pendentes", count: 3, cta: "Confirme hoje", link: "#Prazo/list" },
    { key: "publicacao-nova", title: "Publicações para revisar", count: 1, cta: "Leia estas", link: "#PublicacaoAmbigua/list" },
  ],
  role: "advogado",
  generatedAt: "2026-05-17T00:00:00Z",
};

/** Views criadas no teste — limpas no afterEach (evita leak de listener
 *  visibilitychange no `document` compartilhado entre testes). */
let _createdViews = [];

function buildView(userName = "") {
  const v = new BriefingDoDiaDashletView({});
  const el = document.createElement("div");
  el.innerHTML =
    '<div class="togare-briefing__live sr-only" role="status" aria-live="polite"></div>' +
    '<div class="togare-briefing-root"></div>';
  document.body.appendChild(el);
  v.el = el;
  v._userName = userName;
  v.setup();
  _createdViews.push(v);
  return v;
}

const flush = async () => {
  await Promise.resolve();
  await Promise.resolve();
};

describe("BriefingDoDiaDashletView", () => {
  beforeEach(() => {
    vi.useFakeTimers();
    delete globalThis.__togareAjaxStub;
  });

  afterEach(() => {
    // onRemove em todas as views → remove o listener visibilitychange do
    // `document` compartilhado (senão views órfãs re-pollam em testes seguintes).
    for (const v of _createdViews) {
      try { v.onRemove(); } catch (_) { /* noop */ }
    }
    _createdViews = [];
    vi.useRealTimers();
    delete globalThis.__togareAjaxStub;
    document.body.innerHTML = "";
  });

  it("renderiza o painel após fetch bem-sucedido", async () => {
    globalThis.__togareAjaxStub = vi.fn(() => Promise.resolve(PAYLOAD));
    const v = buildView("Felipe");
    v.afterRender();
    await flush();

    const root = v.el.querySelector(".togare-briefing-root");
    expect(root.innerHTML).toContain("togare-briefing__grid");
    expect(root.innerHTML).toContain("Prazos pendentes");
    expect(globalThis.__togareAjaxStub).toHaveBeenCalledWith("TogareBriefing/action/data");
  });

  it("403 renderiza vazio e NÃO reprograma polling (AC3)", async () => {
    const stub = vi.fn(() => Promise.reject({ status: 403 }));
    globalThis.__togareAjaxStub = stub;
    const v = buildView();
    v.afterRender();
    await flush();

    // DOM existe; conteúdo é EmptyStateCalmo, não erro.
    expect(v.el.querySelector(".togare-briefing-root")).not.toBeNull();
    expect(stub).toHaveBeenCalledTimes(1);

    // Avança 90min (3× polling) — NÃO deve refazer fetch após 403.
    await vi.advanceTimersByTimeAsync(5_400_000);
    expect(stub).toHaveBeenCalledTimes(1);
  });

  it("faz polling a cada 30 min (POLL_INTERVAL_MS)", async () => {
    const stub = vi.fn(() => Promise.resolve(PAYLOAD));
    globalThis.__togareAjaxStub = stub;
    const v = buildView();
    v.afterRender();
    await flush();
    expect(stub).toHaveBeenCalledTimes(1);

    await vi.advanceTimersByTimeAsync(1_800_000);
    await flush();
    expect(stub).toHaveBeenCalledTimes(2);
  });

  it("falha de rede (não-403) não derruba o dashboard", async () => {
    globalThis.__togareAjaxStub = vi.fn(() => Promise.reject({ status: 500 }));
    const v = buildView();
    expect(() => v.afterRender()).not.toThrow();
    await flush();
    expect(v.el.querySelector(".togare-briefing-root")).not.toBeNull();
  });

  it("onRemove não lança e cancela timer", async () => {
    globalThis.__togareAjaxStub = vi.fn(() => Promise.resolve(PAYLOAD));
    const v = buildView();
    v.afterRender();
    await flush();
    expect(() => v.onRemove()).not.toThrow();
  });

  // G2-P2 (code review 2026-05-18): após 403, alternar de aba
  // (visibilitychange) NÃO pode re-disparar _poll() — antes do flag terminal
  // `_stopped` isto derrotava o "sem loop de retry" da AC3.
  it("403 + visibilitychange (volta de aba) NÃO refaz fetch (AC3, G2-P2)", async () => {
    const stub = vi.fn(() => Promise.reject({ status: 403 }));
    globalThis.__togareAjaxStub = stub;
    const v = buildView();
    v.afterRender();
    await flush();
    await flush(); // cadeia .then().catch() do reject precisa de +ticks
    expect(stub).toHaveBeenCalledTimes(1);
    expect(v._stopped).toBe(true);

    // Simula 3 retornos de foco da aba.
    for (let i = 0; i < 3; i++) {
      document.dispatchEvent(new Event("visibilitychange"));
      await flush();
    }
    expect(stub).toHaveBeenCalledTimes(1);
  });

  it("onRemove marca _stopped e impede paint de fetch tardio (G2-P2)", async () => {
    let resolveFetch;
    globalThis.__togareAjaxStub = vi.fn(
      () => new Promise((res) => { resolveFetch = res; }),
    );
    const v = buildView();
    v.afterRender(); // dispara fetch pendente
    v.onRemove();
    expect(v._stopped).toBe(true);

    const root = v.el.querySelector(".togare-briefing-root");
    const before = root.innerHTML;
    resolveFetch(PAYLOAD); // resolve DEPOIS do remove
    await flush();
    expect(root.innerHTML).toBe(before); // não pintou DOM destacado
  });

  // G3-P2 (code review 2026-05-18): bloco "getConfigForRole — 7 configs"
  // removido junto com o dead code `ROLE_CONFIGS`/`getConfigForRole`.
  // A cobertura real de empty-state por role vive em
  // briefing-do-dia-renderer.spec.js ("renderEmptyState — por role (AC3)").

  it("sem ENDPOINT stub (ajax=null) não lança em afterRender", () => {
    // __togareAjaxStub removido; window.Espo inexistente no jsdom
    const v = buildView();
    expect(() => v.afterRender()).not.toThrow();
  });
});
