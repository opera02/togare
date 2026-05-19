import { describe, it, expect } from "vitest";
import {
    renderCardDePrazo,
    escapeHtml,
} from "../../src/files/client/custom/modules/togare-core/src/helpers/card-de-prazo-renderer.js";

const FAKE_NOW = new Date("2026-05-05T12:00:00Z");

function fakeFormatCnj(v) {
    if (typeof v !== "string" || v.length !== 20) return v;
    return v.slice(0, 7) + "-" + v.slice(7, 9) + "." + v.slice(9, 13) + "." +
        v.slice(13, 14) + "." + v.slice(14, 16) + "." + v.slice(16, 20);
}

describe("renderCardDePrazo — Story 4a.4 T9 (AC1+AC2+AC3+AC4+AC15)", () => {
    it("AC1 — header completo: StatusBadge + chip tipoPrazo + chip prioridade + CNJ formatado + descricao", () => {
        const html = renderCardDePrazo(
            {
                id: "abc-123",
                status: "pendente",
                tipoPrazo: "contestacao",
                prioridade: "alta",
                numeroProcessoOriginal: "10228312720208260001",
                descricao: "Contestação à inicial",
                dataFatal: "2026-05-25",
                contagem: "uteis",
            },
            { formatCnj: fakeFormatCnj, now: FAKE_NOW },
        );
        expect(html).toContain("togare-card-de-prazo__badge--warning"); // pendente=warning
        expect(html).toContain("Pendente");
        expect(html).toContain("Contestação");
        expect(html).toContain("Alta");
        expect(html).toContain("▴"); // prioridade=alta icon (urgente=🔥; alta=▴)
        expect(html).toContain("📑"); // tipoPrazo icon
        expect(html).toContain("Proc. 1022831-27.2020.8.26.0001");
        expect(html).toContain("Contestação à inicial");
    });

    it("AC1 — chip tipoPrazo é OMITIDO quando tipoPrazo está vazio (não classificado)", () => {
        const html = renderCardDePrazo(
            {
                id: "x",
                status: "pendente",
                prioridade: "normal",
                tipoPrazo: "",
                numeroProcessoOriginal: "10228312720208260001",
            },
            { formatCnj: fakeFormatCnj, now: FAKE_NOW },
        );
        expect(html).not.toContain("togare-card-de-prazo__chip--tipo-prazo");
        // Mas chip prioridade ainda está.
        expect(html).toContain("togare-card-de-prazo__chip--prioridade");
    });

    it("AC1 — linha descricao é OMITIDA quando descricao está vazia", () => {
        const html = renderCardDePrazo(
            { id: "x", status: "pendente", numeroProcessoOriginal: "abc" },
            { now: FAKE_NOW },
        );
        expect(html).not.toContain("togare-card-de-prazo__descricao");
    });

    it("AC2 — fonteExcerpt > 200 chars renderiza 200 chars + 'Ler mais' button", () => {
        const longText = "a".repeat(250);
        const html = renderCardDePrazo(
            { id: "x", status: "pendente", fonteExcerpt: longText },
            { now: FAKE_NOW },
        );
        expect(html).toContain('data-action="ler-mais"');
        expect(html).toContain("Ler mais");
        expect(html).toContain("data-full=");
        expect(html).toContain("data-truncated=");
    });

    it("AC2 — fonteExcerpt curto (≤200 chars) NÃO mostra Ler mais", () => {
        const html = renderCardDePrazo(
            { id: "x", status: "pendente", fonteExcerpt: "texto curto" },
            { now: FAKE_NOW },
        );
        expect(html).toContain("texto curto");
        expect(html).not.toContain('data-action="ler-mais"');
    });

    it("AC2 — fonteExcerpt vazio omite o bloco inteiro", () => {
        const html = renderCardDePrazo(
            { id: "x", status: "pendente" },
            { now: FAKE_NOW },
        );
        expect(html).not.toContain("togare-card-de-prazo__excerpt");
    });

    it("AC3 — rodapé: data fatal BR + dias restantes calendário + tipo contagem pt-BR", () => {
        const html = renderCardDePrazo(
            {
                id: "x",
                status: "pendente",
                dataFatal: "2026-05-25",
                contagem: "uteis",
            },
            { now: FAKE_NOW },
        );
        expect(html).toContain("Data fatal");
        expect(html).toContain("25/05/2026");
        expect(html).toContain("(20 dias)");
        expect(html).toContain("Dias úteis");
    });

    it("AC3 — links cliente + parteContraria com nome resolvido", () => {
        const html = renderCardDePrazo(
            {
                id: "x",
                status: "pendente",
                cliente: { id: "cli-001", name: "João Silva" },
                parteContraria: { id: "pc-001", name: "Empresa X SA" },
            },
            { now: FAKE_NOW },
        );
        expect(html).toContain('href="#Cliente/view/cli-001"');
        expect(html).toContain("João Silva");
        expect(html).toContain('href="#ParteContraria/view/pc-001"');
        expect(html).toContain("Empresa X SA");
    });

    it("AC3 — sem cliente/parte → linhas omitidas", () => {
        const html = renderCardDePrazo(
            { id: "x", status: "pendente" },
            { now: FAKE_NOW },
        );
        expect(html).not.toContain("togare-card-de-prazo__vinculo");
    });

    it("AC3 — Ver no DJEN ↗ aparece quando sourcePubId existe", () => {
        const html = renderCardDePrazo(
            { id: "x", status: "pendente", sourcePubId: 598515727 },
            { now: FAKE_NOW },
        );
        expect(html).toContain('href="https://comunica.pje.jus.br/consulta/comunicacao/598515727"');
        expect(html).toContain("Ver no DJEN");
        expect(html).toContain('target="_blank"');
        expect(html).toContain('rel="noopener"');
    });

    it("AC3 — sem sourcePubId → link Ver no DJEN omitido", () => {
        const html = renderCardDePrazo(
            { id: "x", status: "pendente" },
            { now: FAKE_NOW },
        );
        expect(html).not.toContain("comunica.pje.jus.br");
    });

    it("AC4 — HedgeBanner inline aparece via helpers.hedgeBannerHtml", () => {
        const html = renderCardDePrazo(
            { id: "x", status: "pendente" },
            {
                now: FAKE_NOW,
                hedgeBannerHtml: '<div class="togare-hedge-banner togare-hedge-banner--module-deadline">HEDGE</div>',
            },
        );
        expect(html).toContain("togare-hedge-banner--module-deadline");
        expect(html).toContain("HEDGE");
    });

    it("AC10 — CNJ formatado via formatCnj helper (20 dígitos puros vira mascarado)", () => {
        const html = renderCardDePrazo(
            { id: "x", status: "pendente", numeroProcessoOriginal: "10228312720208260001" },
            { formatCnj: fakeFormatCnj, now: FAKE_NOW },
        );
        expect(html).toContain("1022831-27.2020.8.26.0001");
    });

    it("AC10 — CNJ inválido (≠20 dígitos) → passa-through", () => {
        const html = renderCardDePrazo(
            { id: "x", status: "pendente", numeroProcessoOriginal: "abc-not-cnj" },
            { formatCnj: fakeFormatCnj, now: FAKE_NOW },
        );
        expect(html).toContain("Proc. abc-not-cnj");
    });

    it("AC15 — chips descricao + prioridade aparecem com cor por prioridade", () => {
        const html = renderCardDePrazo(
            { id: "x", status: "pendente", prioridade: "urgente", descricao: "Texto" },
            { now: FAKE_NOW },
        );
        expect(html).toContain("togare-card-de-prazo__chip--prioridade-urgente");
        expect(html).toContain("Urgente");
        expect(html).toContain("Texto");
    });

    it("XSS guard — descricao com tags HTML é escapada", () => {
        const html = renderCardDePrazo(
            { id: "x", status: "pendente", descricao: "<script>alert(1)</script>" },
            { now: FAKE_NOW },
        );
        expect(html).not.toContain("<script>alert");
        expect(html).toContain("&lt;script&gt;");
    });

    it("XSS guard — cliente.name com aspas é escapado", () => {
        const html = renderCardDePrazo(
            {
                id: "x",
                status: "pendente",
                cliente: { id: "c1", name: 'João "Aspas"' },
            },
            { now: FAKE_NOW },
        );
        expect(html).toContain('João &quot;Aspas&quot;');
    });

    it("data-id = model.id permite click handlers externos identificarem o card", () => {
        const html = renderCardDePrazo(
            { id: "abc-123", status: "pendente" },
            { now: FAKE_NOW },
        );
        expect(html).toContain('data-id="abc-123"');
    });

    it("translate function é usada quando passada (override de fallback)", () => {
        const translate = (key, category) => {
            if (category === "options" && key === "pendente") return "PENDENTE_CUSTOM";
            return undefined;
        };
        const html = renderCardDePrazo(
            { id: "x", status: "pendente" },
            { translate, now: FAKE_NOW },
        );
        expect(html).toContain("PENDENTE_CUSTOM");
        expect(html).not.toContain(">Pendente<");
    });

    it("escapeHtml exposto: cobre as 5 entidades canônicas", () => {
        expect(escapeHtml("<>&\"'")).toBe("&lt;&gt;&amp;&quot;&#39;");
        expect(escapeHtml(null)).toBe("");
        expect(escapeHtml(undefined)).toBe("");
    });
});

