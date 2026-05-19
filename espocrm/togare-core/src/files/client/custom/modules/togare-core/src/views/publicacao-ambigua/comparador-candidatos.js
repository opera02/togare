/**
 * ComparadorCandidatos — view standalone (UX C10) renderizada como middle
 * panel pelo PublicacaoAmbiguaDetailView.
 *
 * Story 4b.1c (filha 3/3 do split 4b.1 — UX flow F3, jornada Beatriz).
 *
 * Anatomia (stack vertical, NÃO lado-a-lado):
 *   - Topo: trecho `texto` (300 chars) com nomes dos candidatos destacados
 *     em <mark> amarelo. Defesa XSS via Espo.Utils.escapeHtml ANTES do <mark>.
 *   - 1 card por candidato (2..5) com banda colorida (codigoCor) + heading
 *     <h3> "Candidato A: {clienteNome}" (colorblind-safe: cor + letra +
 *     heading semântico) + dados discriminantes + 2 CTAs (Confirmar +
 *     Ignorar todos com mesmo).
 *   - Rodapé: botão "Nenhum dos N — ignorar publicação" + HedgeBanner.
 *
 * Endpoints REST consumidos (controller mora em togare-djen):
 *   POST /api/v1/TogareDjenPublicacaoAmbigua/action/resolve
 *     body { publicacaoAmbiguaId, chosenProcessoId } → 200 { prazoId }
 *   POST /api/v1/TogareDjenPublicacaoAmbigua/action/ignore
 *     body { publicacaoAmbiguaId } → 200 { success: true }
 *   POST /api/v1/TogareDjenPublicacaoAmbigua/action/bulkIgnoreProcesso
 *     body { processoId } → 200 { count }
 *
 * Defesas vinculantes (B0–B17 da Story 4a.4 + Df do 4b.0):
 *  - B0: imports só de `view` (whitelisted) + helpers togare-core internos.
 *  - B6: TODOS os dialogs via window.Espo.Ui.Dialog. Nunca DOM puro.
 *  - B7: ToastTogareView importada via ES6 direto. Nunca window.TogareCore.X.
 *  - B11: enum labels via getLanguage().translateOption (não translate
 *    com category=options — retorna object).
 *  - B14b: minifier-safe — Object.prototype.hasOwnProperty.call para detect
 *    de option presente.
 *  - B17: ToastTogareView.show() é DOM puro estático — usar a forma static.
 *  - XSS: Espo.Utils.escapeHtml(texto) ANTES do <mark> — regex destaca
 *    apenas substring escapada.
 */

import View from "view";
import ToastTogareView from "togare-core:views/common/toast-togare";
import { formatCnj } from "togare-core:helpers/hbFormatters";
import { translateOrFallback } from "togare-core:helpers/translate-or-fallback";

const SCOPE = "PublicacaoAmbigua";
const CODIGO_COR_FALLBACK = ["azul", "laranja", "verde", "roxo", "vermelho"];
const TEXTO_MAX_CHARS = 300;
const TEXTO_PUBLICACAO_TRUNCATE_SUFFIX = "…";
const TOAST_DURATION_MS = 4000;
const REDIRECT_AFTER_409_MS = 2000;

export default class ComparadorCandidatosView extends View {
  setup() {
    super.setup();
    this._busy = false;
    this._dialogOpen = false;
    this._lastFocusedCard = null;
    this._loadingButton = null;
    this._lastAction = null;

    if (this.options && this.options.parentDetailView) {
      this._parentDetailView = this.options.parentDetailView;
    }

    // B-NEW-2 (smoke F1 round 2 do Felipe): race condition de hidratação no
    // primeiro mount do detail. A view custom é montada via createView no
    // afterRender do parent ANTES do model.fetch() devolver os candidatos
    // (read-only path). Sem listener, o primeiro render mostra "(sem
    // candidatos)" e só corrige após navegação prev/next que dispara
    // remount. Solução: re-render quando model.sync (carregou) ou
    // change:candidatos (campo populado) dispararem.
    if (this.model && typeof this.listenTo === "function") {
      const safeReRender = () => {
        try {
          if (typeof this.reRender === "function") this.reRender();
        } catch (_) {
          // ignore — render guard
        }
      };
      this.listenTo(this.model, "sync", safeReRender);
      this.listenTo(this.model, "change:candidatos", safeReRender);
    }
  }

