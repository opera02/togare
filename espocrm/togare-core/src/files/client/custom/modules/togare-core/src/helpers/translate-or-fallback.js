/**
 * translateOrFallback — lookup tolerante de string i18n com fallback hardcoded.
 *
 * Story 4b.0 (Df12): consolida 3 cópias divergentes do antigo
 * `_translateOrFallback` (1 com 5 params em status-selector.js, 2 com 4 params
 * em prazo/record/{detail,edit}.js) num único helper compartilhado, evitando
 * drift no próximo caller (alertas D-1 / banner DJEN do Epic 4b).
 *
 * Tenta `view.translate(key, category, scope)` primeiro (path canônico
 * EspoCRM 9.x); se ausente ou retornar a key não traduzida, tenta
 * `view.getLanguage().translate(key, category, scope)`. Em qualquer falha
 * (método ausente, exception, retorno não-string, retorno === key), devolve
 * o fallback. NUNCA lança — graceful degradation alinhada a `formatCnj` /
 * `composeHeadlineHtml`.
 *
 * @param {object|null|undefined} view  view-like com `translate()` e/ou `getLanguage()`.
 * @param {string} key                  chave i18n (ex.: "toastUndoActionLabel").
 * @param {string} category             categoria (ex.: "messages", "labels", "options").
 * @param {string} scope                scope (ex.: "Prazo", "Cliente", "Global").
 * @param {string} fallback             string pt-BR usada quando i18n falha.
 * @returns {string}
 */
export function translateOrFallback(view, key, category, scope, fallback) {
  if (
    !view ||
    (typeof view.translate !== "function" && typeof view.getLanguage !== "function")
  ) {
    return fallback;
  }
  try {
    if (typeof view.translate === "function") {
      const v = view.translate(key, category, scope);
      if (typeof v === "string" && v && v !== key) return v;
    }
    if (typeof view.getLanguage === "function") {
      const lang = view.getLanguage();
      if (lang && typeof lang.translate === "function") {
        const v = lang.translate(key, category, scope);
        if (typeof v === "string" && v && v !== key) return v;
      }
    }
  } catch (_) {
    // ignore — fallback abaixo
  }
  return fallback;
}