describe("renderCardDePrazo — render integrado smoke (todos campos ON)", () => {
    it("Prazo full-feature renderiza todos os blocos sem erro", () => {
        const html = renderCardDePrazo(
            {
                id: "p-001",
                status: "atrasado_reagendado",
                tipoPrazo: "contestacao",
                prioridade: "urgente",
                descricao: "Defesa pendente — protocolar até EOD",
                fonteExcerpt: "Intime-se a parte autora para manifestar-se sobre a contestação no prazo legal de 15 dias úteis. " + "Lorem ipsum ".repeat(20),
                dataFatal: "2026-05-15",
                contagem: "corridos",
                sourcePubId: 12345,
                numeroProcessoOriginal: "10228312720208260001",
                cliente: { id: "cli", name: "Cliente A" },
                parteContraria: { id: "pc", name: "Parte B" },
            },
            {
                formatCnj: fakeFormatCnj,
                now: FAKE_NOW,
                hedgeBannerHtml: '<div class="hedge">⚠ HEDGE</div>',
            },
        );
        // Smoke: todas as seções estão presentes.
        expect(html).toContain("togare-card-de-prazo__badge--danger"); // atrasado_reagendado
        expect(html).toContain("Atrasado/Reagendado");
        expect(html).toContain("Contestação"); // chip tipoPrazo
        expect(html).toContain("Urgente"); // chip prioridade
        expect(html).toContain("Defesa pendente"); // descricao
        expect(html).toContain('data-action="ler-mais"'); // excerpt longo
        expect(html).toContain("15/05/2026"); // dataFatal
        expect(html).toContain("(10 dias)"); // 5/5 → 15/5 = 10 dias
        expect(html).toContain("corridos"); // contagem
        expect(html).toContain("comunica.pje.jus.br"); // DJEN link
        expect(html).toContain("1022831-27.2020.8.26.0001"); // CNJ formatado
        expect(html).toContain("#Cliente/view/cli");
        expect(html).toContain("#ParteContraria/view/pc");
        expect(html).toContain("HEDGE");
        expect(html).toContain('href="#Prazo/view/p-001"'); // botão Revisar
        expect(html).toContain("Dias corridos"); // contagem fallback alinhado ao i18n
    });
});

