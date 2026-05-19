/**
 * StatusSelector field view (Story 4a.4 F1.6 + F1.7).
 *
 * Substitui o dropdown enum nativo do `status` por um botão "Mudar status"
 * que abre menu com **transições válidas para o status atual** (tabela
 * `PRAZO_TRANSITIONS` em helpers/prazo-transitions.js — single source of truth).
 *
 * Comportamento por destino:
 *  - destino == "atrasado_reagendado": abre **dialog modal** com textarea
 *    motivoReagendamento ≥10 chars + counter + Confirmar/Cancelar.
 *  - destino em STATUSES_REQUIRING_CONFIRMATION (protocolado / ciencia_renuncia
 *    / descartado): abre Espo.Ui.confirm leve.
 *  - demais destinos: save direto.
 *
 * Após save bem-sucedido: dispara ToastTogare variant=undo (10s) com
 * mensagem específica do destino. Click "Desfazer" reverte status (e
 * motivoReagendamento, se aplicável).
 *
 * Estende `views/fields/enum` para preservar comportamento padrão em modo
 * EDIT (form completo). Override só ativo em mode=detail OU quando opção
 * `inline: true` (passada pelo CardDePrazo row view).
 *
 * Decisão #2 + #7 da Story 4a.4: dialog próprio NÃO duplica
 * dynamicLogic.required do clientDefs (paths independentes). Validação
 * client-side é primeira camada; backend ValidatePrazoFieldsHook é segunda.
 */

import EnumFieldView from "views/fields/enum";
import ToastTogareView from "togare-core:views/common/toast-togare";
import {
    getValidTransitions,
    requiresMotivo,
    requiresConfirmation,
    MOTIVO_REAGENDAMENTO_MIN_LEN,
} from "togare-core:helpers/prazo-transitions";
import { translateOrFallback } from "togare-core:helpers/translate-or-fallback";

const TRIGGER_HTML = (currentStatusLabel, statusKey) =>
    `<button type="button" class="btn btn-default btn-sm togare-status-selector__trigger togare-status-selector__trigger--${statusKey || "default"}" data-action="open-menu" aria-haspopup="menu" aria-expanded="false">${currentStatusLabel ? escapeHtml(currentStatusLabel) : "Status"} <span class="caret"></span></button>`;

const DROPDOWN_TPL = (items, currentStatusLabel, currentStatusKey, changeLabel) => `
    <div class="togare-status-selector">
        ${TRIGGER_HTML(currentStatusLabel, currentStatusKey)}
        <ul class="togare-status-selector__menu" role="menu" hidden>
            <li class="togare-status-selector__menu-header" role="presentation">${escapeHtml(changeLabel || "Mudar status")}</li>
            ${items
                .map(
                    (it) =>
                        `<li role="none"><a href="javascript:void(0)" role="menuitem" class="togare-status-selector__item" data-target="${escapeHtml(it.value)}">${escapeHtml(it.label)}</a></li>`,
                )
                .join("")}
        </ul>
    </div>`;

