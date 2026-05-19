import { describe, it, expect } from "vitest";
import StatusBadgeView from "../../src/files/client/custom/modules/togare-core/src/views/common/status-badge.js";

/**
 * Spec do StatusBadge expandido (Story 4a.4 T10 Plano A).
 *
 * VALID_STATES = 5 legados + 9 Prazo = 14 total. `pendente` é
 * compartilhado (já existia como legacy + também é status do Prazo enum).
 *
 * Cobre:
 *  - Cada estado novo é aceito (não vira fallback `info`).
 *  - Estado desconhecido cai pra `info` (DEFAULT_STATE).
 *  - Renderiza cssClass + label + icon + ariaLabel via i18n quando disponível.
 *  - colorblind safety: cor + ícone + label TODOS presentes em cada estado.
 *  - Legados continuam funcionando (não-regressão).
 */

const PRAZO_LABELS = {
    rascunho: { label: "Rascunho", icon: "📝", ariaLabel: "Status: rascunho" },
    atrasado_reagendado: {
        label: "Atrasado/Reagendado",
        icon: "⚠️",
        ariaLabel: "Status: atrasado ou reagendado",
        ariaLabelWithDays: "Prazo Atrasado/Reagendado, vence em {days} dia útil",
        ariaLabelWithDaysPlural: "Prazo Atrasado/Reagendado, vence em {days} dias úteis",
    },
    aguardando_cliente: { label: "Aguardando cliente", icon: "👤", ariaLabel: "Status: aguardando retorno do cliente" },
    aguardando_correcao: { label: "Aguardando correção", icon: "✏️", ariaLabel: "Status: aguardando correção" },
    protocolado: { label: "Protocolado", icon: "✅", ariaLabel: "Status: protocolado" },
    ciencia_renuncia: { label: "Ciência com renúncia", icon: "🛡️", ariaLabel: "Status: ciência com renúncia" },
    acompanhamento: { label: "Acompanhamento", icon: "👁️", ariaLabel: "Status: acompanhamento" },
    descartado: { label: "Descartado", icon: "⊘", ariaLabel: "Status: descartado" },
};

const LEGACY_LABELS = {
    pendente: { label: "Pendente", icon: "🟡", ariaLabel: "Status: pendente" },
    confirmado: { label: "Confirmado", icon: "🟢", ariaLabel: "Status: confirmado" },
    "precisa-leitura": { label: "Precisa sua leitura", icon: "🟠", ariaLabel: "Status: precisa da sua leitura" },
    critico: { label: "Crítico", icon: "🔴", ariaLabel: "Status: crítico" },
    info: { label: "Informativo", icon: "🔵", ariaLabel: "Status: informativo" },
};

function makeView(status, options = {}) {
    const all = { ...LEGACY_LABELS, ...PRAZO_LABELS };
    const v = new StatusBadgeView({ status, ...options });
    v.getLanguage = () => ({
        translate: (key) => all[key] || { label: key, icon: "", ariaLabel: key },
    });
    v.setup();
    return v;
}