describe("renderCardDePrazo — patches code review Grupo A", () => {
    it("P1 — status=undefined não renderiza 'undefined' no badge (guard null key)", () => {
        const html = renderCardDePrazo({ id: "x", status: undefined }, { now: FAKE_NOW });
        expect(html).not.toContain(">undefined<");
        expect(html).not.toContain("undefined");
    });

    it("P2 — prioridade com valor desconhecido/injetado omite o chip inteiro (whitelist)", () => {
        const html = renderCardDePrazo(
            { id: "x", status: "pendente", prioridade: 'alta" onmouseover="alert(1)' },
            { now: FAKE_NOW },
        );
        expect(html).not.toContain('onmouseover');
        expect(html).not.toContain("togare-card-de-prazo__chip--prioridade");
    });

    it("P6 — StatusBadge inclui ícone colorblind-safe (AC16: cor+ícone+label)", () => {
        const cases = [
            { status: "pendente", icon: "🟡" },
            { status: "atrasado_reagendado", icon: "⚠️" },
            { status: "protocolado", icon: "✅" },
            { status: "descartado", icon: "⊘" },
        ];
        for (const { status, icon } of cases) {
            const html = renderCardDePrazo({ id: "x", status }, { now: FAKE_NOW });
            expect(html).toContain(icon);
        }
    });

    it("P7 — CONTAGEM_LABELS_FALLBACK alinhado ao i18n ('Dias úteis' / 'Dias corridos')", () => {
        const htmlUteis = renderCardDePrazo(
            { id: "x", status: "pendente", dataFatal: "2026-05-25", contagem: "uteis" },
            { now: FAKE_NOW },
        );
        expect(htmlUteis).toContain("Dias úteis");
        const htmlCorridos = renderCardDePrazo(
            { id: "x", status: "pendente", dataFatal: "2026-05-25", contagem: "corridos" },
            { now: FAKE_NOW },
        );
        expect(htmlCorridos).toContain("Dias corridos");
    });
});

