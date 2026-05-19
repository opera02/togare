/**
 * FaturaCreateModalView — modal "Emitir fatura" (Story 6.3 — T8.4).
 *
 * Estende `views/modals/edit` stock do EspoCRM 9.x. Em vez de form full-custom,
 * reusa o stock + dynamicLogic via clientDefs/Fatura.json + entityDefs/Fatura.json.
 *
 * Pré-fixação por contexto:
 *  - Aberto do painel `faturas` no Cliente → pré-fixa clienteId/clienteName.
 *  - Aberto do painel `faturas` no ContratoHonorarios → pré-fixa
 *    contratoHonorariosId/contratoHonorariosName + clienteId.
 *  - Aberto do painel `faturas` no Processo (read-only — sem botão) → N/A.
 *
 * Pattern literal de ContratoHonorariosUploadModalView (Story 6.1) — Decisão
 * D11.1.1 da 6.1 manda usar stock edit + customizações mínimas.
 *
 * Story 6.2 — GateBanner (REINTERPRETADA — decisão de Felipe, autoridade de
 * domínio jurídico, 2026-05-16; ver memória feedback_oab_art22_contrato_verbal):
 *  - O Art. 22 §4º OAB NÃO exige contrato escrito; contrato verbal é válido.
 *    O banner é APENAS INFORMATIVO — NÃO bloqueia a emissão da fatura.
 *  - Ao selecionar/mudar clienteId ou processoId → chama endpoint
 *    POST /api/v1/ContratoHonorarios/action/hasContratoVigente.
 *  - Se não há contrato vigente → exibe GateBanner informativo no topo do
 *    modal (sem desabilitar submit). O escritório decide as consequências.
 *  - CTA "Cadastrar contrato agora" → abre ContratoHonorariosUploadModalView
 *    pré-fixada (atalho de conveniência, não obrigatório); ao salvar →
 *    re-verifica → esconde banner.
 *  - Fail-open: se o endpoint falhar → banner não aparece (sem impacto, já
 *    que nada é bloqueado). Backend (ValidateFaturaFieldsHook) também NÃO
 *    bloqueia — contratoHonorarios é opcional.
 */
import ModalsEditView from "views/modals/edit";
import GateBannerView from "togare-core:views/common/gate-banner";

export default class FaturaCreateModalView extends ModalsEditView {
    setup() {
        this.scope = "Fatura";
        this.options = this.options || {};
        this.options.scope = "Fatura";
        this.options.entityType = "Fatura";

        // Pré-fixa link cliente quando aberto de panel de Cliente.
        if (this.options && this.options.clienteId) {
            this.options.attributes = this.options.attributes || {};
            this.options.attributes.clienteId = this.options.clienteId;
            if (this.options.clienteName) {
                this.options.attributes.clienteName = this.options.clienteName;
            }
        }

        // Pré-fixa link contratoHonorarios quando aberto de panel de Contrato.
        if (this.options && this.options.contratoHonorariosId) {
            this.options.attributes = this.options.attributes || {};
            this.options.attributes.contratoHonorariosId = this.options.contratoHonorariosId;
            if (this.options.contratoHonorariosName) {
                this.options.attributes.contratoHonorariosName = this.options.contratoHonorariosName;
            }
        }

        // Pré-fixa link processo quando aberto de panel de Processo.
        if (this.options && this.options.processoId) {
            this.options.attributes = this.options.attributes || {};
            this.options.attributes.processoId = this.options.processoId;
            if (this.options.processoName) {
                this.options.attributes.processoName = this.options.processoName;
            }
        }

        // Default dataEmissao = hoje, dataVencimento = hoje + 30 dias.
        this.options.attributes = this.options.attributes || {};
        if (!this.options.attributes.dataEmissao) {
            const today = new Date();
            this.options.attributes.dataEmissao = this._formatDate(today);
        }
        if (!this.options.attributes.dataVencimento) {
            const venc = new Date();
            venc.setDate(venc.getDate() + 30);
            this.options.attributes.dataVencimento = this._formatDate(venc);
        }

        super.setup();

        const label =
            (typeof this.translate === "function" &&
                this.translate("Emitir Fatura", "labels", "Fatura")) ||
            "Emitir fatura";
        this.headerText = label;
        this.headerHtml = null;

        // Story 6.2 — gate FR23: estado interno de bloqueio.
        this._gateActive = false;
        this._gateContext = null;

        // Ouvir mudanças nos campos cliente e processo para re-verificar o gate.
        if (this.model && typeof this.listenTo === "function") {
            this.listenTo(this.model, "change:clienteId change:processoId", () => {
                this._checkGate();
            });
        }

        // Se clienteId já vem pré-fixado, verificar o gate após o render.
        // IMPORTANTE: super.setup() preenche this.model via `relate` (painel EspoCRM)
        // antes de chegarmos aqui — por isso this.model.get("clienteId") é incluído.
        const preClienteId =
            (this.model && typeof this.model.get === "function" && this.model.get("clienteId")) ||
            (this.options.attributes && this.options.attributes.clienteId) ||
            this.options.clienteId ||
            null;
        if (preClienteId && typeof this.once === "function") {
            this.once("after:render", () => {
                this._checkGate();
            });
        }
    }

