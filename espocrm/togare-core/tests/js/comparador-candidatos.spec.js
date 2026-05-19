import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import ComparadorCandidatosView from "../../src/files/client/custom/modules/togare-core/src/views/publicacao-ambigua/comparador-candidatos.js";

/**
 * Story 4b.1c — UX C10 ComparadorCandidatos (stack vertical, 5 cores,
 * 1-clique CTAs, XSS-safe).
 *
 * Cobre AC4 / AC5 / AC6 / AC7 / AC8 / AC9 (parcial — focus listener).
 *
 * Defesas exercitadas: XSS via Espo.Utils.escapeHtml ANTES do <mark>,
 * body shape correto do POST resolve / ignore / bulkIgnoreProcesso,
 * readonly mode (Assistente), aria-describedby em focus.
 */

function makeModel(overrides = {}) {
    const data = {
        id: "pub-1",
        texto: "",
        candidatos: "[]",
        ...overrides,
    };
    return {
        id: data.id,
        attributes: data,
        get(k) {
            return data[k];
        },
        set(k, v) {
            data[k] = v;
        },
    };
}

function buildView({ candidatos = [], texto = "", readonly = false, parentDetailView = null } = {}) {
    const model = makeModel({
        texto,
        candidatos: JSON.stringify(candidatos),
    });
    const view = new ComparadorCandidatosView({
        model,
        parentDetailView,
    });
    // Mock View base não atribui `this.model = options.model` automaticamente
    // nem chama setup(). Em runtime EspoCRM, viewFactory cuida disso. Aqui
    // garantimos manualmente.
    view.model = model;
    // Stub getAcl baseado no flag readonly.
    view.getAcl = () => ({
        check(_m, _action) {
            return !readonly;
        },
    });
    // Stub getLanguage com translateOption real-ish.
    view.getLanguage = () => ({
        translate: () => "",
        translateOption(value, _fieldName, _scope) {
            const map = {
                civel: "Cível",
                trabalhista: "Trabalhista",
                conhecimento: "Conhecimento",
                recurso: "Recurso",
            };
            return map[value] || value;
        },
    });
    // Chama setup() para popular this._parentDetailView a partir de options.
    view.setup();
    return view;
}

const SAMPLE_CANDIDATO_A = {
    processoId: "proc-a",
    numeroCnj: "12345678920248260001",
    clienteNome: "João Silva",
    parteContrariaNome: "Maria Souza",
    dataDistribuicao: "2024-03-15",
    area: "civel",
    fase: "conhecimento",
    codigoCor: "azul",
};
const SAMPLE_CANDIDATO_B = {
    processoId: "proc-b",
    numeroCnj: "23456789020248260001",
    clienteNome: "João Silva",
    parteContrariaNome: "Pedro Costa",
    dataDistribuicao: "2024-07-02",
    area: "civel",
    fase: "conhecimento",
    codigoCor: "laranja",
};

