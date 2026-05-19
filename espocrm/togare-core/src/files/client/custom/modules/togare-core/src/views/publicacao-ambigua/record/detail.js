/**
 * PublicacaoAmbigua — detail view custom (UX C9 QueueNavegavel header +
 * UX C10 ComparadorCandidatos middle panel).
 *
 * Story 4b.1c (filha 3/3 do split 4b.1 — UX flow F3, jornada Beatriz).
 *
 * Extends stock `views/record/detail`. afterRender() injeta:
 *  - header `QueueNavegavel` (contador "Item N de M" + ←/→ + dropdown bulk)
 *    via DOM insert antes do container `.middle` (pattern B2 da Story 4a.4 —
 *    `views/record/detail` template é declarativo e ignora overrides Backbone
 *    de `getValueForDisplay`/etc; substituição DOM em afterRender é a saída).
 *  - middle panel substituído por sub-view `comparador-candidatos`.
 *
 * Keyboard shortcuts (UX-DR8):
 *  - `←` / `→` navegam queue (cached em `_queueIds` por mount; 1 fetch em
 *    `_fetchQueue` via Espo.Ajax.getRequest com boolFilterList=[precisaSuaLeitura]).
 *  - `b` foca dropdown bulk-action.
 *  - aria-live polite no contador.
 *
 * Defesa B0 (Story 4a.4 v0.19.1): único import ES6 é `views/record/detail`
 * (whitelisted em tools/validate-bundle-imports.mjs).
 *
 * Defesa B3 (Story 4a.4): `afterSave()` NÃO existe em views/record/detail —
 * usar listenTo(model, 'sync', cb) se precisar reagir a save.
 *
 * Defesa B4 (Story 4a.4): `change:status` re-renderiza middle panel via
 * setView (re-cria comparador) — útil quando outro advogado resolveu a pub
 * em paralelo.
 */

import DetailRecordView from "views/record/detail";
import { formatCnj } from "togare-core:helpers/hbFormatters";
import { translateOrFallback } from "togare-core:helpers/translate-or-fallback";
import {
  ensureSystemStatusBannerMount,
  mountSystemStatusBanner,
} from "togare-core:helpers/system-status-banner-mount";

const SCOPE = "PublicacaoAmbigua";
const COMPARADOR_VIEW = "togare-core:views/publicacao-ambigua/comparador-candidatos";
const QUEUE_FETCH_MAX = 100;

export default class PublicacaoAmbiguaDetailView extends DetailRecordView {
  setup() {
    super.setup();
    this._queueIds = [];
    this._queueFetched = false;
    this._queuePromise = null;
    this._keydownListener = null;

    // Defesa B4: re-render middle se outra aba resolveu a pub (status
    // mudou para resolvido/ignorado/bulk_ignorado).
    if (this.model && typeof this.listenTo === "function") {
      this.listenTo(this.model, "sync", () => this._maybeReplaceMiddle());
    }
  }

  afterRender() {
    if (typeof super.afterRender === "function") super.afterRender();
    this._injectQueueNavegavel();
    this._maybeReplaceMiddle();
    this._wireKeyboardShortcuts();
    this._mountSystemStatusBanner();
    if (!this._queueFetched) {
      this._queueFetched = true;
      this._queuePromise = this._fetchQueue();
    }
  }

  /**
   * Story 4b.4 fix-pass v0.28.2 — mount SystemStatusBannerView no topo do
   * detail. Helper compartilhado garante id único + selector string (B1 fix).
   */
  _mountSystemStatusBanner() {
    const mount = ensureSystemStatusBannerMount(
      this.el || (this.$el && this.$el[0]) || null,
      [".detail", ".middle", ".panel"],
    );
    mountSystemStatusBanner(this, mount, "_systemStatusBanner");
  }

  remove() {
    if (this._keydownListener && typeof document !== "undefined") {
      document.removeEventListener("keydown", this._keydownListener, true);
      this._keydownListener = null;
    }
    if (typeof super.remove === "function") return super.remove();
  }

