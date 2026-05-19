/**
 * HedgeBanner — Pattern Transversal UX-DR2 P1.
 *
 * Banner de disclaimer polimórfico. 4 variants (footer-global,
 * module-deadline, action-destructive, portal-friendly) — mesma view,
 * copy e classe CSS variam por prop `variant`.
 *
 * Copy em Resources/i18n/pt_BR/HedgeBanner.json (UX-DR5: nunca inline).
 *
 * Uso:
 *   new HedgeBannerView({ variant: 'footer-global' });
 *   new HedgeBannerView({ variant: 'module-deadline', learnMoreUrl: '...' });
 */

import View from "view";

const VALID_VARIANTS = [
  "footer-global",
  "module-deadline",
  "action-destructive",
  "portal-friendly",
];

const DEFAULT_VARIANT = "footer-global";

export default class HedgeBannerView extends View {
  template = "togare-core:common/hedge-banner";

  setup() {
    super.setup();

    const requested = this.options.variant || DEFAULT_VARIANT;
    if (!VALID_VARIANTS.includes(requested)) {
      if (typeof console !== "undefined" && console.warn) {
        console.warn(
          `[togare-core/HedgeBanner] variant inválida '${requested}' — usando fallback '${DEFAULT_VARIANT}'.`,
        );
      }
      this.variant = DEFAULT_VARIANT;
    } else {
      this.variant = requested;
    }

    this.learnMoreUrl = this.options.learnMoreUrl || null;
  }

  data() {
    const text = this.getLanguage().translate(
      this.variant,
      "variants",
      "HedgeBanner",
      "TogareCore",
    );
    // O translate retorna um objeto quando a chave aponta pra dicionário;
    // extraímos `text` e `learnMoreLabel`.
    const variantCopy = typeof text === "object" && text !== null ? text : { text: String(text) };

    return {
      variant: this.variant,
      cssClass: `togare-hedge-banner togare-hedge-banner--${this.variant}`,
      text: variantCopy.text || "",
      learnMoreLabel: variantCopy.learnMoreLabel || null,
      learnMoreUrl: this.learnMoreUrl,
    };
  }
}
