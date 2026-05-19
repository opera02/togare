/**
 * Testes do helper `iconForMimeType` da view `mime-icon` (Story 5.2, AC #14).
 *
 * Cobre o mapping MIME→ícone Unicode usado em list views de Documento.
 */

import { describe, it, expect } from "vitest";
import MimeIconFieldView, {
  iconForMimeType,
} from "togare-core:views/document/fields/mime-icon";

describe("document/fields/mime-icon — iconForMimeType", () => {
  it("retorna 📄 para application/pdf", () => {
    expect(iconForMimeType("application/pdf")).toBe("📄");
  });

  it("retorna 📝 para DOCX (Open XML)", () => {
    expect(
      iconForMimeType(
        "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
      ),
    ).toBe("📝");
  });

  it("retorna 📝 para DOC legacy (application/msword)", () => {
    expect(iconForMimeType("application/msword")).toBe("📝");
  });

  it("retorna 📊 para XLSX (Open XML)", () => {
    expect(
      iconForMimeType(
        "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
      ),
    ).toBe("📊");
  });

  it("retorna 📊 para XLS legacy (application/vnd.ms-excel)", () => {
    expect(iconForMimeType("application/vnd.ms-excel")).toBe("📊");
  });

  it("retorna 🖼️ para PNG", () => {
    expect(iconForMimeType("image/png")).toBe("🖼️");
  });

  it("retorna 🖼️ para JPEG", () => {
    expect(iconForMimeType("image/jpeg")).toBe("🖼️");
  });

  it("retorna 🖼️ para TIFF", () => {
    expect(iconForMimeType("image/tiff")).toBe("🖼️");
  });

  it("retorna 🖼️ para qualquer image/* não listado (fallback genérico)", () => {
    expect(iconForMimeType("image/heic")).toBe("🖼️");
    expect(iconForMimeType("image/svg+xml")).toBe("🖼️");
  });

  it("retorna 📃 para text/plain", () => {
    expect(iconForMimeType("text/plain")).toBe("📃");
  });

  it("retorna 📎 (default) para MIME desconhecido", () => {
    expect(iconForMimeType("application/x-msdownload")).toBe("📎");
    expect(iconForMimeType("application/octet-stream")).toBe("📎");
    expect(iconForMimeType("application/zip")).toBe("📎");
  });

  it("retorna 📎 para mimeType null/undefined/empty (defensivo)", () => {
    expect(iconForMimeType(null)).toBe("📎");
    expect(iconForMimeType(undefined)).toBe("📎");
    expect(iconForMimeType("")).toBe("📎");
  });

  it("retorna 📎 para tipo não-string (defensivo)", () => {
    expect(iconForMimeType(123)).toBe("📎");
    expect(iconForMimeType({})).toBe("📎");
    expect(iconForMimeType([])).toBe("📎");
  });
});

/**
 * Story 5.7-followup gap (d) ROUND 3 — DOM patch via afterRender.
 *
 * Hist. dos rounds:
 *  - ROUND 1 (templateContent): NÃO funcionou — VarcharFieldView Espo 9.x
 *    bypassa templateContent em modo detail.
 *  - ROUND 2 (detailTemplateContent): NÃO funcionou em runtime real
 *    (smoke browser do Felipe 2026-05-12 confirmou DOM `<span class="">📄</span>`
 *    sem class/title/aria-label) — mesmo com bundle correto, o render do parent
 *    bypassa essas propriedades.
 *  - ROUND 3 (afterRender DOM patch): substituir o wrapper hardcoded via
 *    DOM patch direto. Vanilla DOM (setAttribute escapa valores; textContent
 *    não interpreta HTML) = XSS-safe sem depender do escape do Handlebars.
 *    Aplicado em modos de display (detail/list). Edit/search/filter são no-op
 *    para preservar inputs/controles nativos do Espo.
 */
