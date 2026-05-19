/**
 * Story 4b.4 fix-pass v0.28.1 — helper compartilhado de mount do
 * `SystemStatusBannerView` em surfaces (Prazo list, Prazo detail,
 * PublicacaoAmbigua list, dashlet BriefingDoDia).
 *
 * **Bug B1 fix (smoke F1 round 1):** o EspoCRM 9.x `createView(name, viewName,
 * options)` chama internamente `setElement(options.el)` que faz
 * `document.querySelector(el)`. Passar HTMLElement direto produz
 * `'[object HTMLDivElement]' is not a valid selector` e a promise rejeita
 * silenciosamente. Solução: gerar id único no placeholder e passar `el`
 * como CSS selector string `'#<id>'`.
 *
 * Ambos métodos são fail-safe — erro NÃO derruba a view caller.
 */

const SYSTEM_STATUS_BANNER_VIEW = "togare-core:views/common/system-status-banner";
let _mountCounter = 0;

/**
 * Garante que existe um `<div>` placeholder no `rootEl` para o
 * SystemStatusBannerView. Se já existe (data-role match), reusa. Se não,
 * cria com id único + data-role + insere antes do primeiro anchor que
 * encontrar (em ordem de preferência).
 *
 * @param {HTMLElement|null} rootEl
 * @param {string[]} anchorSelectors  Lista de seletores CSS, em ordem de preferência.
 * @return {HTMLElement|null}
 */
export function ensureSystemStatusBannerMount(rootEl, anchorSelectors = []) {
  if (!rootEl || typeof rootEl.querySelector !== "function") return null;

  let mount = rootEl.querySelector("[data-role='togare-system-status-banner-mount']");
  if (mount) return mount;

  mount = document.createElement("div");
  mount.setAttribute("data-role", "togare-system-status-banner-mount");
  // ID único — usado como CSS selector pelo createView (B1 fix).
  _mountCounter += 1;
  mount.id = `togare-system-status-banner-mount-${_mountCounter}-${Math.random().toString(36).slice(2, 8)}`;

  let anchor = null;
  for (const sel of anchorSelectors) {
    if (typeof sel !== "string") continue;
    anchor = rootEl.querySelector(sel);
    if (anchor) break;
  }
  if (!anchor) anchor = rootEl.firstChild;

  if (anchor && anchor.parentNode) {
    anchor.parentNode.insertBefore(mount, anchor);
  } else {
    rootEl.insertBefore(mount, rootEl.firstChild);
  }

  return mount;
}

/**
 * Idempotente: monta o SystemStatusBannerView via `createView` passando
 * `el` como CSS selector string (`'#<mount.id>'`). Tracking via property
 * `propertyKey` no parent view — re-chamadas com mesmo mount são no-op.
 *
 * @param {object} parentView   View que possui `createView` (record/list, dashlet, etc.)
 * @param {HTMLElement|null} mount
 * @param {string} propertyKey  Nome da prop usada pra memoizar (ex.: "_systemStatusBanner")
 */
export function mountSystemStatusBanner(parentView, mount, propertyKey = "_systemStatusBanner") {
  if (!parentView || typeof parentView.createView !== "function") return;
  if (!mount || !mount.id) return;
  if (parentView[propertyKey] && parentView[propertyKey].mount === mount) return;

  parentView[propertyKey] = { mount, mounted: false };

  let rendered = false;
  const renderOnce = (view) => {
    if (rendered) return;
    rendered = true;
    if (view && typeof view.render === "function") view.render();
  };
  const fail = () => {
    parentView[propertyKey] = null;
  };

  try {
    const result = parentView.createView(
      "systemStatusBanner",
      SYSTEM_STATUS_BANNER_VIEW,
      { el: `#${mount.id}` },
      renderOnce,
    );
    if (result && typeof result.then === "function") {
      result.then(renderOnce).catch(fail);
    }
    parentView[propertyKey].mounted = true;
  } catch (_e) {
    fail();
  }
}
