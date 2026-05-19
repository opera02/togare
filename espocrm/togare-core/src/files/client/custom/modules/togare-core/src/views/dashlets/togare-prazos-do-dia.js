/**
 * Dashlet "Meus prazos do dia" — Story 4a.5 (BriefingDoDia).
 *
 * Estende `views/dashlets/abstract/record-list` (validado contra
 * `client/lib/espo-main.js` em runtime EspoCRM 9.3 conforme regra v0.19.1
 * — `grep define."views/dashlets/abstract/record-list"` retorna 1 hit).
 *
 * Adiciona:
 *  - Headline counter dinâmica acima da `.list-container` ("X prazos
 *    pendentes — Confira hoje ↗") via helper puro `composeHeadlineHtml`.
 *  - Listener `sync` no collection que re-renderiza a headline em cada fetch
 *    (auto-refresh 30min, manual refresh, mudança de status remoto).
 *
 * Decisões #1, #2, #3, #6 da Story 4a.5. ACL by-assignment + ordenação
 * `dataFatal ASC + prioridadeWeight DESC` via Orderer custom `DataFatalPriorizado`
 * (registrado em `selectDefs::ordererClassNameMap.dataFatal`).
 * Filtro padrão do dashlet: `meusPendentes` (em `dashlets/Prazos.json::searchData`).
 *
 * Pegadinhas EspoCRM 9.x relevantes (lições da 4a.4):
 *  - Collection é criada async dentro do `super.afterRender()` via
 *    `getCollectionFactory().create(scope, callback)` — não está disponível
 *    imediatamente. Wire-up usa `setTimeout(0)` + retry curto até
 *    `this.collection` existir.
 *  - `previousAttributes()` não é relevante aqui (não comparamos diff —
 *    apenas re-renderizamos contagem nova).
 *  - Nenhum `window.TogareCore.X` — `composeHeadlineHtml` é importado direto
 *    (B7 da v0.19.3).
 */

import RecordListDashletView from "views/dashlets/abstract/record-list";
import { composeHeadlineHtml } from "togare-core:helpers/briefing-headline-renderer";
import { isVenceHoje } from "togare-core:helpers/d-zero-detector";
import { translateOrFallback } from "togare-core:helpers/translate-or-fallback";
import ToastTogareView from "togare-core:views/common/toast-togare";
import {
    ensureSystemStatusBannerMount,
    mountSystemStatusBanner,
} from "togare-core:helpers/system-status-banner-mount";

// Story 4b.3 (Decisão #4) — família de status "ainda em jogo" para D-0.
// Espelha STATUS_PENDENTE_FAMILIA do EnqueuePrazoLembretesHook (PHP).
const STATUS_PENDENTE_FAMILIA_JS = [
    "pendente",
    "atrasado_reagendado",
    "aguardando_cliente",
    "aguardando_correcao",
];

class TogarePrazosDoDiaDashletView extends RecordListDashletView {
    afterRender() {
        if (typeof super.afterRender === "function") {
            super.afterRender();
        }
        // Collection é criada async pelo abstract — adia o wire-up.
        if (typeof window !== "undefined" && typeof window.setTimeout === "function") {
            window.setTimeout(() => this._wireUpHeadline(), 0);
        } else {
            this._wireUpHeadline();
        }
        // Story 4b.4 — banner DJEN indisponível >30min mounted no topo do
        // dashlet (1 instância por dashlet — polling independente).
        this._mountSystemStatusBanner();
    }

    /**
     * Story 4b.4 fix-pass v0.28.1 (B1) — mount SystemStatusBannerView via helper
     * compartilhado. Helper passa `el` como CSS selector string `'#<id>'`
     * (createView do EspoCRM 9.x faz querySelector internamente — passar
     * HTMLElement direto rejeita silently como `'[object HTMLDivElement]'`).
     */
    _mountSystemStatusBanner() {
        const mount = ensureSystemStatusBannerMount(this._getRootElement(), [
            ".togare-briefing-headline",
            ".list-container",
            ".panel",
        ]);
        mountSystemStatusBanner(this, mount, "_systemStatusBanner");
    }

