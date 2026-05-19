/**
 * briefing-do-dia-renderer — helper PURO do BriefingDoDia (Story 10.1).
 *
 * Sem dependência de EspoCRM/DOM-ciclo — testável 100% em vitest.
 * A view dashlet (briefing-do-dia.js) delega TODA a montagem de HTML
 * a estas funções. XSS-safe: todo conteúdo dinâmico é escapado.
 *
 * Acessibilidade AA 4.5:1 (rota CRM, não Portal). Contraste via CSS.
 * Colorblind: não usa cor como único indicador de estado.
 */

export function escapeHtml(value) {
  return String(value == null ? "" : value)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

/**
 * Saneia o scheme de um link antes de virar `href` (G2-P1, code review
 * 2026-05-18). `escapeHtml` neutraliza quote-breakout mas NÃO bloqueia
 * `javascript:`/`data:` — defesa errada para contexto de URL. Hoje os links
 * são constantes do backend (risco baixo), mas o helper se declara XSS-safe
 * e o irmão `card-de-prazo-renderer.js` já usa prefixo fixo. Whitelist:
 * apenas hash-route (`#...`), path relativo (`/...`) ou http(s) absoluto.
 *
 * @param {*}      raw      Link candidato (do payload do serviço)
 * @param {string} fallback Link seguro se o scheme não passa
 * @return {string} Link seguro (ainda precisa de escapeHtml para o atributo)
 */
export function safeLink(raw, fallback) {
  const fb = fallback || "#";
  if (raw == null) return fb;
  const s = String(raw).trim();
  if (s === "") return fb;
  return /^(#|\/|https?:\/\/)/i.test(s) ? s : fb;
}

/**
 * Greeting "Olá, {nome}. {dia da semana por extenso}, {DD} {mês abreviado}."
 *
 * @param {string} userName Nome do usuário autenticado
 * @param {Date}   now      Data/hora atual (injetável para testes)
 * @return {string} HTML do header de saudação
 */
export function renderGreeting(userName, now) {
  const name = escapeHtml(userName || "");
  const dayOfWeek = _formatDayOfWeek(now);
  const dayNum = String(now.getDate()).padStart(2, "0");
  const month = _formatMonth(now);
  return (
    `<p class="togare-briefing__greeting">` +
    `Olá${name ? ", " + name : ""}. ` +
    `${escapeHtml(dayOfWeek)}, ${escapeHtml(dayNum)} ${escapeHtml(month)}.` +
    `</p>`
  );
}

function _formatDayOfWeek(date) {
  try {
    const raw = new Intl.DateTimeFormat("pt-BR", { weekday: "long" }).format(date);
    return raw.charAt(0).toUpperCase() + raw.slice(1);
  } catch (_) {
    return "";
  }
}

function _formatMonth(date) {
  try {
    return new Intl.DateTimeFormat("pt-BR", { month: "short" })
      .format(date)
      .replace(".", "");
  } catch (_) {
    return "";
  }
}

/**
 * Card de um badge. O card inteiro é um link (Tab+Enter nativo, AC4).
 *
 * @param {object} badge  Badge DTO do serviço: { key, title, count, cta, link, type?, healthStatus?, alertCount? }
 * @return {string} HTML do card
 */
export function renderBadgeCard(badge) {
  const b = badge || {};
  if (b.type === "health") {
    return _renderHealthCard(b);
  }
  if (b.key === "licenca") {
    return _renderLicenseCard(b);
  }
  const rawCount = Number(b.count);
  const count = b.count != null && Number.isFinite(rawCount) ? rawCount : 0;
  const isEmpty = count === 0;
  const modClass = isEmpty ? " togare-briefing-card--empty" : "";
  const link = escapeHtml(safeLink(b.link, "#"));
  const title = escapeHtml(b.title || "");
  const cta = escapeHtml(b.cta || "Ver");
  const ariaLabel = escapeHtml(`${b.title || ""}: ${count}`);

  return (
    `<a class="togare-briefing-card${modClass}" href="${link}" tabindex="0" aria-label="${ariaLabel}">` +
    `<span class="togare-briefing-card__count" aria-hidden="true">${count}</span>` +
    `<span class="togare-briefing-card__title">${title}</span>` +
    `<span class="togare-briefing-card__cta">${cta}</span>` +
    `</a>`
  );
}

/**
 * Glyph de estado (AR-5 — "cor + ícone + label", Dev Note linha 279).
 * `aria-hidden` porque a semântica já está no texto + aria-label do card.
 */
function _statusGlyph(kind) {
  const g = kind === "ok" ? "✓" : kind === "atencao" ? "⚠" : "✕";
  return `<span class="togare-briefing-card__glyph" aria-hidden="true">${g}</span>`;
}

function _renderHealthCard(badge) {
  const status = badge.healthStatus || "offline";
  const alerts = Number(badge.alertCount) || 0;
  let statusText;
  if (status === "ok") {
    statusText = "OK";
  } else if (status === "lento") {
    statusText = `Atenção: ${alerts} alerta(s)`;
  } else {
    statusText = `Crítico: ${alerts} falha(s)`;
  }
  const cssMod = status === "ok" ? "ok" : status === "lento" ? "atencao" : "critico";
  const link = escapeHtml(safeLink(badge.link, "#Admin/TogareHealth"));
  const title = escapeHtml(badge.title || "Saúde do sistema");
  const cta = escapeHtml(badge.cta || "Ver painel completo");
  const ariaLabel = escapeHtml(`${badge.title || "Saúde do sistema"}: ${statusText}`);

  return (
    `<a class="togare-briefing-card togare-briefing-card--health togare-briefing-card--health-${cssMod}" ` +
    `href="${link}" tabindex="0" aria-label="${ariaLabel}">` +
    `<span class="togare-briefing-card__title">${title}</span>` +
    `<span class="togare-briefing-card__health-status">` +
    `${_statusGlyph(cssMod)}${escapeHtml(statusText)}</span>` +
    `<span class="togare-briefing-card__cta">${cta}</span>` +
    `</a>`
  );
}

/**
 * Card da Licença (G2-P4, code review 2026-05-18). Backend envia
 * `{ key:'licenca', licencaStatus:'valida'|'expirando'|'vencida', dayDiff:int }`
 * com `count:null` — sem este branch caía como card cinza "0" (uma licença
 * VENCIDA aparecia como "nada pendente", AC1 "Sócio/Admin + Licença").
 * Copy espelha `HealthCheckService::resolveLicenca` (texto já aprovado).
 * Reusa as classes visuais `--health-*` (vermelho/âmbar/verde já AA).
 */
function _renderLicenseCard(badge) {
  const status = badge.licencaStatus || "vencida";
  const rawDiff = Number(badge.dayDiff);
  const dayDiff = Number.isFinite(rawDiff) ? Math.trunc(rawDiff) : 0;
  let statusText;
  let cssMod;
  if (status === "valida") {
    statusText = "Válida";
    cssMod = "ok";
  } else if (status === "expirando") {
    statusText =
      dayDiff <= 0 ? "Expira hoje" : `Expira em ${dayDiff} dia(s)`;
    cssMod = "atencao";
  } else {
    statusText = `Vencida há ${Math.abs(dayDiff)} dia(s)`;
    cssMod = "critico";
  }
  const link = escapeHtml(safeLink(badge.link, "#Admin/ModuleStatus"));
  const title = escapeHtml(badge.title || "Licença");
  const cta = escapeHtml(badge.cta || "Ver status");
  const ariaLabel = escapeHtml(`${badge.title || "Licença"}: ${statusText}`);

  return (
    `<a class="togare-briefing-card togare-briefing-card--health togare-briefing-card--health-${cssMod} togare-briefing-card--license" ` +
    `href="${link}" tabindex="0" aria-label="${ariaLabel}">` +
    `<span class="togare-briefing-card__title">${title}</span>` +
    `<span class="togare-briefing-card__health-status">` +
    `${_statusGlyph(cssMod)}${escapeHtml(statusText)}</span>` +
    `<span class="togare-briefing-card__cta">${cta}</span>` +
    `</a>`
  );
}

/**
 * Grid de cards de badges. Retorna string vazia se não há badges.
 *
 * @param {Array} badges Lista de badge DTOs
 * @return {string} HTML da grid
 */
export function renderBadges(badges) {
  if (!Array.isArray(badges) || badges.length === 0) {
    return "";
  }
  return badges.map(renderBadgeCard).join("");
}

/**
 * EmptyStateCalmo pt-BR por role (AC3). Nunca mostra erro ou "0 items".
 *
 * @param {string} role Chave do role (ex.: 'advogado', 'financeiro')
 * @return {string} HTML do estado vazio
 */
export function renderEmptyState(role) {
  const messages = {
    "advogado": "Tudo em dia. Nenhum prazo pendente para hoje.",
    "financeiro": "Sem faturas pendentes no momento.",
    "rh-lite": "Nenhum funcionário cadastrado ainda.",
    "socio-admin": "Sistema saudável. Nada pendente.",
  };
  const msg = messages[role] || "Nada pendente por enquanto.";
  return `<p class="togare-briefing__empty-state">${escapeHtml(msg)}</p>`;
}

/**
 * Painel completo: header com greeting + badge notificação + grid de cards
 * ou EmptyStateCalmo.
 *
 * @param {object|null} payload   Payload do serviço: { badges, role, generatedAt }
 * @param {string}      userName  Nome do usuário para o greeting
 * @param {Date}        [now]     Data atual (padrão: new Date())
 * @return {string} HTML completo do painel
 */
export function renderPanel(payload, userName, now) {
  const p = payload || {};
  const badges = Array.isArray(p.badges) ? p.badges : [];
  const role = p.role || "";
  const date = now instanceof Date ? now : new Date();

  const greetingHtml = renderGreeting(userName, date);

  // Soma contagens para o badge de notificação. Coerção numérica explícita
  // (G2-P3): `b.count || 0` concatenava string se o payload trouxesse string.
  const totalAlerts = badges.reduce((acc, b) => {
    if (!b) return acc;
    if (b.type === "health") return acc + (Number(b.alertCount) || 0);
    return acc + (Number(b.count) || 0);
  }, 0);
  const notifBadge =
    totalAlerts > 0
      ? `<span class="togare-briefing__notif" role="status" aria-label="${totalAlerts} itens pendentes">` +
        `🔔 ${totalAlerts}</span>`
      : "";

  // AC3 / G2-P7: se o role só tem badges de contagem e TODOS estão zerados
  // (sem health/licença, que são indicadores de estado, não "pendências"),
  // mostra EmptyStateCalmo em vez de cards "0". Backend não omite zerados.
  const hasStatusBadge = badges.some(
    (b) => b && (b.type === "health" || b.key === "licenca"),
  );
  const allZero =
    badges.length > 0 &&
    !hasStatusBadge &&
    badges.every(
      (b) => !b || b.count == null || (Number(b.count) || 0) === 0,
    );

  const badgesHtml = allZero ? "" : renderBadges(badges);
  const contentHtml = badgesHtml || renderEmptyState(role);

  return (
    `<header class="togare-briefing__header">${greetingHtml}${notifBadge}</header>` +
    `<div class="togare-briefing__grid" role="list">${contentHtml}</div>`
  );
}