  // ----- Sub-view (ComparadorCandidatos) ----------------------------------

  _maybeReplaceMiddle() {
    if (!this.el) return;
    const middle = this.el.querySelector(".middle");
    if (!middle) return;
    if (typeof this.createView === "function") {
      this.createView("middle", COMPARADOR_VIEW, {
        model: this.model,
        el: ".middle",
        parentDetailView: this,
      }, (view) => {
        if (typeof view.render === "function") view.render();
      });
    } else if (typeof this.setView === "function") {
      // Mock test path: setView aceita instance direta.
      this.setView("middle", { type: COMPARADOR_VIEW, model: this.model });
    }
  }

  // ----- Header QueueNavegavel -------------------------------------------

  _injectQueueNavegavel() {
    if (!this.el) return;
    const existing = this.el.querySelector("[data-togare-queue-navegavel]");
    if (existing) return; // idempotente

    const header = document.createElement("div");
    header.className = "togare-pub-ambigua__queue";
    header.setAttribute("data-togare-queue-navegavel", "1");
    header.setAttribute("role", "navigation");

    const tooltip = translateOrFallback(
      this,
      "keyboardShortcutsTooltip",
      "messages",
      SCOPE,
      "Navegação: ←/→ entre items, b = bulk action",
    );
    header.setAttribute("title", tooltip);

    const counterText = this._buildCounterText();
    const ariaAnterior = translateOrFallback(this, "itemAnteriorAria", "messages", SCOPE, "Item anterior");
    const ariaProximo = translateOrFallback(this, "proximoItemAria", "messages", SCOPE, "Próximo item");
    const readonly = this._isReadonly();
    const readonlyTooltip = readonly ? this._readonlyTooltip() : "";
    const labelAnterior = translateOrFallback(this, "anterior", "messages", SCOPE, "← Anterior");
    const labelProximo = translateOrFallback(this, "proximo", "messages", SCOPE, "Próximo →");
    const labelMass = translateOrFallback(this, "acoesEmMassa", "messages", SCOPE, "Ações em massa");

    header.innerHTML =
      `<button type="button" class="btn btn-default btn-sm" data-action="queue-prev" aria-label="${this._escapeHtml(ariaAnterior)}">${this._escapeHtml(
        labelAnterior,
      )}</button>` +
      `<span class="togare-pub-ambigua__queue-counter" data-role="queue-counter" aria-live="polite">${this._escapeHtml(
        counterText,
      )}</span>` +
      `<button type="button" class="btn btn-default btn-sm" data-action="queue-next" aria-label="${this._escapeHtml(ariaProximo)}">${this._escapeHtml(
        labelProximo,
      )}</button>` +
      `<div class="togare-pub-ambigua__bulk" data-role="queue-bulk">` +
      `<button type="button" class="btn btn-default btn-sm togare-pub-ambigua__bulk-toggle" data-action="queue-bulk-toggle" aria-haspopup="true" aria-expanded="false"${readonly ? ` disabled aria-disabled="true" title="${readonlyTooltip}"` : ""}>≡ ${this._escapeHtml(
        labelMass,
      )} ▾</button>` +
      `<ul class="togare-pub-ambigua__bulk-menu" role="menu" hidden></ul>` +
      `</div>`;

    const middle = this.el.querySelector(".middle");
    if (middle && middle.parentNode) {
      middle.parentNode.insertBefore(header, middle);
    } else {
      this.el.insertBefore(header, this.el.firstChild);
    }

    this._populateBulkMenu(header);

    header
      .querySelector('[data-action="queue-prev"]')
      ?.addEventListener("click", (e) => {
        e.preventDefault();
        this._navigateQueue(-1);
      });
    header
      .querySelector('[data-action="queue-next"]')
      ?.addEventListener("click", (e) => {
        e.preventDefault();
        this._navigateQueue(1);
      });
    const bulkToggle = header.querySelector('[data-action="queue-bulk-toggle"]');
    const bulkMenu = header.querySelector(".togare-pub-ambigua__bulk-menu");
    if (bulkToggle && bulkMenu) {
      bulkToggle.addEventListener("click", (e) => {
        e.preventDefault();
        this._toggleBulkMenu(bulkMenu, bulkToggle);
      });
    }

    this._updateQueueButtonsState(header);
  }