    /**
     * Wire do listener sync + render inicial. Re-tenta em até 5 ticks de
     * 50ms se a collection ainda não foi criada (defensivo contra cenários
     * lentos de fetch).
     */
    _wireUpHeadline(retryCount = 0) {
        if (this._headlineWired) {
            this._renderHeadline();
            return;
        }
        if (!this.collection) {
            if (retryCount >= 5) {
                // Desistir após 250ms — render só do estado vazio.
                this._headlineWired = true;
                this._renderHeadline();
                return;
            }
            if (typeof window !== "undefined" && typeof window.setTimeout === "function") {
                window.setTimeout(() => this._wireUpHeadline(retryCount + 1), 50);
                return;
            }
        }
        this._headlineWired = true;
        if (this.collection && typeof this.listenTo === "function") {
            this.listenTo(this.collection, "sync", () => {
                this._renderHeadline();
                // Story 4b.3 — toast D-0 + decoração visual D-0 acompanham cada
                // refresh da collection.
                this._renderD0Toast();
                // Decoração roda em microtask separada para garantir que o
                // template stock do EspoCRM já populou as `.list-row` no DOM.
                this._scheduleDecorateD0Cards();
            });
        }
        this._renderHeadline();
        // Story 4b.3 — toast D-0 + decoração visual disparam junto com o
        // render inicial.
        this._renderD0Toast();
        this._scheduleDecorateD0Cards();
    }

    /**
     * Story 4b.3 fix-pass v0.27.1 (B26 — UX-DR10 redundância visual no
     * dashlet) — agenda `_decorateD0Cards` em microtask `setTimeout(0)` +
     * retry curto para tolerar o ciclo async do `views/dashlets/abstract/
     * record-list` (collection é populada via `getCollectionFactory` antes
     * dos `.list-row` aparecerem no DOM).
     */
    _scheduleDecorateD0Cards(retry = 0) {
        if (typeof window === "undefined" || typeof window.setTimeout !== "function") {
            this._decorateD0Cards();
            return;
        }
        window.setTimeout(() => {
            const root = this._getRootElement();
            const rows = root ? root.querySelectorAll(".list-row[data-id]") : null;
            if (rows && rows.length > 0) {
                this._decorateD0Cards();
                return;
            }
            // Sem rows ainda — re-tenta até 5x (250ms total).
            if (retry < 5) {
                this._scheduleDecorateD0Cards(retry + 1);
            } else {
                // Desistiu — empty state ou collection vazia.
                this._decorateD0Cards();
            }
        }, retry === 0 ? 0 : 50);
    }