  /**
   * B-NEW-1 (smoke F1 round 2 do Felipe): `views/record/detail` chama
   * `this.getMiddleView().getFieldViews()` durante o lifecycle (action
   * dispatch, rebind etc). Como esta view substitui o middle panel mas
   * extende `view` puro (sem field views), precisamos retornar `{}` aqui
   * para satisfazer o contrato. Sem isso, Console mostra `TypeError:
   * getFieldViews is not a function` em loop nas interações do parent.
   *
   * @returns {object}
   */
  getFieldViews() {
    return {};
  }

  /**
   * Idem `getFieldViews`: complementa contrato esperado pelo parent
   * `views/record/detail` quando ele inspeciona o middle panel. Retorna
   * a própria view como o "field" único — pattern stock para middle
   * panel sem field views explícitos.
   *
   * @returns {object|null}
   */
  getFieldView(_name) {
    return null;
  }

  // ----- Render principal (DOM imperativo, pattern ToastTogareView.show) -

  async render() {
    if (!this.el) {
      const el = document.createElement("div");
      el.className = "togare-pub-ambigua";
      this.setElement(el);
    }

    const candidatos = this._parseCandidatos();
    const readonly = this._isReadonly();
    const html = this._buildHtml(candidatos, readonly);
    this.el.innerHTML = html;
    this._wireEvents(candidatos);
    this._wireFocusListeners();

    if (typeof this.afterRender === "function") this.afterRender();
    return this;
  }

  _buildHtml(candidatos, readonly) {
    if (candidatos.length === 0) {
      let msg = translateOrFallback(
        this,
        "cartaoSemCandidatos",
        "messages",
        SCOPE,
        "Esta publicação não tem candidatos snapshotted — provavelmente foi resolvida ou ignorada. Volte para a fila.",
      );
      return `<div class="togare-pub-ambigua__empty" role="status">${this._escapeHtml(msg)}</div>`;
    }

    const textoId = `togare-pub-ambigua-texto-${this.model && this.model.id ? this._safeDomId(this.model.id) : "current"}`;
    const textoHtml = this._buildHighlightedTexto(
      this.model && typeof this.model.get === "function" ? this.model.get("texto") : "",
      candidatos,
    );

    const cardsHtml = candidatos.map((c, idx) => this._buildCardHtml(c, idx, readonly, textoId)).join("");

    const rodapeIgnorar = translateOrFallback(
      this,
      "nenhumDosNIgnorar",
      "messages",
      SCOPE,
      "Nenhum dos {N} — ignorar publicação",
    ).replace("{N}", String(candidatos.length));

    const tooltipReadonly = readonly
      ? this._escapeHtml(
          translateOrFallback(
            this,
            "decisaoBloqueadaTooltip",
            "messages",
            SCOPE,
            "Apenas Advogado ou Sócio/Admin pode decidir esta publicação.",
          ),
        )
      : "";

    const hedgeText = translateOrFallback(
      this,
      "hedgeBanner",
      "messages",
      SCOPE,
      "Ferramentas podem falhar — confira sempre antes de protocolar.",
    );

    return (
      `<div class="togare-pub-ambigua__texto" id="${this._escapeHtml(textoId)}">${textoHtml}</div>` +
      `<div class="togare-pub-ambigua__cards">${cardsHtml}</div>` +
      `<div class="togare-pub-ambigua__rodape">` +
      `<button type="button" class="btn btn-default togare-pub-ambigua__cta-ignore" data-action="ignore-publicacao"${
        readonly
          ? ` disabled aria-disabled="true" title="${tooltipReadonly}"`
          : ""
      }>${this._escapeHtml(rodapeIgnorar)}</button>` +
      `<div class="togare-pub-ambigua__hedge" role="note">ⓘ ${this._escapeHtml(hedgeText)}</div>` +
      `</div>`
    );
  }

