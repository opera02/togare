/**
 * WizardShellView — Wizard pós-primeiro-login (Story 2.6, FR34).
 *
 * Modal full-screen com state machine de 4 passos:
 *   1. Identidade (companyName + companyLogoFileId)
 *   2. Cor primária (#RRGGBB com 8 swatches sugeridos + entry manual)
 *   3. Confirmar/renomear roles (read-only para "Sócio/Admin"; outros 7 renomeáveis)
 *   4. Convidar usuários iniciais (≤20 invitees em batch via InvitationService)
 *
 * Patches code review 2026-04-26:
 *   P1:  onFinish chama inviteBatch antes de complete.
 *   P2:  _collectRoleIds split por vírgula.
 *   P12: _post parse JSON de erro em vez de exibir responseText cru.
 *   P13: onSkip remove view existente antes de recriar.
 *   P14: _posting flag previne duplo-clique.
 *   P15: fetchState não sobrescreve state digitado pelo usuário (dirty flag).
 *   P16: onAddInviteRow sincroniza DOM antes de reRender.
 *   P20: hedge-banner montado como view real via afterRender.
 *   P25: sessionStorage preserva step atual entre refreshes.
 *   P31: _collectRoleRenames valida nomes vazios antes de submit.
 *   P36: translate() tem fallback pt-BR hardcoded se i18n falhar.
 *   D2:  0 invitees → warning toast (não erro); wizard continua.
 */

import View from 'view';

const SUGGESTED_COLORS = [
    '#0a4d8c', '#1a6b3d', '#7a2c2c', '#5d3b8c',
    '#b8651a', '#2c5d6b', '#3a3a3a', '#1f7a4d',
];

// P36: fallback pt-BR hardcoded para quando i18n não carregou.
const PT_BR_FALLBACK = {
    'Step1Title': 'Identidade do escritório',
    'Step2Title': 'Cor primária',
    'Step3Title': 'Confirmar roles',
    'Step4Title': 'Convidar usuários iniciais',
    'WizardShell': 'Bem-vindo ao Togare',
};

export default class WizardShellView extends View {
    template = 'togare-rbac:wizard/wizard-shell';

    events = {
        'click [data-action="next"]': 'onNext',
        'click [data-action="prev"]': 'onPrev',
        'click [data-action="skip"]': 'onSkip',
        'click [data-action="finish"]': 'onFinish',
        'click [data-action="invite-add-row"]': 'onAddInviteRow',
        'click [data-role="color-swatch"]': 'onColorSwatchClick',
        'input [data-role="color-input"]': 'onColorInput',
        'input [data-role="company-name"]': 'onCompanyNameChange',
        'input [data-role="company-logo-file-id"]': 'onCompanyLogoFileIdChange',
    };

    setup() {
        super.setup();

        this.currentStep = 1;
        this._stateDirty = false; // P15: flag para evitar sobrescrever dados digitados
        this._posting = false;    // P14: flag para prevenir duplo-clique

        this.state = {
            companyName: '',
            companyLogoFileId: null,
            primaryColor: '#0a4d8c',
            roles: [],
            invitees: [{}],
        };
        this.suggestedColors = SUGGESTED_COLORS;

        // P25: restaurar step de sessionStorage se disponível.
        try {
            const savedStep = parseInt(sessionStorage.getItem('togareWizardStep') || '0', 10);
            if (savedStep >= 1 && savedStep <= 4) {
                this.currentStep = savedStep;
            }
        } catch (e) { /* sessionStorage indisponível */ }

        this._updatePreviewCss();
        this.fetchState();
    }

    afterRender() {
        // P20: montar hedge-banner como componente real togare-core.
        this.createView('hedgeBanner', 'togare-core:common/hedge-banner', {
            el: this.$el.find('[data-role="hedge-banner-mount"]')[0],
            variant: 'footer-global',
        }, function (view) {
            view.render();
        });
    }

