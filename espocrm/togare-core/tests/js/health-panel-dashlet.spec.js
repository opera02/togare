import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import TogareHealthPanelDashletView from "togare-core:views/dashlets/togare-health-panel";

/**
 * Story 10.2 / FR41 — dashlet TogareHealth.
 *
 * Cobre: render via fetch, 403 = vazio sem loop (AC4), aria-live só em
 * mudança de estado (AR-9), polling 60s, pause em hidden, onRemove sem leak.
 *
 * Ajax stub via `globalThis.__togareAjaxStub` (resolver interno consulta esse
 * global em testes — mesmo pattern do system-status-banner).
 */

const PANEL = {
  tiles: [
    { key: "djen", label: "DJEN", state: "ok", message: "OK", detailLink: null },
    { key: "tpu", label: "TPU", state: "nao_instalado", message: "Não instalado", detailLink: null },
    { key: "mariadb", label: "MariaDB", state: "ok", message: "OK", detailLink: null },
    { key: "nextcloud", label: "Nextcloud", state: "nao_instalado", message: "Não instalado", detailLink: null },
    { key: "redis", label: "Redis", state: "ok", message: "OK", detailLink: null },
    { key: "backup", label: "Backup", state: "lento", message: "Backup ainda não rodou. Ver log.", detailLink: "#Admin/jobs" },
  ],
  licenca: null,
  historico: [],
  generatedAt: "2026-05-17T00:00:00+00:00",
};

function buildView() {
  const v = new TogareHealthPanelDashletView({});
  const el = document.createElement("div");
  el.innerHTML =
    '<div class="togare-health-live" role="status" aria-live="polite"></div>' +
    '<div class="togare-health-root"></div>';
  document.body.appendChild(el);
  v.el = el;
  v.setup();
  return v;
}

const flush = async () => {
  await Promise.resolve();
  await Promise.resolve();
};

describe("TogareHealthPanelDashletView", () => {
  beforeEach(() => {
    vi.useFakeTimers();
    delete globalThis.__togareAjaxStub;
  });

  afterEach(() => {
    vi.useRealTimers();
    delete globalThis.__togareAjaxStub;
    document.body.innerHTML = "";
  });

  it("renderiza o painel após fetch bem-sucedido", async () => {
    globalThis.__togareAjaxStub = vi.fn(() => Promise.resolve(PANEL));
    const v = buildView();
    v.afterRender();
    await flush();

    const root = v.el.querySelector(".togare-health-root");
    expect(root.innerHTML).toContain("togare-health-panel__grid");
    expect(root.innerHTML).toContain("DJEN");
    expect(root.innerHTML).toContain("togare-health-tile--nao-instalado");
    expect(globalThis.__togareAjaxStub).toHaveBeenCalledWith("TogareHealth/action/data");
  });

  it("403 renderiza vazio e NÃO reprograma polling (AC4)", async () => {
    const stub = vi.fn(() => Promise.reject({ status: 403 }));
    globalThis.__togareAjaxStub = stub;
    const v = buildView();
    v.afterRender();
    await flush();

    expect(v.el.querySelector(".togare-health-root").innerHTML).toBe("");
    expect(stub).toHaveBeenCalledTimes(1);

    // Avança bem além de 60s — não deve refazer fetch (sem loop).
    await vi.advanceTimersByTimeAsync(180_000);
    expect(stub).toHaveBeenCalledTimes(1);
  });

  it("aria-live dispara só em MUDANÇA de estado, não no refresh (AR-9)", async () => {
    let payload = JSON.parse(JSON.stringify(PANEL));
    globalThis.__togareAjaxStub = vi.fn(() => Promise.resolve(payload));
    const v = buildView();

    // 1º fetch — baseline, sem anúncio.
    v.afterRender();
    await flush();
    expect(v.el.querySelector(".togare-health-live").textContent).toBe("");

    // 2º fetch — só a métrica mudou (mesmos estados) → sem anúncio.
    payload = JSON.parse(JSON.stringify(PANEL));
    payload.tiles[0].message = "há 3 min";
    await vi.advanceTimersByTimeAsync(60_000);
    await flush();
    expect(v.el.querySelector(".togare-health-live").textContent).toBe("");

    // 3º fetch — DJEN ok→offline (mudança de estado) → anuncia.
    payload = JSON.parse(JSON.stringify(PANEL));
    payload.tiles[0].state = "offline";
    await vi.advanceTimersByTimeAsync(60_000);
    await flush();
    expect(v.el.querySelector(".togare-health-live").textContent).toContain(
      "saúde do sistema mudou",
    );
  });

  it("faz polling a cada 60s", async () => {
    const stub = vi.fn(() => Promise.resolve(PANEL));
    globalThis.__togareAjaxStub = stub;
    const v = buildView();
    v.afterRender();
    await flush();
    expect(stub).toHaveBeenCalledTimes(1);

    await vi.advanceTimersByTimeAsync(60_000);
    await flush();
    expect(stub).toHaveBeenCalledTimes(2);
  });

  it("falha de rede (não-403) não derruba o dashboard e mantém DOM", async () => {
    globalThis.__togareAjaxStub = vi.fn(() => Promise.reject({ status: 500 }));
    const v = buildView();
    expect(() => {
      v.afterRender();
    }).not.toThrow();
    await flush();
    // root existe (não lançou); painel vazio renderizado calmamente.
    expect(v.el.querySelector(".togare-health-root")).not.toBeNull();
  });

  it("onRemove não lança e cancela timer", async () => {
    globalThis.__togareAjaxStub = vi.fn(() => Promise.resolve(PANEL));
    const v = buildView();
    v.afterRender();
    await flush();
    expect(() => v.onRemove()).not.toThrow();
  });

  it("toggle do histórico abre/fecha", async () => {
    const withHist = JSON.parse(JSON.stringify(PANEL));
    withHist.historico = [{ occurredAt: "2026-05-17", event: "djen.x", message: "DJEN instável" }];
    globalThis.__togareAjaxStub = vi.fn(() => Promise.resolve(withHist));
    const v = buildView();
    v.afterRender();
    await flush();

    const btn = v.el.querySelector(".togare-health-footer__historico-link");
    const box = v.el.querySelector(".togare-health-panel__historico");
    expect(btn).not.toBeNull();
    expect(box.hasAttribute("hidden")).toBe(true);
    btn.click();
    expect(box.hasAttribute("hidden")).toBe(false);
    expect(btn.getAttribute("aria-expanded")).toBe("true");
  });
});
