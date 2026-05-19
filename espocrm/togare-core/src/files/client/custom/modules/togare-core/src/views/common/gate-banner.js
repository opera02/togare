/**
 * GateBanner — Pattern Transversal UX-DR2 P2.
 *
 * Banner de gate polimórfico. 3 variants (financeiro-sem-contrato,
 * licenca-expirada, pre-requisito-entidade) — mesma view, copy e
 * classe CSS variam por prop `variant`.
 *
 * Diferença de HedgeBanner: GateBanner é ativo (bloqueia ação),
 * aparece condicionalmente e tem CTA obrigatório na variant principal.
 * HedgeBanner é passivo (disclaimer sempre-visível, sem CTA).
 *
 * Copy em Resources/i18n/pt_BR/GateBanner.json (UX-DR5: nunca inline).
 *
 * Uso:
 *   new GateBannerView({ variant: 'financeiro-sem-contrato' });
 *
 * CTA click emite evento Backbone:
 *   view.on('cta:click:cadastrar-contrato', handler)
 */

import View from "view";

const VALID_VARIANTS = [
    "financeiro-sem-contrato",
    "licenca-expirada",
    "pre-requisito-entidade",
];

const DEFAULT_VARIANT = "financeiro-sem-contrato";

export default class GateBannerView extends View {
    template = "togare-core:common/gate-banner";

    events = {
        "click .togare-gate-banner__cta": "_onCtaClick",
    };

    setup() {
        super.setup();

        const requested = this.options.variant || DEFAULT_VARIANT;
        if (!VALID_VARIANTS.includes(requested)) {
            if (typeof console !== "undefined" && console.warn) {
                console.warn(
                    `[togare-core/GateBanner] variant inválida '${requested}' — usando fallback '${DEFAULT_VARIANT}'.`,
                );
            }
            this.variant = DEFAULT_VARIANT;
        } else {
            this.variant = requested;
        }
    }

    data() {
        const raw = this.getLanguage().translate(
            this.variant,
            "variants",
            "GateBanner",
            "TogareCore",
        );
        const variantCopy =
            typeof raw === "object" && raw !== null ? raw : { text: String(raw) };

        return {
            variant: this.variant,
            cssClass: `togare-gate-banner togare-gate-banner--${this.variant}`,
            text: variantCopy.text || "",
            ctaLabel: variantCopy.ctaLabel || null,
            ctaTarget: variantCopy.ctaTarget || null,
        };
    }

    _onCtaClick(e) {
        const target =
            (e && e.currentTarget && e.currentTarget.dataset
                ? e.currentTarget.dataset.ctaTarget
                : null) || null;
        if (target) {
            this.trigger("cta:click:" + target);
        }
    }
}