    /**
     * Story 4b.3 fix-pass v0.27.1 (B26) — UX-DR10 redundância visual D-0
     * cumulativa no dashlet "Meus prazos do dia".
     *
     * Aplica modifier `togare-row--d-zero` + injeta chip vermelho
     * `[🔔 VENCE HOJE]` ANTES do conteúdo nativo das `.list-row` cujos
     * prazos estão em D-0 (`isVenceHoje(dataFatal)` E status ∈ família
     * "ainda em jogo"). Idempotente — chip não duplica em re-renders.
     *
     * Por quê via DOM injection (não custom rowView):
     *  - 4a.4 fix-pass 0.19.1 removeu PrazoListView/PrazoRowView por bug
     *    do `views/record/row` ES6 module fantasma.
     *  - Dashlet usa template stock `abstract/record-list` → `<div class=
     *    "list-row" data-id="...">` populado pelo Bullbone.
     *  - Decoração via DOM injection é não-invasiva, alinhada com o pattern
     *    de `_renderHeadline` (post-render hook) e dispensa ressuscitar o
     *    bug do row.
     *
     * Decisão #4 da spec preservada: D-0 é camada visual cumulativa, NÃO
     * substitui o status real do Prazo. Status finais (`protocolado` etc)
     * NÃO disparam decoração mesmo se `dataFatal=hoje`.
     */
    _decorateD0Cards() {
        const root = this._getRootElement();
        if (!root) return;
        const rows = root.querySelectorAll(".list-row[data-id]");
        if (!rows || rows.length === 0) return;

        const c = this.collection;
        const modelsById = new Map();
        if (c && Array.isArray(c.models)) {
            for (const m of c.models) {
                if (m && typeof m.get === "function" && m.id) {
                    modelsById.set(String(m.id), m);
                }
            }
        }

        rows.forEach((row) => {
            const id = row.getAttribute("data-id");
            if (!id) return;
            const model = modelsById.get(id);
            if (!model) {
                // Sem model — desfaz qualquer decoração legada (idempotência).
                this._undecorateD0Row(row);
                return;
            }
            const dataFatal = model.get("dataFatal");
            const status = model.get("status");
            const venceHoje = isVenceHoje(dataFatal)
                && STATUS_PENDENTE_FAMILIA_JS.includes(status);
            if (venceHoje) {
                this._decorateD0Row(row);
            } else {
                this._undecorateD0Row(row);
            }
        });
    }

    _decorateD0Row(row) {
        if (!row) return;
        if (row.classList && !row.classList.contains("togare-row--d-zero")) {
            row.classList.add("togare-row--d-zero");
        }
        // Chip "VENCE HOJE" idempotente — só injeta se ainda não existe.
        if (row.querySelector(".togare-row__d-zero-badge")) return;
        // Tenta injetar antes da primeira coluna do row para não quebrar layout.
        const target = row.firstElementChild || row;
        const chip = document.createElement("span");
        chip.className = "togare-row__d-zero-badge togare-status-badge togare-status-badge--vence-hoje";
        chip.setAttribute("role", "status");
        chip.setAttribute("aria-label", "VENCE HOJE — confirme ou adie");
        chip.innerHTML = '<span aria-hidden="true">🔔 </span>VENCE HOJE';
        target.insertAdjacentElement("afterbegin", chip);
    }

    _undecorateD0Row(row) {
        if (!row) return;
        if (row.classList && row.classList.contains("togare-row--d-zero")) {
            row.classList.remove("togare-row--d-zero");
        }
        const chip = row.querySelector(".togare-row__d-zero-badge");
        if (chip && chip.parentNode) {
            chip.parentNode.removeChild(chip);
        }
    }

    /**
     * Story 4b.3 (Decisão #5) — UX-DR10 redundância semântica D-0.
     *
     * Conta prazos em D-0 (`isVenceHoje(dataFatal) E status ∈ família ainda
     * em jogo`) e dispara/atualiza um toast estático persistente
     * (`duration: null`) variant `warning`.
     *
     * Idempotente entre auto-refreshes (autorefresh padrão a cada 30min).
     * Gestão de fadiga: se user dismissar manualmente, NÃO re-aparece nesta
     * sessão de view (flag `_d0ToastDismissedManually`); some no reload da
     * página.
     */
    _renderD0Toast() {
        const count = this._countD0PrazosInCollection();

        // Sem D-0 → garante toast some e reseta state.
        if (count <= 0) {
            if (this._d0ToastHandle && typeof this._d0ToastHandle.dismiss === "function") {
                this._d0ToastHandle.dismiss("programmatic");
            }
            this._d0ToastHandle = null;
            this._d0ToastLastCount = 0;
            return;
        }

        // Gestão de fadiga: dismissado manualmente → não re-aparece.
        if (this._d0ToastDismissedManually) {
            return;
        }

        // Idempotente: se contagem inalterada, no-op.
        if (this._d0ToastHandle && this._d0ToastLastCount === count) {
            return;
        }

        // Contagem mudou — dismiss anterior antes de criar novo.
        if (this._d0ToastHandle && typeof this._d0ToastHandle.dismiss === "function") {
            this._d0ToastHandle.dismiss("programmatic");
        }

        const tpl = translateOrFallback(this, "briefingD0ToastMessage", "messages", "Dashlets", "VENCE HOJE: {N} prazo(s) \u2014 confirme ou adie");
        const message = String(tpl).replace(/\{N\}/g, String(count));

        const handle = ToastTogareView.show({
            variant: "warning",
            message,
            duration: null,        // PERSISTENTE — só some com ação do usuário.
            actionLabel: null,     // sem botão "Continuar".
            onAction: null,
            onDismiss: (reason) => {
                if (reason === "escape" || reason === "close") {
                    this._d0ToastDismissedManually = true;
                }
                if (this._d0ToastHandle && this._d0ToastHandle.id === handle.id) {
                    this._d0ToastHandle = null;
                }
            },
        });

        // onDismiss diferencia fechamento manual de cleanup programatico.
        // (ESC ou X). Distingue de dismiss programático (setando handle=null
        // logo em seguida não dispara fadiga porque a flag só importa para
        // próximo render).
        this._d0ToastHandle = handle;
        this._d0ToastLastCount = count;
    }

