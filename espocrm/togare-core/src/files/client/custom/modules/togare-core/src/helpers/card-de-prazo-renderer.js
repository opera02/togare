/**
 * Renderer puro do CardDePrazo (Story 4a.4 T9 / Decisão #1).
 *
 * Recebe um objeto plano `attrs` (snapshot do model.attributes — JS-friendly,
 * sem dependência de Backbone) + `helpers` opcional (formatadores e i18n) e
 * retorna o HTML do card como string.
 *
 * Lógica pura permite testes vitest isolados sem precisar montar Backbone
 * row + view + template engine reais. O `views/prazo/record/row.js` chama
 * este renderer dentro do seu `data()` ou `setup()`.
 *
 * Anatomia v1.1 da UX spec (linhas 612-630) — Story 4a.4 Dev Notes §3.
 */

import { formatAtoCodigo } from "togare-core:helpers/atoCodigo-formatter";
import { isVenceHoje } from "togare-core:helpers/d-zero-detector";

// Story 4b.3 (Decisão #4) — família de status "ainda em jogo" para os quais
// faz sentido mostrar redundância visual D-0. Status finais
// (protocolado/descartado/ciencia_renuncia/acompanhamento) NÃO disparam
// "VENCE HOJE" mesmo se dataFatal=hoje (sem stake operacional).
const STATUS_PENDENTE_FAMILIA_JS = [
    "pendente",
    "atrasado_reagendado",
    "aguardando_cliente",
    "aguardando_correcao",
];

const STATUS_LABELS_FALLBACK = {
    rascunho: "Rascunho",
    pendente: "Pendente",
    atrasado_reagendado: "Atrasado/Reagendado",
    aguardando_cliente: "Aguardando cliente",
    aguardando_correcao: "Aguardando correção",
    protocolado: "Protocolado",
    ciencia_renuncia: "Ciência com renúncia",
    acompanhamento: "Acompanhamento",
    descartado: "Descartado",
};

const STATUS_BADGE_CLASS = {
    rascunho: "info",
    pendente: "warning",
    atrasado_reagendado: "danger",
    aguardando_cliente: "default",
    aguardando_correcao: "default",
    protocolado: "success",
    ciencia_renuncia: "success",
    acompanhamento: "info",
    descartado: "default",
};

const STATUS_BADGE_ICONS = {
    rascunho: "📝",
    pendente: "🟡",
    atrasado_reagendado: "⚠️",
    aguardando_cliente: "👤",
    aguardando_correcao: "✏️",
    protocolado: "✅",
    ciencia_renuncia: "🛡️",
    acompanhamento: "👁️",
    descartado: "⊘",
};

const PRIORIDADE_LABELS_FALLBACK = {
    baixa: "Baixa",
    normal: "Normal",
    alta: "Alta",
    urgente: "Urgente",
};

const PRIORIDADE_ICONS = {
    baixa: "▾",
    normal: "•",
    alta: "▴",
    urgente: "🔥",
};

const TIPO_PRAZO_LABELS_FALLBACK = {
    "": "(não classificado)",
    peticao_inicial: "Petição inicial",
    contestacao: "Contestação",
    replica: "Réplica",
    apelacao: "Apelação",
    agravo_instrumento: "Agravo de Instrumento",
    agravo_interno: "Agravo Interno",
    embargos_declaracao: "Embargos de Declaração",
    resp_re: "REsp / RE",
    aresp_are: "AREsp / ARE",
    manifestacao_diversa: "Manifestações diversas",
    impugnacao_laudo_cumprimento: "Impugnação (laudo / cumprimento de sentença)",
    contrarrazoes: "Contrarrazões",
    idpj: "IDPJ",
    mandado_seguranca: "Mandado de Segurança",
    audiencia: "Audiência",
    ciencia_renuncia: "Ciência com renúncia",
    diligencia_administrativa: "Diligências administrativas / resposta ao cliente",
};

const CONTAGEM_LABELS_FALLBACK = {
    uteis: "Dias úteis",
    corridos: "Dias corridos",
};

const TRECHO_LIMIT = 200;