  _populateBulkMenu(header) {
    const menu = header.querySelector(".togare-pub-ambigua__bulk-menu");
    if (!menu) return;
    menu.innerHTML = "";

    const candidatos = this._parseCandidatos();
    const readonly = this._isReadonly();
    const readonlyTooltip = readonly ? this._readonlyTooltip() : "";
    if (candidatos.length === 0) {
      const li = document.createElement("li");
      li.setAttribute("role", "menuitem");
      const empty = translateOrFallback(
        this,
        "bulkMenuEmpty",
        "messages",
        SCOPE,
        "(sem candidatos)",
      );
      li.textContent = empty;
      menu.appendChild(li);
      return;
    }

    const labelTpl = translateOrFallback(
      this,
      "ignorarTodosCandidato",
      "messages",
      SCOPE,
      "Ignorar todos com mesmo {label}",
    );

    candidatos.forEach((c, idx) => {
      const letra = String.fromCharCode(65 + idx);
      const li = document.createElement("li");
      li.setAttribute("role", "menuitem");
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "togare-pub-ambigua__bulk-menu-item";
      if (readonly) {
        btn.disabled = true;
        btn.setAttribute("aria-disabled", "true");
        btn.setAttribute("title", readonlyTooltip);
      }
      btn.textContent = labelTpl
        .replace("{label}", `Candidato ${letra}`)
        .replace("{N}", String(candidatos.length));
      btn.dataset.processoId = c.processoId || "";
      btn.addEventListener("click", (e) => {
        e.preventDefault();
        if (btn.disabled) return;
        this._closeBulkMenu();
        this._onBulkIgnoreCandidato(c.processoId, letra, c.numeroCnj);
      });
      li.appendChild(btn);
      menu.appendChild(li);
    });
  }

  _toggleBulkMenu(menu, toggle) {
    if (toggle && toggle.disabled) return;
    const isOpen = !menu.hasAttribute("hidden");
    if (isOpen) {
      menu.setAttribute("hidden", "");
      toggle.setAttribute("aria-expanded", "false");
    } else {
      menu.removeAttribute("hidden");
      toggle.setAttribute("aria-expanded", "true");
      const first = menu.querySelector("button");
      if (first && typeof first.focus === "function") first.focus();
    }
  }

  _closeBulkMenu() {
    if (!this.el) return;
    const menu = this.el.querySelector(".togare-pub-ambigua__bulk-menu");
    const toggle = this.el.querySelector('[data-action="queue-bulk-toggle"]');
    if (menu) menu.setAttribute("hidden", "");
    if (toggle) toggle.setAttribute("aria-expanded", "false");
  }

  _buildCounterText() {
    const total = this._queueIds.length;
    const idx = this.model && this.model.id ? this._queueIds.indexOf(this.model.id) : -1;
    const tpl = translateOrFallback(
      this,
      "itemDeQueue",
      "messages",
      SCOPE,
      "Item {n} de {total}",
    );
    if (total === 0 || idx < 0) {
      return tpl.replace("{n}", "—").replace("{total}", total === 0 ? "—" : String(total));
    }
    return tpl.replace("{n}", String(idx + 1)).replace("{total}", String(total));
  }

  _updateCounter() {
    if (!this.el) return;
    const node = this.el.querySelector('[data-role="queue-counter"]');
    if (!node) return;
    node.textContent = this._buildCounterText();
  }

