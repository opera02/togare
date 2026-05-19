/**
 * Fatura record/detail view (Story 6.3 — T8.1).
 *
 * Adiciona 2 actions custom no detail page:
 *  - `actionRegistrarPagamento` — abre modal `views/lancamento-financeiro/modals/
 *    registrar-pagamento` pré-fixando faturaId + valorSugerido=saldo + tipo
 *    inferido (pagamento_total se valor==saldo, parcial caso contrário; user
 *    pode editar).
 *  - `actionCancelarFatura` — confirmação textual ("digite CANCELAR") +
 *    POST /api/v1/Fatura/action/cancelar com motivo (≥10 chars).
 *
 * Listener `fatura.updated` re-renderiza o detail quando outro componente
 * (ex.: modal de pagamento) sinaliza mudança no saldo/status.
 *
 * Plugado via `clientDefs.Fatura.recordViews.detail`.
 *
 * Fix-pass v0.34.2 (smoke F1 browser round 2 — NOK ponto 7): wiring das
 * actions migrado de `getMenu()` override (frágil; EspoCRM 9.x reconstrói o
 * menu e o push em `menu.buttons` não renderizava) para o pattern canônico
 * `setupActionItems()` + `dropdownItemList` — IDÊNTICO ao
 * ContratoHonorarios/record/detail.js validado por Felipe na Story 6.1.
 * Visibilidade por status via hideActionItem/showActionItem.
 */
import DetailView from "views/record/detail";

export default class FaturaRecordDetailView extends DetailView {
    setup() {
        super.setup();

        if (this.model && typeof this.listenTo === "function") {
            this.listenTo(this.model, "sync", () => {
                this._refreshActionsByStatus();
            });
            this.listenTo(this.model, "change:status", () => {
                this._refreshActionsByStatus();
            });
        }

        // Escuta evento global de pagamentos para forçar re-fetch.
        if (this.model && typeof window !== "undefined") {
            this._faturaUpdatedHandler = (event) => {
                if (event && event.detail && event.detail.faturaId === this.model.id) {
                    this.model.fetch();
                }
            };
            window.addEventListener("togare:fatura.updated", this._faturaUpdatedHandler);
        }
    }

    onRemove() {
        if (this._faturaUpdatedHandler && typeof window !== "undefined") {
            window.removeEventListener("togare:fatura.updated", this._faturaUpdatedHandler);
            this._faturaUpdatedHandler = null;
        }
        if (typeof super.onRemove === "function") {
            super.onRemove();
        }
    }

    /**
     * Pattern canônico EspoCRM 9.x (espelho de ContratoHonorarios detail.js
     * validado no browser na Story 6.1). Roda no setup; itens vão para o
     * menu "..." (dropdownItemList). Visibilidade por status é aplicada via
     * _refreshActionsByStatus (hideActionItem/showActionItem).
     */
    setupActionItems() {
        super.setupActionItems();

        if (!this.model || !this.getAcl().checkModel(this.model, "read")) {
            return;
        }

        this.dropdownItemList = this.dropdownItemList || [];

        const hasItem = (name) =>
            this.dropdownItemList.some((item) => item && item.name === name);

        if (!hasItem("registrarPagamento")) {
            this.dropdownItemList.unshift({
                name: "registrarPagamento",
                label:
                    this.translate("Registrar pagamento", "labels", "Fatura") ||
                    "Registrar pagamento",
                action: "registrarPagamento",
                groupIndex: 0,
            });
        }

        if (!hasItem("cancelarFatura") && this.getAcl().check("Fatura", "delete")) {
            this.dropdownItemList.push({
                name: "cancelarFatura",
                label:
                    this.translate("Cancelar fatura", "labels", "Fatura") ||
                    "Cancelar fatura",
                action: "cancelarFatura",
                groupIndex: 0,
            });
        }
    }

    afterRender() {
        if (typeof super.afterRender === "function") {
            super.afterRender();
        }
        this._refreshActionsByStatus();
    }

