/**
 * Helper de contraste WCAG 2.1 (puro, sem dependências, testável).
 *
 * Story 7a.1 — usado pelo painel "Portal → Aparência" para avisar (AC5,
 * NÃO-bloqueante) quando a cor primária escolhida pelo escritório derruba
 * o contraste do texto do splash abaixo de 7:1 (rota crítica AAA #1).
 *
 * REUSO PREVISTO: o gate AUTOMATIZADO de build que FALHA a publicação do
 * módulo se uma rota AAA cai <7:1 é da Story 7a.6 — ela deve importar este
 * mesmo helper (não reimplementar a fórmula de luminância).
 *
 * Referência: WCAG 2.1 SC 1.4.6 (Contrast Enhanced — AAA = 7:1 texto normal).
 */

/** Limiar AAA para texto normal (WCAG 2.1 SC 1.4.6). */
export const AAA_NORMAL_TEXT = 7;

/** Limiar AA para texto normal (WCAG 2.1 SC 1.4.3). */
export const AA_NORMAL_TEXT = 4.5;

/**
 * Converte cor hex (#RGB ou #RRGGBB) em {r,g,b} 0–255.
 * Retorna null se a string não for um hex válido.
 *
 * @param {string} hex
 * @return {{r:number,g:number,b:number}|null}
 */
export function hexToRgb(hex) {
    if (typeof hex !== "string") {
        return null;
    }

    let h = hex.trim().replace(/^#/, "");

    if (h.length === 3) {
        h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
    }

    if (!/^[0-9a-fA-F]{6}$/.test(h)) {
        return null;
    }

    return {
        r: parseInt(h.slice(0, 2), 16),
        g: parseInt(h.slice(2, 4), 16),
        b: parseInt(h.slice(4, 6), 16),
    };
}

/**
 * Luminância relativa de uma cor (WCAG 2.1 — formula 8-bit sRGB).
 *
 * @param {{r:number,g:number,b:number}} rgb
 * @return {number} 0 (preto) a 1 (branco)
 */
export function relativeLuminance(rgb) {
    const channel = (c) => {
        const s = c / 255;

        return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
    };

    return (
        0.2126 * channel(rgb.r) +
        0.7152 * channel(rgb.g) +
        0.0722 * channel(rgb.b)
    );
}

/**
 * Razão de contraste entre duas cores hex (WCAG 2.1).
 * Retorna null se qualquer cor for hex inválido.
 *
 * @param {string} hexA
 * @param {string} hexB
 * @return {number|null} razão de 1 (igual) a 21 (preto×branco)
 */
export function contrastRatio(hexA, hexB) {
    const a = hexToRgb(hexA);
    const b = hexToRgb(hexB);

    if (a === null || b === null) {
        return null;
    }

    const lA = relativeLuminance(a);
    const lB = relativeLuminance(b);

    const lighter = Math.max(lA, lB);
    const darker = Math.min(lA, lB);

    return (lighter + 0.05) / (darker + 0.05);
}

/**
 * O texto branco (#ffffff) sobre `bgHex` atinge AAA 7:1?
 * O splash usa texto branco sobre o fundo da cor do escritório.
 * Retorna false (conservador) se a cor for hex inválida.
 *
 * @param {string} bgHex
 * @param {string} [textHex="#ffffff"]
 * @return {boolean}
 */
export function meetsAaaOnBackground(bgHex, textHex = "#ffffff") {
    const ratio = contrastRatio(textHex, bgHex);

    if (ratio === null) {
        return false;
    }

    return ratio >= AAA_NORMAL_TEXT;
}
