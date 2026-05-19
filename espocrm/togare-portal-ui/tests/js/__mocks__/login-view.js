/**
 * Mock mínimo de `views/login` (login stock do EspoCRM 9.x) para Vitest.
 *
 * Cobre só o que o TogarePortalSplashLoginView consome: options, element,
 * getConfig().get(), getBasePath(), getLogoSrc() (super), translate(),
 * afterRender()/onRemove() encadeáveis.
 */

export default class LoginView {
    constructor(options = {}) {
        this.options = options;
        this.element = options.element || document.createElement("div");
        this._config = options.config || {};
        this._lang = options.lang || {};
    }

    getConfig() {
        return {
            get: (k) => this._config[k],
        };
    }

    getBasePath() {
        return "/";
    }

    getLogoSrc() {
        return "STOCK_LOGO_SRC";
    }

    translate(key, category, scope) {
        const dict = this._lang[scope] || {};
        const cat = dict[category] || {};

        return cat[key] !== undefined ? cat[key] : key;
    }

    afterRender() {
        this._superAfterRenderCalled = true;
    }

    onRemove() {
        this._superOnRemoveCalled = true;
    }

    // --- Funil de FALHA de login do stock (Story 7a.2 só sobrescreve este) ---

    /** "super" — onFail nativo (cosmético; só roda quando o login falha). */
    onFail(msg) {
        this._superOnFailCalled = msg;
    }
}