    /**
     * Esconde/mostra as actions conforme o status atual da fatura:
     *  - registrarPagamento: oculto se cancelada ou paga.
     *  - cancelarFatura: oculto se cancelada.
     */
    _refreshActionsByStatus() {
        if (!this.model || typeof this.hideActionItem !== "function") {
            return;
        }

        const status = this.model.get("status");

        if (status === "cancelada" || status === "paga") {
            this.hideActionItem("registrarPagamento");
        } else {
            this.showActionItem("registrarPagamento");
        }

        if (status === "cancelada") {
            this.hideActionItem("cancelarFatura");
        } else {
            this.showActionItem("cancelarFatura");
        }
    }

    /**
     * Action: abrir modal de registro de pagamento.
     * Pré-fixa faturaId, valor sugerido (= saldo), tipo (pagamento_total ou
     * parcial conforme valor==saldo).
     */
    actionRegistrarPagamento() {
        const fatura = this.model;
        if (!fatura) {
            return;
        }
        if (fatura.get("status") === "cancelada") {
            Espo.Ui.warning(
                this.translate("faturaJaCancelada", "messages", "Fatura") ||
                    "Esta fatura está cancelada e não aceita novas operações.",
            );
            return;
        }
        if (fatura.get("status") === "paga") {
            Espo.Ui.warning(
                this.translate("statusJaPaga", "messages", "Fatura") || "Esta fatura já está paga.",
            );
            return;
        }

        const saldo = parseFloat(fatura.get("saldo") || 0);
        const valorBruto = parseFloat(fatura.get("valorBruto") || 0);
        const tipoInferido =
            valorBruto > 0.001 && Math.abs(saldo - valorBruto) < 0.01
                ? "pagamento_total"
                : "pagamento_parcial";

        this.createView(
            "registrarPagamentoModal",
            "togare-core:views/lancamento-financeiro/modals/registrar-pagamento",
            {
                faturaId: fatura.id,
                clienteId: fatura.get("clienteId"),
                clienteName: fatura.get("clienteName"),
                processoId: fatura.get("processoId"),
                saldoSugerido: saldo,
                tipoSugerido: tipoInferido,
            },
            (view) => {
                view.render();
                view.listenToOnce(view, "after:save", () => {
                    fatura.fetch();
                });
            },
        );
    }

    /**
     * Action: cancelar fatura.
     *
     * Fix-pass v0.34.3 (smoke F1 browser round 3 — NOK ponto 7b): a versão
     * v0.34.2 usava `window.prompt` (×2), que o EspoCRM 9.x SPA / navegador
     * suprime silenciosamente (retorna null → return sem feedback). Migrado
     * para o pattern `Espo.Ui.Dialog` com textarea + contador + validação
     * ≥10 chars inline — IDÊNTICO ao status-selector.js (Story 4a.4) validado
     * por Felipe no browser. Fallback DOM puro p/ jsdom/tests.
     */
    actionCancelarFatura() {
        const fatura = this.model;
        if (!fatura) {
            return;
        }
        if (fatura.get("status") === "cancelada") {
            Espo.Ui.warning(
                this.translate("faturaJaCancelada", "messages", "Fatura") ||
                    "Esta fatura já está cancelada.",
            );
            return;
        }

        const title =
            this.translate("Cancelar fatura", "labels", "Fatura") || "Cancelar fatura";
        const irreversivel =
            this.translate("cancelarConfirmacao", "messages", "Fatura") ||
            "Esta ação é irreversível. A fatura ficará cancelada permanentemente.";
        const placeholder =
            this.translate("cancelarMotivoPlaceholder", "messages", "Fatura") ||
            "Motivo do cancelamento (mínimo 10 caracteres)";
        const confirmLabel = title;
        const cancelLabel = this.translate("Voltar") || "Voltar";
        const minChars = 10;

        this._openCancelarDialog({
            title,
            irreversivel,
            placeholder,
            confirmLabel,
            cancelLabel,
            minChars,
            onConfirm: (motivo) => this._doCancelarFatura(motivo),
        });
    }

