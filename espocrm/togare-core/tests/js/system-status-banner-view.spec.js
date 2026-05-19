import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import SystemStatusBannerView from "../../src/files/client/custom/modules/togare-core/src/views/common/system-status-banner.js";

/**
 * Story 4b.4 — SystemStatusBannerView (FR18, NFR19, ADR 0009).
 *
 * Cobre AC9 (renderização condicional), AC10 (polling 60s + visibility pause),
 * AC11 (texto literal pt-BR + sem `<a>`/`<button>`).
 *
 * Stub do Espo.Ajax.getRequest via `globalThis.__togareAjaxStub` (resolver
 * interno da view consulta esse global em testes).
 */

function buildView(options = {}) {
  const v = new SystemStatusBannerView(options);
  v.getLanguage = () => ({
    translate: (key, category, scope) => {
      if (scope === "SystemStatusBanner" && category === "messages" && key === "djenUnavailable") {
        return "Sync DJEN pausada há {N}min. Próxima tentativa às {HH:MM}.";
      }
      return key;
    },
  });
  // Override `renderHtml` para gerar markup determinístico (templates reais
  // do EspoCRM são compilados Handlebars; mock devolve HTML equivalente).
  v.renderHtml = (d) => `
    <div class="togare-system-status-banner togare-system-status-banner--warning"
         role="status" aria-live="polite" data-visible="${d.visible}">
      <span class="togare-system-status-banner__icon" aria-hidden="true">⚠</span>
      <span class="togare-system-status-banner__text">${d.message}</span>
    </div>
  `;
  // Chama setup() explicitamente — em runtime EspoCRM o factory chama
  // antes de render(); em testes vitest precisa explícito.
  v.setup();
  return v;
}