    data() {
        const rolesAnnotated = (Array.isArray(this.state.roles) ? this.state.roles : []).map(function (r) {
            return Object.assign({}, r, {
                isReserved: r && r.name === 'Sócio/Admin',
            });
        });

        // P16: preservar valores digitados nos invitees ao reRender.
        const inviteesAnnotated = (this.state.invitees || []).map(function (inv) {
            return Object.assign({}, inv, {
                roleIdsStr: Array.isArray(inv.roleIds) ? inv.roleIds.join(', ') : (inv.roleIds || ''),
            });
        });

        return {
            currentStep: this.currentStep,
            state: Object.assign({}, this.state, {
                roles: rolesAnnotated,
                invitees: inviteesAnnotated,
            }),
            suggestedColors: this.suggestedColors,
            isStep1: this.currentStep === 1,
            isStep2: this.currentStep === 2,
            isStep3: this.currentStep === 3,
            isStep4: this.currentStep === 4,
            stepLabel: this.translate('Step' + this.currentStep + 'Title', 'labels', 'TogareWizard'),
        };
    }

    fetchState() {
        const self = this;
        try {
            Espo.Ajax.getRequest('TogareRbacWizard/action/state').then(function (response) {
                // P15: não sobrescrever se usuário já digitou algo.
                if (self._stateDirty) return;

                if (response) {
                    self.state.companyName = response.companyName || '';
                    self.state.companyLogoFileId = response.companyLogoId || null;
                    self.state.primaryColor = response.togarePrimaryColor || '#0a4d8c';
                    self.state.roles = Array.isArray(response.roles) ? response.roles : [];

                    // P25: sessionStorage tem precedência sobre heurística do servidor.
                    let serverStep = 1;
                    if (response.currentStep && Number.isInteger(response.currentStep)) {
                        serverStep = Math.max(1, Math.min(4, response.currentStep));
                    }
                    try {
                        const savedStep = parseInt(sessionStorage.getItem('togareWizardStep') || '0', 10);
                        if (savedStep >= serverStep && savedStep <= 4) {
                            self.currentStep = savedStep;
                        } else {
                            self.currentStep = serverStep;
                        }
                    } catch (e) {
                        self.currentStep = serverStep;
                    }

                    self._updatePreviewCss();
                    self.reRender();
                }
            }).catch(function (err) {
                if (typeof console !== 'undefined' && console.error) {
                    console.error('[wizard] fetchState failed:', err);
                }
            });
        } catch (e) {
            if (typeof console !== 'undefined' && console.error) {
                console.error('[wizard] fetchState exception:', e);
            }
        }
    }

    onCompanyNameChange(e) {
        this.state.companyName = (e && e.target ? e.target.value : '') || '';
        this._stateDirty = true; // P15
    }

    onCompanyLogoFileIdChange(e) {
        const v = (e && e.target ? e.target.value : '') || '';
        this.state.companyLogoFileId = v.trim() || null;
        this._stateDirty = true; // P15
    }

    onColorSwatchClick(e) {
        const color = e && e.currentTarget ? e.currentTarget.getAttribute('data-color') : null;
        if (!color) return;
        this.state.primaryColor = color;
        this._updatePreviewCss();
        const input = this.$el.find('[data-role="color-input"]');
        if (input && input.length) input.val(color);
    }

    onColorInput(e) {
        this.state.primaryColor = (e && e.target ? e.target.value : '') || '';
        this._updatePreviewCss();
    }

    _updatePreviewCss() {
        try {
            if (typeof document !== 'undefined' && document.documentElement) {
                document.documentElement.style.setProperty(
                    '--togare-primary',
                    this.state.primaryColor || '#0a4d8c'
                );
            }
        } catch (e) {
            // ignore
        }
    }

    onNext() {
        const self = this;
        const step = this.currentStep;

        const advance = function () {
            self.currentStep = Math.min(4, step + 1);
            // P25: persistir step em sessionStorage.
            try { sessionStorage.setItem('togareWizardStep', String(self.currentStep)); } catch (e) { /* ignore */ }
            self.reRender();
            self._toast('success', self._translateMessage(step));
        };

        if (step === 1) {
            this._post('TogareRbacWizard/action/applyOrgInfo', {
                companyName: this.state.companyName,
                companyLogoFileId: this.state.companyLogoFileId,
            }, advance);
        } else if (step === 2) {
            this._post('TogareRbacWizard/action/applyPrimaryColor', {
                primaryColor: this.state.primaryColor,
            }, advance);
        } else if (step === 3) {
            // P31: validar nomes antes de submeter.
            const renames = this._collectRoleRenames();
            if (renames === null) return;
            this._post('TogareRbacWizard/action/confirmRoles', {
                roleRenameMap: renames,
            }, advance);
        }
    }

