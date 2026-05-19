/**
 * Detector cliente-side de auto-vínculo Cliente/ParteContraria via
 * AutoLinkClientHook (Story 4a.4 F1.9 / Decisão #3).
 *
 * Lógica pura, testável, isolada do framework EspoCRM. Recebe:
 *  - prevAttrs    : valores do model antes do save (model.previousAttributes()).
 *  - currAttrs    : valores do model depois do save (model.attributes).
 *  - touchedFields: Set/Array dos fields que o user editou explicitamente
 *                   nesta sessão de edit (clienteId, parteContrariaId).
 *
 * Retorna um objeto descritor:
 *  { variant: 'pair' | 'cliente_only' | 'none', cliente: {...}|null, parte: {...}|null }
 *
 * Casos cobertos (espelha AC13 da story):
 *  - prev=null/undef + curr=valor + NÃO touched → field foi auto-vinculado.
 *  - 1 cliente + 1 parte ambos auto-vinculados → variant='pair'.
 *  - só cliente auto-vinculado → variant='cliente_only'.
 *  - só parte auto-vinculada → variant='none' (UX spec não cobre — banner solo
 *    de parte sem cliente é caso operacional improvável; silently ignore).
 *  - ambos null no curr OU ambos touched → variant='none'.
 *
 * Inputs com curr === prev (sem mudança) → variant='none'.
 */

function isEmpty(v) {
    return v === null || v === undefined || v === "";
}

function inSet(setOrArr, key) {
    if (!setOrArr) return false;
    if (typeof setOrArr.has === "function") return setOrArr.has(key);
    return Array.isArray(setOrArr) && setOrArr.indexOf(key) !== -1;
}

function wasAutoLinked(field, prev, curr, touched) {
    return (
        isEmpty(prev[field]) &&
        !isEmpty(curr[field]) &&
        !inSet(touched, field)
    );
}

/**
 * Resolve o nome amigável associado a um link relacional. EspoCRM expõe via
 * `model.get('cliente')` que pode retornar:
 *  - objeto { id, name } se o link foi resolvido pelo backend.
 *  - apenas o id (string) — fallback com placeholder "Cliente vinculado".
 */
function resolveLinkName(currAttrs, linkField) {
    const linked = currAttrs[linkField];
    if (linked && typeof linked === "object" && linked.name) {
        return linked.name;
    }
    return null;
}

/**
 * @param {object} prevAttrs - model.previousAttributes() snapshot (pode incluir clienteId, parteContrariaId, processoId, etc).
 * @param {object} currAttrs - model.attributes (igual escopo).
 * @param {Set|Array} touched - fields que user editou (clienteId / parteContrariaId).
 * @returns {{variant: 'pair'|'cliente_only'|'none', clienteName: string|null, parteName: string|null, cnj: string|null}}
 */
export function detectAutoLink(prevAttrs, currAttrs, touched) {
    const out = {
        variant: "none",
        clienteName: null,
        parteName: null,
        cnj: null,
    };
    if (!prevAttrs || !currAttrs) return out;

    const clienteAuto = wasAutoLinked("clienteId", prevAttrs, currAttrs, touched);
    const parteAuto = wasAutoLinked("parteContrariaId", prevAttrs, currAttrs, touched);

    if (clienteAuto && parteAuto) {
        out.variant = "pair";
        out.clienteName = resolveLinkName(currAttrs, "cliente");
        out.parteName = resolveLinkName(currAttrs, "parteContraria");
    } else if (clienteAuto) {
        out.variant = "cliente_only";
        out.clienteName = resolveLinkName(currAttrs, "cliente");
    } else {
        // Só parte auto-vinculada (sem cliente) → não mostra banner. UX spec
        // não cobre (caso operacional improvável); evita feedback genérico.
        return out;
    }

    // CNJ do Processo (campo do Prazo: numeroProcessoOriginal). Pode estar
    // vazio se Processo não foi setado nesta save.
    if (currAttrs.numeroProcessoOriginal) {
        out.cnj = currAttrs.numeroProcessoOriginal;
    }

    return out;
}

/**
 * Helper para formatar a mensagem do banner com base no descritor + i18n.
 * Aceita `i18n` como objeto { pair: '...', cliente_only: '...' } com placeholders
 * `{nomeCliente}` `{nomeParte}` `{cnj}`. CNJ é renderizado via `formatCnj` se
 * disponível em `formatters.formatCnj`; senão raw.
 */
export function formatAutoLinkMessage(descriptor, i18n, formatCnj) {
    if (!descriptor || descriptor.variant === "none") return "";
    const cnjFormatted =
        descriptor.cnj && typeof formatCnj === "function"
            ? formatCnj(descriptor.cnj)
            : (descriptor.cnj || "");
    const tpl =
        descriptor.variant === "pair"
            ? (i18n && i18n.pair) || "Cliente {nomeCliente} e Parte {nomeParte} herdados do Processo {cnj}."
            : (i18n && i18n.cliente_only) || "Cliente {nomeCliente} herdado do Processo {cnj}.";
    return tpl
        .replace(/{nomeCliente}/g, descriptor.clienteName || "vinculado")
        .replace(/{nomeParte}/g, descriptor.parteName || "vinculada")
        .replace(/{cnj}/g, cnjFormatted);
}