describe("ComparadorCandidatosView — render (AC4)", () => {
    let originalEspo;

    beforeEach(() => {
        originalEspo = window.Espo;
        window.Espo = {
            Ajax: {
                postRequest: vi.fn(() => Promise.resolve({})),
            },
            Ui: {
                Dialog: vi.fn().mockImplementation(function (opts) {
                    this.opts = opts;
                    this.show = vi.fn();
                    this.close = vi.fn();
                    this.$el = [document.createElement("div")];
                }),
                success: vi.fn(),
                warning: vi.fn(),
                error: vi.fn(),
                notify: vi.fn(),
            },
            Utils: {
                escapeHtml: (s) =>
                    String(s == null ? "" : s)
                        .replace(/&/g, "&amp;")
                        .replace(/</g, "&lt;")
                        .replace(/>/g, "&gt;")
                        .replace(/"/g, "&quot;")
                        .replace(/'/g, "&#39;"),
            },
        };
    });

    afterEach(() => {
        window.Espo = originalEspo;
    });

    it("renderiza 2 cards (azul + laranja) para 2 candidatos", async () => {
        const v = buildView({ candidatos: [SAMPLE_CANDIDATO_A, SAMPLE_CANDIDATO_B] });
        await v.render();
        const cards = v.el.querySelectorAll(".togare-pub-ambigua__card");
        expect(cards.length).toBe(2);
        expect(cards[0].classList.contains("togare-pub-ambigua__card--azul")).toBe(true);
        expect(cards[1].classList.contains("togare-pub-ambigua__card--laranja")).toBe(true);
        // Heading colorblind-safe
        const headings = v.el.querySelectorAll(".togare-pub-ambigua__candidato-heading");
        expect(headings[0].textContent).toContain("Candidato A");
        expect(headings[1].textContent).toContain("Candidato B");
    });

    it("renderiza 5 candidatos com 5 cores distintas em ordem", async () => {
        const candidatos = ["azul", "laranja", "verde", "roxo", "vermelho"].map((cor, i) => ({
            ...SAMPLE_CANDIDATO_A,
            processoId: `proc-${i}`,
            clienteNome: `Cliente ${i}`,
            codigoCor: cor,
        }));
        const v = buildView({ candidatos });
        await v.render();
        const cards = v.el.querySelectorAll(".togare-pub-ambigua__card");
        expect(cards.length).toBe(5);
        const cores = ["azul", "laranja", "verde", "roxo", "vermelho"];
        cards.forEach((card, i) => {
            expect(card.classList.contains(`togare-pub-ambigua__card--${cores[i]}`)).toBe(true);
        });
    });

    it("EmptyStateCalmo `cartaoSemCandidatos` quando candidatos=[]", async () => {
        const v = buildView({ candidatos: [] });
        await v.render();
        const empty = v.el.querySelector(".togare-pub-ambigua__empty");
        expect(empty).toBeTruthy();
        expect(empty.textContent).toMatch(/snapshotted|fila/i);
    });

    it("graceful degradation com candidatos JSON inválido", async () => {
        const model = makeModel({ candidatos: "not-json{" });
        const v = new ComparadorCandidatosView({ model });
        v.model = model;
        v.getAcl = () => ({ check: () => true });
        v.getLanguage = () => ({ translate: () => "", translateOption: (x) => x });
        await v.render();
        const empty = v.el.querySelector(".togare-pub-ambigua__empty");
        expect(empty).toBeTruthy();
    });

    it("destaca clienteNome e parteContrariaNome em <mark> amarelo (XSS-safe)", async () => {
        const texto =
            "Publicação intimando João Silva e Maria Souza para audiência conciliatória.";
        const v = buildView({
            candidatos: [SAMPLE_CANDIDATO_A, SAMPLE_CANDIDATO_B],
            texto,
        });
        await v.render();
        const textoNode = v.el.querySelector(".togare-pub-ambigua__texto");
        const html = textoNode.innerHTML;
        // João Silva é clienteNome em ambos candidatos.
        expect(html).toMatch(
            /<mark class="togare-pub-ambigua__mark">João Silva<\/mark>/,
        );
        // Maria Souza é parteContrariaNome do candidato A.
        expect(html).toMatch(
            /<mark class="togare-pub-ambigua__mark">Maria Souza<\/mark>/,
        );
        // Pedro Costa é parteContrariaNome do candidato B mas NÃO está no
        // trecho — então não deve aparecer marcado (verifica que não há
        // marca espúria).
        expect(html).not.toMatch(
            /<mark class="togare-pub-ambigua__mark">Pedro Costa/,
        );
    });

    it("defesa XSS — texto e nomes maliciosos ficam escapados (sem tags executáveis)", async () => {
        const texto = "<script>alert(1)</script> intimação";
        const candidatoMalicioso = {
            ...SAMPLE_CANDIDATO_A,
            clienteNome: "<img src=x onerror=alert(2)>",
        };
        const v = buildView({
            candidatos: [candidatoMalicioso, SAMPLE_CANDIDATO_B],
            texto,
        });
        await v.render();
        const textoNode = v.el.querySelector(".togare-pub-ambigua__texto");
        const html = textoNode.innerHTML;
        // <script> do texto vira &lt;script&gt;
        expect(html).not.toMatch(/<script>/i);
        expect(html).toMatch(/&lt;script&gt;/);
        // O nome malicioso aparece dentro de <mark> mas escapado.
        expect(html).not.toMatch(/<img\s+src=x\s+onerror/i);
    });

    it("highlight não reprocessa o HTML dos <mark> já inseridos", async () => {
        const v = buildView({
            candidatos: [
                { ...SAMPLE_CANDIDATO_A, clienteNome: "Mark", parteContrariaNome: "Silva" },
            ],
            texto: "Mark Silva foi intimado.",
        });
        await v.render();
        const html = v.el.querySelector(".togare-pub-ambigua__texto").innerHTML;
        expect(html).toContain('<mark class="togare-pub-ambigua__mark">Mark</mark>');
        expect(html).not.toContain("togare-pub-ambigua__<mark");
    });
});

describe("ComparadorCandidatosView — interações (AC5/AC6/AC7)", () => {
    let originalEspo;
    let postSpy;
    let toastSpy;

    beforeEach(() => {
        postSpy = vi.fn(() => Promise.resolve({ prazoId: "prazo-novo" }));
        originalEspo = window.Espo;
        // Story 4b.1c v0.25.3 fix-pass (B-NEW-3): _onIgnorar e
        // _onBulkIgnoreCandidato agora usam Espo.Ui.confirm (helper de mais
        // alto nível) em vez de new Espo.Ui.Dialog. Mock auto-invoca o
        // confirmCallback (3º arg) para exercitar o caminho de sucesso.
        const confirmSpy = vi.fn((_body, _opts, confirmCb /*, cancelCb*/) => {
            if (typeof confirmCb === "function") confirmCb();
        });
        window.Espo = {
            Ajax: { postRequest: postSpy },
            Ui: {
                Dialog: vi.fn().mockImplementation(function (opts) {
                    // Mantido por compat com testes legados que ainda fazem
                    // expect(Dialog) — mas as views custom usam confirm() agora.
                    this.opts = opts;
                    this.show = vi.fn();
                    this.close = vi.fn();
                    this.$el = [document.createElement("div")];
                }),
                confirm: confirmSpy,
                success: vi.fn(),
                warning: vi.fn(),
                error: vi.fn(),
                notify: vi.fn(),
            },
            Utils: {
                escapeHtml: (s) => String(s == null ? "" : s).replace(/</g, "&lt;").replace(/>/g, "&gt;"),
            },
        };
        toastSpy = vi.fn();
        // Spy no ToastTogareView.show é difícil de injetar; checamos via window.Espo.Ui.success/warning/error.
    });

    afterEach(() => {
        window.Espo = originalEspo;
    });

    it("CTA confirm-candidato dispara POST resolve com body shape correto", async () => {
        const parent = { redirectToNextOrList: vi.fn() };
        const v = buildView({
            candidatos: [SAMPLE_CANDIDATO_A, SAMPLE_CANDIDATO_B],
            parentDetailView: parent,
        });
        await v.render();
        const btn = v.el.querySelector('[data-action="confirm-candidato"][data-candidato-idx="0"]');
        btn.click();
        expect(window.Espo.Ui.notify).toHaveBeenCalled();
        expect(btn.querySelector(".togare-pub-ambigua__spinner")).toBeTruthy();
        // Promise resolve em microtask
        await Promise.resolve();
        await Promise.resolve();
        expect(postSpy).toHaveBeenCalledTimes(1);
        const [path, body] = postSpy.mock.calls[0];
        expect(path).toBe("TogareDjenPublicacaoAmbigua/action/resolve");
        expect(body).toEqual({
            publicacaoAmbiguaId: "pub-1",
            chosenProcessoId: "proc-a",
        });
    });

    it("_onIgnorar abre Espo.Ui.confirm antes do POST ignore (AC6)", async () => {
        const parent = { redirectToNextOrList: vi.fn() };
        const v = buildView({
            candidatos: [SAMPLE_CANDIDATO_A, SAMPLE_CANDIDATO_B],
            parentDetailView: parent,
        });
        await v.render();
        // Chama o handler diretamente — confirm() auto-invoca confirmCallback
        // via mock no beforeEach. Isso exercita o caminho confirm → POST →
        // toast → redirect.
        v._onIgnorar();
        expect(window.Espo.Ui.confirm).toHaveBeenCalled();
        await Promise.resolve();
        await Promise.resolve();
        await Promise.resolve();
        const ignoreCall = postSpy.mock.calls.find(
            (c) => c[0] === "TogareDjenPublicacaoAmbigua/action/ignore",
        );
        expect(ignoreCall).toBeTruthy();
        expect(ignoreCall[1]).toEqual({ publicacaoAmbiguaId: "pub-1" });
    });

    it("_onBulkIgnoreCandidato dispara POST bulkIgnoreProcesso com processoId correto (AC7)", async () => {
        const parent = { redirectToNextOrList: vi.fn() };
        const v = buildView({
            candidatos: [SAMPLE_CANDIDATO_A, SAMPLE_CANDIDATO_B],
            parentDetailView: parent,
        });
        await v.render();
        v._onBulkIgnoreCandidato("proc-b", "B");
        expect(window.Espo.Ui.confirm).toHaveBeenCalled();
        await Promise.resolve();
        await Promise.resolve();
        await Promise.resolve();
        const bulkCall = postSpy.mock.calls.find(
            (c) => c[0] === "TogareDjenPublicacaoAmbigua/action/bulkIgnoreProcesso",
        );
        expect(bulkCall).toBeTruthy();
        expect(bulkCall[1]).toEqual({ processoId: "proc-b" });
    });

    it("_onBulkIgnoreCandidato interpola processoCnj no toast de sucesso (AC7)", async () => {
        postSpy.mockImplementationOnce(() => Promise.resolve({ count: 5 }));
        const v = buildView({
            candidatos: [SAMPLE_CANDIDATO_A, SAMPLE_CANDIDATO_B],
            parentDetailView: { redirectToNextOrList: vi.fn() },
        });
        v._toast = vi.fn();
        await v.render();
        v._onBulkIgnoreCandidato("proc-b", "B", SAMPLE_CANDIDATO_B.numeroCnj);
        await Promise.resolve();
        await Promise.resolve();
        await Promise.resolve();
        expect(v._toast).toHaveBeenCalledWith(expect.objectContaining({
            variant: "success",
            message: expect.stringContaining("2345678-90.2024.8.26.0001"),
        }));
    });

    it("não abre dialogs duplicados antes de confirmar ignore", async () => {
        // Mock para NÃO auto-invocar confirmCallback aqui, simulando dialog
        // pendente — assim o `_dialogOpen` flag impede a 2ª chamada.
        const noopConfirm = vi.fn();
        window.Espo.Ui.confirm = noopConfirm;
        const v = buildView({ candidatos: [SAMPLE_CANDIDATO_A] });
        await v.render();
        v._onIgnorar();
        v._onIgnorar();
        expect(noopConfirm).toHaveBeenCalledTimes(1);
    });

    it("readonly (Assistente) — 3 botões com disabled + aria-disabled + tooltip (AC4)", async () => {
        // Renderiza HTML diretamente (sem depender de event bubbling) e
        // verifica os atributos no markup gerado por _buildHtml.
        const v = buildView({
            candidatos: [SAMPLE_CANDIDATO_A, SAMPLE_CANDIDATO_B],
            readonly: true,
        });
        const html = v._buildHtml(
            [SAMPLE_CANDIDATO_A, SAMPLE_CANDIDATO_B],
            true,
        );
        // 2 confirms + 2 bulk + 1 ignore = 5 botões disabled.
        const disabledCount = (html.match(/ disabled aria-disabled="true"/g) || []).length;
        expect(disabledCount).toBe(5);
        // Tooltip presente
        expect(html).toMatch(/title="[^"]*Apenas Advogado[^"]*"/);
    });

    it("aria-describedby do card aponta para o trecho destacado (AC9)", async () => {
        const v = buildView({
            candidatos: [SAMPLE_CANDIDATO_A, SAMPLE_CANDIDATO_B],
            texto: "Texto da publicação com João Silva.",
        });
        await v.render();
        document.body.appendChild(v.el);
        const card0 = v.el.querySelector('[data-candidato-idx="0"]');
        const textoNode = v.el.querySelector(".togare-pub-ambigua__texto");
        expect(card0).toBeTruthy();
        expect(textoNode).toBeTruthy();
        card0.dispatchEvent(new Event("focus", { bubbles: true }));
        expect(card0.getAttribute("aria-describedby")).toBe(textoNode.id);
        v.el.remove();
    });
});

describe("ComparadorCandidatosView — _handleAjaxError (AC8)", () => {
    let originalEspo;
    let postSpy;

    beforeEach(() => {
        postSpy = vi.fn();
        originalEspo = window.Espo;
        window.Espo = {
            Ajax: { postRequest: postSpy },
            Ui: {
                Dialog: vi.fn(),
                success: vi.fn(),
                warning: vi.fn(),
                error: vi.fn(),
                notify: vi.fn(),
            },
            Utils: { escapeHtml: (s) => String(s) },
        };
    });

    afterEach(() => {
        window.Espo = originalEspo;
        vi.useRealTimers();
    });

    it("response 409 chama _notifyParentRedirect após 2s (AC8)", async () => {
        const parent = { redirectToNextOrList: vi.fn() };
        postSpy.mockImplementationOnce(() => Promise.reject({ status: 409 }));
        const v = buildView({
            candidatos: [SAMPLE_CANDIDATO_A, SAMPLE_CANDIDATO_B],
            parentDetailView: parent,
        });
        await v.render();
        // Chamada direta exercita o caminho catch → toast warning + setTimeout 2s.
        await v._onConfirmar(SAMPLE_CANDIDATO_A);
        // O catch já foi chamado (await garantiu microtasks). Antes do
        // setTimeout disparar, redirect ainda não foi chamado.
        expect(parent.redirectToNextOrList).not.toHaveBeenCalled();
        // Aguarda > 2s real (jsdom default real-timer); 2.1s é suficiente.
        await new Promise((r) => setTimeout(r, 2100));
        expect(parent.redirectToNextOrList).toHaveBeenCalledTimes(1);
    });

    it("response 409 usa mensagem do backend sem deixar placeholder {timestamp}", async () => {
        postSpy.mockImplementationOnce(() => Promise.reject({
            status: 409,
            responseJSON: { messageTranslation: "Já resolvida em 2026-05-08 10:00." },
        }));
        const v = buildView({ candidatos: [SAMPLE_CANDIDATO_A] });
        v._toast = vi.fn();
        await v.render();
        await v._onConfirmar(SAMPLE_CANDIDATO_A);
        expect(v._toast).toHaveBeenCalledWith(expect.objectContaining({
            variant: "warning",
            message: "Já resolvida em 2026-05-08 10:00.",
        }));
        expect(v._toast.mock.calls[0][0].message).not.toContain("{timestamp}");
    });

    it("response 400 NÃO redireciona, reabilita botões (AC8)", async () => {
        const parent = { redirectToNextOrList: vi.fn() };
        postSpy.mockImplementationOnce(() => Promise.reject({ status: 400 }));
        const v = buildView({
            candidatos: [SAMPLE_CANDIDATO_A, SAMPLE_CANDIDATO_B],
            parentDetailView: parent,
        });
        await v.render();
        // await garante .finally rodou antes da assertion (_busy → false).
        await v._onConfirmar(SAMPLE_CANDIDATO_A);
        expect(parent.redirectToNextOrList).not.toHaveBeenCalled();
        // _setAllButtonsDisabled(false) reabilita CTAs após erro recoverable.
        // Verificamos o flag _busy = false após o catch + finally.
        expect(v._busy).toBe(false);
    });

    it("response 400 prefere messageTranslation/statusText do backend", async () => {
        postSpy.mockImplementationOnce(() => Promise.reject({
            status: 400,
            responseJSON: { messageTranslation: "Processo manipulado fora da lista." },
        }));
        const v = buildView({ candidatos: [SAMPLE_CANDIDATO_A] });
        v._toast = vi.fn();
        await v.render();
        await v._onConfirmar(SAMPLE_CANDIDATO_A);
        expect(v._toast).toHaveBeenCalledWith(expect.objectContaining({
            variant: "error",
            message: "Processo manipulado fora da lista.",
        }));
    });

    it("response 5xx mantém na pub e oferece ação Tentar de novo", async () => {
        postSpy.mockImplementationOnce(() => Promise.reject({ status: 500 }));
        const v = buildView({ candidatos: [SAMPLE_CANDIDATO_A] });
        v._toast = vi.fn();
        await v.render();
        await v._onConfirmar(SAMPLE_CANDIDATO_A);
        expect(v._toast).toHaveBeenCalledWith(expect.objectContaining({
            variant: "error",
            actionLabel: expect.stringMatching(/Tentar/i),
            onAction: expect.any(Function),
        }));
    });
});
