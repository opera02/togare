import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import ToastTogareView, {
  __resetEscStackForTests,
} from "../../src/files/client/custom/modules/togare-core/src/views/common/toast-togare.js";

/**
 * Stubs de i18n e template aplicados em cada cenário. Os testes instanciam
 * a view diretamente (sem passar pelo stack global de `show()`) — mais
 * previsível e isolado.
 */

function buildView(options) {
  const v = new ToastTogareView(options);
  v.getLanguage = () => ({
    translate: (variantKey) => {
      const map = {
        undo:        { icon: "✓",  role: "status", defaultActionLabel: "Desfazer" },
        warning:     { icon: "⚠",  role: "alert",  defaultActionLabel: "Continuar" },
        success:     { icon: "✓",  role: "status", defaultActionLabel: null },
        error:       { icon: "✗",  role: "status", defaultActionLabel: "Tentar de novo" },
        "auto-link": { icon: "🔗", role: "status", defaultActionLabel: "Editar" },
      };
      return map[variantKey] || map.success;
    },
  });
  v.renderHtml = (d) => `
    <div class="${d.cssClass}" role="${d.role}" data-id="${d.id}" data-variant="${d.variant}">
      <span class="togare-toast__icon">${d.icon}</span>
      <span class="togare-toast__message">${d.message}</span>
      ${d.actionLabel ? `<button type="button" data-action="toast-do">${d.actionLabel}</button>` : ""}
    </div>
  `;
  return v;
}