  _buildCardHtml(candidato, idx, readonly, textoId) {
    const letra = String.fromCharCode(65 + idx);
    const cor = this._codigoCor(candidato, idx);
    const headingTpl = translateOrFallback(
      this,
      "candidatoLetra",
      "messages",
      SCOPE,
      "Candidato {letra}: {clienteNome}",
    );
    const heading = headingTpl
      .replace("{letra}", letra)
      .replace("{clienteNome}", this._safeStr(candidato.clienteNome) || "(sem cliente)");

    const cnjMasked = candidato.numeroCnj ? formatCnj(this._safeStr(candidato.numeroCnj)) : "";
    const dataDistribuicao = this._formatDateBr(candidato.dataDistribuicao);
    const dataDistribuicaoFallback = translateOrFallback(this, "dataDistribuicaoFallback", "messages", SCOPE, "—");
    const areaLabel = this._translateEnumOption(candidato.area, "area", "Processo");
    const faseLabel = this._translateEnumOption(candidato.fase, "fase", "Processo");

    const ctaConfirmar = translateOrFallback(
      this,
      "confirmarPrazoNesteProcesso",
      "messages",
      SCOPE,
      "Confirmar prazo neste processo",
    );
    const ctaBulkTpl = translateOrFallback(
      this,
      "ignorarTodosCandidato",
      "messages",
      SCOPE,
      "Ignorar todos com mesmo {label}",
    );
    const ctaBulk = ctaBulkTpl.replace("{label}", `Candidato ${letra}`);

    const tooltipReadonly = readonly
      ? this._escapeHtml(
          translateOrFallback(
            this,
            "decisaoBloqueadaTooltip",
            "messages",
            SCOPE,
            "Apenas Advogado ou Sócio/Admin pode decidir esta publicação.",
          ),
        )
      : "";

    const cardId = `togare-pub-ambigua-card-${idx}`;

    return (
      `<div class="togare-pub-ambigua__card togare-pub-ambigua__card--${cor}" id="${cardId}" data-candidato-idx="${idx}" data-candidato-letra="${letra}" data-processo-id="${this._escapeHtml(candidato.processoId || "")}" aria-describedby="${this._escapeHtml(textoId)}" tabindex="0">` +
      `<div class="togare-pub-ambigua__band togare-pub-ambigua__band--${cor}" aria-label="Candidato ${letra}"></div>` +
      `<h3 class="togare-pub-ambigua__candidato-heading">${this._escapeHtml(heading)}</h3>` +
      `<div class="togare-pub-ambigua__row"><strong>CNJ:</strong> ${this._escapeHtml(cnjMasked || "—")}</div>` +
      `<div class="togare-pub-ambigua__row"><strong>Cliente:</strong> ${this._escapeHtml(this._safeStr(candidato.clienteNome) || "—")}</div>` +
      `<div class="togare-pub-ambigua__row"><strong>Parte contrária:</strong> ${this._escapeHtml(this._safeStr(candidato.parteContrariaNome) || "—")}</div>` +
      `<div class="togare-pub-ambigua__row"><strong>Autuação:</strong> ${this._escapeHtml(dataDistribuicao || dataDistribuicaoFallback)} · <strong>Área:</strong> ${this._escapeHtml(areaLabel || "—")} · <strong>Fase:</strong> ${this._escapeHtml(faseLabel || "—")}</div>` +
      `<div class="togare-pub-ambigua__cta-row">` +
      `<button type="button" class="btn btn-primary togare-pub-ambigua__cta-confirmar" data-action="confirm-candidato" data-candidato-idx="${idx}"${
        readonly
          ? ` disabled aria-disabled="true" title="${tooltipReadonly}"`
          : ""
      }>${this._escapeHtml(ctaConfirmar)}</button>` +
      `<button type="button" class="btn btn-default togare-pub-ambigua__cta-bulk" data-action="bulk-ignore-candidato" data-candidato-idx="${idx}"${
        readonly
          ? ` disabled aria-disabled="true" title="${tooltipReadonly}"`
          : ""
      }>${this._escapeHtml(ctaBulk)}</button>` +
      `</div>` +
      `</div>`
    );
  }

