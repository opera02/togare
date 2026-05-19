import { describe, it, expect } from "vitest";
import PayloadJsonFieldView, {
    tryParseJson,
    renderPayloadHtml,
} from "../../src/files/client/custom/modules/togare-core/src/views/fields/payload-json.js";

describe("tryParseJson — Story 4a.4 F1.1 (parsing tolerante)", () => {
    it("JSON válido (objeto) → retorna o objeto", () => {
        const obj = tryParseJson('{"a":1,"b":"x"}');
        expect(obj).toEqual({ a: 1, b: "x" });
    });

    it("JSON válido (array) → retorna o array", () => {
        expect(tryParseJson("[1,2,3]")).toEqual([1, 2, 3]);
    });

    it("texto não-JSON → null (não throws)", () => {
        expect(tryParseJson("isto não é json")).toBe(null);
    });

    it("JSON malformado → null (não throws)", () => {
        expect(tryParseJson('{"a":1,')).toBe(null);
    });

    it("string vazia / null / undefined → null", () => {
        expect(tryParseJson("")).toBe(null);
        expect(tryParseJson("   ")).toBe(null);
        expect(tryParseJson(null)).toBe(null);
        expect(tryParseJson(undefined)).toBe(null);
    });

    it("primitivos JSON (number, boolean, string) → null (semanticamente NÃO é payload)", () => {
        expect(tryParseJson("42")).toBe(null);
        expect(tryParseJson("true")).toBe(null);
        expect(tryParseJson('"foo"')).toBe(null);
    });
});

describe("renderPayloadHtml — Story 4a.4 F1.1 (3 cenários AC9)", () => {
    it("AC9 cenário 1 — JSON válido com campos chave: extrai tribunal/sigla/link/texto", () => {
        const payload = JSON.stringify({
            id: 598515727,
            tribunal: "Tribunal de Justiça do Estado de São Paulo",
            siglaTribunal: "TJSP",
            linkOrigem: "https://comunica.pje.jus.br/foo",
            textoPublicacao: "Intime-se a parte autora...",
            outroCampo: "ignorado-não-é-key-field",
        });
        const html = renderPayloadHtml(payload);

        // Accordion colapsado por padrão (sem atributo `open`).
        expect(html).toContain("<details");
        expect(html).not.toContain("<details open");
        expect(html).toContain("Payload bruto (clique para expandir)");

        // Campos chave renderizados em <dl>.
        expect(html).toContain("Tribunal de Justiça do Estado de São Paulo");
        expect(html).toContain("TJSP");
        // linkOrigem vira <a>.
        expect(html).toContain('href="https://comunica.pje.jus.br/foo"');
        expect(html).toContain('target="_blank"');
        expect(html).toContain('rel="noopener"');
        expect(html).toContain("Intime-se a parte autora");

        // Pretty-printed JSON cru também presente (HTML-escaped — defesa XSS).
        expect(html).toContain("&quot;id&quot;: 598515727");

        // Campos não-chave ficam SÓ no JSON cru, NÃO em <dt>.
        expect(html).not.toContain("<dt class=\"togare-payload-accordion__key\">outroCampo</dt>");
    });

    it("AC9 cenário 2 — texto não-JSON: warning + raw escapado", () => {
        const html = renderPayloadHtml("isto < & > não é json");

        expect(html).toContain("togare-payload-accordion--invalid");
        expect(html).toContain("⚠ Payload não pôde ser parseado como JSON");
        // Caracteres especiais escapados (segurança XSS — campo audit/debug
        // pode receber texto adversarial de fontes externas).
        expect(html).toContain("&lt;");
        expect(html).toContain("&amp;");
        expect(html).toContain("&gt;");
    });

    it("AC9 cenário 3 — null/empty: retorna string vazia (campo omitido)", () => {
        expect(renderPayloadHtml(null)).toBe("");
        expect(renderPayloadHtml(undefined)).toBe("");
        expect(renderPayloadHtml("")).toBe("");
    });

    it("JSON sem campos chave: ainda renderiza accordion + raw, sem <dl>", () => {
        const payload = JSON.stringify({ apenas: "outros", campos: 42 });
        const html = renderPayloadHtml(payload);
        expect(html).toContain("<details");
        expect(html).not.toContain("togare-payload-accordion__keys");
        expect(html).toContain("&quot;apenas&quot;: &quot;outros&quot;");
    });

    it("aceita override de labels via 2º param (i18n hook)", () => {
        const html = renderPayloadHtml('{"a":1}', {
            summary: "Custom summary label",
            warning: "Custom warning",
        });
        expect(html).toContain("Custom summary label");
        expect(html).not.toContain("Payload bruto (clique para expandir)");
    });

    it("XSS guard: chave-de-i18n maliciosa também é escapada", () => {
        const html = renderPayloadHtml('{"tribunal":"<script>alert(1)</script>"}');
        expect(html).not.toContain("<script>");
        expect(html).toContain("&lt;script&gt;");
    });
});

