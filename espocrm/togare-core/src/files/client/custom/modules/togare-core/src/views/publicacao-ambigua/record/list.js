/**
 * PublicacaoAmbigua — list view custom (UX C9 list "Precisa sua leitura").
 *
 * Story 4b.1c (filha 3/3 do split 4b.1 — UX flow F3).
 *
 * Extends stock `views/record/list`. Acrescenta:
 *  - mass-action `bulkIgnoreProcesso` que abre Espo.Ui.Dialog (B6) e chama
 *    o endpoint REST do togare-djen quando ≥1 row é selecionada e os
 *    candidatos das rows compartilham processoIds.
 *
 * O boolFilter `precisaSuaLeitura` aparece selecionado por default via
 * `defaultFilterData.boolFilterList` em clientDefs/PublicacaoAmbigua.json
 * (AC12) — não precisa de setup extra aqui.
 *
 * Defesa B0 (Story 4a.4 v0.19.1): único import ES6 é `views/record/list`
 * (whitelisted em tools/validate-bundle-imports.mjs).
 *
 * Endpoint consumido (controller mora em togare-djen):
 *   POST /api/v1/TogareDjenPublicacaoAmbigua/action/bulkIgnoreProcesso
 *   body { processoId } → 200 { count }
 */

import ListRecordView from "views/record/list";
import { formatCnj } from "togare-core:helpers/hbFormatters";
import { translateOrFallback } from "togare-core:helpers/translate-or-fallback";
import {
  ensureSystemStatusBannerMount,
  mountSystemStatusBanner,
} from "togare-core:helpers/system-status-banner-mount";

const SCOPE = "PublicacaoAmbigua";

export default class PublicacaoAmbiguaListView extends ListRecordView {
  setup() {
    super.setup();

    // Story 4b.1c AC2: registra mass-action `bulkIgnoreProcesso` que aparece
    // só quando ≥1 row está selecionada (comportamento default do EspoCRM —
    // mass-actions só ficam visíveis no estado "selectedAll" ou "selected").
    if (!Array.isArray(this.massActionList)) {
      this.massActionList = [];
    }
    if (!this.massActionList.includes("bulkIgnoreProcesso")) {
      this.massActionList.push("bulkIgnoreProcesso");
    }
  }

  afterRender() {
    if (typeof super.afterRender === "function") {
      super.afterRender();
    }
    this._mountSystemStatusBanner();
  }

  /**
   * Story 4b.4 — mount SystemStatusBannerView como child da list view.
   * Fix-pass v0.28.1 (B1): usa helper compartilhado que gera id único +
   * passa el como CSS selector string (createView do EspoCRM 9.x faz
   * querySelector internamente — passar HTMLElement quebra silently).
   */
  _mountSystemStatusBanner() {
    const mount = ensureSystemStatusBannerMount(this.el || (this.$el && this.$el[0]) || null, [
      ".list-container",
      ".list",
      ".panel",
    ]);
    mountSystemStatusBanner(this, mount, "_systemStatusBanner");
  }