describe("ToastTogare", () => {
  let container;

  beforeEach(() => {
    vi.useFakeTimers();
    container = document.createElement("div");
    document.body.appendChild(container);
    // Story 4b.0 (Df7): isolamento entre cenários — esvazia o stack global
    // de cleanups e remove o container do toast stack do DOM.
    __resetEscStackForTests();
    const stackEl = document.getElementById("togare-toast-stack");
    if (stackEl) stackEl.remove();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it("testRendersAllFiveVariants — cada variant aplica cssClass + role + icon (Story 4a.4 +auto-link)", async () => {
    for (const [variant, expected] of Object.entries({
      undo: { role: "status", icon: "✓" },
      warning: { role: "alert", icon: "⚠" },
      success: { role: "status", icon: "✓" },
      error: { role: "status", icon: "✗" },
      "auto-link": { role: "status", icon: "🔗" },
    })) {
      const slot = document.createElement("div");
      document.body.appendChild(slot);
      const v = buildView({ variant, message: `smoke-${variant}` });
      v.setup();
      v.setElement(slot);
      await v.render();

      const root = slot.querySelector(`[data-variant="${variant}"]`);
      expect(root).not.toBeNull();
      expect(root.classList.contains(`togare-toast--${variant}`)).toBe(true);
      expect(root.getAttribute("role")).toBe(expected.role);
      expect(root.querySelector(".togare-toast__icon").textContent).toBe(expected.icon);

      v.remove();
      slot.remove();
    }
  });

  it("testUndoActionCallsCallback — clique em 'Desfazer' chama onAction", async () => {
    const onAction = vi.fn();
    const v = buildView({
      variant: "undo",
      message: "Feito",
      actionLabel: "Desfazer",
      onAction,
      duration: 10000,
    });
    v.setup();
    v.setElement(container);
    await v.render();

    const btn = container.querySelector('[data-action="toast-do"]');
    expect(btn).not.toBeNull();
    btn.dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true }));

    expect(onAction).toHaveBeenCalledTimes(1);

    // Click duplo não chama de novo (actionTaken flag).
    btn.dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true }));
    expect(onAction).toHaveBeenCalledTimes(1);
  });

  it("testAutoDismissAfterDuration — auto-remove ao fim do duration", async () => {
    const onAction = vi.fn();
    const v = buildView({
      variant: "undo",
      message: "X",
      onAction,
      duration: 5000,
    });
    v.setup();
    v.setElement(container);
    await v.render();

    // Antes do timer expirar.
    expect(v.el).not.toBeNull();

    vi.advanceTimersByTime(5000);
    // dismissNow adiciona classe --leaving e chama remove() com 300ms de
    // atraso — avançamos mais um pouco.
    vi.advanceTimersByTime(300);

    expect(v.el).toBeNull();
    // onAction não foi chamado (auto-dismiss).
    expect(onAction).not.toHaveBeenCalled();
  });

  it("testEscDismissesWithoutAction — ESC fecha sem chamar onAction", async () => {
    const onAction = vi.fn();
    const v = buildView({
      variant: "undo",
      message: "X",
      actionLabel: "Desfazer",
      onAction,
      duration: 10000,
    });
    v.setup();
    v.setElement(container);
    await v.render();

    document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }));
    vi.advanceTimersByTime(300);

    expect(v.el).toBeNull();
    expect(onAction).not.toHaveBeenCalled();
  });

  it("testPersistentDurationNull — duration null não arma timer", async () => {
    const v = buildView({
      variant: "warning",
      message: "Sessão expirando",
      duration: null,
    });
    v.setup();
    v.setElement(container);
    await v.render();

    vi.advanceTimersByTime(60_000);

    // View continua presente mesmo após tempo avançar.
    expect(v.el).not.toBeNull();
    v.dismissNow();
    vi.advanceTimersByTime(300);
    expect(v.el).toBeNull();
  });

  it("testVariantAutoLinkRenderizaIconeELink — Story 4a.4 D3 (AutoLinkBanner como variant)", async () => {
    const onAction = vi.fn();
    const slot = document.createElement("div");
    document.body.appendChild(slot);
    const v = buildView({
      variant: "auto-link",
      message: "Cliente João Silva e Parte Empresa X SA herdados do Processo 0001234... ",
      actionLabel: "Editar",
      onAction,
      duration: 8000,
    });
    v.setup();
    v.setElement(slot);
    await v.render();

    const root = slot.querySelector('[data-variant="auto-link"]');
    expect(root).not.toBeNull();
    expect(root.classList.contains("togare-toast--auto-link")).toBe(true);
    expect(root.querySelector(".togare-toast__icon").textContent).toBe("🔗");

    // Click no botão Editar dispara onAction (segue mesmo pattern do undo).
    root.querySelector('[data-action="toast-do"]').click();
    expect(onAction).toHaveBeenCalledTimes(1);

    slot.remove();
  });

  it("testShowStackSingleton — show() cria stack global e empilha com mais recente no topo", async () => {
    const h1 = ToastTogareView.show({ variant: "success", message: "A", duration: null });
    const h2 = ToastTogareView.show({ variant: "success", message: "B", duration: null });

    // Aguardar microtasks pra render() async do mock concluir.
    await Promise.resolve();
    await Promise.resolve();

    const stack = document.getElementById("togare-toast-stack");
    expect(stack).not.toBeNull();
    expect(stack.children.length).toBe(2);

    // Handle tem IDs únicos.
    expect(h1.id).not.toBe(h2.id);

    // Não testamos textContent aqui: `show()` usa o renderHtml default do mock
    // de View (template HTML padrão). Verificação de textContent com template
    // real fica coberta pelos outros testes (que sobrescrevem renderHtml).

    h1.dismiss();
    h2.dismiss();
    vi.advanceTimersByTime(300);

    expect(stack.children.length).toBe(0);
  });

  it("testStaticShowCloseButtonCallsOnDismissCloseOnce", () => {
    const onDismiss = vi.fn();
    const handle = ToastTogareView.show({
      variant: "warning",
      message: "VENCE HOJE",
      duration: null,
      actionLabel: null,
      onDismiss,
    });

    const close = document.querySelector(`#togare-toast-slot-${handle.id} .togare-toast__close`);
    expect(close).not.toBeNull();

    close.click();
    close.click();

    expect(onDismiss).toHaveBeenCalledTimes(1);
    expect(onDismiss).toHaveBeenCalledWith("close");
  });

  it("testStaticShowEscCallsOnDismissEscape", () => {
    const onDismiss = vi.fn();
    ToastTogareView.show({
      variant: "warning",
      message: "VENCE HOJE",
      duration: null,
      actionLabel: null,
      onDismiss,
    });

    document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }));

    expect(onDismiss).toHaveBeenCalledTimes(1);
    expect(onDismiss).toHaveBeenCalledWith("escape");
  });

  it("testStaticShowProgrammaticDismissKeepsReasonProgrammatic", () => {
    const onDismiss = vi.fn();
    const handle = ToastTogareView.show({
      variant: "warning",
      message: "VENCE HOJE",
      duration: null,
      actionLabel: null,
      onDismiss,
    });

    handle.dismiss("programmatic");

    expect(onDismiss).toHaveBeenCalledTimes(1);
    expect(onDismiss).toHaveBeenCalledWith("programmatic");
  });

  // ----- Story 4b.0 (T3.3 + T4.4 + T4.5) -----

  it("testVariantDefaultsConsistencyDataVsStaticShow — Df11 (Story 4b.0): VARIANT_DEFAULTS é único entre data() e static show()", async () => {
    // data() (instância) usa o defaultActionLabel do variant quando o caller
    // não passa actionLabel explícito.
    const v = buildView({ variant: "auto-link", message: "x" });
    v.setup();
    const d = v.data();
    expect(d.icon).toBe("🔗");
    expect(d.role).toBe("status");
    // Vem de VARIANT_DEFAULTS["auto-link"].defaultActionLabel.
    expect(d.actionLabel).toBe("Editar");

    // static show() usa o MESMO mapping — slot DOM tem mesmo icon/role e
    // o botão de ação tem o mesmo label default.
    const handle = ToastTogareView.show({
      variant: "auto-link",
      message: "y",
      duration: null,
    });
    const stack = document.getElementById("togare-toast-stack");
    const root = stack.querySelector('[data-variant="auto-link"]');
    expect(root).not.toBeNull();
    expect(root.getAttribute("role")).toBe("status");
    expect(root.querySelector(".togare-toast__icon").textContent).toBe("🔗");
    expect(root.querySelector('[data-action="toast-do"]').textContent).toBe("Editar");

    handle.dismiss();
    vi.advanceTimersByTime(300);
  });

  it("testEscStackLifoFechaSomenteTopoStaticShow — Df7 (Story 4b.0): 3 toasts via static show, ESC 3x fecha em ordem LIFO", async () => {
    const a = ToastTogareView.show({ variant: "warning", message: "A", duration: null });
    const b = ToastTogareView.show({ variant: "warning", message: "B", duration: null });
    const c = ToastTogareView.show({ variant: "warning", message: "C", duration: null });

    const stack = document.getElementById("togare-toast-stack");
    expect(stack.children.length).toBe(3);

    // 1º ESC fecha SÓ o último a entrar (C).
    document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }));
    vi.advanceTimersByTime(300);
    expect(document.getElementById(`togare-toast-slot-${c.id}`)).toBeNull();
    expect(document.getElementById(`togare-toast-slot-${b.id}`)).not.toBeNull();
    expect(document.getElementById(`togare-toast-slot-${a.id}`)).not.toBeNull();
    expect(stack.children.length).toBe(2);

    // 2º ESC fecha B (novo topo).
    document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }));
    vi.advanceTimersByTime(300);
    expect(document.getElementById(`togare-toast-slot-${b.id}`)).toBeNull();
    expect(document.getElementById(`togare-toast-slot-${a.id}`)).not.toBeNull();
    expect(stack.children.length).toBe(1);

    // 3º ESC fecha A.
    document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }));
    vi.advanceTimersByTime(300);
    expect(document.getElementById(`togare-toast-slot-${a.id}`)).toBeNull();
    expect(stack.children.length).toBe(0);
  });

  it("testEscStackRebuildAfterMidStackDismiss — Df7 (Story 4b.0): cleanup do toast do meio remove pelo id; próximo ESC fecha o NOVO topo", async () => {
    const a = ToastTogareView.show({ variant: "warning", message: "A", duration: null });
    const b = ToastTogareView.show({ variant: "warning", message: "B", duration: null });
    const c = ToastTogareView.show({ variant: "warning", message: "C", duration: null });

    // Dismiss programático do B (meio do stack — não é o topo).
    b.dismiss();
    vi.advanceTimersByTime(300);
    expect(document.getElementById(`togare-toast-slot-${b.id}`)).toBeNull();

    // Stack agora é [A, C]. Topo é C. ESC dismissa C (não A).
    document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }));
    vi.advanceTimersByTime(300);
    expect(document.getElementById(`togare-toast-slot-${c.id}`)).toBeNull();
    expect(document.getElementById(`togare-toast-slot-${a.id}`)).not.toBeNull();

    // ESC seguinte dismissa A.
    document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }));
    vi.advanceTimersByTime(300);
    expect(document.getElementById(`togare-toast-slot-${a.id}`)).toBeNull();
  });

  it("test__resetEscStackForTestsClearsStackBetweenScenarios — Df7 (Story 4b.0): helper de teste limpa stack global entre cenários (sanity do beforeEach)", async () => {
    // Empilha 2 toasts.
    ToastTogareView.show({ variant: "success", message: "x", duration: null });
    ToastTogareView.show({ variant: "success", message: "y", duration: null });

    const stack = document.getElementById("togare-toast-stack");
    expect(stack.children.length).toBe(2);

    // Limpa o stack global manualmente — emula o que beforeEach faz no
    // próximo cenário. Após o reset, ESC NÃO dispara nenhum cleanup
    // (stack vazio), e os DOM elements continuam órfãos no document até
    // que o próximo beforeEach remova o stack do DOM.
    __resetEscStackForTests();

    document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }));
    vi.advanceTimersByTime(300);

    // ESC sem entries no stack global = no-op. DOM dos toasts permanece
    // (não foram dismissados). Confirma que nenhum cleanup parasita
    // sobreviveu o reset.
    expect(stack.children.length).toBe(2);
  });
});