describe("StatusBadge expandido — Story 4a.4 T10 Plano A (14 estados)", () => {
    describe("9 estados Prazo (novos)", () => {
        for (const [state, expected] of Object.entries(PRAZO_LABELS)) {
            it(`status='${state}' aplica cssClass + label + icon + ariaLabel via i18n`, () => {
                const v = makeView(state);
                const data = v.data();
                expect(data.cssClass).toContain(`togare-status-badge--${state}`);
                expect(data.label).toBe(expected.label);
                expect(data.icon).toBe(expected.icon);
                expect(data.ariaLabel).toBe(expected.ariaLabel);
                // Colorblind safety: cor (cssClass), ícone, label todos presentes
                expect(data.icon).toBeTruthy();
                expect(data.label).toBeTruthy();
            });
        }
    });

    describe("5 estados legados (não-regressão)", () => {
        for (const [state, expected] of Object.entries(LEGACY_LABELS)) {
            it(`legacy '${state}' continua funcionando`, () => {
                const v = makeView(state);
                const data = v.data();
                expect(data.cssClass).toContain(`togare-status-badge--${state}`);
                expect(data.label).toBe(expected.label);
            });
        }
    });

    describe("fallback / defaults", () => {
        it("status desconhecido → fallback 'info'", () => {
            const v = makeView("estado_inexistente_xyz");
            expect(v.status).toBe("info");
        });

        it("status null/undefined → fallback 'info'", () => {
            const v = makeView(undefined);
            expect(v.status).toBe("info");
        });

        it("size desconhecido → fallback 'medium'", () => {
            const v = makeView("rascunho", { size: "ginormous" });
            expect(v.size).toBe("medium");
        });
    });

    describe("ariaLabel com criticalDays (Decisão UX-1: AAA em rotas críticas)", () => {
        it("status=atrasado_reagendado + criticalDays=1 → ariaLabelWithDays", () => {
            const v = makeView("atrasado_reagendado", { criticalDays: 1 });
            const data = v.data();
            expect(data.ariaLabel).toContain("1 dia útil");
        });

        it("status=atrasado_reagendado + criticalDays=5 → ariaLabelWithDaysPlural", () => {
            const v = makeView("atrasado_reagendado", { criticalDays: 5 });
            const data = v.data();
            expect(data.ariaLabel).toContain("5 dias úteis");
        });

        it("status=critico (legacy) + criticalDays continua funcionando", () => {
            const v = new StatusBadgeView({ status: "critico", criticalDays: 2 });
            v.getLanguage = () => ({
                translate: () => ({
                    label: "Crítico",
                    icon: "🔴",
                    ariaLabel: "Status: crítico",
                    ariaLabelWithDays: "Prazo crítico, vence em {days} dia útil",
                    ariaLabelWithDaysPlural: "Prazo crítico, vence em {days} dias úteis",
                }),
            });
            v.setup();
            const data = v.data();
            expect(data.ariaLabel).toContain("2 dias úteis");
        });
    });

    describe("structure invariants — Decisão UX-1 (cor + ícone + label OBRIGATÓRIOS)", () => {
        it("para CADA dos 14 estados, data() devolve cssClass + icon + label + ariaLabel non-empty", () => {
            const all = { ...LEGACY_LABELS, ...PRAZO_LABELS };
            for (const state of Object.keys(all)) {
                const v = makeView(state);
                const data = v.data();
                expect(data.cssClass).toContain(`togare-status-badge--${state}`);
                expect(data.icon).not.toBe("");
                expect(data.label).not.toBe("");
                expect(data.ariaLabel).not.toBe("");
            }
        });
    });

    // ========== Story 4b.3 — UX-DR10 redundância semântica D-0 ==========
    describe("Story 4b.3 — vence_hoje (UX-DR10)", () => {
        const VENCE_HOJE_I18N = {
            label: "VENCE HOJE",
            icon: "🔔",
            ariaLabel: "VENCE HOJE — confirme ou adie",
        };

        function makeViewVenceHoje(options = {}) {
            const v = new StatusBadgeView({ status: "vence_hoje", ...options });
            v.getLanguage = () => ({
                translate: (key) =>
                    key === "vence_hoje" ? VENCE_HOJE_I18N : { label: key, icon: "", ariaLabel: key },
            });
            v.setup();
            return v;
        }

        it("status='vence_hoje' aplica cssClass --vence-hoje + label/icon/ariaLabel literais", () => {
            const v = makeViewVenceHoje();
            const data = v.data();
            expect(data.cssClass).toContain("togare-status-badge--vence-hoje");
            expect(data.cssClass).not.toContain("togare-status-badge--vence_hoje");
            expect(data.label).toBe("VENCE HOJE");
            expect(data.icon).toBe("🔔");
            // ariaLabel literal — rota crítica AAA exige texto explícito.
            expect(data.ariaLabel).toBe("VENCE HOJE — confirme ou adie");
        });

        it("vence_hoje passa pelo VALID_STATES (não cai em fallback 'info')", () => {
            const v = makeViewVenceHoje();
            expect(v.status).toBe("vence_hoje");
        });
    });
});
