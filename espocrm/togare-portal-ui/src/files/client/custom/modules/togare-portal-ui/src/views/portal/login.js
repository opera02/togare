/**
 * PortalSplash — login branded do Portal do Cliente (Story 7a.1, UX-DR11,
 * C5, rota crítica AAA #1, flow F2).
 *
 * Estende o login STOCK do EspoCRM (views/login). O branding é aplicado
 * via afterRender DOM-patch APENAS quando em contexto Portal:
 *  - contexto Portal  → splash full-screen branded (logo/cor/frase/fone);
 *  - contexto CRM      → passthrough transparente (login operacional do
 *                        escritório permanece 100% padrão EspoCRM — AC3).
 *
 * Por que afterRender DOM-patch e não reescrever o template: o EspoCRM 9.x
 * frequentemente ignora templateContent/detailTemplateContent (memória
 * feedback_espocrm_field_view_dom_patch — saga 5.7-followup). Patchar o DOM
 * do login stock no afterRender tem a MENOR superfície de regressão sobre
 * o fluxo de autenticação (que é da Story 7a.2, fora do escopo aqui).
 *
 * Config (canal pré-auth getAllNonInternalData — params globais de Settings):
 *  - togarePortalSplashPrimaryColor (hex)
 *  - togarePortalSplashWelcome      (texto; default curado vem de i18n)
 *  - togarePortalSplashPhone        (telefone; opcional)
 *  - togarePortalSplashLogoId       (attachment id; servido pelo entry
 *                                    point público do módulo)
 *
 * Copy: rótulos/placeholder/default 100% via i18n (PortalSplash scope) —
 * zero string visível inline (AC4/A2; antecipa a regra cobrada na 7a.3).
 *
 * Story 7a.2 (estende, não reescreve — fronteira de escopo): copy
 * acolhedora de senha errada (C5, AC6) via override SOMENTE de
 * `onFail()` — o funil de FALHA de login do stock (nunca roda no
 * sucesso → não afeta o boot do CRM; fix da regressão 2026-05-17, ver
 * docstring de onFail). Lockout = rate limit 100% NATIVO (togare-rbac);
 * NÃO se sobrescreve proceed()/login(). CRM intacto (AC3). Auth/ACL
 * backend e provisionamento no PHP do módulo (Tools/PortalAccess,
 * Classes/*).
 *
 * Checklist A3 (2 traps ES module EspoCRM 9.x):
 *  (a) window.Espo referenciado explicitamente onde usado (helper _espo()
 *      — não assume global no escopo do módulo transpilado).
 *  (b) Sem createView({el}) — esta view NÃO monta sub-view custom; só
 *      faz injeção DOM idempotente + override de onFail (funil de falha,
 *      não fluxo de auth). A 2ª trap não se aplica.
 */

import LoginView from "views/login";

const ROOT_CLASS = "togare-portal-splash";

export default class TogarePortalSplashLoginView extends LoginView {
    /**
     * Contexto Portal? Detecção primária por config portalId (o
     * Espo\Core\Portal\Utils\Config injeta params de portal pré-auth);
     * secundária defensiva pelo path da URL.
     * @return {boolean}
     * @private
     */
    _isPortalContext() {
        try {
            if (this.getConfig().get("portalId")) {
                return true;
            }
        } catch (e) {
            // getConfig pode não ter portalId — cai no fallback de path.
        }

        const path = (window.location && window.location.pathname) || "";

        return /\/portal(\/|$)/.test(path) || path.indexOf("/portal") !== -1;
    }

    /** @inheritDoc */
    getLogoSrc() {
        if (this._isPortalContext()) {
            const logoId = this.getConfig().get("togarePortalSplashLogoId");

            if (logoId) {
                return (
                    this.getBasePath() +
                    "?entryPoint=PortalSplashLogoImage&id=" +
                    encodeURIComponent(logoId)
                );
            }
        }

        return super.getLogoSrc();
    }

    /** @inheritDoc */
    afterRender() {
        super.afterRender();

        if (!this._isPortalContext()) {
            // AC3: CRM intacto.
            return;
        }

        this._applyBranding();
    }

    /**
     * Aplica o branding do splash de forma idempotente.
     * @private
     */
    _applyBranding() {
        const root = this.element;

        if (!root) {
            return;
        }

        // Marca o body e o container para o CSS de acessibilidade AAA
        // (fonte >=18px, alvos >=48px, foco 2px) atuar só no Portal.
        if (document.body && !document.body.classList.contains(ROOT_CLASS)) {
            document.body.classList.add(ROOT_CLASS);
        }

        document.documentElement.setAttribute("lang", "pt-BR");

        // Cor primária do escritório → fundo full-screen.
        const color = this.getConfig().get("togarePortalSplashPrimaryColor");

        if (color && /^#[0-9a-fA-F]{3,6}$/.test(color)) {
            document.body.style.setProperty(
                "--togare-portal-primary",
                color,
            );
        }

        this._renderWelcome(root);
        this._renderPhoneFallback(root);
        this._enlargeFormControls(root);
    }

