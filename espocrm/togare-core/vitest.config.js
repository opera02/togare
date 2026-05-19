import { defineConfig } from "vitest/config";

export default defineConfig({
  test: {
    environment: "jsdom",
    globals: true,
    include: ["tests/js/**/*.spec.js"],
  },
  resolve: {
    alias: {
      // EspoCRM expõe `view` como módulo global no runtime. Nos testes, usamos
      // um stub mínimo Backbone-compatível.
      view: new URL("./tests/js/__mocks__/view.js", import.meta.url).pathname,

      // Mock minimal do parent `views/fields/varchar` consumido pelas field
      // views custom (Story 3-A: cpf-br/cnpj-br/cep-br/telefone-br + Story 3.4
      // cnj). Cobre `mode`, `MODE_EDIT`, `model.get/set`, `$el.find().on()`.
      "views/fields/varchar": new URL(
        "./tests/js/__mocks__/varchar.js",
        import.meta.url,
      ).pathname,

      // Imports `togare-core:helpers/*` resolvidos pelos arquivos reais —
      // funções puras (formatX/digitsOnly) reusadas sem mock.
      "togare-core:helpers/hbFormatters": new URL(
        "./src/files/client/custom/modules/togare-core/src/helpers/hbFormatters.js",
        import.meta.url,
      ).pathname,
      "togare-core:helpers/brValidators": new URL(
        "./src/files/client/custom/modules/togare-core/src/helpers/brValidators.js",
        import.meta.url,
      ).pathname,

      // Story 4a.4 — helpers novos (atoCodigo dictionary + transições do
      // StatusSelector). Funções puras reusadas sem mock.
      "togare-core:helpers/atoCodigo-formatter": new URL(
        "./src/files/client/custom/modules/togare-core/src/helpers/atoCodigo-formatter.js",
        import.meta.url,
      ).pathname,
      "togare-core:helpers/prazo-transitions": new URL(
        "./src/files/client/custom/modules/togare-core/src/helpers/prazo-transitions.js",
        import.meta.url,
      ).pathname,
      "togare-core:helpers/auto-link-detector": new URL(
        "./src/files/client/custom/modules/togare-core/src/helpers/auto-link-detector.js",
        import.meta.url,
      ).pathname,
      "togare-core:helpers/card-de-prazo-renderer": new URL(
        "./src/files/client/custom/modules/togare-core/src/helpers/card-de-prazo-renderer.js",
        import.meta.url,
      ).pathname,
      // Story 4b.3 (UX-DR10) — helper puro de detecção D-0 (isVenceHoje).
      "togare-core:helpers/d-zero-detector": new URL(
        "./src/files/client/custom/modules/togare-core/src/helpers/d-zero-detector.js",
        import.meta.url,
      ).pathname,
      // Story 4a.5 — helper puro do BriefingDoDia dashlet (composeHeadlineHtml).
      "togare-core:helpers/briefing-headline-renderer": new URL(
        "./src/files/client/custom/modules/togare-core/src/helpers/briefing-headline-renderer.js",
        import.meta.url,
      ).pathname,
      // Story 4b.0 (Df12) — helper compartilhado i18n com fallback (consolida
      // 3 cópias inline de _translateOrFallback em status-selector + edit + detail).
      "togare-core:helpers/translate-or-fallback": new URL(
        "./src/files/client/custom/modules/togare-core/src/helpers/translate-or-fallback.js",
        import.meta.url,
      ).pathname,
      // Story 4b.4 fix-pass v0.28.1 — helper compartilhado de mount do
      // SystemStatusBannerView (Bug B1: createView precisa de selector string).
      "togare-core:helpers/system-status-banner-mount": new URL(
        "./src/files/client/custom/modules/togare-core/src/helpers/system-status-banner-mount.js",
        import.meta.url,
      ).pathname,
      // Story 10.2 / FR41 — helper puro do painel TogareHealth + a view dashlet.
      "togare-core:helpers/health-panel-renderer": new URL(
        "./src/files/client/custom/modules/togare-core/src/helpers/health-panel-renderer.js",
        import.meta.url,
      ).pathname,
      "togare-core:views/dashlets/togare-health-panel": new URL(
        "./src/files/client/custom/modules/togare-core/src/views/dashlets/togare-health-panel.js",
        import.meta.url,
      ).pathname,
      // Story 10.1 — helper puro do BriefingDoDia (renderPanel, escapeHtml, etc.)
      // + a view dashlet. Funções puras testadas em briefing-do-dia-renderer.spec.js.
      "togare-core:helpers/briefing-do-dia-renderer": new URL(
        "./src/files/client/custom/modules/togare-core/src/helpers/briefing-do-dia-renderer.js",
        import.meta.url,
      ).pathname,
      "togare-core:views/dashlets/briefing-do-dia": new URL(
        "./src/files/client/custom/modules/togare-core/src/views/dashlets/briefing-do-dia.js",
        import.meta.url,
      ).pathname,
      // Story 4a.4 fix-pass 0.19.3 — ToastTogareView importado direto (não
      // mais via window.TogareCore.ToastTogare que nunca foi registrado).
      "togare-core:views/common/toast-togare": new URL(
        "./src/files/client/custom/modules/togare-core/src/views/common/toast-togare.js",
        import.meta.url,
      ).pathname,

      // Story 5.2 — view de field mime-icon + modal de upload de Documento.
      "togare-core:views/document/fields/mime-icon": new URL(
        "./src/files/client/custom/modules/togare-core/src/views/document/fields/mime-icon.js",
        import.meta.url,
      ).pathname,
      "togare-core:views/document/upload-modal": new URL(
        "./src/files/client/custom/modules/togare-core/src/views/document/upload-modal.js",
        import.meta.url,
      ).pathname,
      "togare-core:views/document/record/list": new URL(
        "./src/files/client/custom/modules/togare-core/src/views/document/record/list.js",
        import.meta.url,
      ).pathname,
      "views/modal": new URL(
        "./tests/js/__mocks__/modal.js",
        import.meta.url,
      ).pathname,

      // Story 5.6 — handler do panel action "Anexar documento" (3 branches:
      // Processo/Cliente/Prazo). Stub mínimo do parent `action-handler` do
      // EspoCRM 9.x (atribui `this.view = panelView` no constructor).
      "togare-core:handlers/documento/panel-action-handler": new URL(
        "./src/files/client/custom/modules/togare-core/src/handlers/documento/panel-action-handler.js",
        import.meta.url,
      ).pathname,
      "action-handler": new URL(
        "./tests/js/__mocks__/action-handler.js",
        import.meta.url,
      ).pathname,

      // Story 4a.4 — parents `views/fields/text` (PayloadAccordion) e
      // `views/fields/enum` (StatusSelector). Mesmo pattern do mock varchar.
      "views/fields/text": new URL(
        "./tests/js/__mocks__/text.js",
        import.meta.url,
      ).pathname,
      "views/fields/enum": new URL(
        "./tests/js/__mocks__/enum.js",
        import.meta.url,
      ).pathname,
      "views/fields/base": new URL(
        "./tests/js/__mocks__/base-field.js",
        import.meta.url,
      ).pathname,

      // Story 4a.4 — parents `views/record/edit` e `views/record/detail`
      // para PrazoEditView/PrazoDetailView (AutoLinkBanner trigger).
      "views/record/edit": new URL(
        "./tests/js/__mocks__/record-edit.js",
        import.meta.url,
      ).pathname,
      "views/record/detail": new URL(
        "./tests/js/__mocks__/record-detail.js",
        import.meta.url,
      ).pathname,

      // Story 4a.5 — parent `views/dashlets/abstract/record-list` para o
      // dashlet `togare-prazos-do-dia` do BriefingDoDia. Validado contra
      // espo-main.js (regra v0.19.1) — module existe em runtime.
      "views/dashlets/abstract/record-list": new URL(
        "./tests/js/__mocks__/dashlets-record-list.js",
        import.meta.url,
      ).pathname,

      // Story 10.2 / FR41 — parent `views/dashlets/abstract/base` para o
      // dashlet `togare-health-panel`. Whitelisted em
      // validate-bundle-imports.mjs (KNOWN_ESPOCRM_MODULES) — existe em runtime.
      "views/dashlets/abstract/base": new URL(
        "./tests/js/__mocks__/dashlets-base.js",
        import.meta.url,
      ).pathname,

      // Story 4b.1c — parent `views/record/list` para PublicacaoAmbiguaListView
      // (mass-action bulkIgnoreProcesso). Validado contra espo-main.js (regra
      // v0.19.1) — `views/record/list` consta no whitelist confirmado em
      // runtime; reabilitado depois da remoção temporária da Story 4a.4
      // fix-pass 0.19.1 (que tinha sido motivada por import fantasma de
      // `views/record/row`, não pelo `list`).
      "views/record/list": new URL(
        "./tests/js/__mocks__/record-list.js",
        import.meta.url,
      ).pathname,
      // Story 4b.1c — parent `views/fields/link` para LinkAutocompleteFieldView
      // (regra A6 — Decisão #6 da spec-mãe 4b.1, sem call site nesta story).
      "views/fields/link": new URL(
        "./tests/js/__mocks__/link.js",
        import.meta.url,
      ).pathname,

      // Nota Story 4a.4 fix-pass 0.19.1: alias para `views/record/row`
      // permanece REMOVIDO — esse módulo NÃO existe em EspoCRM 9.3 como
      // classe ES6 importável (blacklisted em validate-bundle-imports.mjs).
      // O CardDePrazo rowView foi diferido para deferred-work (precisa
      // spike do pattern correto de customização de list em EspoCRM 9.x
      // via template/buildRow override, não via class extension).
      // card-de-prazo-renderer.js continua como helper puro testado.

      // Story 6.1 — views frontend de ContratoHonorarios.
      "togare-core:views/contrato-honorarios/record/list": new URL(
        "./src/files/client/custom/modules/togare-core/src/views/contrato-honorarios/record/list.js",
        import.meta.url,
      ).pathname,
      "togare-core:views/contrato-honorarios/record/detail": new URL(
        "./src/files/client/custom/modules/togare-core/src/views/contrato-honorarios/record/detail.js",
        import.meta.url,
      ).pathname,
      "togare-core:views/contrato-honorarios/record/row-actions/relationship-with-download": new URL(
        "./src/files/client/custom/modules/togare-core/src/views/contrato-honorarios/record/row-actions/relationship-with-download.js",
        import.meta.url,
      ).pathname,
      // Story 6.3 — views/common/fields/processos-by-cliente (rename de
      // views/contrato-honorarios/fields/processos-by-cliente — Decisão #6).
      "togare-core:views/common/fields/processos-by-cliente": new URL(
        "./src/files/client/custom/modules/togare-core/src/views/common/fields/processos-by-cliente.js",
        import.meta.url,
      ).pathname,
      // Story 6.3 — variante singular para LancamentoFinanceiro N:1.
      "togare-core:views/common/fields/processo-by-cliente": new URL(
        "./src/files/client/custom/modules/togare-core/src/views/common/fields/processo-by-cliente.js",
        import.meta.url,
      ).pathname,
      // Story 6.3 fix-pass B2 — contrato filtrado por cliente (Fatura.contratoHonorarios).
      "togare-core:views/common/fields/contrato-honorarios-by-cliente": new URL(
        "./src/files/client/custom/modules/togare-core/src/views/common/fields/contrato-honorarios-by-cliente.js",
        import.meta.url,
      ).pathname,
      "togare-core:views/contrato-honorarios/upload-modal": new URL(
        "./src/files/client/custom/modules/togare-core/src/views/contrato-honorarios/upload-modal.js",
        import.meta.url,
      ).pathname,
      "togare-core:handlers/contrato-honorarios/panel-action-handler": new URL(
        "./src/files/client/custom/modules/togare-core/src/handlers/contrato-honorarios/panel-action-handler.js",
        import.meta.url,
      ).pathname,

      // Story 6.1 — parent `views/record/row-actions/relationship` para
      // ContratoHonorariosRelationshipRowActionsView (pattern Documento 5.3).
      "views/record/row-actions/relationship": new URL(
        "./tests/js/__mocks__/row-actions-relationship.js",
        import.meta.url,
      ).pathname,

      // Story 6.1 — parent `views/modals/edit` para ContratoHonoraiosUploadModalView
      // (Dev Decision D11.1.1 — usa stock modal em vez de full-custom).
      "views/modals/edit": new URL(
        "./tests/js/__mocks__/modals-edit.js",
        import.meta.url,
      ).pathname,
      "views/fields/link-multiple": new URL(
        "./tests/js/__mocks__/link-multiple.js",
        import.meta.url,
      ).pathname,

      // Story 6.3 — views frontend de Fatura.
      "togare-core:views/fatura/record/detail": new URL(
        "./src/files/client/custom/modules/togare-core/src/views/fatura/record/detail.js",
        import.meta.url,
      ).pathname,
      "togare-core:views/fatura/record/list": new URL(
        "./src/files/client/custom/modules/togare-core/src/views/fatura/record/list.js",
        import.meta.url,
      ).pathname,
      "togare-core:views/fatura/record/row-actions/relationship-with-pagamento":
        new URL(
          "./src/files/client/custom/modules/togare-core/src/views/fatura/record/row-actions/relationship-with-pagamento.js",
          import.meta.url,
        ).pathname,
      "togare-core:views/fatura/create-modal": new URL(
        "./src/files/client/custom/modules/togare-core/src/views/fatura/create-modal.js",
        import.meta.url,
      ).pathname,
      "togare-core:handlers/fatura/panel-action-handler": new URL(
        "./src/files/client/custom/modules/togare-core/src/handlers/fatura/panel-action-handler.js",
        import.meta.url,
      ).pathname,

      // Story 6.2 — GateBanner (UX-DR2 P2, gate de cobrança sem contrato).
      "togare-core:views/common/gate-banner": new URL(
        "./src/files/client/custom/modules/togare-core/src/views/common/gate-banner.js",
        import.meta.url,
      ).pathname,

      // Story 6.3 — views frontend de LancamentoFinanceiro.
      "togare-core:views/lancamento-financeiro/modals/registrar-pagamento":
        new URL(
          "./src/files/client/custom/modules/togare-core/src/views/lancamento-financeiro/modals/registrar-pagamento.js",
          import.meta.url,
        ).pathname,
      "togare-core:views/lancamento-financeiro/record/list": new URL(
        "./src/files/client/custom/modules/togare-core/src/views/lancamento-financeiro/record/list.js",
        import.meta.url,
      ).pathname,
      "togare-core:views/lancamento-financeiro/record/detail": new URL(
        "./src/files/client/custom/modules/togare-core/src/views/lancamento-financeiro/record/detail.js",
        import.meta.url,
      ).pathname,
    },
  },
});