    _openCancelarDialog(opts) {
        const esc = (s) =>
            String(s == null ? "" : s)
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;");

        const hasEspoDialog =
            typeof window !== "undefined" &&
            window.Espo &&
            window.Espo.Ui &&
            typeof window.Espo.Ui.Dialog === "function";

        const taId = `togare-cancelar-motivo-${Date.now()}`;
        const counterId = `togare-cancelar-counter-${Date.now()}`;
        const errId = `togare-cancelar-err-${Date.now()}`;
        const minCharsMsg = `Mínimo ${opts.minChars} caracteres.`;

        const bodyHtml =
            `<div class="togare-cancelar-fatura__form">` +
            `<p class="text-danger" style="font-weight:600;margin-bottom:8px;">${esc(opts.irreversivel)}</p>` +
            `<textarea id="${taId}" class="form-control" rows="4" placeholder="${esc(opts.placeholder)}" aria-label="${esc(opts.title)}" style="width:100%;margin-bottom:6px;"></textarea>` +
            `<div id="${counterId}" class="text-muted small" aria-live="polite"></div>` +
            `<div id="${errId}" class="text-danger small" aria-live="polite" style="display:none;"></div>` +
            `</div>`;

        if (!hasEspoDialog) {
            // Fallback DOM puro (jsdom/tests ou ambiente sem Espo.Ui.Dialog).
            if (typeof document === "undefined") {
                return;
            }
            const dlg = document.createElement("div");
            dlg.setAttribute("role", "dialog");
            dlg.setAttribute("aria-label", opts.title);
            dlg.innerHTML =
                `<div>${bodyHtml}` +
                `<button type="button" data-action="confirm">${esc(opts.confirmLabel)}</button>` +
                `<button type="button" data-action="cancel">${esc(opts.cancelLabel)}</button></div>`;
            document.body.appendChild(dlg);
            const ta = dlg.querySelector("textarea");
            dlg.querySelector('[data-action="cancel"]').addEventListener("click", () => dlg.remove());
            dlg.querySelector('[data-action="confirm"]').addEventListener("click", () => {
                const motivo = String((ta && ta.value) || "").trim();
                if (motivo.length < opts.minChars) {
                    return;
                }
                dlg.remove();
                opts.onConfirm(motivo);
            });
            return;
        }

        const dialog = new window.Espo.Ui.Dialog({
            backdrop: "static",
            header: opts.title,
            body: bodyHtml,
            buttonList: [
                {
                    text: opts.confirmLabel,
                    name: "confirm",
                    style: "danger",
                    onClick: (d) => {
                        const ta = document.getElementById(taId);
                        const errBox = document.getElementById(errId);
                        const motivo = String((ta && ta.value) || "").trim();
                        if (motivo.length < opts.minChars) {
                            if (errBox) {
                                errBox.style.display = "block";
                                errBox.textContent = minCharsMsg;
                            }
                            if (ta && typeof ta.focus === "function") ta.focus();
                            return false;
                        }
                        d.close();
                        opts.onConfirm(motivo);
                    },
                },
                {
                    text: opts.cancelLabel,
                    name: "cancel",
                    onClick: (d) => d.close(),
                },
            ],
        });
        dialog.show();

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
            const update = () => {
                const n = String(ta.value || "").trim().length;
                counter.textContent = `${n}/${opts.minChars}`;
                if (n >= opts.minChars) {
                    errBox.style.display = "none";
                }
            };
            ta.addEventListener("input", update);
            update();
            try {
                ta.focus();
            } catch (_) {
                // ignore
            }
        };
        tryWire(0);
    }

    _doCancelarFatura(motivo) {
        const fatura = this.model;
        if (!fatura) {
            return;
        }

        Espo.Ajax.postRequest("Fatura/action/cancelar", {
            id: fatura.id,
            motivo: motivo,
        })
            .then(() => {
                Espo.Ui.success(
                    this.translate("cancelarSucesso", "messages", "Fatura") || "Fatura cancelada.",
                );
                fatura.fetch();
                if (typeof window !== "undefined" && typeof window.CustomEvent === "function") {
                    window.dispatchEvent(
                        new CustomEvent("togare:fatura.updated", {
                            detail: { faturaId: fatura.id },
                        }),
                    );
                }
            })
            .catch((xhr) => {
                let detail = "";
                try {
                    if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                        detail = String(xhr.responseJSON.message);
                    } else if (xhr && xhr.getResponseHeader) {
                        detail = xhr.getResponseHeader("X-Status-Reason") || "";
                    }
                } catch (e) {
                    // ignore
                }
                Espo.Ui.error(detail || "Não foi possível cancelar esta fatura.");
            });
    }
}