  _updateQueueButtonsState(header) {
    const root = header || (this.el && this.el.querySelector("[data-togare-queue-navegavel]"));
    if (!root) return;
    const prev = root.querySelector('[data-action="queue-prev"]');
    const next = root.querySelector('[data-action="queue-next"]');
    const idx = this.model && this.model.id ? this._queueIds.indexOf(this.model.id) : -1;
    if (prev) {
      const disabled = idx <= 0;
      prev.disabled = disabled;
      prev.setAttribute("aria-disabled", disabled ? "true" : "false");
    }
    if (next) {
      const disabled = idx < 0 || idx >= this._queueIds.length - 1;
      next.disabled = disabled;
      next.setAttribute("aria-disabled", disabled ? "true" : "false");
    }
  }

  // ----- Queue fetch + navigation ----------------------------------------

  _fetchQueue() {
    if (typeof window === "undefined" || !window.Espo || !window.Espo.Ajax || typeof window.Espo.Ajax.getRequest !== "function") {
      return Promise.resolve([]);
    }
    // EspoCRM 9.x serializa array via PHP-style query string
    // (`boolFilterList[]=precisaSuaLeitura`). Note: `primaryFilter` é apenas
    // para `presetFilters` (não para `boolFilters`) e retorna HTTP 400 quando
    // a chave referencia um boolFilter — por isso usamos `boolFilterList`.
    return window.Espo.Ajax
      .getRequest("PublicacaoAmbigua", {
        boolFilterList: ["precisaSuaLeitura"],
        maxSize: QUEUE_FETCH_MAX,
        select: "id",
        orderBy: "createdAt",
      })
      .then((r) => {
        const list = r && Array.isArray(r.list) ? r.list : [];
        this._queueIds = list.map((o) => (o && o.id ? o.id : null)).filter(Boolean);
        this._updateCounter();
        this._updateQueueButtonsState();
      })
      .catch(() => {
        // Falha silenciosa — queue mostra "Item — de —".
        this._queueIds = [];
        this._updateCounter();
        this._updateQueueButtonsState();
      });
  }

  _navigateQueue(delta) {
    if (!this.model || !this.model.id) return;
    const idx = this._queueIds.indexOf(this.model.id);
    if (idx < 0) return;
    const nextIdx = idx + delta;
    if (nextIdx < 0 || nextIdx >= this._queueIds.length) return;
    const nextId = this._queueIds[nextIdx];
    if (!nextId) return;
    const router = typeof this.getRouter === "function" ? this.getRouter() : null;
    if (router && typeof router.navigate === "function") {
      router.navigate(`#PublicacaoAmbigua/view/${nextId}`, { trigger: true });
    } else if (typeof window !== "undefined" && window.location) {
      window.location.hash = `#PublicacaoAmbigua/view/${nextId}`;
    }
  }

  // ----- Keyboard shortcuts ----------------------------------------------

  _wireKeyboardShortcuts() {
    if (typeof document === "undefined") return;
    if (this._keydownListener) {
      document.removeEventListener("keydown", this._keydownListener, true);
      this._keydownListener = null;
    }
    this._keydownListener = (e) => {
      if (!e || typeof e.key !== "string") return;
      // Defesa: NÃO interceptar quando user está digitando em input/textarea
      // ou em elemento contentEditable (pode haver dialog aberto, search bar etc).
      const t = e.target;
      const tag = t && typeof t.tagName === "string" ? t.tagName.toUpperCase() : "";
      if (tag === "INPUT" || tag === "TEXTAREA" || tag === "SELECT") return;
      if (t && t.isContentEditable) return;

      if (e.key === "ArrowLeft") {
        e.preventDefault();
        this._navigateQueue(-1);
      } else if (e.key === "ArrowRight") {
        e.preventDefault();
        this._navigateQueue(1);
      } else if (e.key === "b" || e.key === "B") {
        if (this._isReadonly()) return;
        e.preventDefault();
        this._focusBulkToggle();
      }
    };
    document.addEventListener("keydown", this._keydownListener, true);
  }