describe("SystemStatusBannerView", () => {
  let container;
  const _activeViews = [];

  // Wrapper que rastreia views criadas para cleanup global no afterEach
  // (garante que listeners de visibilitychange não vazam entre cenários).
  function trackView(v) {
    _activeViews.push(v);
    return v;
  }

  beforeEach(() => {
    vi.useFakeTimers();
    container = document.createElement("div");
    document.body.appendChild(container);
    delete globalThis.__togareAjaxStub;
  });

  afterEach(() => {
    while (_activeViews.length > 0) {
      const v = _activeViews.pop();
      try { v.onRemove(); } catch { /* defensivo */ }
    }
    vi.useRealTimers();
    delete globalThis.__togareAjaxStub;
    document.body.innerHTML = "";
  });

  it("renderiza invisível inicial até fetch completar", async () => {
    let resolveFn;
    globalThis.__togareAjaxStub = vi.fn(
      () => new Promise((resolve) => { resolveFn = resolve; }),
    );

    const view = trackView(buildView());
    view.setElement(container);
    await view.render();

    const root = container.querySelector(".togare-system-status-banner");
    expect(root).not.toBeNull();
    // Antes do fetch resolver: data-visible="false"
    expect(root.getAttribute("data-visible")).toBe("false");

    // Resolve fetch e re-render
    resolveFn({ cbOpen: false, minutesOpen: 0 });
    await Promise.resolve();
    expect(root.getAttribute("data-visible")).toBe("false");
  });

  it("renderiza visível com cbOpen=true E minutesOpen >= 30", async () => {
    globalThis.__togareAjaxStub = vi.fn().mockResolvedValue({
      cbOpen: true,
      openedAt: "2026-05-09T14:00:00-03:00",
      openUntil: "2026-05-09T14:35:00-03:00",
      minutesOpen: 35,
      nextRetryHint: "14:35",
    });

    const view = trackView(buildView());
    view.setElement(container);
    await view.render();
    await vi.advanceTimersByTimeAsync(0);
    await Promise.resolve();
    await Promise.resolve();

    const root = container.querySelector(".togare-system-status-banner");
    expect(root.getAttribute("data-visible")).toBe("true");
    const textEl = root.querySelector(".togare-system-status-banner__text");
    expect(textEl.textContent).toBe("Sync DJEN pausada há 35min. Próxima tentativa às 14:35.");
  });

  it("renderiza invisível quando cbOpen=true mas minutesOpen < 30 (limiar não atingido)", async () => {
    globalThis.__togareAjaxStub = vi.fn().mockResolvedValue({
      cbOpen: true,
      minutesOpen: 12,
      nextRetryHint: "14:35",
    });

    const view = trackView(buildView());
    view.setElement(container);
    await view.render();
    await vi.advanceTimersByTimeAsync(0);
    await Promise.resolve();
    await Promise.resolve();

    const root = container.querySelector(".togare-system-status-banner");
    expect(root.getAttribute("data-visible")).toBe("false");
  });

  it("renderiza invisível quando cbOpen=false", async () => {
    globalThis.__togareAjaxStub = vi.fn().mockResolvedValue({
      cbOpen: false,
      minutesOpen: 0,
      nextRetryHint: null,
    });

    const view = trackView(buildView());
    view.setElement(container);
    await view.render();
    await vi.advanceTimersByTimeAsync(0);
    await Promise.resolve();
    await Promise.resolve();

    const root = container.querySelector(".togare-system-status-banner");
    expect(root.getAttribute("data-visible")).toBe("false");
  });

  it("texto NUNCA contém <a> ou <button> (Discovery #1 — sem link Ver status)", async () => {
    globalThis.__togareAjaxStub = vi.fn().mockResolvedValue({
      cbOpen: true,
      minutesOpen: 45,
      nextRetryHint: "15:00",
    });

    const view = trackView(buildView());
    view.setElement(container);
    await view.render();
    await Promise.resolve();
    await Promise.resolve();

    const root = container.querySelector(".togare-system-status-banner");
    expect(root.querySelector("a")).toBeNull();
    expect(root.querySelector("button")).toBeNull();
  });

  it("substitui {N} e {HH:MM} corretamente no texto", async () => {
    globalThis.__togareAjaxStub = vi.fn().mockResolvedValue({
      cbOpen: true,
      minutesOpen: 45,
      nextRetryHint: "15:30",
    });

    const view = trackView(buildView());
    view.setElement(container);
    await view.render();
    await Promise.resolve();
    await Promise.resolve();

    const textEl = container.querySelector(".togare-system-status-banner__text");
    expect(textEl.textContent).toBe("Sync DJEN pausada há 45min. Próxima tentativa às 15:30.");
  });

  it("fetch com erro mantém invisível e loga warning", async () => {
    const consoleSpy = vi.spyOn(console, "warn").mockImplementation(() => {});
    globalThis.__togareAjaxStub = vi.fn().mockRejectedValue(new Error("network error"));

    const view = trackView(buildView());
    view.setElement(container);
    await view.render();
    // Flush pending microtasks (rejected promise → catch handler é async).
    await vi.advanceTimersByTimeAsync(0);
    await Promise.resolve();
    await Promise.resolve();

    const root = container.querySelector(".togare-system-status-banner");
    expect(root.getAttribute("data-visible")).toBe("false");
    expect(consoleSpy).toHaveBeenCalledWith(
      expect.stringContaining("snapshot failed"),
      expect.anything(),
    );
    consoleSpy.mockRestore();
  });

  it("polling dispara novo fetch após 60s", async () => {
    let callCount = 0;
    globalThis.__togareAjaxStub = vi.fn(() => {
      callCount++;
      return Promise.resolve({ cbOpen: false, minutesOpen: 0 });
    });

    const view = trackView(buildView());
    view.setElement(container);
    await view.render();
    await Promise.resolve();
    await Promise.resolve();
    expect(callCount).toBe(1);

    await vi.advanceTimersByTimeAsync(60_000);
    await Promise.resolve();
    expect(callCount).toBe(2);
  });

  it("visibilitychange para hidden cancela timer pendente", async () => {
    globalThis.__togareAjaxStub = vi.fn().mockResolvedValue({ cbOpen: false, minutesOpen: 0 });

    const view = trackView(buildView());
    view.setElement(container);
    await view.render();
    await Promise.resolve();
    await Promise.resolve();

    // Simula transição para hidden
    Object.defineProperty(document, "visibilityState", { value: "hidden", configurable: true });
    document.dispatchEvent(new Event("visibilitychange"));

    // Avança 60s — fetch NÃO deve disparar pois timer foi cancelado.
    const callsBefore = globalThis.__togareAjaxStub.mock.calls.length;
    await vi.advanceTimersByTimeAsync(60_000);
    await Promise.resolve();
    const callsAfter = globalThis.__togareAjaxStub.mock.calls.length;
    expect(callsAfter).toBe(callsBefore);
  });

  it("visibilitychange para visible dispara fetch imediato", async () => {
    globalThis.__togareAjaxStub = vi.fn().mockResolvedValue({ cbOpen: false, minutesOpen: 0 });

    const view = trackView(buildView());
    view.setElement(container);
    await view.render();
    await Promise.resolve();
    await Promise.resolve();

    // Hidden → cancela
    Object.defineProperty(document, "visibilityState", { value: "hidden", configurable: true });
    document.dispatchEvent(new Event("visibilitychange"));
    const callsHidden = globalThis.__togareAjaxStub.mock.calls.length;

    // Visible → dispara imediato
    Object.defineProperty(document, "visibilityState", { value: "visible", configurable: true });
    document.dispatchEvent(new Event("visibilitychange"));
    await Promise.resolve();
    expect(globalThis.__togareAjaxStub.mock.calls.length).toBe(callsHidden + 1);
  });

  it("onRemove cancela timer e remove visibilitychange listener", async () => {
    globalThis.__togareAjaxStub = vi.fn().mockResolvedValue({ cbOpen: false, minutesOpen: 0 });

    const view = trackView(buildView());
    view.setElement(container);
    await view.render();
    await Promise.resolve();
    await Promise.resolve();

    const removeSpy = vi.spyOn(document, "removeEventListener");
    view.onRemove();

    // Re-visiability change não deve disparar fetch (listener removido).
    const callsBefore = globalThis.__togareAjaxStub.mock.calls.length;
    Object.defineProperty(document, "visibilityState", { value: "visible", configurable: true });
    document.dispatchEvent(new Event("visibilitychange"));
    await Promise.resolve();
    expect(globalThis.__togareAjaxStub.mock.calls.length).toBe(callsBefore);

    expect(removeSpy).toHaveBeenCalledWith("visibilitychange", expect.any(Function));
    removeSpy.mockRestore();
  });
});