// ====== Story 4b.3 — UX-DR10 redundância semântica D-0 ======
describe("renderCardDePrazo — Story 4b.3 (D-0 redundância semântica UX-DR10)", () => {
    // Note: FAKE_NOW = 2026-05-05T12:00:00Z = 2026-05-05T09:00 BRT — usamos
    // como dataFatal para casos D-0.
    const TODAY_BRT_YMD = "2026-05-05";

    function runtimeTodayBrtYmd() {
        return new Intl.DateTimeFormat("en-CA", {
            timeZone: "America/Sao_Paulo",
            year: "numeric",
            month: "2-digit",
            day: "2-digit",
        }).format(new Date());
    }

    it("AC8 - sem helpers.now em runtime ainda detecta dataFatal=hoje", () => {
        const html = renderCardDePrazo(
            {
                id: "runtime-today",
                status: "pendente",
                dataFatal: runtimeTodayBrtYmd(),
            },
            { formatCnj: fakeFormatCnj },
        );

        expect(html).toContain("togare-card-de-prazo--d-zero");
        expect(html).toContain("VENCE HOJE");
    });

    it("AC8 — dataFatal=hoje + status=pendente → adiciona modifier --d-zero + chip VENCE HOJE", () => {
        const html = renderCardDePrazo(
            {
                id: "abc-123",
                status: "pendente",
                dataFatal: TODAY_BRT_YMD,
                numeroProcessoOriginal: "10228312720208260001",
            },
            { formatCnj: fakeFormatCnj, now: FAKE_NOW },
        );

        // Modifier wrapper
        expect(html).toContain('class="togare-card-de-prazo togare-card-de-prazo--d-zero"');
        // Chip combinado (vermelho + sino + texto literal)
        expect(html).toContain('togare-card-de-prazo__d-zero-badge');
        expect(html).toContain('togare-status-badge--vence-hoje');
        expect(html).toContain('aria-label="VENCE HOJE — confirme ou adie"');
        expect(html).toContain('🔔');
        expect(html).toContain('VENCE HOJE');
        // StatusBadge real (Pendente) PERMANECE — chip combinado, não substituição.
        expect(html).toContain('Pendente');
    });

    it("AC8 — dataFatal=hoje + status=protocolado → SEM modifier --d-zero (status final, Decisão #4)", () => {
        const html = renderCardDePrazo(
            {
                id: "abc-123",
                status: "protocolado",
                dataFatal: TODAY_BRT_YMD,
                numeroProcessoOriginal: "10228312720208260001",
            },
            { formatCnj: fakeFormatCnj, now: FAKE_NOW },
        );

        expect(html).not.toContain('togare-card-de-prazo--d-zero');
        expect(html).not.toContain('togare-card-de-prazo__d-zero-badge');
        expect(html).not.toContain('VENCE HOJE');
        // StatusBadge real (Protocolado) presente.
        expect(html).toContain('Protocolado');
    });

    it("AC8 — dataFatal=amanhã + status=pendente → SEM --d-zero (D-1 NÃO é D-0)", () => {
        const html = renderCardDePrazo(
            {
                id: "x",
                status: "pendente",
                dataFatal: "2026-05-06", // dia seguinte ao FAKE_NOW.
                numeroProcessoOriginal: "10228312720208260001",
            },
            { formatCnj: fakeFormatCnj, now: FAKE_NOW },
        );

        expect(html).not.toContain('togare-card-de-prazo--d-zero');
        expect(html).not.toContain('togare-card-de-prazo__d-zero-badge');
        expect(html).not.toContain('VENCE HOJE');
    });

    it("AC8 — D-0 com descricao com payload XSS → escapado em chip + descricao", () => {
        const xss = '<script>alert(1)</script>';
        const html = renderCardDePrazo(
            {
                id: "x",
                status: "pendente",
                dataFatal: TODAY_BRT_YMD,
                descricao: xss,
                numeroProcessoOriginal: "10228312720208260001",
            },
            { formatCnj: fakeFormatCnj, now: FAKE_NOW },
        );

        // Não pode haver script tag literal.
        expect(html).not.toContain('<script>alert(1)</script>');
        expect(html).toContain('&lt;script&gt;');
        // Mas o chip VENCE HOJE — texto literal — está presente (não foi
        // poluído pelo conteúdo do user).
        expect(html).toContain('VENCE HOJE');
        expect(html).toContain('togare-card-de-prazo--d-zero');
    });

    it("AC10 — chip VENCE HOJE NÃO usa classe --pulse por default (redundância sem pulsação)", () => {
        const html = renderCardDePrazo(
            {
                id: "x",
                status: "pendente",
                dataFatal: TODAY_BRT_YMD,
            },
            { formatCnj: fakeFormatCnj, now: FAKE_NOW },
        );

        // Decisão #6 da Story 4b.3 — pulse OFF por default. Redundância
        // semântica (cor + ícone + texto + borda) já cumpre AR-7 mesmo
        // com prefers-reduced-motion ativo.
        expect(html).not.toContain('togare-status-badge--pulse');
    });

    it("AC8 — sem dataFatal → SEM --d-zero (graceful)", () => {
        const html = renderCardDePrazo(
            { id: "x", status: "pendente" },
            { now: FAKE_NOW },
        );
        expect(html).not.toContain('togare-card-de-prazo--d-zero');
        expect(html).not.toContain('VENCE HOJE');
    });
});
