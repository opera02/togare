/**
 * MimeIconFieldView — ícone visual por MIME type (Story 5.2).
 *
 * Renderização list/detail mode com mapping MIME→ícone Unicode (FontAwesome
 * via Espo é alternativa; aqui usamos Unicode pra zero dependência de fonte).
 *
 * Mapping:
 *  - application/pdf  → 📄
 *  - application/vnd.*.wordprocessingml.document → 📝 (DOCX)
 *  - application/msword → 📝 (DOC)
 *  - application/vnd.*.spreadsheetml.sheet → 📊 (XLSX)
 *  - application/vnd.ms-excel → 📊 (XLS)
 *  - image/* → 🖼️
 *  - text/plain → 📃
 *  - default → 📎
 *
 * @example
 *   {"name": "mimeType", "view": "togare-core:views/document/fields/mime-icon"}
 */
import VarcharFieldView from "views/fields/varchar";

const MIME_ICON_MAP = {
  "application/pdf": "📄",
  "application/vnd.openxmlformats-officedocument.wordprocessingml.document": "📝",
  "application/msword": "📝",
  "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet": "📊",
  "application/vnd.ms-excel": "📊",
  "image/png": "🖼️",
  "image/jpeg": "🖼️",
  "image/tiff": "🖼️",
  "text/plain": "📃",
};

const DEFAULT_ICON = "📎";

export function iconForMimeType(mimeType) {
  if (!mimeType || typeof mimeType !== "string") {
    return DEFAULT_ICON;
  }
  if (Object.prototype.hasOwnProperty.call(MIME_ICON_MAP, mimeType)) {
    return MIME_ICON_MAP[mimeType];
  }
  if (mimeType.indexOf("image/") === 0) {
    return "🖼️";
  }
  return DEFAULT_ICON;
}

export default class MimeIconFieldView extends VarcharFieldView {
  data() {
    const data = super.data();
    const mimeType = (this.model && this.model.get("mimeType")) || "";
    data.mimeIcon = iconForMimeType(mimeType);
    data.mimeType = mimeType;
    data.mimeTitle = mimeType ? mimeType : "";
    return data;
  }

  // Mantido para compatibilidade com fluxos do Espo que ainda chamam
  // getValueForDisplay() para representação string-only (export CSV, PDF print).
  // Retorna apenas o ícone Unicode — sem wrapper HTML.
  getValueForDisplay() {
    const mimeType = (this.model && this.model.get("mimeType")) || "";
    return iconForMimeType(mimeType);
  }

  // Story 5.7-followup gap (d) ROUND 3 — DOM patch via afterRender.
  //
  // Hist.: tentativas anteriores via templateContent (round 1) e
  // detailTemplateContent (round 2) NÃO funcionaram em runtime real.
  // Smoke browser do Felipe (2026-05-12) confirmou: VarcharFieldView no
  // Espo 9.x bypassa essas propriedades em detail/list mode — usa
  // getValueForDisplay() inserido em wrapper hardcoded `<span class="">`.
  //
  // Fix definitivo: substituir o wrapper hardcoded via DOM patch em
  // afterRender(), aplicando classe + atributos a11y. Vanilla DOM
  // (setAttribute escapa valores; textContent não interpreta HTML) =
  // XSS-safe sem depender do escape do Handlebars.
  //
  // Aplica só em modos de exibição conhecidos. Search/filter/edit precisam
  // preservar inputs e controles nativos do Espo.
  afterRender() {
    if (typeof super.afterRender === "function") {
      super.afterRender();
    }
    if (this.mode !== "detail" && this.mode !== "list") {
      return;
    }
    const el = this.$el && this.$el[0];
    if (!el || typeof document === "undefined") {
      return;
    }
    const mimeType = (this.model && this.model.get("mimeType")) || "";
    const icon = iconForMimeType(mimeType);
    const span = document.createElement("span");
    span.className = "togare-mime-icon";
    if (mimeType) {
      span.setAttribute("title", mimeType);
      span.setAttribute("aria-label", mimeType);
    }
    span.textContent = icon;
    const target = this.findDisplayTarget(el);
    if (target && target.parentNode) {
      target.parentNode.replaceChild(span, target);
      return;
    }
    el.appendChild(span);
  }

  findDisplayTarget(el) {
    const nodes = el.childNodes || [];
    for (let i = 0; i < nodes.length; i++) {
      const node = nodes[i];
      if (
        node.nodeType === 1 &&
        node.tagName &&
        node.tagName.toLowerCase() === "span"
      ) {
        return node;
      }
      if (node.nodeType === 3 && String(node.textContent || "").trim()) {
        return node;
      }
    }
    return null;
  }
}
