/**
 * health-panel-renderer — helper PURO do painel TogareHealth (Story 10.2, FR41).
 *
 * Sem dependência de EspoCRM/DOM-ciclo — testável 100% em vitest. A view
 * dashlet (togare-health-panel.js) só orquestra fetch + ciclo de vida e
 * delega TODA a montagem de HTML a estas funções.
 *
 * Acessibilidade (AR-5 / PR-10 — colorblind-safe, vinculante): TODO indicador
 * de estado carrega **cor + ícone único + label**. As 4 cores
 * (verde/amarelo/vermelho/cinza) têm ícones DISTINTOS para serem
 * distinguíveis sem cor. `role="status"` por tile. Contraste AA (CSS).
 */

/**
 * Mapa estado→apresentação. Os 4 estados vêm de HealthPanelComposer (PHP):
 * ok | lento | offline | nao_instalado. Ícones distintos por estado.
 */
export const STATE_META = {
  ok: { cssMod: "ok", icon: "✓", srLabel: "Saudável" },
  lento: { cssMod: "lento", icon: "▲", srLabel: "Lento" },
  offline: { cssMod: "offline", icon: "✕", srLabel: "Fora do ar" },
  nao_instalado: { cssMod: "nao-instalado", icon: "○", srLabel: "Não instalado" },
};

const FALLBACK_STATE = "offline";

export function escapeHtml(value) {
  return String(value == null ? "" : value)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

function metaFor(state) {
  return STATE_META[state] || STATE_META[FALLBACK_STATE];
}

/**
 * HTML de um tile. XSS-safe (todo conteúdo dinâmico escapado).
 *
 * @param {{key:string,label:string,state:string,message:string,detailLink:?string}} tile
 * @return {string}
 */
export function composeTileHtml(tile) {
  const t = tile || {};
  const state = STATE_META[t.state] ? t.state : FALLBACK_STATE;
  const meta = metaFor(state);
  const label = escapeHtml(t.label || t.key || "");
  const message = escapeHtml(t.message || "");
  const srLabel = escapeHtml(meta.srLabel);

  const detail =
    typeof t.detailLink === "string" && t.detailLink !== ""
      ? `<a class="togare-health-tile__detail" href="${escapeHtml(
          t.detailLink,
        )}" tabindex="0">Ver detalhe</a>`
      : "";

  return (
    `<div class="togare-health-tile togare-health-tile--${meta.cssMod}" ` +
    `role="status" data-key="${escapeHtml(t.key || "")}" data-state="${escapeHtml(
      state,
    )}">` +
    `<div class="togare-health-tile__head">` +
    `<span class="togare-health-tile__icon" aria-hidden="true">${meta.icon}</span>` +
    `<span class="togare-health-tile__name">${label}</span>` +
    `</div>` +
    `<div class="togare-health-tile__metric">` +
    `<span class="togare-health-tile__sr-state">${srLabel}:</span> ${message}` +
    `</div>` +
    detail +
    `</div>`
  );
}

/**
 * Assinatura de estados (ordem dos tiles + estado de cada) — usada pela view
 * para decidir quando disparar `aria-live` (AR-9: anuncia só em MUDANÇA de
 * estado, NÃO no refresh de 60s de métrica estática).
 *
 * @param {{tiles?: Array}} payload
 * @return {string}
 */
export function stateSignature(payload) {
  const tiles = (payload && payload.tiles) || [];
  return tiles.map((t) => `${t.key}:${t.state}`).join("|");
}

/**
 * HTML do rodapé de licença (AC3). `null` → string vazia (linha some).
 *
 * @param {?{state:string,message:string}} licenca
 * @return {string}
 */
export function composeLicencaHtml(licenca) {
  if (!licenca || typeof licenca.message !== "string" || licenca.message === "") {
    return "";
  }
  const state = escapeHtml(licenca.state || "valida");
  return (
    `<div class="togare-health-footer__licenca" data-licenca-state="${state}">` +
    `${escapeHtml(licenca.message)}` +
    `</div>`
  );
}

/**
 * Painel completo: header + grid 3x2 + rodapé licença + link histórico.
 * `historico` (lista de eventos do audit log) só habilita o link se houver
 * itens; senão o link some (Decisão A2.2 — sem entidade nova).
 *
 * @param {{tiles?:Array,licenca?:object,historico?:Array}} payload
 * @return {string}
 */
export function composePanelHtml(payload) {
  const p = payload || {};
  const tiles = Array.isArray(p.tiles) ? p.tiles : [];
  const tilesHtml = tiles.map(composeTileHtml).join("");
  const licencaHtml = composeLicencaHtml(p.licenca);
  const historico = Array.isArray(p.historico) ? p.historico : [];

  const historicoLink =
    historico.length > 0
      ? `<button type="button" class="togare-health-footer__historico-link" ` +
        `aria-expanded="false">Ver histórico de incidentes</button>`
      : "";

  return (
    `<div class="togare-health-panel">` +
    `<div class="togare-health-panel__header">Saúde do Togare</div>` +
    `<div class="togare-health-panel__grid">${tilesHtml}</div>` +
    `<div class="togare-health-panel__footer">${licencaHtml}${historicoLink}</div>` +
    `<div class="togare-health-panel__historico" hidden>${composeHistoricoHtml(
      historico,
    )}</div>` +
    `</div>`
  );
}

/**
 * Lista do histórico de incidentes (audit log). XSS-safe.
 *
 * @param {Array<{occurredAt:string,event:string,message:string}>} historico
 * @return {string}
 */
export function composeHistoricoHtml(historico) {
  const items = Array.isArray(historico) ? historico : [];
  if (items.length === 0) {
    return `<p class="togare-health-historico__vazio">Sem incidentes registrados.</p>`;
  }
  const rows = items
    .map(
      (h) =>
        `<li class="togare-health-historico__item">` +
        `<span class="togare-health-historico__when">${escapeHtml(
          h.occurredAt || "",
        )}</span> ` +
        `<span class="togare-health-historico__msg">${escapeHtml(
          h.message || h.event || "",
        )}</span>` +
        `</li>`,
    )
    .join("");
  return `<ul class="togare-health-historico__list">${rows}</ul>`;
}