function escapeHtml(str) {
    if (str === null || str === undefined) return "";
    return String(str)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

export default class StatusSelectorFieldView extends EnumFieldView {
    setup() {
        if (super.setup) super.setup();
        this._inline = this.options && this.options.inline === true;
        this._toastFactory = (this.options && this.options.toastFactory) || null;
        this._dialogFactory = (this.options && this.options.dialogFactory) || null;
        this._confirmFactory =
            (this.options && this.options.confirmFactory) || null;
        this._dialogOpen = false;
        // Story 4a.4 fix-pass 0.19.4 (B12): atualiza trigger HTML direto
        // quando status do model muda. NÃO chama reRender() do Bullbone.
        // Story 4a.4 fix-pass 0.19.6 (B15): defer via setTimeout(0) pra
        // deixar EspoCRM dynamicLogic completar primeiro (motivoReagendamento
        // visible + required do clientDefs). Sem o defer, meu DOM patch
        // interferia no ciclo e exigia F5 pra ver motivoReagendamento.
        if (this.model && typeof this.listenTo === "function") {
            this.listenTo(this.model, `change:${this.name}`, () => {
                if (this.mode === this.MODE_EDIT && !this._inline) return;
                if (this.mode === this.MODE_LIST) return;
                setTimeout(() => this._patchTriggerHtml(), 0);
            });
        }
    }

    _patchTriggerHtml() {
        if (!this.el) return;
        const html = this.getValueForDisplay();
        if (!html) return;
        this.el.innerHTML = html;
        this._bindDropdownEvents();
    }

    /**
     * Story 4a.4 fix-pass 0.19.9 (B15): após save bem-sucedido em mode=detail,
     * tenta forçar reRender do parent recordView para que panels com fields
     * com `dynamicLogic` (ex.: motivoReagendamento.visible quando
     * status=atrasado_reagendado) atualizem a visibilidade. EspoCRM 9.x
     * `views/record/detail` em mode=detail NÃO re-avalia dynamicLogic
     * automaticamente em mudanças de field — só em mode=edit. Best-effort:
     * tenta múltiplas APIs em order de robustez. Se nenhuma funciona,
     * fallback é F5 manual do user.
     */
    _tryRefreshParentRecordView() {
        try {
            const parent = typeof this.getParentView === "function" ? this.getParentView() : null;
            if (!parent) return;
            // Tenta processDynamicLogic se existe (mais leve).
            if (typeof parent.processDynamicLogic === "function") {
                parent.processDynamicLogic();
                return;
            }
            // Fallback: reRender da recordView completa (sem fetch — só re-render DOM).
            if (typeof parent.reRender === "function") {
                parent.reRender();
            }
        } catch (_) {
            // best-effort — silencia. F5 manual cobre.
        }
    }

    /**
     * Mantido para tests que dependem de string-render direto. Em runtime
     * EspoCRM 9.x, `views/fields/enum` em mode=detail usa template
     * `fields/enum/detail` que NÃO chama `getValueForDisplay()` — então este
     * método sozinho NÃO renderiza o dropdown na UI. O wiring real é feito
     * em `afterRender()` substituindo o conteúdo do field-cell. Mantido
     * exposto para isolamento de tests vitest.
     */
    getValueForDisplay() {
        if (this.mode === this.MODE_EDIT && !this._inline) {
            return super.getValueForDisplay
                ? super.getValueForDisplay()
                : this.model.get(this.name);
        }
        const current = this.model ? this.model.get(this.name) : null;
        const currentLabel = this._labelFor(current);
        const valid = getValidTransitions(current);
        const items = valid.map((v) => ({ value: v, label: this._labelFor(v) }));
        const changeLabel = this._triggerLabel();
        if (items.length === 0) {
            return `<span class="togare-status-selector togare-status-selector--terminal togare-status-selector--${current || "default"}">${escapeHtml(currentLabel || current || "")}</span>`;
        }
        return DROPDOWN_TPL(items, currentLabel, current, changeLabel);
    }

    afterRender() {
        if (super.afterRender) super.afterRender();
        if (this.mode === this.MODE_EDIT && !this._inline) {
            return;
        }
        if (this.mode === this.MODE_LIST) {
            // Story 4a.4 fix-pass 0.19.4 (B9): em mode=list, NÃO substituir DOM.
            // Deixar o template enum/list nativo do EspoCRM renderizar (com
            // badge colorido + label pt-BR via i18n options.status). O botão
            // "Mudar status" só faz sentido em mode=detail/edit/inline onde
            // user efetivamente vai interagir.
            return;
        }
        if (!this.el) return;
        // EspoCRM 9.x renderiza o template `fields/enum/detail` por padrão,
        // que ignora `getValueForDisplay()`. Substitui o HTML do field-cell
        // pelo dropdown gerado pelo render `getValueForDisplay()`.
        this._patchTriggerHtml();
    }

    _bindDropdownEvents() {
        if (!this.el) return;
        const trigger = this.el.querySelector(".togare-status-selector__trigger");
        const menu = this.el.querySelector(".togare-status-selector__menu");
        if (trigger && menu) {
            trigger.addEventListener("click", (e) => {
                e.preventDefault();
                this._toggleMenu(trigger, menu);
            });
            this.el.addEventListener("keydown", (e) => {
                if (e.key === "Escape" && !menu.hidden) {
                    this._closeMenu(trigger, menu);
                }
            });
        }
        const items = this.el.querySelectorAll(".togare-status-selector__item");
        items.forEach((btn) => {
            btn.addEventListener("click", (e) => {
                e.preventDefault();
                if (menu) this._closeMenu(trigger, menu);
                const target = btn.getAttribute("data-target");
                if (target) this._handleStatusChange(target);
            });
        });
    }

    _toggleMenu(trigger, menu) {
        const open = !menu.hidden;
        if (open) this._closeMenu(trigger, menu);
        else this._openMenu(trigger, menu);
    }

    _openMenu(trigger, menu) {
        menu.hidden = false;
        trigger.setAttribute("aria-expanded", "true");
        const first = menu.querySelector(".togare-status-selector__item");
        if (first) first.focus();
    }

    _closeMenu(trigger, menu) {
        menu.hidden = true;
        trigger.setAttribute("aria-expanded", "false");
    }

    _handleStatusChange(targetStatus) {
        const validNow = getValidTransitions(this.model.get(this.name));
        if (!validNow.includes(targetStatus)) {
            // Defesa contra race condition: status mudou no model entre o
            // render do dropdown e o click. Silently ignore.
            return;
        }
        if (requiresMotivo(targetStatus)) {
            this._openMotivoDialog(targetStatus);
            return;
        }
        if (requiresConfirmation(targetStatus)) {
            this._openConfirmation(targetStatus);
            return;
        }
        this._saveStatusChange(targetStatus, undefined);
    }

    _openMotivoDialog(targetStatus) {
        if (this._dialogOpen) return;
        this._dialogOpen = true;
        const labels = this._dialogLabels();
        const dialog = this._buildDialogFn();
        if (!dialog) {
            this._dialogOpen = false;
            return;
        }
        dialog({
            title: labels.title,
            placeholder: labels.placeholder,
            counterTpl: labels.counterTpl,
            minChars: MOTIVO_REAGENDAMENTO_MIN_LEN,
            minCharsMsg: labels.minCharsMsg,
            confirmLabel: labels.confirmLabel,
            cancelLabel: labels.cancelLabel,
            onConfirm: (motivo) => {
                this._dialogOpen = false;
                this._saveStatusChange(targetStatus, motivo);
            },
            onCancel: () => {
                this._dialogOpen = false;
            },
        });
    }

    _openConfirmation(targetStatus) {
        const labels = this._confirmationLabels(targetStatus);
        const confirm = this._buildConfirmFn();
        if (!confirm) {
            // Sem confirm helper, save direto (smoke environment / test).
            this._saveStatusChange(targetStatus, undefined);
            return;
        }
        confirm({
            message: labels.message,
            confirmLabel: labels.confirmLabel,
            cancelLabel: labels.cancelLabel,
            onConfirm: () => {
                this._saveStatusChange(targetStatus, undefined);
            },
        });
    }

    /**
     * Aplica a mudança no model + save + ToastTogare undo. Em erro, reverte.
     *
     * @param {string} targetStatus
     * @param {string|undefined} motivo - opcional, só passa para reagendamento.
     */
    _saveStatusChange(targetStatus, motivo) {
        const prevStatus = this.model.get(this.name);
        const prevMotivo = this.model.get("motivoReagendamento");
        const setObj = { status: targetStatus };
        if (motivo !== undefined) {
            setObj.motivoReagendamento = motivo;
        }
        this.model.set(setObj, { ui: true });
        const savePromise = this.model.save
            ? this.model.save(null, { fromStatusSelector: true })
            : Promise.resolve();
        Promise.resolve(savePromise)
            .then(() => {
                this._showUndoToast(targetStatus, prevStatus, prevMotivo);
                // Story 4a.4 fix-pass 0.19.9 (B15): força parent recordView
                // a re-renderizar/re-avaliar dynamicLogic após save. Sem isso,
                // motivoReagendamento (que tem visible:status=atrasado_reagendado)
                // permanece stale no detail até F5 manual.
                this._tryRefreshParentRecordView();
            })
            .catch((err) => {
                // Reverte model em caso de erro de validação backend.
                const revertObj = { status: prevStatus };
                if (motivo !== undefined) {
                    revertObj.motivoReagendamento = prevMotivo;
                }
                this.model.set(revertObj);
                this._notifyError(err);
            });
    }

    _showUndoToast(targetStatus, prevStatus, prevMotivo) {
        const Toast = this._buildToastFactory();
        if (!Toast || typeof Toast.show !== "function") return;
        const label = this._labelFor(targetStatus);
        const message = this._formatToastUndoMessage(label);
        Toast.show({
            variant: "undo",
            message,
            actionLabel: translateOrFallback(
                this,
                "toastUndoActionLabel",
                "messages",
                "Prazo",
                "Desfazer",
            ),
            onAction: () => this._undoStatusChange(prevStatus, prevMotivo),
            duration: 10000,
        });
    }

    _undoStatusChange(prevStatus, prevMotivo) {
        if (!this.model) return;
        this.model.set(
            { status: prevStatus, motivoReagendamento: prevMotivo ?? null },
            { ui: true, fromUndo: true },
        );
        if (this.model.save) {
            // Best-effort save no undo. Erros em undo NÃO mostram toast novo
            // (evita loop UX).
            Promise.resolve(this.model.save(null, { fromUndo: true })).catch(() => {});
        }
    }

    _notifyError(err) {
        const msg =
            (err && err.message) ||
            (err && err.responseJSON && err.responseJSON.message) ||
            "Não foi possível salvar a mudança de status.";
        if (typeof window !== "undefined" && window.Espo && window.Espo.Ui && typeof window.Espo.Ui.error === "function") {
            window.Espo.Ui.error(msg);
            return;
        }
        if (typeof console !== "undefined" && typeof console.error === "function") {
            // eslint-disable-next-line no-console
            console.error("[StatusSelector] save failed:", msg);
        }
    }

    _formatToastUndoMessage(label) {
        const tpl = translateOrFallback(
            this,
            "toastUndoPrazoMarcado",
            "messages",
            "Prazo",
            "Marcado como {label}.",
        );
        return tpl.replace("{label}", label);
    }

    _labelFor(value) {
        if (value === null || value === undefined || value === "") return "";
        // Story 4a.4 fix-pass 0.19.4 (B11): EspoCRM 9.x API correta para
        // traduzir um VALOR de enum field é `getLanguage().translateOption(
        // value, fieldName, scope)`. `this.translate(value, "options", scope)`
        // retorna o objeto inteiro do options (NÃO o valor traduzido), então
        // a chamada anterior caía sempre no fallback que retornava o snake_case.
        if (typeof this.getLanguage === "function") {
            try {
                const lang = this.getLanguage();
                if (lang && typeof lang.translateOption === "function") {
                    const out = lang.translateOption(value, "status", "Prazo");
                    if (out && typeof out === "string" && out !== value) return out;
                }
            } catch (_) {
                // ignore — fallback abaixo
            }
        }
        // Fallback final: valor cru (graceful — sintoma será visual mas não crash).
        return value;
    }

    _triggerLabel() {
        return translateOrFallback(
            this,
            "statusSelectorTrigger",
            "labels",
            "Prazo",
            "Mudar status",
        );
    }

    _dialogLabels() {
        return {
            title: translateOrFallback(
                this,
                "statusSelectorMotivoTitulo",
                "messages",
                "Prazo",
                "Por que este prazo foi reagendado/atrasado?",
            ),
            placeholder: translateOrFallback(
                this,
                "statusSelectorMotivoPlaceholder",
                "messages",
                "Prazo",
                "Ex.: Tribunal reagendou audiência para 20/05; cliente solicitou prorrogação",
            ),
            counterTpl: translateOrFallback(
                this,
                "statusSelectorMotivoCounterTpl",
                "messages",
                "Prazo",
                "{n}/10 caracteres mínimo",
            ),
            minCharsMsg: translateOrFallback(
                this,
                "statusSelectorMotivoMinChars",
                "messages",
                "Prazo",
                "Mínimo 10 caracteres",
            ),
            confirmLabel: "Confirmar",
            cancelLabel: "Cancelar",
        };
    }

    _confirmationLabels(targetStatus) {
        const messageKey =
            targetStatus === "protocolado"
                ? "confirmStatusProtocolado"
                : targetStatus === "ciencia_renuncia"
                    ? "confirmStatusCienciaRenuncia"
                    : "confirmStatusDescartado";
        const fallback =
            targetStatus === "protocolado"
                ? "Marcar este prazo como Protocolado? Esta ação fica registrada no audit log."
                : targetStatus === "ciencia_renuncia"
                    ? "Marcar este prazo como Ciência com renúncia? Esta ação fica registrada no audit log."
                    : "Descartar este prazo? Esta ação fica registrada no audit log e pode ser revertida em até 30 dias via Admin → Trash.";
        return {
            message: translateOrFallback(this, messageKey, "messages", "Prazo", fallback),
            confirmLabel: "Confirmar",
            cancelLabel: "Cancelar",
        };
    }

    _buildToastFactory() {
        if (this._toastFactory) return this._toastFactory;
        // Story 4a.4 fix-pass 0.19.3: ToastTogareView importado direto como
        // ES6 module (window.TogareCore.ToastTogare nunca foi registrado).
        if (typeof ToastTogareView === "function" && typeof ToastTogareView.show === "function") {
            return ToastTogareView;
        }
        return null;
    }

    _buildDialogFn() {
        if (this._dialogFactory) return this._dialogFactory;
        // Em runtime EspoCRM 9.x, usa Espo.Ui.Dialog (modal Bootstrap real
        // com backdrop, focus trap, ESC handler, etc).
        if (
            typeof window !== "undefined" &&
            window.Espo &&
            window.Espo.Ui &&
            typeof window.Espo.Ui.Dialog === "function"
        ) {
            return (opts) => this._openMotivoDialogEspoUi(opts);
        }
        // Fallback DOM puro — usado SOMENTE em jsdom/tests onde Espo.Ui.Dialog
        // não existe. Constrói um overlay simples mas FUNCIONAL.
        return (opts) => this._openMotivoDialogFallback(opts);
    }

    _openMotivoDialogEspoUi(opts) {
        // Story 4a.4 fix-pass 0.19.5 (B13): validação no onClick direto do
        // Confirm — sem `disabled: true` no buttonList nem setTimeout race.
        // Se motivo < minChars, mostra erro inline + NÃO fecha dialog.
        // Counter atualiza via input listener wired DENTRO do body via script
        // inline (executado quando body é injetado no DOM pelo modal).
        const taId = `togare-motivo-textarea-${Date.now()}`;
        const counterId = `togare-motivo-counter-${Date.now()}`;
        const errId = `togare-motivo-err-${Date.now()}`;
        const body =
            `<div class="togare-status-selector__dialog-form">` +
            `<textarea id="${taId}" class="form-control togare-status-selector__dialog-textarea" placeholder="${escapeHtml(opts.placeholder)}" rows="4" aria-label="${escapeHtml(opts.title)}" style="width:100%;margin-bottom:8px;"></textarea>` +
            `<div id="${counterId}" class="togare-status-selector__dialog-counter text-muted small" aria-live="polite"></div>` +
            `<div id="${errId}" class="togare-status-selector__dialog-error text-danger small" aria-live="polite" style="display:none;"></div>` +
            `</div>`;
        const minCharsMsg = opts.minCharsMsg || "Mínimo 10 caracteres";
        const counterTpl = opts.counterTpl || "{n}/10";
        const minChars = opts.minChars;
        const dialog = new window.Espo.Ui.Dialog({
            backdrop: "static",
            header: opts.title,
            body,
            buttonList: [
                {
                    text: opts.confirmLabel,
                    name: "confirm",
                    style: "primary",
                    onClick: (d) => {
                        const ta = document.getElementById(taId);
                        const errBox = document.getElementById(errId);
                        const motivo = String((ta && ta.value) || "").trim();
                        if (motivo.length < minChars) {
                            // Validação fail → mostra erro inline + NÃO fecha.
                            if (errBox) {
                                errBox.style.display = "block";
                                errBox.textContent = minCharsMsg;
                            }
                            if (ta && typeof ta.focus === "function") ta.focus();
                            return false; // tentativa de prevenir close — Espo.Ui.Dialog
                        }
                        d.close();
                        if (typeof opts.onConfirm === "function") opts.onConfirm(motivo);
                    },
                },
                {
                    text: opts.cancelLabel,
                    name: "cancel",
                    onClick: (d) => {
                        d.close();
                        if (typeof opts.onCancel === "function") opts.onCancel();
                    },
                },
            ],
        });
        dialog.show();
        // Bind input listener pro counter — usa setTimeout com retry pra aguardar
        // o modal renderizar (Espo.Ui.Dialog é async no show()).
        const tryWire = (attempt) => {
            const ta = document.getElementById(taId);
            const counter = document.getElementById(counterId);
            const errBox = document.getElementById(errId);
            if (!ta || !counter || !errBox) {
                if (attempt < 10) {
                    setTimeout(() => tryWire(attempt + 1), 50);
                }
                return;
            }
            const confirmBtn = ta.closest(".modal-content")
                ? ta.closest(".modal-content").querySelector('[data-name="confirm"]')
                : null;
            const update = () => {
                const n = String(ta.value || "").trim().length;
                counter.textContent = counterTpl.replace("{n}", String(n));
                if (n >= minChars) {
                    errBox.style.display = "none";
                    if (confirmBtn) confirmBtn.disabled = false;
                } else {
                    if (confirmBtn) confirmBtn.disabled = true;
                }
            };
            ta.addEventListener("input", update);
            update();
            try { ta.focus(); } catch (_) {}
        };
        tryWire(0);
    }

    _openMotivoDialogFallback(opts) {
        if (typeof document === "undefined") return;
        const dlg = document.createElement("div");
        dlg.className = "togare-status-selector__dialog";
        dlg.setAttribute("role", "dialog");
        dlg.setAttribute("aria-label", opts.title);
        dlg.innerHTML =
            `<div class="togare-status-selector__dialog-backdrop"></div>` +
            `<div class="togare-status-selector__dialog-body">` +
            `<h3 class="togare-status-selector__dialog-title">${escapeHtml(opts.title)}</h3>` +
            `<textarea class="togare-status-selector__dialog-textarea" placeholder="${escapeHtml(opts.placeholder)}" rows="4" aria-label="${escapeHtml(opts.title)}"></textarea>` +
            `<div class="togare-status-selector__dialog-counter" aria-live="polite"></div>` +
            `<div class="togare-status-selector__dialog-error" aria-live="polite" hidden></div>` +
            `<div class="togare-status-selector__dialog-actions">` +
            `<button type="button" class="btn btn-default" data-action="cancel">${escapeHtml(opts.cancelLabel)}</button>` +
            `<button type="button" class="btn btn-primary" data-action="confirm" disabled>${escapeHtml(opts.confirmLabel)}</button>` +
            `</div></div>`;
        document.body.appendChild(dlg);
        const ta = dlg.querySelector("textarea");
        const counter = dlg.querySelector(".togare-status-selector__dialog-counter");
        const errBox = dlg.querySelector(".togare-status-selector__dialog-error");
        const confirmBtn = dlg.querySelector('[data-action="confirm"]');
        const cancelBtn = dlg.querySelector('[data-action="cancel"]');
        const updateCounter = () => {
            const n = String(ta.value || "").trim().length;
            counter.textContent = (opts.counterTpl || "{n}/10").replace("{n}", String(n));
            if (n < opts.minChars) {
                confirmBtn.disabled = true;
                errBox.hidden = false;
                errBox.textContent = opts.minCharsMsg || "Mínimo 10 caracteres";
            } else {
                confirmBtn.disabled = false;
                errBox.hidden = true;
            }
        };
        ta.addEventListener("input", updateCounter);
        cancelBtn.addEventListener("click", (e) => {
            e.preventDefault();
            dlg.remove();
            if (typeof opts.onCancel === "function") opts.onCancel();
        });
        confirmBtn.addEventListener("click", (e) => {
            e.preventDefault();
            const motivo = String(ta.value || "").trim();
            if (motivo.length < opts.minChars) return;
            dlg.remove();
            if (typeof opts.onConfirm === "function") opts.onConfirm(motivo);
        });
        updateCounter();
        setTimeout(() => ta.focus(), 0);
    }

    _buildConfirmFn() {
        if (this._confirmFactory) return this._confirmFactory;
        if (typeof window !== "undefined" && window.Espo && window.Espo.Ui && typeof window.Espo.Ui.confirm === "function") {
            return (opts) => {
                window.Espo.Ui.confirm(opts.message, { confirmText: opts.confirmLabel, cancelText: opts.cancelLabel }, () => {
                    if (typeof opts.onConfirm === "function") opts.onConfirm();
                });
            };
        }
        // Fallback minimal — uses window.confirm (works in jsdom for tests
        // by spying on window.confirm).
        return (opts) => {
            if (typeof window === "undefined" || typeof window.confirm !== "function") return;
            const ok = window.confirm(opts.message);
            if (ok && typeof opts.onConfirm === "function") opts.onConfirm();
        };
    }
}