describe("document/fields/mime-icon — MimeIconFieldView afterRender DOM patch (Story 5.7-followup)", () => {
  const mountStubDom = (mimeType) => {
    const root = document.createElement("div");
    root.className = "field";
    root.setAttribute("data-name", "mimeType");
    // Wrapper inicial que o Espo gera (placeholder) — afterRender deve substituir.
    const initialSpan = document.createElement("span");
    initialSpan.className = "";
    initialSpan.textContent = "📄";
    root.appendChild(initialSpan);
    document.body.appendChild(root);
    const view = Object.create(MimeIconFieldView.prototype);
    view.mode = "detail";
    view.model = { get: (k) => (k === "mimeType" ? mimeType : "") };
    view.$el = { 0: root, length: 1 };
    return { view, root };
  };

  it("afterRender em modo detail substitui o wrapper hardcoded por <span class=\"togare-mime-icon\"> com title + aria-label", () => {
    const { view, root } = mountStubDom("application/pdf");
    view.afterRender();
    const span = root.querySelector("span");
    expect(span).not.toBeNull();
    expect(span.className).toBe("togare-mime-icon");
    expect(span.getAttribute("title")).toBe("application/pdf");
    expect(span.getAttribute("aria-label")).toBe("application/pdf");
    expect(span.textContent).toBe("📄");
    document.body.removeChild(root);
  });

  it("afterRender em modo list aplica o mesmo patch", () => {
    const { view, root } = mountStubDom(
      "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    );
    view.mode = "list";
    view.afterRender();
    const span = root.querySelector("span");
    expect(span.className).toBe("togare-mime-icon");
    expect(span.getAttribute("title")).toContain("wordprocessingml");
    expect(span.textContent).toBe("📝");
    document.body.removeChild(root);
  });

  it("afterRender em modo edit é no-op (mimeType é readOnly — não exercita edit)", () => {
    const { view, root } = mountStubDom("application/pdf");
    view.mode = "edit";
    view.afterRender();
    // Não tocou — wrapper inicial preservado.
    const span = root.querySelector("span");
    expect(span.className).toBe("");
    expect(span.getAttribute("title")).toBeNull();
    document.body.removeChild(root);
  });

  it("afterRender em modo search é no-op e preserva controles de filtro", () => {
    const { view, root } = mountStubDom("application/pdf");
    view.mode = "search";
    const input = document.createElement("input");
    input.name = "mimeType";
    input.value = "application/pdf";
    root.appendChild(input);
    view.afterRender();
    expect(root.querySelector("input[name='mimeType']")).toBe(input);
    expect(root.querySelector(".togare-mime-icon")).toBeNull();
    document.body.removeChild(root);
  });

  it("afterRender substitui só o span de display e preserva controles extras", () => {
    const { view, root } = mountStubDom("application/pdf");
    const button = document.createElement("button");
    button.type = "button";
    button.textContent = "acao";
    root.appendChild(button);
    view.afterRender();
    expect(root.querySelector(".togare-mime-icon")).not.toBeNull();
    expect(root.querySelector("button")).toBe(button);
    expect(root.children.length).toBe(2);
    document.body.removeChild(root);
  });

  it("afterRender com mimeType vazio renderiza só o ícone default 📎 sem title/aria-label", () => {
    const { view, root } = mountStubDom("");
    view.afterRender();
    const span = root.querySelector("span");
    expect(span.className).toBe("togare-mime-icon");
    expect(span.textContent).toBe("📎");
    expect(span.getAttribute("title")).toBeNull();
    expect(span.getAttribute("aria-label")).toBeNull();
    document.body.removeChild(root);
  });

  it("afterRender é defensivo contra $el ausente (não quebra)", () => {
    const view = Object.create(MimeIconFieldView.prototype);
    view.mode = "detail";
    view.model = { get: () => "application/pdf" };
    view.$el = null;
    expect(() => view.afterRender()).not.toThrow();
  });

  it("afterRender escapa MIME type malicioso nos atributos (defesa em profundidade)", () => {
    // setAttribute escapa automaticamente — payload XSS no MIME type
    // fica como string literal nos atributos, não vira tag <script>.
    const { view, root } = mountStubDom('"><script>alert(1)</script>');
    view.afterRender();
    const span = root.querySelector("span");
    // setAttribute escapa "; o atributo title contém o payload literal
    // (não interpretado como HTML).
    expect(span.getAttribute("title")).toBe('"><script>alert(1)</script>');
    // Não deve haver <script> tag injetada no DOM.
    expect(root.querySelector("script")).toBeNull();
    document.body.removeChild(root);
  });

  it("getValueForDisplay retorna apenas o ícone Unicode (compat export/print)", () => {
    const view = Object.create(MimeIconFieldView.prototype);
    view.model = { get: (k) => (k === "mimeType" ? "application/pdf" : "") };
    const out = view.getValueForDisplay();
    expect(out).toBe("📄");
    expect(out).not.toMatch(/<span/);
  });

  it("getValueForDisplay retorna 📎 (default) quando model não tem mimeType", () => {
    const view = Object.create(MimeIconFieldView.prototype);
    view.model = { get: () => "" };
    expect(view.getValueForDisplay()).toBe("📎");
  });
});
