/**
 * StatusBadge — Componente UX-DR1 C2.
 *
 * Indicador visual de estado em cards, listas e itens de fila. 5 estados
 * cromáticos fixos + 3 tamanhos.
 *
 * Colorblind-safe: cor + ícone + label (nunca só cor).
 * Pulso do estado crítico respeita `prefers-reduced-motion` via CSS.
 *
 * Copy em Resources/i18n/pt_BR/StatusBadge.json.
 *
 * Uso:
 *   new StatusBadgeView({ status: 'pendente' });
 *   new StatusBadgeView({ status: 'critico', size: 'large', criticalDays: 1 });
 */

import View from "view";

// Story 4a.4 T10 (Plano A): expansão de 5 → 14 estados.
// Story 4b.3: +1 estado `vence_hoje` (UX-DR10 — redundância semântica D-0).
// Estados legados (não-Prazo, ainda usados em outros componentes):
//   pendente, confirmado, precisa-leitura, critico, info
// Estados Prazo enum 9 valores (mapeia entityDefs/Prazo.json::status.options
// + UX spec C2 v1.1 linhas 574-583):
//   rascunho, atrasado_reagendado, aguardando_cliente, aguardando_correcao,
//   protocolado, ciencia_renuncia, acompanhamento, descartado
// Story 4b.3 (Decisão #4): `vence_hoje` é CAMADA VISUAL CUMULATIVA — NÃO
// substitui o status real do Prazo. Renderizado em badge ADICIONAL pelo
// CardDePrazo quando `isVenceHoje(dataFatal)` E status ∈ família "ainda
// em jogo". Background vermelho sólido + ícone sino + texto "VENCE HOJE".
// Nota: `pendente` é COMPARTILHADO entre legacy e Prazo (mesma cor amarelo +
// mesmo ícone clock). i18n diferencia via override por instância se preciso.
const VALID_STATES = [
    // Legados:
    "pendente",
    "confirmado",
    "precisa-leitura",
    "critico",
    "info",
    // Prazo (Story 4a.3.1 + 4a.4):
    "rascunho",
    "atrasado_reagendado",
    "aguardando_cliente",
    "aguardando_correcao",
    "protocolado",
    "ciencia_renuncia",
    "acompanhamento",
    "descartado",
    // Story 4b.3 — UX-DR10 redundância semântica D-0:
    "vence_hoje",
];
const VALID_SIZES = ["small", "medium", "large"];
const DEFAULT_STATE = "info";
const DEFAULT_SIZE = "medium";

export default class StatusBadgeView extends View {
  template = "togare-core:common/status-badge";

  setup() {
    super.setup();

    const requestedStatus = this.options.status || DEFAULT_STATE;
    this.status = VALID_STATES.includes(requestedStatus) ? requestedStatus : DEFAULT_STATE;

    const requestedSize = this.options.size || DEFAULT_SIZE;
    this.size = VALID_SIZES.includes(requestedSize) ? requestedSize : DEFAULT_SIZE;

    // Apenas relevante para status=critico.
    this.criticalDays = this.options.criticalDays ?? null;
  }

  data() {
    const copy = this.getLanguage().translate(
      this.status,
      "states",
      "StatusBadge",
      "TogareCore",
    );
    const state = typeof copy === "object" && copy !== null ? copy : { label: this.status, icon: "", ariaLabel: this.status };

    let ariaLabel = state.ariaLabel || state.label || this.status;
    // Story 4a.4 T10: critical-days override também aplica ao novo
    // `atrasado_reagendado` (rota crítica AAA). i18n declara
    // ariaLabelWithDays/Plural quando aplicável; outros estados sem essas
    // chaves ignoram o criticalDays silenciosamente.
    const STATES_WITH_CRITICAL_DAYS = ["critico", "atrasado_reagendado"];
    if (STATES_WITH_CRITICAL_DAYS.includes(this.status) && this.criticalDays !== null) {
      const tpl = this.criticalDays === 1
        ? state.ariaLabelWithDays
        : state.ariaLabelWithDaysPlural;
      if (typeof tpl === "string") {
        ariaLabel = tpl.replace("{days}", String(this.criticalDays));
      }
    }

    const cssStatus = this.status === "vence_hoje" ? "vence-hoje" : this.status;

    return {
      status: this.status,
      size: this.size,
      cssClass: `togare-status-badge togare-status-badge--${cssStatus} togare-status-badge--${this.size}`,
      icon: state.icon || "",
      label: state.label || this.status,
      ariaLabel,
    };
  }
}