  // ----- Texto com destaque amarelo (XSS-safe) ---------------------------

  _buildHighlightedTexto(rawTexto, candidatos) {
    const texto = this._safeStr(rawTexto);
    if (!texto) return "";

    let snippet = texto.slice(0, TEXTO_MAX_CHARS);
    if (texto.length > TEXTO_MAX_CHARS) {
      snippet = snippet + TEXTO_PUBLICACAO_TRUNCATE_SUFFIX;
    }

    const nomes = new Set();
    for (const c of candidatos) {
      const a = this._safeStr(c.clienteNome);
      const b = this._safeStr(c.parteContrariaNome);
      if (a && a !== "(múltiplos)" && a !== "(sem cliente)") nomes.add(a);
      if (b && b !== "(múltiplos)" && b !== "(sem parte contrária)") nomes.add(b);
    }
    // Ordena por tamanho desc para evitar substring shadowing
    // ("Joao Silva" antes de "Joao").
    const sortedNomes = Array.from(nomes).sort((a, b) => b.length - a.length);

    const ranges = [];
    for (const nome of sortedNomes) {
      if (!nome) continue;
      const safePattern = this._regexEscape(nome);
      try {
        const re = new RegExp(`(${safePattern})`, "gi");
        let match;
        while ((match = re.exec(snippet)) !== null) {
          const start = match.index;
          const end = start + match[0].length;
          if (end <= start) {
            re.lastIndex += 1;
            continue;
          }
          const overlaps = ranges.some((r) => start < r.end && end > r.start);
          if (!overlaps) ranges.push({ start, end });
        }
      } catch (_) {
        // ignore — string vai sem destaque
      }
    }

    if (ranges.length === 0) return this._escapeHtml(snippet);

    ranges.sort((a, b) => a.start - b.start);
    let out = "";
    let cursor = 0;
    for (const range of ranges) {
      out += this._escapeHtml(snippet.slice(cursor, range.start));
      out += `<mark class="togare-pub-ambigua__mark">${this._escapeHtml(snippet.slice(range.start, range.end))}</mark>`;
      cursor = range.end;
    }
    out += this._escapeHtml(snippet.slice(cursor));
    return out;

  }

  _regexEscape(s) {
    return String(s).replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  }

  // ----- Eventos / handlers ----------------------------------------------

  _wireEvents(candidatos) {
    if (!this.el) return;

    const ignoreBtn = this.el.querySelector('[data-action="ignore-publicacao"]');
    if (ignoreBtn) {
      ignoreBtn.addEventListener("click", (e) => {
        e.preventDefault();
        if (ignoreBtn.disabled) return;
        this._onIgnorar();
      });
    }

    const confirmBtns = this.el.querySelectorAll('[data-action="confirm-candidato"]');
    confirmBtns.forEach((btn) => {
      btn.addEventListener("click", (e) => {
        e.preventDefault();
        if (btn.disabled) return;
        const idx = this._readIdx(btn);
        if (idx == null) return;
        const candidato = candidatos[idx];
        if (!candidato) return;
        this._onConfirmar(candidato, btn);
      });
    });

    const bulkBtns = this.el.querySelectorAll('[data-action="bulk-ignore-candidato"]');
    bulkBtns.forEach((btn) => {
      btn.addEventListener("click", (e) => {
        e.preventDefault();
        if (btn.disabled) return;
        const idx = this._readIdx(btn);
        if (idx == null) return;
        const candidato = candidatos[idx];
        if (!candidato) return;
        const letra = String.fromCharCode(65 + idx);
        this._onBulkIgnoreCandidato(candidato.processoId, letra, candidato.numeroCnj);
      });
    });
  }

