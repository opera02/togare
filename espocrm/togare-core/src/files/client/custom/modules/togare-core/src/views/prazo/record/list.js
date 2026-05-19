/**
 * Prazo — list view custom (Story 4b.4 / FR18 / NFR19).
 *
 * Extends stock `views/record/list` apenas para mount o
 * `SystemStatusBannerView` no topo da tabela. Sem mudança em
 * mass-actions, columns, sorting — preserva 100% do comportamento default.
 *
 * Banner aparece quando o circuit breaker do DjenAdapter está aberto há
 * ≥30 min (consome `GET /api/v1/TogareDjenStatus/action/snapshot` via
 * polling 60s). Some quando o CB recupera.
 *
 * Defesa B0 (Story 4a.4 v0.19.1): único import ES6 é `views/record/list`
 * (whitelisted em tools/validate-bundle-imports.mjs).
 */

import ListRecordView from "views/record/list";
import {
  ensureSystemStatusBannerMount,
  mountSystemStatusBanner,
} from "togare-core:helpers/system-status-banner-mount";

export default class PrazoListView extends ListRecordView {
  afterRender() {
    if (typeof super.afterRender === "function") {
      super.afterRender();
    }
    this._mountSystemStatusBanner();
  }

  _mountSystemStatusBanner() {
    const mount = ensureSystemStatusBannerMount(this.el || (this.$el && this.$el[0]) || null, [
      ".list-container",
      ".list",
      ".panel",
    ]);
    mountSystemStatusBanner(this, mount, "_systemStatusBanner");
  }
}