  _focusBulkToggle() {
    if (!this.el) return;
    const toggle = this.el.querySelector('[data-action="queue-bulk-toggle"]');
    if (toggle && toggle.disabled) return;
    if (toggle && typeof toggle.focus === "function") toggle.focus();
  }

  // ----- Bulk-ignore (a partir do detail view) ---------------------------

  _onBulkIgnoreCandidato(processoId, letra, processoCnj) {
    if (this._isReadonly()) return;
    if (!processoId) return;
    if (typeof window === "undefined" || !window.Espo || !window.Espo.Ui || !window.Espo.Ui.Dialog) return;

    const header = translateOrFallback(this, "bulkIgnoreHeader", "messages", SCOPE, "Ignorar todos do processo");
    const body = translateOrFallback(
      this,
      "bulkIgnoreConfirmation",
      "messages",
      SCOPE,
      "Isso vai ignorar TODAS as publicações pendentes que tenham este Processo como candidato. Continuar?",
    );
    const buttonContinue = translateOrFallback(this, "buttonContinue", "messages", SCOPE, "Continuar");
    const buttonCancel = translateOrFallback(this, "buttonCancel", "messages", SCOPE, "Cancelar");

    const dialog = new window.Espo.Ui.Dialog({
      backdrop: "static",
      header: `${header} (Candidato ${letra})`,
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
        // Após bulk-ignore, removemos o id atual da queue (foi ignorado se
        // estiver entre os pendentes do processoId) e avançamos.
        this.redirectToNextOrList();
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

  // ----- Redirect helpers ------------------------------------------------

  /**
   * Chamado pelo ComparadorCandidatos sub-view após resolve / ignore /
   * bulk-ignore bem-sucedido. Remove o id atual da queue cached (já não
   * deveria aparecer mais em precisaSuaLeitura) e navega ao próximo OU
   * volta à list.
   */
  redirectToNextOrList() {
    const waitForQueue = this._queuePromise || Promise.resolve();
    return Promise.resolve(waitForQueue)
      .catch(() => null)
      .then(() => this._redirectToNextOrList());
  }

  _redirectToNextOrList() {
    const currentId = this.model && this.model.id ? this.model.id : null;
    const originalQueue = Array.isArray(this._queueIds) ? this._queueIds.slice() : [];
    const currentIndex = currentId ? originalQueue.indexOf(currentId) : -1;
    this._queueIds = currentId
      ? originalQueue.filter((id) => id !== currentId)
      : originalQueue;
    const router = typeof this.getRouter === "function" ? this.getRouter() : null;
    let nextId = null;
    if (currentIndex >= 0 && currentIndex < this._queueIds.length) {
      nextId = this._queueIds[currentIndex];
    } else if (currentIndex < 0 && this._queueIds.length > 0) {
      nextId = this._queueIds[0];
    }
    const target = nextId ? `#PublicacaoAmbigua/view/${nextId}` : "#PublicacaoAmbigua";
    if (router && typeof router.navigate === "function") {
      router.navigate(target, { trigger: true });
    } else if (typeof window !== "undefined" && window.location) {
      window.location.hash = target;
    }
  }

  // ----- Helpers ----------------------------------------------------------

  _parseCandidatos() {
    if (!this.model || typeof this.model.get !== "function") return [];
    const raw = this.model.get("candidatos");
    if (!raw || typeof raw !== "string") return [];
    try {
      const parsed = JSON.parse(raw);
      return Array.isArray(parsed) ? parsed : [];
    } catch (_) {
      return [];
    }
  }

  _isReadonly() {
    if (typeof this.getAcl !== "function") return false;
    try {
      const acl = this.getAcl();
      if (!acl || typeof acl.check !== "function") return false;
      return acl.check(this.model, "edit") === false;
    } catch (_) {
      return false;
    }
  }

  _readonlyTooltip() {
    return this._escapeHtml(translateOrFallback(
      this,
      "decisaoBloqueadaTooltip",
      "messages",
      SCOPE,
      "Apenas Advogado ou Socio/Admin pode decidir esta publicacao.",
    ));
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
