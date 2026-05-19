/**
 * EmptyStateCalmo — Componente UX-DR1 C12.
 *
 * Estado vazio acolhedor. Substitui o "No records found" vanilla por
 * algo humano em pt-BR. 3 variantes:
 *   - zero-calmo: não tem e é ok ("Nenhum prazo hoje. Aproveita o café.")
 *   - zero-educativo: nunca teve ainda, explica o porquê ("Você ainda não
 *     tem prazos cadastrados. Quando o DJEN sincronizar, eles aparecem aqui.")
 *   - zero-filtrado: filtro não retornou ("Nenhum resultado com esses filtros.")
 *
 * Copy em Resources/i18n/pt_BR/EmptyStateCalmo.json.
 *
 * Uso:
 *   new EmptyStateCalmoView({ variant: 'zero-calmo', context: 'prazos-hoje' });
 *   new EmptyStateCalmoView({ variant: 'zero-filtrado', cta: 'limparFiltros', onCta: () => ... });
 */

import View from "view";

const VALID_VARIANTS = ["zero-calmo", "zero-educativo", "zero-filtrado"];
const DEFAULT_VARIANT = "zero-calmo";
const DEFAULT_CONTEXT = "default";

export default class EmptyStateCalmoView extends View {
  template = "togare-core:common/empty-state-calmo";

  events = {
    "click [data-action=\"togare-empty-cta\"]": "onCtaClick",
  };

  setup() {
    super.setup();

    const requestedVariant = this.options.variant || DEFAULT_VARIANT;
    this.variant = VALID_VARIANTS.includes(requestedVariant) ? requestedVariant : DEFAULT_VARIANT;
    this.context = this.options.context || DEFAULT_CONTEXT;
    this.ctaKey = this.options.cta || null;
    this.onCtaCallback = typeof this.options.onCta === "function" ? this.options.onCta : null;
  }

  data() {
    const variantMap = this.getLanguage().translate(
      this.variant,
      "variants",
      "EmptyStateCalmo",
      "TogareCore",
    );
    const contextMap = typeof variantMap === "object" && variantMap !== null ? variantMap : {};
    const text =
      contextMap[this.context] ||
      contextMap[DEFAULT_CONTEXT] ||
      "Nada por aqui agora.";

    let ctaLabel = null;
    if (this.ctaKey) {
      const ctasMap = this.getLanguage().translate("ctas", "fields", "EmptyStateCalmo", "TogareCore");
      if (typeof ctasMap === "object" && ctasMap !== null && ctasMap[this.ctaKey]) {
        ctaLabel = ctasMap[this.ctaKey];
      }
    }

    return {
      variant: this.variant,
      context: this.context,
      cssClass: `togare-empty-state-calmo togare-empty-state-calmo--${this.variant}`,
      text,
      ctaLabel,
    };
  }

  onCtaClick(e) {
    e.preventDefault();
    if (this.onCtaCallback) {
      this.onCtaCallback(e);
    }
  }
}