  /**
   * Mass-action handler invocado pelo EspoCRM quando o user clica
   * "Ignorar todos do processo X" no menu mass-actions com rows selecionadas.
   *
   * Estratégia de derivação do processoId-alvo:
   *  1. Coletar `candidatos[]` JSON-decoded de cada model selecionado.
   *  2. Calcular interseção de processoIds que aparecem em TODOS os modelos.
   *  3. Se exatamente 1 processoId comum → confirmar via Espo.Ui.Dialog e
   *     POSTar com esse processoId.
   *  4. Se 2+ processoIds comuns → mostrar Espo.Ui.Dialog com lista de
   *     escolha (radio) e POSTar com o escolhido.
   *  5. Se 0 processoIds comuns → toast warning "Nenhum processo comum nas
   *     publicações selecionadas — selecione rows que compartilham
   *     processoIds em candidatos."
   */
  massActionBulkIgnoreProcesso() {
    if (this._isSelectedAllActive()) {
      const msg = translateOrFallback(
        this,
        "bulkIgnoreVisibleRowsOnly",
        "messages",
        SCOPE,
        "Esta acao vale apenas para linhas visiveis selecionadas. Desmarque selecionar todos e escolha linhas da pagina atual.",
      );
      if (typeof window !== "undefined" && window.Espo && window.Espo.Ui && typeof window.Espo.Ui.warning === "function") {
        window.Espo.Ui.warning(msg);
      }
      return;
    }

    const collection = this.collection;
    const selected = (typeof this.getSelected === "function" && this.getSelected())
      || (collection && Array.isArray(collection.models)
        ? collection.models.filter((m) => m && m._selected)
        : []);

    if (!Array.isArray(selected) || selected.length === 0) {
      const msg = translateOrFallback(
        this,
        "bulkIgnoreNoSelection",
        "messages",
        SCOPE,
        "Selecione ao menos 1 publicação para usar esta ação.",
      );
      if (typeof window !== "undefined" && window.Espo && window.Espo.Ui && typeof window.Espo.Ui.warning === "function") {
        window.Espo.Ui.warning(msg);
      }
      return;
    }

    const intersect = this._intersectProcessoIds(selected);

    if (intersect.length === 0) {
      const msg = translateOrFallback(
        this,
        "bulkIgnoreNoCommon",
        "messages",
        SCOPE,
        "As publicações selecionadas não compartilham processoIds em candidatos. Refine a seleção.",
      );
      if (typeof window !== "undefined" && window.Espo && window.Espo.Ui && typeof window.Espo.Ui.warning === "function") {
        window.Espo.Ui.warning(msg);
      }
      return;
    }

    if (intersect.length === 1) {
      this._openBulkConfirm(intersect[0], this._findProcessoCnj(selected, intersect[0]));
      return;
    }

    this._openBulkChooser(intersect, selected);
  }

  _intersectProcessoIds(models) {
    let intersect = null;
    for (const model of models) {
      const ids = this._extractCandidatosProcessoIds(model);
      if (intersect === null) {
        intersect = new Set(ids);
        continue;
      }
      const next = new Set();
      for (const id of ids) {
        if (intersect.has(id)) next.add(id);
      }
      intersect = next;
      if (intersect.size === 0) return [];
    }
    return intersect ? Array.from(intersect) : [];
  }

  _extractCandidatosProcessoIds(model) {
    return this._extractCandidatos(model)
      .map((c) => (c && typeof c === "object" ? c.processoId : null))
      .filter((id) => typeof id === "string" && id.length > 0);
  }

  _openBulkConfirm(processoId, processoCnj) {
    const body = translateOrFallback(
      this,
      "bulkIgnoreConfirmation",
      "messages",
      SCOPE,
      "Isso vai ignorar TODAS as publicações pendentes que tenham este Processo como candidato. Continuar?",
    );
    const header = translateOrFallback(
      this,
      "bulkIgnoreHeader",
      "messages",
      SCOPE,
      "Ignorar todos do processo",
    );
    const buttonContinue = translateOrFallback(
      this,
      "buttonContinue",
      "messages",
      SCOPE,
      "Continuar",
    );
    const buttonCancel = translateOrFallback(
      this,
      "buttonCancel",
      "messages",
      SCOPE,
      "Cancelar",
    );

    if (typeof window === "undefined" || !window.Espo || !window.Espo.Ui || !window.Espo.Ui.Dialog) {
      return;
    }
    const dialog = new window.Espo.Ui.Dialog({
      backdrop: "static",
      header,
      body: `<p>${this._escapeHtml(body)}</p>`,
      buttonList: [
        {
          text: buttonContinue,
          style: "primary",
          onClick: (d) => {
            d.close();
            this._postBulkIgnore(processoId, processoCnj);
          },
        },
        { text: buttonCancel, onClick: (d) => d.close() },
      ],
    });
    dialog.show();
  }