    onPrev() {
        if (this.currentStep > 1) {
            this.currentStep -= 1;
            try { sessionStorage.setItem('togareWizardStep', String(this.currentStep)); } catch (e) { /* ignore */ }
            this.reRender();
        }
    }

    /**
     * P1 + D2: Passo 4 — enviar convites (se houver) e depois completar wizard.
     * D2: se nenhum invitee preenchido, exibe warning toast e prossegue sem convidar.
     */
    onFinish() {
        const self = this;
        this._syncInviteesFromDom();

        const filledInvitees = (this.state.invitees || []).filter(function (inv) {
            return (inv.userName || '').trim() || (inv.emailAddress || '').trim();
        });

        const doComplete = function () {
            self._post('TogareRbacWizard/action/complete', {}, function () {
                self._toast('success', self.translate('togareWizardCompleted', 'messages', 'Global'));
                self._closeWizard();
            });
        };

        if (filledInvitees.length === 0) {
            // D2: 0 invitees → warning + prosseguir sem convidar.
            self._toast('warning', self.translate('inviteNoRows', 'messages', 'TogareWizard'));
            doComplete();
            return;
        }

        // P1: enviar inviteBatch antes de marcar wizard completo.
        self._post('TogareRbacWizard/action/inviteBatch', { invitees: self.state.invitees }, function (resp) {
            const sent = (resp && Array.isArray(resp.succeeded)) ? resp.succeeded.length : 0;
            const failed = (resp && Array.isArray(resp.failed)) ? resp.failed.length : 0;
            self._toast('success', self.translate('togareWizardInvitesSent', 'messages', 'Global')
                .replace('{sent}', sent)
                .replace('{failed}', failed));
            doComplete();
        });
    }

    _collectRoleRenames() {
        const map = {};
        let hasError = false;
        if (!Array.isArray(this.state.roles)) return map;
        const inputs = this.$el.find('[data-role="role-rename"]');
        for (let i = 0; i < inputs.length; i++) {
            const $i = inputs.eq(i);
            const oldName = $i.attr('data-old-name');
            const val = ($i.val() || '').trim();
            // P31: validar nome vazio inline.
            if (oldName && val === '') {
                $i.addClass('togare-wizard__input--error');
                hasError = true;
                continue;
            }
            $i.removeClass('togare-wizard__input--error');
            if (oldName && val && val !== oldName) {
                map[oldName] = val;
            }
        }
        if (hasError) {
            this._toast('error', this.translate('roleRenameInvalid', 'messages', 'TogareWizard'));
            return null; // sinaliza erro ao chamador
        }
        return map;
    }

    onAddInviteRow() {
        if ((this.state.invitees || []).length >= 20) {
            this._toast('warning', this.translate('inviteMaxLimit', 'messages', 'TogareWizard'));
            return;
        }
        // P16: sincronizar DOM → state antes de reRender para não perder dados digitados.
        this._syncInviteesFromDom();
        this.state.invitees.push({});
        this.reRender();
    }

    /** P16: lê valores do DOM e sincroniza em state.invitees. */
    _syncInviteesFromDom() {
        if (!this.$el) return;
        const rows = this.$el.find('[data-role="invitee-row"]');
        const invitees = [];
        for (let i = 0; i < rows.length; i++) {
            const $r = rows.eq(i);
            invitees.push({
                userName: ($r.find('[name="userName"]').val() || '').trim(),
                emailAddress: ($r.find('[name="emailAddress"]').val() || '').trim(),
                firstName: ($r.find('[name="firstName"]').val() || '').trim(),
                lastName: ($r.find('[name="lastName"]').val() || '').trim(),
                roleIds: this._collectRoleIds($r),
            });
        }
        if (invitees.length > 0) {
            this.state.invitees = invitees;
        }
    }

