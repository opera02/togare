/**
 * PayloadAccordion field view (Story 4a.4 F1.1).
 *
 * Renderiza `publicacaoOrigemRaw` (JSON cru do payload Comunica DJEN) de forma
 * legível: accordion `<details>` colapsado por padrão; expansão mostra
 * primeiro **campos chave** parseados (tribunal, siglaTribunal, linkOrigem,
 * textoPublicacao) seguidos do JSON pretty-printed.
 *
 * Decisão #4 da Story 4a.4: parsing tolerante a falha.
 *  - JSON válido → accordion com campos chave + pretty.
 *  - Texto não-JSON → accordion com warning + raw.
 *  - null/empty → renderiza nada (campo omitido).
 *
 * Edit mode mantém textarea nativo (não há valor em editar JSON via UI —
 * audit/debug field).
 *
 * Aplicado via entityDefs/Prazo.json::publicacaoOrigemRaw.view.
 */

import TextFieldView from "views/fields/text";

const KEY_FIELDS = [
    { key: "tribunal", label: "Tribunal" },
    { key: "siglaTribunal", label: "Sigla" },
    { key: "linkOrigem", label: "Link original" },
    { key: "link", label: "Link" }, // alias presente em algumas pubs Comunica
    { key: "textoPublicacao", label: "Texto da publicação" },
];

function escapeHtml(str) {
    if (str === null || str === undefined) return "";
    return String(str)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

/**
 * Tenta parsear `value` como JSON. Retorna o objeto ou null se falhar.
 */
export function tryParseJson(value) {
    if (typeof value !== "string" || value.trim() === "") return null;
    try {
        const parsed = JSON.parse(value);
        // Aceita objeto OU array; rejeita primitivos (number/boolean) — não
        // fazem sentido como payload de publicação.
        if (parsed === null || typeof parsed !== "object") return null;
        return parsed;
    } catch (_) {
        return null;
    }
}

/**
 * Renderiza HTML do accordion. Pública para test directly em isolation.
 */
export function renderPayloadHtml(value, labels) {
    const lbls = Object.assign(
        {
            summary: "Payload bruto (clique para expandir)",
            warning: "⚠ Payload não pôde ser parseado como JSON",
        },
        labels || {},
    );

    if (value === null || value === undefined || value === "") {
        return "";
    }

    const parsed = tryParseJson(value);

    if (parsed === null) {
        return (
            `<details class="togare-payload-accordion togare-payload-accordion--invalid">` +
            `<summary>${escapeHtml(lbls.warning)}</summary>` +
            `<pre class="togare-payload-accordion__raw"><code>${escapeHtml(String(value))}</code></pre>` +
            `</details>`
        );
    }

    let keyHtml = "";
    for (const { key, label } of KEY_FIELDS) {
        if (
            Object.prototype.hasOwnProperty.call(parsed, key) &&
            parsed[key] !== null &&
            parsed[key] !== undefined &&
            parsed[key] !== ""
        ) {
            const v = parsed[key];
            const isLink = key === "linkOrigem" || key === "link";
            let valHtml;
            if (isLink) {
                const urlStr = (v && typeof v === "object") ? (v.url || v.href || null) : String(v);
                if (urlStr && /^https?:\/\//i.test(urlStr)) {
                    valHtml = `<a href="${escapeHtml(urlStr)}" target="_blank" rel="noopener">${escapeHtml(urlStr)}</a>`;
                } else {
                    const display = urlStr !== null ? urlStr
                        : (v && typeof v === "object" ? (v.texto || "") : "");
                    valHtml = escapeHtml(display);
                }
            } else {
                valHtml = escapeHtml(String(v));
            }
            keyHtml +=
                `<dt class="togare-payload-accordion__key">${escapeHtml(label)}</dt>` +
                `<dd class="togare-payload-accordion__val">${valHtml}</dd>`;
        }
    }
    const dlHtml = keyHtml
        ? `<dl class="togare-payload-accordion__keys">${keyHtml}</dl>`
        : "";

    const pretty = JSON.stringify(parsed, null, 2);

    return (
        `<details class="togare-payload-accordion">` +
        `<summary>${escapeHtml(lbls.summary)}</summary>` +
        dlHtml +
        `<pre class="togare-payload-accordion__raw"><code>${escapeHtml(pretty)}</code></pre>` +
        `</details>`
    );
}

export default class PayloadJsonFieldView extends TextFieldView {
    /**
     * EspoCRM 9.x renderiza o resultado de `getValueForDisplay()` no slot do
     * field em modo detail/list. Devolvendo HTML bruto, EspoCRM aceita pq
     * o valueIsSet do framework injeta via .html() em vez de .text() para
     * field views custom que opt-in via `useStringTemplate`-like behavior.
     *
     * Para garantir injeção correta em todos modos, override também
     * `afterRender()` substituindo o conteúdo do field-cell quando em
     * modo detail.
     */
    getValueForDisplay() {
        const value = this.model ? this.model.get(this.name) : null;
        const labels = this._collectLabels();
        return renderPayloadHtml(value, labels);
    }

    afterRender() {
        if (super.afterRender) super.afterRender();
        if (this.mode !== this.MODE_DETAIL && this.mode !== this.MODE_LIST) {
            return;
        }
        const value = this.model ? this.model.get(this.name) : null;
        const html = renderPayloadHtml(value, this._collectLabels());
        if (!this.el) return;
        // Procura o slot canônico do EspoCRM; fallback substitui o el inteiro.
        const slot =
            this.el.querySelector(".field") ||
            this.el.querySelector(".cell") ||
            this.el;
        slot.innerHTML = html;
    }

    _collectLabels() {
        if (typeof this.translate !== "function") {
            return undefined;
        }
        try {
            return {
                summary: this.translate("payloadAccordion", "labels", "Prazo"),
                warning: this.translate("payloadAccordionWarning", "labels", "Prazo"),
            };
        } catch (_) {
            return undefined;
        }
    }
}