    _formatDate(d) {
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, "0");
        const day = String(d.getDate()).padStart(2, "0");
        return `${y}-${m}-${day}`;
    }

    // -------------------------------------------------------------------------
    // Gate FR23 — GateBanner (Story 6.2)
    // -------------------------------------------------------------------------

    /**
     * Verifica junto ao backend se o cliente selecionado tem ContratoHonorarios
     * vigente. Exibe ou esconde o GateBanner conforme resultado.
     * Fail-open: qualquer falha de rede → esconde banner (backend enforça).
     *
     * Retorna Promise para permitir await em testes via _ajaxPost hook.
     */
    async _checkGate() {
        const clienteId =
            (this.model && typeof this.model.get === "function" && this.model.get("clienteId")) ||
            (this.options.attributes && this.options.attributes.clienteId) ||
            this.options.clienteId ||
            null;
        if (!clienteId) {
            this._hideGateBanner();
            return;
        }

        const processoId =
            (this.model && typeof this.model.get === "function" && this.model.get("processoId")) ||
            (this.options.attributes && this.options.attributes.processoId) ||
            this.options.processoId ||
            null;
        const clienteName =
            (this.model && typeof this.model.get === "function" && this.model.get("clienteName")) ||
            (this.options.attributes && this.options.attributes.clienteName) ||
            this.options.clienteName ||
            null;
        const processoName =
            (this.model && typeof this.model.get === "function" && this.model.get("processoName")) ||
            (this.options.attributes && this.options.attributes.processoName) ||
            this.options.processoName ||
            null;

        const payload = { clienteId };
        if (processoId) {
            payload.processoId = processoId;
        }

        // _ajaxPost é hook injetável em testes (quando Espo global não existe).
        // Em produção usa window.Espo.Ajax.postRequest — no contexto de módulo
        // ES do EspoCRM 9.x, `Espo` NÃO é global; só `window.Espo` resolve
        // (pattern literal de comparador-candidatos.js / publicacao-ambigua).
        const espoAjax =
            typeof window !== "undefined" &&
            window.Espo &&
            window.Espo.Ajax &&
            typeof window.Espo.Ajax.postRequest === "function"
                ? window.Espo.Ajax
                : null;
        const postFn =
            typeof this._ajaxPost === "function"
                ? (data) => this._ajaxPost(data)
                : espoAjax
                  ? (data) =>
                        espoAjax.postRequest(
                            "ContratoHonorarios/action/hasContratoVigente",
                            data,
                        )
                  : null;

        if (!postFn) {
            // Sem runtime e sem hook → fail-open.
            this._hideGateBanner();
            return;
        }

        try {
            const result = await postFn(payload);
            this._applyGateResult(result, {
                clienteId,
                clienteName,
                processoId,
                processoName,
            });
        } catch (_) {
            // Fail-open: endpoint falhou → sem bloqueio.
            this._hideGateBanner();
        }
    }

    _applyGateResult(result, context) {
        if (result && result.hasContratoVigente === false) {
            this._showGateBanner(context);
        } else {
            this._hideGateBanner();
        }
    }

    /**
     * Resolve o `.modal-body` REAL. Round 6 provou que `this.el` do modal-view
     * EspoCRM 9.x é o `.modal-container` (wrapper externo) — inserir o
     * placeholder ali deixa o banner fora do `.modal-dialog` visível. Precisa
     * descer até `.modal-body`. Ordem: (1) this.el já é modal-body;
     * (2) descendente de this.el; (3) closest ancestral; (4) null (modal ainda
     * não construído → caller faz retry).
     */
    _resolveModalBody() {
        const el = this.el || (this.$el && this.$el[0]) || null;
        if (!el) {
            return null;
        }
        if (el.classList && el.classList.contains("modal-body")) {
            return el;
        }
        if (typeof el.querySelector === "function") {
            const descendant = el.querySelector(".modal-body");
            if (descendant) {
                return descendant;
            }
        }
        if (typeof el.closest === "function") {
            const ancestor = el.closest(".modal-body");
            if (ancestor) {
                return ancestor;
            }
        }
        return null;
    }

    /**
     * Exibe o GateBanner INFORMATIVO no topo do modal. Story 6.2 reinterpretada
     * (Felipe, 2026-05-16): NÃO bloqueia o submit — só informa que não há
     * ContratoHonorarios ativo. Backend também não bloqueia. Idempotente.
     *
     * EspoCRM 9.x `createView(name, viewName, opts)` chama `setElement(opts.el)`
     * → `querySelector`. Sem `el` → seletor `[data-view-cid]` inexistente →
     * "Could not set element". Solução: placeholder `<div>` com id único no
     * `.modal-body` real (this.el é `.modal-container` — Round 6) + `el:'#id'`.
     */
    _showGateBanner(context) {
        this._gateActive = true;
        this._gateContext = context || null;

        if (this.hasView("gateBanner")) {
            return;
        }

        const body = this._resolveModalBody();
        if (!body) {
            // .modal-body ainda não no DOM (Bootstrap constrói async pós
            // after:render). Retry limitado: ~20×60ms = 1.2s.
            this._gateBannerRetries = (this._gateBannerRetries || 0) + 1;
            if (this._gateBannerRetries <= 20 && typeof setTimeout === "function") {
                setTimeout(() => {
                    if (this._gateActive && !this.hasView("gateBanner")) {
                        this._showGateBanner(this._gateContext);
                    }
                }, 60);
            }
            return;
        }
        this._gateBannerRetries = 0;

        let mount = body.querySelector("[data-role='togare-gate-banner-mount']");
        if (!mount) {
            mount = document.createElement("div");
            mount.setAttribute("data-role", "togare-gate-banner-mount");
            mount.id = `togare-gate-banner-mount-${Date.now()}-${Math.random()
                .toString(36)
                .slice(2, 8)}`;
            // Topo do modal-body, antes do form.
            body.insertBefore(mount, body.firstChild);
        }

        this.createView(
            "gateBanner",
            "togare-core:views/common/gate-banner",
            { el: `#${mount.id}`, variant: "financeiro-sem-contrato" },
            (view) => {
                // CTA "Cadastrar contrato agora" → abre modal de ContratoHonorarios.
                this.listenTo(view, "cta:click:cadastrar-contrato", () => {
                    this._openCadastrarContratoModal(this._gateContext || {});
                });
                view.render();
            },
        );
    }

    /**
     * Esconde o GateBanner informativo e remove o placeholder do DOM.
     */
    _hideGateBanner() {
        this._gateActive = false;
        this._gateContext = null;
        this._gateBannerRetries = 0;
        this.clearView("gateBanner");

        const body = this._resolveModalBody();
        const scope =
            body ||
            this.el ||
            (this.$el && this.$el[0]) ||
            (typeof document !== "undefined" ? document : null);
        if (scope && typeof scope.querySelector === "function") {
            const mount = scope.querySelector(
                "[data-role='togare-gate-banner-mount']",
            );
            if (mount && mount.parentNode) {
                mount.parentNode.removeChild(mount);
            }
        }
    }

    /**
     * Abre o modal de cadastro de ContratoHonorarios pré-fixado com
     * clienteId e processoId (se disponível). Ao salvar → re-verifica gate.
     */
    _openCadastrarContratoModal(contextOrClienteId, maybeProcessoId) {
        const context =
            typeof contextOrClienteId === "object" && contextOrClienteId !== null
                ? contextOrClienteId
                : { clienteId: contextOrClienteId || null, processoId: maybeProcessoId || null };
        const modalOptions = {
            clienteId: context.clienteId || null,
        };
        if (context.clienteName) {
            modalOptions.clienteName = context.clienteName;
        }
        if (context.processoId) {
            modalOptions.processoId = context.processoId;
        }
        if (context.processoName) {
            modalOptions.processoName = context.processoName;
        }

        this.createView(
            "cadastrarContratoModal",
            "togare-core:views/contrato-honorarios/upload-modal",
            modalOptions,
            (view) => {
                view.render();
                this.listenToOnce(view, "after:save", () => {
                    // Contrato cadastrado → re-verificar se agora está vigente.
                    this._checkGate();
                });
            },
        );
    }

}