    remove() {
        if (this._d0ToastHandle && typeof this._d0ToastHandle.dismiss === "function") {
            this._d0ToastHandle.dismiss("programmatic");
            this._d0ToastHandle = null;
        }
        if (typeof super.remove === "function") {
            return super.remove();
        }
        return undefined;
    }

    /**
     * Conta prazos em D-0 + status ∈ família "ainda em jogo".
     * Defensivo contra collection.models ausente.
     */
    _countD0PrazosInCollection() {
        const c = this.collection;
        if (!c || !Array.isArray(c.models)) return 0;
        let count = 0;
        for (const m of c.models) {
            if (!m || typeof m.get !== "function") continue;
            const dataFatal = m.get("dataFatal");
            const status = m.get("status");
            if (isVenceHoje(dataFatal) && STATUS_PENDENTE_FAMILIA_JS.includes(status)) {
                count++;
            }
        }
        return count;
    }

    /**
     * Computa total de prazos via `this.collection.total` e injeta o HTML
     * do headline antes do `.list-container` nativo. Idempotente — se o
     * elemento já existe, substitui via `outerHTML`.
     */
    _renderHeadline() {
        const total = this._readCollectionTotal();
        const html = composeHeadlineHtml(total, this._buildI18nFn());
        const root = this._getRootElement();
        if (!root) {
            return;
        }
        const existing = root.querySelector(".togare-briefing-headline");
        if (existing) {
            existing.outerHTML = html;
            return;
        }
        const listContainer = root.querySelector(".list-container");
        if (listContainer && listContainer.insertAdjacentHTML) {
            listContainer.insertAdjacentHTML("beforebegin", html);
        }
    }

    _readCollectionTotal() {
        const c = this.collection;
        if (!c) return 0;
        if (typeof c.total === "number" && c.total >= 0) {
            return c.total;
        }
        // Pré-fetch ou total inválido: retorna 0. Não usar collection.length
        // (AC2: count deve refletir o total do servidor, não a página carregada).
        return 0;
    }

    _getRootElement() {
        if (this.element) return this.element;
        if (this.$el && this.$el[0]) return this.$el[0];
        return null;
    }

    /**
     * Constrói função i18n tolerante a falha. Em runtime EspoCRM, view tem
     * `this.translate(key, category, scope)`. Em testes vitest com mocks,
     * `translate` pode ser stub que retorna undefined — `composeHeadlineHtml`
     * cobre o fallback.
     */
    _buildI18nFn() {
        return (key) => {
            if (typeof this.translate !== "function") {
                return null;
            }
            try {
                return this.translate(key, "messages", "Dashlets");
            } catch (_e) {
                return null;
            }
        };
    }
}

export default TogarePrazosDoDiaDashletView;
