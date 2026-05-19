/**
 * Helper puro para renderizar a "headline" do dashlet BriefingDoDia
 * (Story 4a.5, AC2 + Decisão #3).
 *
 * Função pura testável sem instanciar Backbone — recebe `count` (total de
 * Prazos pendentes do advogado) e retorna o HTML do `<div class="togare-briefing-headline">`
 * que é injetado pelo dashlet view antes do `.list-container` nativo.
 *
 * 3 cenários renderizados:
 *  - count === 0: estado calmo (modificador `--zero`); SEM CTA; texto
 *    "Nenhum prazo pendente — aproveita o café. ☕".
 *  - count === 1: "**1 prazo pendente**" + CTA "Confira hoje ↗".
 *  - count >= 2: "**{N} prazos pendentes**" + CTA.
 *
 * Defesas:
 *  - `count` null/undefined/NaN → tratado como 0 (não quebra render).
 *  - `i18n` ausente ou retorna falsy → fallback para strings literais pt-BR
 *    hardcoded (graceful degradation; mesmo pattern de `formatCnj` da Story 3.4).
 *  - i18n malicioso (HTML adversarial) → escapado via `escapeHtml`.
 *  - Placeholder `{N}` substituído via regex `/\{N\}/g`.
 *  - Href do CTA é literal `#Prazo?bool=meusPendentes` — navegação
 *    Backbone hash router padrão (Decisão #6).
 */

const FALLBACK_COPY = Object.freeze({
  briefingHeadlineZero: "Nenhum prazo pendente — aproveita o café. ☕",
  briefingHeadlineOne: "1 prazo pendente",
  briefingHeadlineMany: "{N} prazos pendentes",
  briefingCtaConfiraHoje: "Confira hoje",
});

const CTA_HREF = "#Prazo?bool=meusPendentes";

/**
 * Escapa HTML em strings para prevenir XSS injection — função local idêntica
 * ao pattern do `card-de-prazo-renderer.js` para evitar acoplamento.
 *
 * @param {*} value
 * @returns {string}
 */
function escapeHtml(value) {
  if (value === null || value === undefined) return "";
  return String(value)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

/**
 * Lookup tolerante: tenta `i18n(key)`; se cair em null/undefined/empty,
 * usa o fallback hardcoded. NUNCA retorna HTML pré-escapado — o caller
 * (composeHeadlineHtml) escapa.
 *
 * @param {(key: string) => string | null | undefined} i18n
 * @param {string} key
 * @returns {string}
 */
function lookupCopy(i18n, key) {
  let value = null;
  if (typeof i18n === "function") {
    try {
      value = i18n(key);
    } catch (_e) {
      // i18n pode estar mid-render; degrade silencioso pro fallback.
      value = null;
    }
  }
  if (value === null || value === undefined || value === "") {
    return FALLBACK_COPY[key] ?? key;
  }
  return String(value);
}

/**
 * Sanitiza `count` para inteiro >= 0. Retorna 0 para null/undefined/NaN/negativo.
 *
 * @param {*} count
 * @returns {number}
 */
function normalizeCount(count) {
  if (count === null || count === undefined) return 0;
  const n = Number(count);
  if (!Number.isFinite(n) || n < 0) return 0;
  return Math.floor(n);
}

/**
 * Compõe o HTML do bloco headline do BriefingDoDia.
 *
 * @param {*} count                                              Total de Prazos pendentes (collection.total).
 * @param {(key: string) => string | null | undefined} [i18n]    Função de tradução (opcional; fallback pt-BR).
 * @returns {string}                                             HTML completo do `<div class="togare-briefing-headline">`.
 */
export function composeHeadlineHtml(count, i18n) {
  const total = normalizeCount(count);

  if (total === 0) {
    const message = lookupCopy(i18n, "briefingHeadlineZero");
    return (
      '<div class="togare-briefing-headline togare-briefing-headline--zero">' +
      "<span>" +
      escapeHtml(message) +
      "</span>" +
      "</div>"
    );
  }

  let messageRaw;
  if (total === 1) {
    messageRaw = lookupCopy(i18n, "briefingHeadlineOne");
  } else {
    const tpl = lookupCopy(i18n, "briefingHeadlineMany");
    messageRaw = tpl.replace(/\{N\}/g, String(total));
  }

  const cta = lookupCopy(i18n, "briefingCtaConfiraHoje");

  // O número entra dentro de <strong>. Para count=1, separamos "1" do resto;
  // para count>=2 mais simples colocar a frase inteira em <strong>.
  // Decisão pragmática: <strong> em volta da frase inteira (uniforme + simples
  // + bate com a UX spec "**3 prazos pendentes**").
  return (
    '<div class="togare-briefing-headline togare-briefing-headline--active">' +
    "<strong>" +
    escapeHtml(messageRaw) +
    "</strong>" +
    '<a class="btn btn-primary btn-sm togare-briefing-cta" href="' +
    CTA_HREF +
    '">' +
    escapeHtml(cta) +
    " ↗" +
    "</a>" +
    "</div>"
  );
}

export { CTA_HREF, FALLBACK_COPY };
