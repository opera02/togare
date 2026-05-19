/**
 * Bootstrap do togare-core — registra helpers Handlebars globais.
 *
 * Arquivo "plain JS" (fora de src/ que passa pelo bundler espo-extension-tools).
 * É copiado literalmente para o zip e carregado pelo EspoCRM via scriptList
 * em Resources/metadata/app/client.json.
 *
 * As funções aqui são idênticas às de helpers/hbFormatters.js (consumido
 * por Backbone views via import ES6). Duplicação aceita pra evitar um
 * import do bundle nesse script de bootstrap global.
 */
(function () {
    "use strict";

    function digitsOnly(value) {
        if (value === null || value === undefined) return "";
        return String(value).replace(/\D+/g, "");
    }

    function formatCpf(value) {
        var d = digitsOnly(value);
        if (d.length !== 11) return value;
        return d.slice(0, 3) + "." + d.slice(3, 6) + "." + d.slice(6, 9) + "-" + d.slice(9, 11);
    }

    function formatCnpj(value) {
        var d = digitsOnly(value);
        if (d.length !== 14) return value;
        return d.slice(0, 2) + "." + d.slice(2, 5) + "." + d.slice(5, 8) + "/" + d.slice(8, 12) + "-" + d.slice(12, 14);
    }

    function formatCep(value) {
        var d = digitsOnly(value);
        if (d.length !== 8) return value;
        return d.slice(0, 5) + "-" + d.slice(5, 8);
    }

    function formatPhone(value) {
        var d = digitsOnly(value);
        if (d.length === 10) {
            return "(" + d.slice(0, 2) + ") " + d.slice(2, 6) + "-" + d.slice(6, 10);
        }
        if (d.length === 11) {
            return "(" + d.slice(0, 2) + ") " + d.slice(2, 7) + "-" + d.slice(7, 11);
        }
        return value;
    }

    function formatCnj(value) {
        var d = digitsOnly(value);
        if (d.length !== 20) return value;
        return d.slice(0, 7) + "-" + d.slice(7, 9) + "." + d.slice(9, 13) + "." +
            d.slice(13, 14) + "." + d.slice(14, 16) + "." + d.slice(16, 20);
    }

    // Story 4a.4: dictionary atoCodigo → label pt-BR. Manter sincronizado com
    // src/helpers/atoCodigo-formatter.js (ES6 module) + i18n/pt_BR/Prazo.json
    // options.atoCodigo. PrazoMetadataTest valida o cruzamento.
    var ATO_CODIGO_LABELS = {
        impugnacao_cumprimento: "Impugnação ao cumprimento de sentença",
        cumprimento_sentenca: "Cumprimento de sentença",
        embargos_declaracao: "Embargos de Declaração",
        agravo_instrumento: "Agravo de Instrumento",
        agravo_interno: "Agravo Interno",
        quesitos_pericia: "Quesitos de perícia",
        replica: "Réplica",
        recurso_apelacao: "Recurso de Apelação",
        contestacao: "Contestação",
        manifestacao_geral_intimacao: "Manifestação geral / intimação",
        manifestacao_generica: "Manifestação genérica"
    };

    function formatAtoCodigo(value) {
        if (value === null || value === undefined || value === "") return "";
        return Object.prototype.hasOwnProperty.call(ATO_CODIGO_LABELS, value)
            ? ATO_CODIGO_LABELS[value]
            : value;
    }

    // Story 4a.4 D10: diff em dias calendário (NÃO úteis — frontend não importa
    // BrazilianBusinessCalendar PHP-only). Aceita Date, ISO string ou
    // 'YYYY-MM-DD' bare. Retorna inteiro com sinal: positivo = futuro, 0 =
    // hoje, negativo = passado. Input inválido → null (template decide
    // como renderizar).
    function daysUntil(value, now) {
        if (value === null || value === undefined || value === "") return null;
        var target = value instanceof Date ? value : new Date(value);
        if (isNaN(target.getTime())) return null;
        var ref = (now instanceof Date) ? now : new Date();
        // Normaliza para meia-noite local — diff em dias civis, sem ruído de
        // hora/minuto.
        var t0 = Date.UTC(ref.getUTCFullYear(), ref.getUTCMonth(), ref.getUTCDate());
        var t1 = Date.UTC(target.getUTCFullYear(), target.getUTCMonth(), target.getUTCDate());
        return Math.round((t1 - t0) / 86400000);
    }

    // Story 4a.4 F1.11: ícones por prioridade do Prazo (chip do CardDePrazo).
    // Usa caracteres Unicode (sem dependência de pacote de ícones) por
    // consistência com StatusBadge i18n existente.
    var PRIORIDADE_ICONS = {
        baixa: "▾",
        normal: "•",
        alta: "▴",
        urgente: "🔥"
    };

    function prioridadeIcon(value) {
        if (value === null || value === undefined || value === "") return "";
        return Object.prototype.hasOwnProperty.call(PRIORIDADE_ICONS, value)
            ? PRIORIDADE_ICONS[value]
            : "";
    }

    // Story 4a.4: trunca value para len chars + " ..." se exceder. Caso
    // contrário retorna o original. Útil para fonteExcerpt no CardDePrazo
    // (Pattern 11 Progressive Disclosure — 200 chars + Ler mais inline).
    function truncate(value, len) {
        if (value === null || value === undefined) return "";
        var s = String(value);
        var n = (typeof len === "number" && len > 0) ? len : 200;
        if (s.length <= n) return s;
        return s.slice(0, n) + " ...";
    }

    var Hbs = (typeof window !== "undefined" && window.Handlebars)
        || (typeof globalThis !== "undefined" && globalThis.Handlebars);

    if (Hbs && typeof Hbs.registerHelper === "function") {
        Hbs.registerHelper("formatCpf", formatCpf);
        Hbs.registerHelper("formatCnpj", formatCnpj);
        Hbs.registerHelper("formatCep", formatCep);
        Hbs.registerHelper("formatPhone", formatPhone);
        Hbs.registerHelper("formatCnj", formatCnj);
        Hbs.registerHelper("formatAtoCodigo", formatAtoCodigo);
        Hbs.registerHelper("daysUntil", daysUntil);
        Hbs.registerHelper("prioridadeIcon", prioridadeIcon);
        Hbs.registerHelper("truncate", truncate);
    }

    // Expõe globalmente para debug/console smoke.
    if (typeof window !== "undefined") {
        window.TogareCore = window.TogareCore || {};
        window.TogareCore.formatters = {
            formatCpf: formatCpf,
            formatCnpj: formatCnpj,
            formatCep: formatCep,
            formatPhone: formatPhone,
            formatCnj: formatCnj,
            formatAtoCodigo: formatAtoCodigo,
            daysUntil: daysUntil,
            prioridadeIcon: prioridadeIcon,
            truncate: truncate
        };
    }
})();