  _readIdx(btn) {
    const raw = btn && btn.dataset && btn.dataset.candidatoIdx;
    if (raw == null) return null;
    const n = parseInt(raw, 10);
    return Number.isFinite(n) ? n : null;
  }

  _wireFocusListeners() {
    if (!this.el) return;
    const cards = this.el.querySelectorAll(".togare-pub-ambigua__card");
    cards.forEach((card) => {
      card.addEventListener("focus", () => {
        this._lastFocusedCard = card;
      });
    });
  }

  _onConfirmar(candidato, button) {
    if (this._busy) return;
    if (!candidato || !candidato.processoId) return;
    this._busy = true;
    this._lastAction = { type: "resolve", candidato };
    this._setAllButtonsDisabled(true);
    this._setLoadingButton(button || null, true);
    this._notifyResolving();
    return this._postResolve(candidato.processoId)
      .then(() => {
        const msg = translateOrFallback(
          this,
          "resolveSucesso",
          "messages",
          SCOPE,
          "Prazo confirmado neste processo.",
        );
        this._toast({ variant: "success", message: msg, duration: TOAST_DURATION_MS });
        this._notifyParentRedirect();
      })
      .catch((xhr) => this._handleAjaxError(xhr, "resolve"))
      .finally(() => {
        this._busy = false;
      });
  }

  _onIgnorar() {
    if (this._busy || this._dialogOpen) return;
    if (
      typeof window === "undefined"
      || !window.Espo
      || !window.Espo.Ui
      || typeof window.Espo.Ui.confirm !== "function"
    ) return;
    this._dialogOpen = true;

    // B-NEW-3 (smoke F1 round 2 do Felipe): trocamos `new Espo.Ui.Dialog`
    // por `Espo.Ui.confirm` (helper de mais alto nível, recomendado pelo
    // EspoCRM 9.x). O Dialog manual exige onClick no buttonList que em
    // alguns ambientes não dispara via clique sintético — confirm() usa
    // callbacks dedicados confirmCallback/cancelCallback que sempre
    // funcionam.
    // B-NEW-5: confirmText agora é "Ignorar" explícito (era "Continuar"
    // genérico) — espelha intenção do botão.
    const body = translateOrFallback(
      this,
      "confirmationIgnore",
      "messages",
      SCOPE,
      "Esta publicação não vai gerar Prazo. Decisão fica registrada para auditoria.",
    );
    const confirmText = translateOrFallback(this, "confirmIgnoreButton", "messages", SCOPE, "Ignorar");
    const cancelText = translateOrFallback(this, "buttonCancel", "messages", SCOPE, "Cancelar");

    window.Espo.Ui.confirm(
      body,
      {
        confirmText,
        cancelText,
        confirmStyle: "primary",
        backdrop: true,
        cancelCallback: () => {
          this._dialogOpen = false;
        },
      },
      () => {
        // confirmCallback
        this._dialogOpen = false;
        this._busy = true;
        this._lastAction = { type: "ignore" };
        this._setAllButtonsDisabled(true);
        this._postIgnore()
          .then(() => {
            const msg = translateOrFallback(
              this,
              "ignoreSucesso",
              "messages",
              SCOPE,
              "Publicação ignorada.",
            );
            this._toast({ variant: "success", message: msg, duration: TOAST_DURATION_MS });
            this._notifyParentRedirect();
          })
          .catch((xhr) => this._handleAjaxError(xhr, "ignore"))
          .finally(() => {
            this._busy = false;
          });
      },
    );
  }

