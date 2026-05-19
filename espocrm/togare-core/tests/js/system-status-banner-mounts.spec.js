import { describe, it, expect, vi, beforeEach } from "vitest";
import PrazoListView from "../../src/files/client/custom/modules/togare-core/src/views/prazo/record/list.js";
import PrazoDetailView from "../../src/files/client/custom/modules/togare-core/src/views/prazo/record/detail.js";
import PublicacaoAmbiguaListView from "../../src/files/client/custom/modules/togare-core/src/views/publicacao-ambigua/record/list.js";
import TogarePrazosDoDiaDashletView from "../../src/files/client/custom/modules/togare-core/src/views/dashlets/togare-prazos-do-dia.js";

/**
 * Story 4b.4 AC12 — banner mounted em 3 das 4 surfaces:
 *   1. Prazo list view custom (extends views/record/list)
 *   2. Prazo detail view custom (extends views/record/detail)
 *   3. PublicacaoAmbigua list view custom (extends views/record/list)
 *   4. Dashlet TogarePrazosDoDia (extends views/dashlets/abstract/record-list)
 */

const SYSTEM_STATUS_BANNER_VIEW = "togare-core:views/common/system-status-banner";

describe("SystemStatusBanner mounts (Story 4b.4 AC12)", () => {
  beforeEach(() => {
    delete globalThis.__togareAjaxStub;
    document.body.innerHTML = "";
  });

  function attachRoot(html = '<div class="list-container"></div>') {
    const root = document.createElement("div");
    root.innerHTML = html;
    document.body.appendChild(root);
    return root;
  }

  function makeCreateViewSpy() {
    return vi.fn((_name, _viewName, _options, callback) => {
      const child = { render: vi.fn() };
      if (typeof callback === "function") callback(child);
      return Promise.resolve(child);
    });
  }

  it("PrazoListView monta systemStatusBanner em placeholder DOM no afterRender", async () => {
    const view = new PrazoListView();
    view.el = attachRoot();
    const createViewSpy = makeCreateViewSpy();
    view.createView = createViewSpy;

    view.afterRender();

    const mount = view.el.querySelector("[data-role='togare-system-status-banner-mount']");
    expect(mount).not.toBeNull();
    expect(createViewSpy).toHaveBeenCalledWith(
      "systemStatusBanner",
      SYSTEM_STATUS_BANNER_VIEW,
      expect.objectContaining({ el: expect.stringMatching(/^#togare-system-status-banner-mount-/) }),
      expect.any(Function),
    );
  });

  it("PrazoDetailView monta systemStatusBanner na surface detail", async () => {
    const view = new PrazoDetailView();
    view.model = { attributes: {}, on: vi.fn() };
    view.listenTo = vi.fn();
    view.el = attachRoot('<div class="detail"></div>');
    const createViewSpy = makeCreateViewSpy();
    view.createView = createViewSpy;

    view.afterRender();

    const mount = view.el.querySelector("[data-role='togare-system-status-banner-mount']");
    expect(mount).not.toBeNull();
    expect(createViewSpy).toHaveBeenCalledWith(
      "systemStatusBanner",
      SYSTEM_STATUS_BANNER_VIEW,
      expect.objectContaining({ el: expect.stringMatching(/^#togare-system-status-banner-mount-/) }),
      expect.any(Function),
    );
  });

  it("PublicacaoAmbiguaListView monta systemStatusBanner no afterRender (após mass-action registration)", async () => {
    const view = new PublicacaoAmbiguaListView();
    view.el = attachRoot();
    const createViewSpy = makeCreateViewSpy();
    view.createView = createViewSpy;

    view.setup();
    view.afterRender();

    const mount = view.el.querySelector("[data-role='togare-system-status-banner-mount']");
    expect(mount).not.toBeNull();
    expect(createViewSpy).toHaveBeenCalledWith(
      "systemStatusBanner",
      SYSTEM_STATUS_BANNER_VIEW,
      expect.objectContaining({ el: expect.stringMatching(/^#togare-system-status-banner-mount-/) }),
      expect.any(Function),
    );
    // Mass-action da 4b.1c continua registrada (não-regressão).
    expect(view.massActionList).toContain("bulkIgnoreProcesso");
  });

  it("TogarePrazosDoDiaDashletView monta systemStatusBanner no afterRender", async () => {
    const view = new TogarePrazosDoDiaDashletView();
    view.element = attachRoot('<div class="list-container"></div>');
    const createViewSpy = makeCreateViewSpy();
    view.createView = createViewSpy;

    view.afterRender();

    // _mountSystemStatusBanner é chamado dentro do afterRender.
    const mount = view.element.querySelector("[data-role='togare-system-status-banner-mount']");
    expect(mount).not.toBeNull();
    expect(createViewSpy).toHaveBeenCalledWith(
      "systemStatusBanner",
      SYSTEM_STATUS_BANNER_VIEW,
      expect.objectContaining({ el: expect.stringMatching(/^#togare-system-status-banner-mount-/) }),
      expect.any(Function),
    );
  });

  it("TogarePrazosDoDiaDashletView NÃO monta banner duas vezes em re-renders (idempotência)", async () => {
    const view = new TogarePrazosDoDiaDashletView();
    view.element = attachRoot('<div class="list-container"></div>');
    const createViewSpy = makeCreateViewSpy();
    view.createView = createViewSpy;

    view.afterRender();
    // Filtra apenas chamadas relacionadas ao systemStatusBanner.
    const firstCalls = createViewSpy.mock.calls.filter(
      (args) => args[0] === "systemStatusBanner",
    ).length;
    expect(firstCalls).toBe(1);

    view.afterRender();
    const secondCalls = createViewSpy.mock.calls.filter(
      (args) => args[0] === "systemStatusBanner",
    ).length;
    expect(secondCalls).toBe(1); // ainda 1 — idempotente
  });

  it("erro no createView NÃO derruba a list view (fail-safe)", async () => {
    const view = new PrazoListView();
    view.el = attachRoot();
    const createViewSpy = vi.fn().mockRejectedValue(new Error("fail"));
    view.createView = createViewSpy;

    expect(() => view.afterRender()).not.toThrow();
  });
});