  _openBulkChooser(processoIds, selectedModels = []) {
    if (typeof window === "undefined" || !window.Espo || !window.Espo.Ui || !window.Espo.Ui.Dialog) {
      return;
    }
    const header = translateOrFallback(
      this,
      "bulkIgnoreChooseHeader",
      "messages",
      SCOPE,
      "Escolha o processo a ignorar",
    );
    const buttonCancel = translateOrFallback(
      this,
      "buttonCancel",
      "messages",
      SCOPE,
      "Cancelar",
    );
    const buttonContinue = translateOrFallback(
      this,
      "buttonContinue",
      "messages",
      SCOPE,
      "Continuar",
    );

    const radios = processoIds
      .map((id, i) => {
        const cnj = this._findProcessoCnj(selectedModels, id);
        const label = this._formatProcessoCnj(cnj || id);
        return (
          `<label style="display:block;margin-bottom:6px;"><input type="radio" name="togare-pub-ambigua-bulk-pid" value="${this._escapeHtml(
            id,
          )}" data-processo-cnj="${this._escapeHtml(cnj || "")}"${i === 0 ? " checked" : ""}> ${this._escapeHtml(label)}</label>`
        );
      })
      .join("");

    const dialog = new window.Espo.Ui.Dialog({
      backdrop: "static",
      header,
      body: `<form data-role="togare-pub-ambigua-bulk-form">${radios}</form>`,
      buttonList: [
        {
          text: buttonContinue,
          style: "primary",
          onClick: (d) => {
            const root = d.$el && d.$el[0] ? d.$el[0] : (d.el || document);
            const checked =
              root.querySelector && root.querySelector('input[name="togare-pub-ambigua-bulk-pid"]:checked');
            const chosen = checked && checked.value ? checked.value : processoIds[0];
            const chosenCnj = checked && checked.getAttribute ? checked.getAttribute("data-processo-cnj") : "";
            d.close();
            this._postBulkIgnore(chosen, chosenCnj || this._findProcessoCnj(selectedModels, chosen));
          },
        },
        { text: buttonCancel, onClick: (d) => d.close() },
      ],
    });
    dialog.show();
  }

  _postBulkIgnore(processoId, processoCnj) {
    if (typeof window === "undefined" || !window.Espo || !window.Espo.Ajax || typeof window.Espo.Ajax.postRequest !== "function") {
      return;
    }
    return window.Espo.Ajax
      .postRequest("TogareDjenPublicacaoAmbigua/action/bulkIgnoreProcesso", { processoId })
      .then((resp) => {
        const count = resp && typeof resp === "object" && typeof resp.count === "number" ? resp.count : 0;
        const cnj = this._formatProcessoCnj(processoCnj || processoId);
        const successMsg = translateOrFallback(
          this,
          "bulkIgnoreSuccess",
          "messages",
          SCOPE,
          `${count} publicações marcadas como bulk_ignorado para o processo ${cnj}.`,
        )
          .replace("{count}", String(count))
          .replace("{processoCnj}", cnj);
        if (window.Espo.Ui && typeof window.Espo.Ui.success === "function") {
          window.Espo.Ui.success(successMsg);
        }
        if (this.collection && typeof this.collection.fetch === "function") {
          this.collection.fetch();
        }
      })
      .catch((xhr) => {
        const status = xhr && xhr.status ? xhr.status : 0;
        let key = "serverError";
        let fallback = "Erro do servidor. Tente novamente.";
        if (status === 400) {
          key = "invalidCandidateEmpty";
          fallback = "Informe o processo a ser bulk-ignorado.";
        } else if (status === 403) {
          key = "forbidden";
          fallback = "Sem permissão.";
        }
        const msg = translateOrFallback(this, key, "messages", SCOPE, fallback);
        if (window.Espo.Ui && typeof window.Espo.Ui.error === "function") {
          window.Espo.Ui.error(msg);
        }
      });
  }

  _findProcessoCnj(models, processoId) {
    for (const model of models || []) {
      const candidatos = this._extractCandidatos(model);
      const found = candidatos.find((c) => c && c.processoId === processoId);
      if (found && found.numeroCnj) return found.numeroCnj;
    }
    return "";
  }

  _extractCandidatos(model) {
    if (!model || typeof model.get !== "function") return [];
    const raw = model.get("candidatos");
    if (!raw || typeof raw !== "string") return [];
    try {
      const parsed = JSON.parse(raw);
      return Array.isArray(parsed) ? parsed.filter((c) => c && typeof c === "object") : [];
    } catch (_) {
      return [];
    }
  }

  _isSelectedAllActive() {
    const candidates = [
      this.allResultIsChecked,
      this.allResultSelected,
      this.selectAllResult,
      this.isSelectedAll,
      this.collection && this.collection.allResultIsChecked,
      this.collection && this.collection.allResultSelected,
      this.collection && this.collection.selectAllResult,
    ];
    return candidates.some((v) => v === true);
  }

  _formatProcessoCnj(value) {
    const safe = String(value == null ? "" : value);
    if (!safe) return "processo escolhido";
    return formatCnj(safe) || safe;
  }

  _escapeHtml(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }
}