  _onBulkIgnoreCandidato(processoId, letra, processoCnj) {
    if (this._busy || this._dialogOpen) return;
    if (!processoId) return;
    if (
      typeof window === "undefined"
      || !window.Espo
      || !window.Espo.Ui
      || typeof window.Espo.Ui.confirm !== "function"
    ) return;
    this._dialogOpen = true;

    // B-NEW-3: trocar `new Espo.Ui.Dialog` por `Espo.Ui.confirm` (callbacks
    // dedicados — evita o issue de onClick não disparar via clique sintético).
    const body = translateOrFallback(
      this,
      "bulkIgnoreConfirmation",
      "messages",
      SCOPE,
      "Isso vai ignorar TODAS as publicações pendentes que tenham este Processo como candidato. Continuar?",
    );
    const confirmText = translateOrFallback(this, "buttonContinue", "messages", SCOPE, "Continuar");
    const cancelText = translateOrFallback(this, "buttonCancel", "messages", SCOPE, "Cancelar");

    window.Espo.Ui.confirm(
      body,
      {
        confirmText,
        cancelText,
        confirmStyle: "primary",
        backdrop: true,
        cancelCallback: () => {
          this._dialogOpen = false;
        },
      },
      () => {
        // confirmCallback
        this._dialogOpen = false;
        this._busy = true;
        this._lastAction = { type: "bulk", processoId, letra, processoCnj };
        this._setAllButtonsDisabled(true);
        this._postBulkIgnoreProcesso(processoId)
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
            this._toast({ variant: "success", message: successMsg, duration: TOAST_DURATION_MS });
            this._notifyParentRedirect();
          })
          .catch((xhr) => this._handleAjaxError(xhr, "bulk"))
          .finally(() => {
            this._busy = false;
          });
      },
    );
  }

  // ----- HTTP -----------------------------------------------------------

  _postResolve(chosenProcessoId) {
    return window.Espo.Ajax.postRequest(
      "TogareDjenPublicacaoAmbigua/action/resolve",
      {
        publicacaoAmbiguaId: this.model.id,
        chosenProcessoId,
      },
    );
  }

  _postIgnore() {
    return window.Espo.Ajax.postRequest(
      "TogareDjenPublicacaoAmbigua/action/ignore",
      { publicacaoAmbiguaId: this.model.id },
    );
  }

  _postBulkIgnoreProcesso(processoId) {
    return window.Espo.Ajax.postRequest(
      "TogareDjenPublicacaoAmbigua/action/bulkIgnoreProcesso",
      { processoId },
    );
  }

  _handleAjaxError(xhr, action) {
    const status = xhr && xhr.status ? xhr.status : 0;

    if (status === 409) {
      let msg = translateOrFallback(
        this,
        "alreadyResolved",
        "messages",
        SCOPE,
        "Esta publicação já foi resolvida por outro advogado. A tela vai atualizar.",
      );
      msg = this._messageFromXhr(xhr, "alreadyResolved", msg);
      this._toast({ variant: "warning", message: msg, duration: 6000 });
      // Defesa AC8: redirect 2s para próximo item OR list (advogado precisa
      // ter tempo de ler o toast antes da página mudar).
      setTimeout(() => this._notifyParentRedirect(), REDIRECT_AFTER_409_MS);
      // Reabilita CTAs em caso de erro de race — porém o redirect cuida.
      this._setAllButtonsDisabled(false);
      this._setLoadingButton(null, false);
      return;
    }

    let key = "serverError";
    let fallback = "Erro do servidor. Tente novamente.";
    if (status === 400) {
      key = action === "bulk" ? "invalidCandidateEmpty" : "invalidCandidate";
      fallback = action === "bulk"
        ? "Informe o processo a ser bulk-ignorado."
        : "O processo escolhido não está na lista de candidatos desta publicação.";
    } else if (status === 403) {
      key = "forbidden";
      fallback = "Sem permissão.";
    }
    const fallbackMsg = translateOrFallback(this, key, "messages", SCOPE, fallback);
    const msg = this._messageFromXhr(xhr, key, fallbackMsg);
    const toastOptions = { variant: "error", message: msg, duration: TOAST_DURATION_MS };
    if (status >= 500 || status === 0) {
      toastOptions.actionLabel = translateOrFallback(this, "tentarDeNovo", "messages", SCOPE, "Tentar de novo");
      toastOptions.onAction = () => this._retryLastAction();
      toastOptions.duration = 10000;
    }
    this._toast(toastOptions);

    // Re-habilita botões após erro recoverable (não-409).
    this._setAllButtonsDisabled(false);
    this._setLoadingButton(null, false);
  }

  _notifyParentRedirect() {
    const parent = this._parentDetailView
      || (typeof this.getParentView === "function" ? this.getParentView() : null);
    if (parent && typeof parent.redirectToNextOrList === "function") {
      parent.redirectToNextOrList();
      return;
    }
    // Fallback se sem parent: tenta hash navigate.
    if (typeof window !== "undefined" && window.location) {
      window.location.hash = "#PublicacaoAmbigua";
    }
  }

  // ----- UI helpers ------------------------------------------------------

  _setAllButtonsDisabled(disabled) {
    if (!this.el) return;
    const btns = this.el.querySelectorAll(
      '[data-action="confirm-candidato"], [data-action="bulk-ignore-candidato"], [data-action="ignore-publicacao"]',
    );
    btns.forEach((b) => {
      b.disabled = !!disabled;
      if (disabled) b.setAttribute("aria-busy", "true");
      else b.removeAttribute("aria-busy");
    });
  }

  _setLoadingButton(button, loading) {
    if (this._loadingButton && this._loadingButton !== button) {
      this._loadingButton.classList.remove("togare-pub-ambigua__cta--loading");
      const oldSpinner = this._loadingButton.querySelector(".togare-pub-ambigua__spinner");
      if (oldSpinner) oldSpinner.remove();
    }
    this._loadingButton = loading && button ? button : null;
    if (!button) return;
    if (loading) {
      button.classList.add("togare-pub-ambigua__cta--loading");
      if (!button.querySelector(".togare-pub-ambigua__spinner")) {
        const spinner = document.createElement("span");
        spinner.className = "togare-pub-ambigua__spinner";
        spinner.setAttribute("aria-hidden", "true");
        button.insertBefore(spinner, button.firstChild);
      }
      return;
    }
    button.classList.remove("togare-pub-ambigua__cta--loading");
    const spinner = button.querySelector(".togare-pub-ambigua__spinner");
    if (spinner) spinner.remove();
  }

  _notifyResolving() {
    if (typeof window === "undefined" || !window.Espo || !window.Espo.Ui || typeof window.Espo.Ui.notify !== "function") return;
    const msg = translateOrFallback(this, "resolvendo", "messages", SCOPE, "Resolvendo...");
    window.Espo.Ui.notify(msg);
  }

  _toast(options) {
    if (!options || typeof options !== "object") return;
    if (typeof ToastTogareView.show === "function") {
      ToastTogareView.show(options);
    }
  }

  _messageFromXhr(xhr, key, fallback) {
    const json = xhr && xhr.responseJSON && typeof xhr.responseJSON === "object" ? xhr.responseJSON : null;
    if (json) {
      const direct = json.messageTranslation || json.message || json.error;
      if (typeof direct === "string" && direct) return direct;
    }
    if (xhr && typeof xhr.responseText === "string" && xhr.responseText) {
      try {
        const parsed = JSON.parse(xhr.responseText);
        const parsedMessage = parsed.messageTranslation || parsed.message || parsed.error;
        if (typeof parsedMessage === "string" && parsedMessage) return parsedMessage;
      } catch (_) {
        // ignore
      }
    }
    if (xhr && typeof xhr.statusText === "string" && xhr.statusText) return xhr.statusText;
    const timestamp = json && (json.timestamp || json.decidedAt || json.modifiedAt);
    return this._safeStr(fallback)
      .replace("{timestamp}", timestamp ? String(timestamp) : "instante anterior")
      .replace(/\s+em instante anterior\./, ".");
  }

  _retryLastAction() {
    const action = this._lastAction;
    if (!action || this._busy) return;
    if (action.type === "resolve") return this._onConfirmar(action.candidato, null);
    if (action.type === "ignore") {
      this._busy = true;
      this._setAllButtonsDisabled(true);
      return this._postIgnore()
        .then(() => {
          const msg = translateOrFallback(this, "ignoreSucesso", "messages", SCOPE, "PublicaÃ§Ã£o ignorada.");
          this._toast({ variant: "success", message: msg, duration: TOAST_DURATION_MS });
          this._notifyParentRedirect();
        })
        .catch((xhr) => this._handleAjaxError(xhr, "ignore"))
        .finally(() => {
          this._busy = false;
        });
    }
    if (action.type === "bulk") {
      this._busy = true;
      this._setAllButtonsDisabled(true);
      return this._postBulkIgnoreProcesso(action.processoId)
        .then((resp) => {
          const count = resp && typeof resp === "object" && typeof resp.count === "number" ? resp.count : 0;
          const cnj = this._formatProcessoCnj(action.processoCnj || action.processoId);
          const successMsg = translateOrFallback(
            this,
            "bulkIgnoreSuccess",
            "messages",
            SCOPE,
            `${count} publicaÃ§Ãµes marcadas como bulk_ignorado para o processo ${cnj}.`,
          )
            .replace("{count}", String(count))
            .replace("{processoCnj}", cnj);
          this._toast({ variant: "success", message: successMsg, duration: TOAST_DURATION_MS });
          this._notifyParentRedirect();
        })
        .catch((xhr) => this._handleAjaxError(xhr, "bulk"))
        .finally(() => {
          this._busy = false;
        });
    }
  }

  _isReadonly() {
    if (typeof this.getAcl !== "function") return false;
    try {
      const acl = this.getAcl();
      if (!acl || typeof acl.check !== "function") return false;
      const ok = acl.check(this.model, "edit");
      return ok === false;
    } catch (_) {
      return false;
    }
  }

  // ----- Parsing / formatting -------------------------------------------

  _parseCandidatos() {
    if (!this.model || typeof this.model.get !== "function") return [];
    const raw = this.model.get("candidatos");
    if (!raw || typeof raw !== "string") return [];
    try {
      const parsed = JSON.parse(raw);
      if (!Array.isArray(parsed)) return [];
      return parsed.filter((c) => c && typeof c === "object");
    } catch (_) {
      return [];
    }
  }

  _codigoCor(candidato, idx) {
    const declared = this._safeStr(candidato && candidato.codigoCor);
    if (CODIGO_COR_FALLBACK.includes(declared)) return declared;
    return CODIGO_COR_FALLBACK[idx % CODIGO_COR_FALLBACK.length];
  }

  _formatDateBr(value) {
    if (!value || typeof value !== "string") return "";
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(value);
    if (!m) return value;
    return `${m[3]}/${m[2]}/${m[1]}`;
  }

  _formatProcessoCnj(value) {
    const safe = this._safeStr(value);
    if (!safe) return "processo escolhido";
    return formatCnj(safe) || safe;
  }

  _safeDomId(value) {
    return this._safeStr(value).replace(/[^A-Za-z0-9_-]/g, "-") || "current";
  }

  /**
   * Defesa B11: enum labels via getLanguage().translateOption(value,
   * fieldName, scope) — `translate(value, "options", scope)` retorna OBJECT
   * em EspoCRM 9.x.
   */
  _translateEnumOption(value, fieldName, scope) {
    const safeValue = this._safeStr(value);
    if (!safeValue) return "";
    if (typeof this.getLanguage !== "function") return safeValue;
    try {
      const lang = this.getLanguage();
      if (!lang || typeof lang.translateOption !== "function") return safeValue;
      const out = lang.translateOption(safeValue, fieldName, scope);
      return typeof out === "string" && out ? out : safeValue;
    } catch (_) {
      return safeValue;
    }
  }

  _safeStr(v) {
    if (v === null || v === undefined) return "";
    return String(v);
  }

  _escapeHtml(s) {
    if (
      typeof window !== "undefined"
      && window.Espo
      && window.Espo.Utils
      && typeof window.Espo.Utils.escapeHtml === "function"
    ) {
      return window.Espo.Utils.escapeHtml(this._safeStr(s));
    }
    return this._safeStr(s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }
}
