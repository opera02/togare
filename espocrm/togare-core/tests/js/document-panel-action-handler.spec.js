/**
 * Testes do panel action handler `DocumentoPanelActionHandler` (Story 5.6).
 *
 * Cobre os 3 branches de `actionAnexarDocumento`:
 *  - entityType='Processo' → options.processoId + processoName (numeroCnj || numeroProcessoOriginal || name).
 *  - entityType='Cliente'  → options.clienteId + clienteName (name).
 *  - entityType='Prazo'    → options.prazoId + prazoName (atoCodigo || referenciaLegal || numeroProcessoOriginal).
 *
 * Também cobre o fall-through: entityType desconhecido NÃO invoca createView.
 */

import { describe, it, expect, vi, beforeEach } from "vitest";
import DocumentoPanelActionHandler from "togare-core:handlers/documento/panel-action-handler";

function makePanelView(modelOverrides = {}) {
  const model = {
    entityType: modelOverrides.entityType ?? "Processo",
    _attrs: modelOverrides.attrs ?? { id: "abc123" },
    get(key) {
      return this._attrs[key];
    },
  };
  return {
    model,
    createView: vi.fn(),
    listenToOnce: vi.fn(),
    actionRefresh: vi.fn(),
  };
}

describe("DocumentoPanelActionHandler — actionAnexarDocumento (Story 5.6)", () => {
  let createViewArgs;
  beforeEach(() => {
    createViewArgs = null;
  });

  it("entityType=Prazo popula options.prazoId e prazoName (atoCodigo prioritário)", () => {
    const panelView = makePanelView({
      entityType: "Prazo",
      attrs: {
        id: "prz-001",
        atoCodigo: "cumprimento_sentenca",
        referenciaLegal: "CPC art. 523",
        numeroProcessoOriginal: "12345",
      },
    });
    const handler = new DocumentoPanelActionHandler(panelView);

    handler.actionAnexarDocumento({}, new Event("click"));

    expect(panelView.createView).toHaveBeenCalledTimes(1);
    const [name, viewPath, options] = panelView.createView.mock.calls[0];
    expect(name).toBe("documentoUpload");
    expect(viewPath).toBe("togare-core:views/document/upload-modal");
    expect(options.prazoId).toBe("prz-001");
    expect(options.prazoName).toBe("cumprimento_sentenca");
    expect(options.processoId).toBeUndefined();
    expect(options.clienteId).toBeUndefined();
  });

  it("entityType=Prazo cai em referenciaLegal quando atoCodigo é vazio", () => {
    const panelView = makePanelView({
      entityType: "Prazo",
      attrs: {
        id: "prz-002",
        atoCodigo: "",
        referenciaLegal: "CPC art. 523",
        numeroProcessoOriginal: "12345",
      },
    });
    const handler = new DocumentoPanelActionHandler(panelView);

    handler.actionAnexarDocumento({}, new Event("click"));

    const [, , options] = panelView.createView.mock.calls[0];
    expect(options.prazoName).toBe("CPC art. 523");
  });

  it("entityType desconhecido (Audiencia) NÃO chama createView", () => {
    const panelView = makePanelView({
      entityType: "Audiencia",
      attrs: { id: "aud-001", name: "audiencia X" },
    });
    const handler = new DocumentoPanelActionHandler(panelView);

    handler.actionAnexarDocumento({}, new Event("click"));

    expect(panelView.createView).not.toHaveBeenCalled();
  });

  it("não-regressão Processo: ainda popula processoId + numeroCnj", () => {
    const panelView = makePanelView({
      entityType: "Processo",
      attrs: {
        id: "proc-001",
        numeroCnj: "0001234-56.2026.8.26.0100",
        name: "Processo X",
      },
    });
    const handler = new DocumentoPanelActionHandler(panelView);

    handler.actionAnexarDocumento({}, new Event("click"));

    const [, , options] = panelView.createView.mock.calls[0];
    expect(options.processoId).toBe("proc-001");
    expect(options.processoName).toBe("0001234-56.2026.8.26.0100");
    expect(options.prazoId).toBeUndefined();
  });

  it("não-regressão Cliente: ainda popula clienteId + name", () => {
    const panelView = makePanelView({
      entityType: "Cliente",
      attrs: { id: "cli-001", name: "Acme Ltda" },
    });
    const handler = new DocumentoPanelActionHandler(panelView);

    handler.actionAnexarDocumento({}, new Event("click"));

    const [, , options] = panelView.createView.mock.calls[0];
    expect(options.clienteId).toBe("cli-001");
    expect(options.clienteName).toBe("Acme Ltda");
    expect(options.prazoId).toBeUndefined();
  });
});