export function escapeHtml(str) {
    if (str === null || str === undefined) return "";
    return String(str)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function digitsOnly(value) {
    if (value === null || value === undefined) return "";
    return String(value).replace(/\D+/g, "");
}

function formatCnjFallback(value) {
    const d = digitsOnly(value);
    if (d.length !== 20) return value || "";
    return (
        d.slice(0, 7) + "-" + d.slice(7, 9) + "." + d.slice(9, 13) + "." +
        d.slice(13, 14) + "." + d.slice(14, 16) + "." + d.slice(16, 20)
    );
}

function formatDateBR(value) {
    if (!value) return "";
    // Aceita 'YYYY-MM-DD' ou Date ou ISO.
    let date;
    if (value instanceof Date) {
        date = value;
    } else {
        const m = String(value).match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (m) {
            return `${m[3]}/${m[2]}/${m[1]}`;
        }
        date = new Date(value);
    }
    if (isNaN(date.getTime())) return String(value);
    const dd = String(date.getUTCDate()).padStart(2, "0");
    const mm = String(date.getUTCMonth() + 1).padStart(2, "0");
    const yyyy = String(date.getUTCFullYear());
    return `${dd}/${mm}/${yyyy}`;
}

function daysUntil(value, now) {
    if (!value) return null;
    let target;
    if (value instanceof Date) {
        target = value;
    } else {
        const m = String(value).match(/^(\d{4})-(\d{2})-(\d{2})/);
        target = m
            ? new Date(Date.UTC(+m[1], +m[2] - 1, +m[3]))
            : new Date(value);
    }
    if (isNaN(target.getTime())) return null;
    const ref = (now instanceof Date) ? now : new Date();
    const t0 = Date.UTC(ref.getUTCFullYear(), ref.getUTCMonth(), ref.getUTCDate());
    const t1 = Date.UTC(target.getUTCFullYear(), target.getUTCMonth(), target.getUTCDate());
    return Math.round((t1 - t0) / 86400000);
}

function truncate(value, len) {
    if (value === null || value === undefined) return "";
    const s = String(value);
    const n = (typeof len === "number" && len > 0) ? len : TRECHO_LIMIT;
    if (s.length <= n) return s;
    return s.slice(0, n) + " ...";
}

function lookupLabel(translateFn, key, category, scope, fallbackMap) {
    if (typeof translateFn === "function") {
        try {
            const v = translateFn(key, category, scope);
            if (typeof v === "string" && v && v !== key) return v;
        } catch (_) {
            // ignore
        }
    }
    if (key == null) return "";
    return fallbackMap[key] ?? String(key);
}

/**
 * Renderiza o HTML do CardDePrazo a partir dos atributos do model.
 *
 * @param {object} attrs - snapshot model.attributes (clienteId, parteContrariaId,
 *                         status, prioridade, tipoPrazo, descricao, fonteExcerpt,
 *                         dataFatal, contagem, sourcePubId, numeroProcessoOriginal,
 *                         cliente, parteContraria, id).
 * @param {object} helpers - opcional.
 * @param {Function} [helpers.translate] - função de i18n do EspoCRM.
 * @param {Function} [helpers.formatCnj] - formata CNJ; fallback interno se ausente.
 * @param {Date} [helpers.now] - data de referência para daysUntil; usa new Date() se ausente.
 * @param {string} [helpers.hedgeBannerHtml] - HTML pré-sanitizado pelo caller (trusted HTML).
 *   NUNCA aplicar escapeHtml aqui — o caller é responsável por sanitizar antes de passar.
 * @returns {string} HTML string do card.
 */
export function renderCardDePrazo(attrs, helpers) {
    const a = attrs || {};
    const h = helpers || {};
    const translate = h.translate || null;
    const formatCnj = h.formatCnj || formatCnjFallback;
    const now = h.now === null ? undefined : h.now;
    const hedgeBannerHtml = h.hedgeBannerHtml || "";

    // Story 4b.3 (Decisão #4 + #6) — UX-DR10 redundância semântica D-0.
    // Detecção camada visual cumulativa: vence hoje E status ∈ família ainda
    // em jogo. Status finais NÃO disparam D-0 (sem stake operacional).
    const venceHoje = isVenceHoje(a.dataFatal, now)
        && STATUS_PENDENTE_FAMILIA_JS.includes(a.status);

    const statusLabel = lookupLabel(translate, a.status, "options", "Prazo", STATUS_LABELS_FALLBACK);
    const statusKlass = STATUS_BADGE_CLASS[a.status] || "default";
    const prioridadeLabel = a.prioridade
        ? lookupLabel(translate, a.prioridade, "options", "Prazo", PRIORIDADE_LABELS_FALLBACK)
        : "";
    const prioridadeIcon = PRIORIDADE_ICONS[a.prioridade] || "";
    const tipoPrazoLabel = a.tipoPrazo
        ? lookupLabel(translate, a.tipoPrazo, "options", "Prazo", TIPO_PRAZO_LABELS_FALLBACK)
        : "";
    const contagemLabel = a.contagem
        ? lookupLabel(translate, a.contagem, "options", "Prazo", CONTAGEM_LABELS_FALLBACK)
        : "";
    const cnjFormatted = a.numeroProcessoOriginal ? formatCnj(a.numeroProcessoOriginal) : "";
    const dataFatalBR = a.dataFatal ? formatDateBR(a.dataFatal) : "";
    const dias = a.dataFatal ? daysUntil(a.dataFatal, now) : null;

    const statusIcon = STATUS_BADGE_ICONS[a.status] || "";
    const prioridadeValida = a.prioridade && Object.prototype.hasOwnProperty.call(PRIORIDADE_ICONS, a.prioridade);

    const headerChips = [];
    // Story 4b.3 (Decisão #6) — chip "VENCE HOJE" ANTES do StatusBadge real
    // quando vence hoje. Cor + ícone sino + texto literal "VENCE HOJE" —
    // colorblind-safe + WCAG AAA (rota crítica D-0). aria-label literal
    // exigido pelo AC7 ("VENCE HOJE — confirme ou adie").
    if (venceHoje) {
        headerChips.push(
            '<span class="togare-card-de-prazo__d-zero-badge togare-status-badge togare-status-badge--vence-hoje" '
            + 'aria-label="VENCE HOJE — confirme ou adie" role="status">'
            + '<span aria-hidden="true">🔔 </span>'
            + 'VENCE HOJE'
            + '</span>'
        );
    }
    headerChips.push(
        `<span class="togare-card-de-prazo__badge togare-card-de-prazo__badge--${statusKlass}" aria-label="${escapeHtml(statusLabel)}">` +
        `${statusIcon ? `<span aria-hidden="true">${statusIcon} </span>` : ""}` +
        `${escapeHtml(statusLabel)}</span>`
    );
    if (a.tipoPrazo) {
        headerChips.push(`<span class="togare-card-de-prazo__chip togare-card-de-prazo__chip--tipo-prazo">📑 ${escapeHtml(tipoPrazoLabel)}</span>`);
    }
    if (prioridadeValida) {
        headerChips.push(`<span class="togare-card-de-prazo__chip togare-card-de-prazo__chip--prioridade togare-card-de-prazo__chip--prioridade-${a.prioridade}">${escapeHtml(prioridadeIcon)} ${escapeHtml(prioridadeLabel)}</span>`);
    }

    const cnjLine = cnjFormatted
        ? `<div class="togare-card-de-prazo__cnj">Proc. ${escapeHtml(cnjFormatted)}</div>`
        : "";
    const descricaoLine = a.descricao
        ? `<div class="togare-card-de-prazo__descricao">${escapeHtml(a.descricao)}</div>`
        : "";

    const trechoTruncado = a.fonteExcerpt ? truncate(a.fonteExcerpt, TRECHO_LIMIT) : "";
    const trechoOverflow = a.fonteExcerpt && String(a.fonteExcerpt).length > TRECHO_LIMIT;
    const excerptBlock = a.fonteExcerpt
        ? `<div class="togare-card-de-prazo__excerpt" data-full="${escapeHtml(a.fonteExcerpt)}" data-truncated="${escapeHtml(trechoTruncado)}">
              <span class="togare-card-de-prazo__excerpt-text">${escapeHtml(trechoTruncado)}</span>
              ${trechoOverflow ? `<button type="button" class="togare-card-de-prazo__ler-mais" data-action="ler-mais">Ler mais</button>` : ""}
           </div>`
        : "";

    const diasLabel = dias === null ? "" : ` (${dias} dias)`;
    const dataFatalBlock = dataFatalBR
        ? `<div class="togare-card-de-prazo__data-fatal">Data fatal: <strong>${escapeHtml(dataFatalBR)}</strong>${escapeHtml(diasLabel)}${contagemLabel ? ` · ${escapeHtml(contagemLabel)}` : ""}</div>`
        : "";

    const clienteBlock = a.cliente && a.cliente.id
        ? `<div class="togare-card-de-prazo__vinculo">Cliente: <a href="#Cliente/view/${escapeHtml(a.cliente.id)}">${escapeHtml(a.cliente.name || a.cliente.id)}</a></div>`
        : "";
    const parteBlock = a.parteContraria && a.parteContraria.id
        ? `<div class="togare-card-de-prazo__vinculo">Parte: <a href="#ParteContraria/view/${escapeHtml(a.parteContraria.id)}">${escapeHtml(a.parteContraria.name || a.parteContraria.id)}</a></div>`
        : "";

    const djenLink = a.sourcePubId
        ? `<a class="togare-card-de-prazo__djen-link" target="_blank" rel="noopener" href="https://comunica.pje.jus.br/consulta/comunicacao/${encodeURIComponent(String(a.sourcePubId))}">Ver no DJEN ↗</a>`
        : "";

    const reviewBtn = a.id
        ? `<a class="btn btn-default togare-card-de-prazo__revisar" href="#Prazo/view/${escapeHtml(a.id)}">Revisar</a>`
        : "";

    // Story 4b.3 (Decisão #6) — modifier wrapper aplicado quando D-0.
    const wrapperClass = venceHoje
        ? "togare-card-de-prazo togare-card-de-prazo--d-zero"
        : "togare-card-de-prazo";

    return (
        `<div class="${wrapperClass}" data-id="${escapeHtml(a.id || "")}">` +
        `<div class="togare-card-de-prazo__header">${headerChips.join(" ")}</div>` +
        cnjLine +
        descricaoLine +
        excerptBlock +
        `<div class="togare-card-de-prazo__rodape">` +
        dataFatalBlock +
        clienteBlock +
        parteBlock +
        djenLink +
        `<div class="togare-card-de-prazo__acoes">${reviewBtn}</div>` +
        `</div>` +
        hedgeBannerHtml +
        `</div>`
    );
}

// Re-export para conveniência em testes (formatAtoCodigo embora não seja
// usado diretamente no template do CardDePrazo, é exposto no chip futuro
// caso a story precise mostrar atoCodigo no card).
export { formatAtoCodigo };