    /**
     * Frase de boas-vindas configurável; default curado vem do i18n
     * (aprovado por Felipe — Gate A2). Idempotente.
     * @private
     */
    _renderWelcome(root) {
        const configured = this.getConfig().get("togarePortalSplashWelcome");

        const text =
            configured && String(configured).trim() !== ""
                ? String(configured)
                : this.translate("splashDefault", "messages", "PortalSplash");

        let el = root.querySelector(".togare-portal-splash__welcome");

        if (!el) {
            el = document.createElement("p");
            el.className = "togare-portal-splash__welcome";

            const panelBody =
                root.querySelector(".panel-body") ||
                root.querySelector("#login") ||
                root;

            panelBody.insertBefore(el, panelBody.firstChild);
        }

        el.textContent = text;
    }

    /**
     * Fallback telefônico SEMPRE visível (AC6). Texto template via i18n
     * com placeholder {telefone}; quando o escritório não configurou o
     * número, usa o placeholder neutro pt-BR do i18n (nunca vazio/undefined).
     * @private
     */
    _renderPhoneFallback(root) {
        const phone = this.getConfig().get("togarePortalSplashPhone");

        const placeholder = this.translate(
            "phonePlaceholder",
            "messages",
            "PortalSplash",
        );

        const shown =
            phone && String(phone).trim() !== ""
                ? String(phone)
                : placeholder;

        const template = this.translate(
            "phoneFallback",
            "messages",
            "PortalSplash",
        );

        const text = template.replace("{telefone}", shown);

        let el = root.querySelector(".togare-portal-splash__phone");

        if (!el) {
            el = document.createElement("p");
            el.className = "togare-portal-splash__phone";

            const panelBody =
                root.querySelector(".panel-body") ||
                root.querySelector("#login") ||
                root;

            panelBody.appendChild(el);
        }

        el.textContent = text;
    }

    /**
     * Garante alvos de toque >=48px e labels explícitos via classe no
     * container (o dimensionamento real está no accessibility.css; aqui
     * só marcamos para o CSS escopar). Idempotente.
     * @private
     */
    _enlargeFormControls(root) {
        const form = root.querySelector("#login-form") || root;

        if (form && !form.classList.contains("togare-portal-splash__form")) {
            form.classList.add("togare-portal-splash__form");
        }
    }

    /**
     * Resolve o objeto global Espo de forma robusta (trap A3 — escopo de
     * módulo transpilado; memória feedback_espocrm_window_espo_module_scope).
     * @private
     */
    _espo() {
        return (typeof window !== "undefined" && window.Espo) || Espo;
    }

    /**
     * Estados de erro do login do Portal (AC6, C5) — copy acolhedora.
     *
     * CRÍTICO (regressão do smoke browser Felipe 2026-05-17): a 7a.2
     * original sobrescrevia `proceed()`/`login()`. Esses métodos do login
     * stock rodam no **caminho de sucesso** da autenticação (login →
     * proceed → triggerLogin). Sobrescrevê-los — mesmo delegando via
     * `super` fora do Portal — quebrava o boot do CRM no navegador
     * (classe-bug "só pega no browser", saga GateBanner 6.2): após login
     * o app abria 1-2s e voltava pro login. Servidor 100% saudável; era
     * a view custom global. **Fix:** override APENAS de `onFail()` — o
     * funil ÚNICO do stock para falha de login (`onWrongCredentials`/
     * `onError` → `onFail`). `onFail` só é chamado quando o login
     * **falha**; nunca no sucesso → fisicamente incapaz de causar o
     * bounce pós-login. Zero override do fluxo de auth. Fora do Portal,
     * delega 100% ao stock (AC3 — CRM intacto).
     *
     * Lockout (NFR11): o rate limit é 100% NATIVO (togare-rbac
     * AuthLockoutEnforcer → Forbidden com mensagem pt-BR acolhedora "Conta
     * temporariamente bloqueada. Tente novamente em 15 minutos."). NÃO
     * reimplementamos contador nem reescrevemos proceed só para trocar a
     * copy do 403 — o risco de regressão no CRM (provado) supera o ganho
     * cosmético do {telefone}. Ajuste de escopo AC6 documentado p/ Felipe.
     *
     * @inheritDoc
     * @param {string} msg
     */
    onFail(msg) {
        if (!this._isPortalContext()) {
            return super.onFail(msg);
        }

        const Espo_ = this._espo();

        const cell =
            (this.element &&
                (this.element.querySelector("#login .form-group") ||
                    this.element.querySelector(".form-group"))) ||
            null;

        if (cell && cell.classList) {
            cell.classList.add("has-error");
        }

        const field =
            (this.element &&
                (this.element.querySelector('input[name="password"]') ||
                    this.element.querySelector('input[name="username"]'))) ||
            null;

        if (field && typeof field.focus === "function") {
            try {
                field.focus();
            } catch (e) {
                // ambiente sem DOM real (vitest) — ignora.
            }
        }

        Espo_.Ui.error(
            this.translate("senhaErrada", "messages", "PortalSplash"),
        );
    }

    /** @inheritDoc */
    onRemove() {
        if (document.body) {
            document.body.classList.remove(ROOT_CLASS);
            document.body.style.removeProperty("--togare-portal-primary");
        }

        if (super.onRemove) {
            super.onRemove();
        }
    }
}
