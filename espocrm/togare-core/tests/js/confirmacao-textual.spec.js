import { describe, it, expect, vi, beforeEach } from "vitest";
import ConfirmacaoTextualView from "../../src/files/client/custom/modules/togare-core/src/views/common/confirmacao-textual.js";

/**
 * Os testes usam o mock `view.js` via alias em vitest.config.js.
 * Sobrescrevemos `renderHtml()` no setUp de cada teste para produzir
 * o HTML equivalente ao que o template Handlebars geraria no runtime.
 *
 * Copy i18n é stubado via override de `getLanguage()`.
 */

function buildView(options) {
  const v = new ConfirmacaoTextualView(options);
  // Stub da i18n: retornamos os labels usados pelo template.
  v.getLanguage = () => ({
    translate: () => ({
      instrucao: "Digite o nome exato:",
      placeholderPrefix: "Digite: ",
      ctaDefault: "Confirmar",
      cancelLabel: "Cancelar",
      ariaDescription: "",
      ariaDestructiveWarning: "",
    }),
  });
  // Stub do template — HTML mínimo com os data-roles que a view usa.
  v.renderHtml = (d) => `
    <form>
      <label for="togare-confirmacao-input">${d.instrucao}</label>
      <input
        type="text"
        id="togare-confirmacao-input"
        data-role="confirmacao-input"
        placeholder="${d.placeholder}">
      <button type="button" data-action="cancel">${d.cancelLabel}</button>
      <button type="submit" data-action="confirm" ${d.ctaDisabled ? "disabled" : ""}>
        ${d.ctaLabel}
      </button>
    </form>
  `;
  return v;
}

describe("ConfirmacaoTextual", () => {
  let container;

  beforeEach(() => {
    container = document.createElement("div");
    document.body.appendChild(container);
  });

  it("testCtaDisabledInitial — CTA nasce desabilitado", async () => {
    const v = buildView({ expectedName: "política RET-001" });
    v.setup();
    v.setElement(container);
    await v.render();

    const cta = container.querySelector('[data-action="confirm"]');
    expect(cta).not.toBeNull();
    expect(cta.hasAttribute("disabled")).toBe(true);

    v.remove();
  });

  it("testCtaEnablesWhenMatch — habilita quando input bate exatamente", async () => {
    const v = buildView({ expectedName: "política RET-001" });
    v.setup();
    v.setElement(container);
    await v.render();

    const input = container.querySelector('[data-role="confirmacao-input"]');
    input.value = "política RET-001";
    input.dispatchEvent(new Event("input", { bubbles: true }));

    const cta = container.querySelector('[data-action="confirm"]');
    expect(cta.hasAttribute("disabled")).toBe(false);

    v.remove();
  });

  it("testCtaDisablesAgainOnDivergence — volta a disabled ao diverger", async () => {
    const v = buildView({ expectedName: "política RET-001" });
    v.setup();
    v.setElement(container);
    await v.render();

    const input = container.querySelector('[data-role="confirmacao-input"]');
    const cta = container.querySelector('[data-action="confirm"]');

    input.value = "política RET-001";
    input.dispatchEvent(new Event("input", { bubbles: true }));
    expect(cta.hasAttribute("disabled")).toBe(false);

    input.value = "política RET-00";
    input.dispatchEvent(new Event("input", { bubbles: true }));
    expect(cta.hasAttribute("disabled")).toBe(true);

    v.remove();
  });

  it("testEnterCallsConfirmWhenMatch — submit do form chama onConfirm quando bate", async () => {
    const onConfirm = vi.fn();
    const v = buildView({ expectedName: "x", onConfirm });
    v.setup();
    v.setElement(container);
    await v.render();

    const input = container.querySelector('[data-role="confirmacao-input"]');
    input.value = "x";
    input.dispatchEvent(new Event("input", { bubbles: true }));

    const form = container.querySelector("form");
    form.dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }));

    expect(onConfirm).toHaveBeenCalledTimes(1);

    v.remove();
  });

  it("testEscCallsCancel — ESC global dispara onCancel + destrói view", async () => {
    const onCancel = vi.fn();
    const v = buildView({ expectedName: "x", onCancel });
    v.setup();
    v.setElement(container);
    await v.render();

    document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }));

    expect(onCancel).toHaveBeenCalledTimes(1);
    // view foi removida (el é null após remove).
    expect(v.el).toBeNull();
  });

  it("testTrimOnlyOuterSpaces — bate com espaços externos, não com internos extras", async () => {
    const v = buildView({ expectedName: "alfa beta" });
    v.setup();
    v.setElement(container);
    await v.render();

    const input = container.querySelector('[data-role="confirmacao-input"]');
    const cta = container.querySelector('[data-action="confirm"]');

    // Outer spaces → bate.
    input.value = "  alfa beta  ";
    input.dispatchEvent(new Event("input", { bubbles: true }));
    expect(cta.hasAttribute("disabled")).toBe(false);

    // Inner space extra → não bate.
    input.value = "alfa  beta"; // 2 espaços internos
    input.dispatchEvent(new Event("input", { bubbles: true }));
    expect(cta.hasAttribute("disabled")).toBe(true);

    v.remove();
  });
});