describe("renderPayloadHtml — patches code review Grupo B (P1 — segurança links)", () => {
    it("P1 — linkOrigem com javascript: URI NÃO gera <a> (XSS stored via payload externo)", () => {
        const html = renderPayloadHtml(JSON.stringify({ linkOrigem: "javascript:alert(1)" }));
        expect(html).not.toContain("<a href");
        // Valor ainda aparece como texto (escapado).
        expect(html).toContain("javascript:alert(1)");
    });

    it("P1 — linkOrigem como objeto { url } extrai URL corretamente e gera <a>", () => {
        const html = renderPayloadHtml(
            JSON.stringify({ linkOrigem: { url: "https://pje.tjsp.jus.br/foo", texto: "Ver" } }),
        );
        expect(html).toContain('href="https://pje.tjsp.jus.br/foo"');
        expect(html).toContain('target="_blank"');
        expect(html).not.toContain("[object Object]");
    });

    it("P1 — linkOrigem como objeto sem url/href → renderiza como texto sem <a>", () => {
        const html = renderPayloadHtml(
            JSON.stringify({ linkOrigem: { texto: "Apenas texto" } }),
        );
        expect(html).not.toContain("<a href");
        expect(html).not.toContain("[object Object]");
    });

    it("P1 — link (alias) com https válido → gera <a>", () => {
        const html = renderPayloadHtml(
            JSON.stringify({ link: "https://esaj.tjsp.jus.br/bar" }),
        );
        expect(html).toContain('href="https://esaj.tjsp.jus.br/bar"');
    });

    it("P1 — link com http:// (não https) → também gera <a> (esquema permitido)", () => {
        const html = renderPayloadHtml(JSON.stringify({ link: "http://legado.tjsp.jus.br" }));
        expect(html).toContain('<a href="http://legado.tjsp.jus.br"');
    });
});

describe("PayloadJsonFieldView (instância — getValueForDisplay)", () => {
    function makeView(value) {
        const v = new PayloadJsonFieldView({
            name: "publicacaoOrigemRaw",
            model: {
                _data: { publicacaoOrigemRaw: value },
                get(k) {
                    return this._data[k];
                },
            },
        });
        // Espelha mock — translate inexistente → labels default.
        v.translate = undefined;
        return v;
    }

    it("getValueForDisplay com JSON válido → retorna HTML com accordion + campos chave", () => {
        const view = makeView('{"tribunal":"TJSP","textoPublicacao":"Foo"}');
        const out = view.getValueForDisplay();
        expect(out).toContain("<details");
        expect(out).toContain("TJSP");
        expect(out).toContain("Foo");
    });

    it("getValueForDisplay com null → retorna string vazia (campo omitido)", () => {
        const view = makeView(null);
        expect(view.getValueForDisplay()).toBe("");
    });

    it("getValueForDisplay com texto não-JSON → retorna HTML com warning", () => {
        const view = makeView("texto cru qualquer");
        const out = view.getValueForDisplay();
        expect(out).toContain("Payload não pôde ser parseado");
    });
});