    _collectRoleIds($row) {
        const select = $row.find('[name="roleIds"]');
        if (!select || !select.length) return [];
        const v = select.val();
        if (Array.isArray(v)) return v.filter(Boolean);
        // P2: split por vírgula — o input é texto "id1,id2".
        return v ? v.split(',').map(function (s) { return s.trim(); }).filter(Boolean) : [];
    }

    onSkip() {
        const self = this;
        // P13: remover view existente antes de criar nova (evita modais aninhados).
        const existingSkip = self.getView('skipConfirm');
        if (existingSkip) { existingSkip.remove(); }

        const expected = this.translate('togareWizardSkipConfirmExpected', 'messages', 'Global') || 'pular';
        const skipLabel = this.translate('skipWizard', 'messages', 'TogareWizard') || 'Pular wizard';

        this.createView('skipConfirm', 'togare-core:common/confirmacao-textual', {
            el: this.$el.find('[data-role="skip-confirm-mount"]')[0] || this.el,
            expectedName: expected,
            ctaLabel: skipLabel,
            modal: true,
            onConfirm: function () {
                const v = self.getView('skipConfirm');
                if (v && v.remove) v.remove();
                self._post('TogareRbacWizard/action/complete', { skipped: true }, function () {
                    self._toast('warning', self.translate('togareWizardSkipped', 'messages', 'Global'));
                    self._closeWizard();
                });
            },
            onCancel: function () {
                const v = self.getView('skipConfirm');
                if (v && v.remove) v.remove();
            },
        }, function (view) {
            view.render();
        });
    }

    _post(url, body, onOk) {
        const self = this;
        // P14: prevenir duplo-clique / requests simultâneas.
        if (self._posting) return;
        self._posting = true;

        try {
            Espo.Ajax.postRequest(url, body || {}).then(function (resp) {
                self._posting = false;
                if (typeof onOk === 'function') onOk(resp);
            }).catch(function (xhr) {
                self._posting = false;
                // P12: parse JSON de erro; não exibir responseText cru (stack trace).
                let message = 'Erro ao processar passo. Tente novamente.';
                try {
                    const status = xhr && xhr.status ? xhr.status : 0;
                    if (status > 0 && status < 500 && xhr.responseText) {
                        try {
                            const parsed = JSON.parse(xhr.responseText);
                            message = parsed.message || parsed.error || message;
                        } catch (e) {
                            // corpo não-JSON: manter mensagem genérica
                        }
                    }
                } catch (e) { /* ignore */ }
                self._toast('error', message);
            });
        } catch (e) {
            self._posting = false;
            self._toast('error', 'Erro inesperado. Tente novamente.');
        }
    }

    _toast(variant, message) {
        try {
            this.createView('toast', 'togare-core:common/toast-togare', {
                variant: variant,
                message: message,
            }, function (view) {
                view.render();
            });
        } catch (e) {
            if (typeof Espo !== 'undefined' && Espo.Ui && Espo.Ui.notify) {
                Espo.Ui.notify(message);
            } else if (typeof alert !== 'undefined') {
                alert(message);
            }
        }
    }

    _translateMessage(step) {
        const map = {
            1: 'togareWizardOrgConfirmed',
            2: 'togareWizardColorConfirmed',
            3: 'togareWizardRolesConfirmed',
        };
        return this.translate(map[step] || 'togareWizardCompleted', 'messages', 'Global');
    }

    _closeWizard() {
        // P25: limpar sessionStorage ao fechar.
        try { sessionStorage.removeItem('togareWizardStep'); } catch (e) { /* ignore */ }
        const mount = document.getElementById('togare-rbac-wizard-mount');
        if (mount && mount.parentNode) {
            mount.parentNode.removeChild(mount);
        }
        this.remove();
    }

    // P36: translate com fallback pt-BR hardcoded quando i18n não carregou.
    translate(label, category, scope) {
        if (this.getLanguage && this.getLanguage().translate) {
            const v = this.getLanguage().translate(label, category || 'labels', scope || 'TogareWizard');
            if (v !== label) return v; // tradução real encontrada
        }
        return PT_BR_FALLBACK[label] || label;
    }
}
