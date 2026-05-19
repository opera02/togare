/**
 * Testes da view DocumentoListView (Stories 5.2 + 5.3).
 *
 * Cobre: actionDownload (download real), actionRemove (confirm + deleteRequest +
 * collection.fetch), actionQuickRemove (delega a actionRemove).
 *
 * `views/record/list` é mockado via alias em vitest.config.js.
 */

import { describe, it, expect, vi, beforeEach } from "vitest";

// setTimeout faz fila de macrotask — garante que toda cadeia de microtasks
// de promises pendentes seja drenada antes de continuar o teste.
const flushPromises = () => new Promise((resolve) => setTimeout(resolve, 0));
import DocumentoListView from "togare-core:views/document/record/list";

const makeCollection = () => ({
  fetch: vi.fn(),
  models: [],
});

const makeView = (overrides = {}) => {
  const view = Object.create(DocumentoListView.prototype);
  view.collection = makeCollection();
  view.translate = vi.fn((k) => k);
  Object.assign(view, overrides);
  return view;
};

const mockEspo = (ajax = {}, ui = {}) => {
  globalThis.Espo = {
    Ajax: {
      getRequest: vi.fn().mockResolvedValue({}),
      deleteRequest: vi.fn().mockResolvedValue({}),
      ...ajax,
    },
    Ui: {
      warning: vi.fn(),
      success: vi.fn(),
      error: vi.fn(),
      confirm: vi.fn(),
      ...ui,
    },
  };
};

beforeEach(() => {
  mockEspo();
});

describe("DocumentoListView — actionDownload", () => {
  it("abre iframe com endpoint canônico de download", () => {
    const opened = [];
    const view = makeView({
      _openDownloadUrl: vi.fn((url) => opened.push(url)),
    });

    view.actionDownload({ id: "abc123" });

    expect(view._openDownloadUrl).toHaveBeenCalledTimes(1);
    expect(opened[0]).toBe("api/v1/Documento/action/download?id=abc123");
  });

  it("não faz nada quando data.id está ausente", () => {
    const view = makeView({ _openDownloadUrl: vi.fn() });
    view.actionDownload({});
    view.actionDownload(null);
    view.actionDownload(undefined);
    expect(view._openDownloadUrl).not.toHaveBeenCalled();
  });

  it("codifica id antes de montar URL", () => {
    const view = makeView({ _openDownloadUrl: vi.fn() });
    view.actionDownload({ id: "doc 1/2" });
    expect(view._openDownloadUrl).toHaveBeenCalledWith(
      "api/v1/Documento/action/download?id=doc%201%2F2",
    );
  });

  it("cria iframe oculto para iniciar download sem sair da SPA", () => {
    const appended = [];
    const fakeIframe = {
      setAttribute: vi.fn(),
      style: {},
      parentNode: { removeChild: vi.fn() },
    };
    const originalDocument = globalThis.document;
    const originalWindow = globalThis.window;
    globalThis.document = {
      body: {
        appendChild: vi.fn((node) => appended.push(node)),
      },
      createElement: vi.fn(() => fakeIframe),
    };
    globalThis.window = {
      setTimeout: vi.fn((fn) => fn()),
    };

    const view = makeView();

    try {
      view._openDownloadUrl("api/v1/Documento/action/download?id=abc");
    } finally {
      globalThis.document = originalDocument;
      globalThis.window = originalWindow;
    }

    expect(fakeIframe.setAttribute).toHaveBeenCalledWith("aria-hidden", "true");
    expect(fakeIframe.style.display).toBe("none");
    expect(fakeIframe.src).toBe("api/v1/Documento/action/download?id=abc");
    expect(appended).toEqual([fakeIframe]);
    expect(fakeIframe.parentNode.removeChild).toHaveBeenCalledWith(fakeIframe);
  });
});

describe("DocumentoListView — actionRemove", () => {
  it("não faz nada quando data.id está ausente", () => {
    const view = makeView();
    view.actionRemove({});
    view.actionRemove(null);
    expect(globalThis.Espo.Ui.confirm).not.toHaveBeenCalled();
  });

  it("chama Espo.Ui.confirm com mensagem sobre Nextcloud", () => {
    const view = makeView();
    view.translate = vi.fn((k) => {
      if (k === "removeConfirm") return "Remover? Lixeira Nextcloud 30 dias.";
      return k;
    });
    view.actionRemove({ id: "xyz" });
    const confirmCall = globalThis.Espo.Ui.confirm.mock.calls[0];
    expect(confirmCall[0]).toContain("Nextcloud");
  });

  it("chama deleteRequest e collection.fetch após confirmação", async () => {
    globalThis.Espo.Ui.confirm = vi.fn((msg, opts, cb) => cb());
    globalThis.Espo.Ajax.deleteRequest = vi.fn().mockResolvedValue({});
    const view = makeView();
    view.translate = vi.fn((k) => (k === "removeConfirm" ? "Nextcloud msg" : k));
    view.actionRemove({ id: "doc99" });
    expect(globalThis.Espo.Ajax.deleteRequest).toHaveBeenCalledWith("Documento/doc99");
    await flushPromises();
    expect(view.collection.fetch).toHaveBeenCalled();
  });

  it("exibe purgeFailed em caso de erro no deleteRequest", async () => {
    globalThis.Espo.Ui.confirm = vi.fn((msg, opts, cb) => cb());
    globalThis.Espo.Ajax.deleteRequest = vi.fn().mockRejectedValue({ responseJSON: null });
    const view = makeView();
    view.translate = vi.fn((k) => (k === "removeConfirm" ? "Nextcloud msg" : k));
    view.actionRemove({ id: "doc99" });
    await flushPromises();
    expect(globalThis.Espo.Ui.error).toHaveBeenCalled();
  });
});

describe("DocumentoListView — actionQuickRemove", () => {
  it("delega para actionRemove", () => {
    const view = makeView();
    view.actionRemove = vi.fn();
    const data = { id: "qr1" };
    view.actionQuickRemove(data);
    expect(view.actionRemove).toHaveBeenCalledWith(data);
  });
});
