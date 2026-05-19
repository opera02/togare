/**
 * Testes vitest do LembreteConfigView (Story 4b.2, AC5 + AC12).
 */
import { describe, it, expect, beforeEach } from "vitest";
import LembreteConfigView, {
  DEFAULT_CONFIG,
  FIELDS,
  mergeWithDefaults,
  readPath,
  setPath,
} from "../../src/files/client/custom/modules/togare-core/src/views/preferences/lembrete-config.js";

function makeMockModel(initial = null) {
  let stored = initial;
  return {
    get(name) {
      if (name === "togareLembreteConfig") return stored;
      return undefined;
    },
    set(name, value) {
      if (name === "togareLembreteConfig") stored = value;
    },
    _peek() {
      return stored;
    },
  };
}

async function buildView(model) {
  const v = new LembreteConfigView({ model, name: "togareLembreteConfig" });
  v.model = model; // mock View base não atribui automaticamente.
  v.setup();
  await v.render();
  return v;
}

describe("LembreteConfigView — helpers puros", () => {
  it("mergeWithDefaults aplica defaults quando stored é null", () => {
    expect(mergeWithDefaults(null)).toEqual(DEFAULT_CONFIG);
  });

  it("mergeWithDefaults preserva overrides false explícitos", () => {
    const merged = mergeWithDefaults({
      channels: { popup: false, email: true },
      marcos: { "D-1": false },
    });
    expect(merged.channels.popup).toBe(false);
    expect(merged.channels.email).toBe(true);
    expect(merged.marcos["D-1"]).toBe(false);
    // Defaults preservados pra keys ausentes.
    expect(merged.marcos["D-7"]).toBe(true);
    expect(merged.marcos["D-3"]).toBe(true);
    // Story 4b.3 — D-0 default true.
    expect(merged.marcos["D-0"]).toBe(true);
    expect(merged.marcos.status_dirigido).toBe(true);
  });

  // Story 4b.3 — D-0 entrou em DEFAULT_CONFIG e pode ser desligado.
  it("mergeWithDefaults respeita override D-0 false explícito", () => {
    const merged = mergeWithDefaults({ marcos: { "D-0": false } });
    expect(merged.marcos["D-0"]).toBe(false);
    // Outros marcos mantêm default.
    expect(merged.marcos["D-1"]).toBe(true);
    expect(merged.marcos["D-7"]).toBe(true);
  });

  it("DEFAULT_CONFIG tem D-0=true (fail-safe alerta crítico)", () => {
    expect(DEFAULT_CONFIG.marcos["D-0"]).toBe(true);
  });

  it("readPath/setPath manipulam caminhos pontuados", () => {
    const obj = { channels: { popup: false } };
    expect(readPath(obj, "channels.popup")).toBe(false);
    setPath(obj, "marcos.D-1", true);
    expect(obj.marcos["D-1"]).toBe(true);
  });
});

describe("LembreteConfigView — render", () => {
  it("renderiza 7 checkboxes (2 canais + 5 marcos incluindo D-0) com defaults marcados", async () => {
    // Story 4b.3 — fieldset "Marcos ativos" passa de 4 → 5 checkboxes (D-0 entra).
    const model = makeMockModel(null);
    const v = await buildView(model);

    const checkboxes = v.el.querySelectorAll("input.togare-lembrete-checkbox");
    expect(checkboxes.length).toBe(7);
    for (const cb of checkboxes) {
      expect(cb.checked).toBe(true); // todos defaults true.
    }

    // Story 4b.3 — D-0 está presente entre D-1 e status_dirigido.
    const d0 = v.el.querySelector('input[data-key="marcos.D-0"]');
    expect(d0).toBeTruthy();
    expect(d0.id).toBe("togare-lembrete-d0");
    expect(d0.checked).toBe(true);
  });

  // Story 4b.3 — D-0 toggle off persiste com defaults dos demais.
  it("toggle marco D-0 off persiste com defaults dos outros marcos", async () => {
    const model = makeMockModel(null);
    const v = await buildView(model);

    const d0 = v.el.querySelector('input[data-key="marcos.D-0"]');
    expect(d0).toBeTruthy();
    d0.checked = false;
    d0.dispatchEvent(new Event("change", { bubbles: true }));

    const stored = model._peek();
    expect(stored.marcos["D-0"]).toBe(false);
    // Outros marcos default preservados (não regridem).
    expect(stored.marcos["D-7"]).toBe(true);
    expect(stored.marcos["D-3"]).toBe(true);
    expect(stored.marcos["D-1"]).toBe(true);
    expect(stored.marcos.status_dirigido).toBe(true);
  });

  // Story 4b.3 — ordem visual D-7 → D-3 → D-1 → D-0 → status_dirigido.
  it("FIELDS array tem D-0 na posição 5 (após D-1, antes de status_dirigido)", () => {
    const keys = FIELDS.map((f) => f.key);
    expect(keys).toEqual([
      "channels.popup",
      "channels.email",
      "marcos.D-7",
      "marcos.D-3",
      "marcos.D-1",
      "marcos.D-0",
      "marcos.status_dirigido",
    ]);
  });

  it("renderiza com checkbox popup desmarcado quando model tem popup=false", async () => {
    const model = makeMockModel({ channels: { popup: false } });
    const v = await buildView(model);

    const popup = v.el.querySelector('input[data-key="channels.popup"]');
    expect(popup).toBeTruthy();
    expect(popup.checked).toBe(false);
    // E-mail (default true) preservado.
    const email = v.el.querySelector('input[data-key="channels.email"]');
    expect(email.checked).toBe(true);
  });

  it("hint visível com aria-describedby correto em todos os inputs", async () => {
    const model = makeMockModel(null);
    const v = await buildView(model);

    const hint = v.el.querySelector("p.togare-lembrete-hint");
    expect(hint).toBeTruthy();
    expect(hint.id).toBe("togare-lembrete-hint");

    const checkboxes = v.el.querySelectorAll("input.togare-lembrete-checkbox");
    for (const cb of checkboxes) {
      expect(cb.getAttribute("aria-describedby")).toBe("togare-lembrete-hint");
    }
  });

  it("cada checkbox tem label associado via for/id", async () => {
    const model = makeMockModel(null);
    const v = await buildView(model);

    for (const f of FIELDS) {
      const cb = v.el.querySelector(`#${f.id}`);
      expect(cb).toBeTruthy();
      const label = v.el.querySelector(`label[for="${f.id}"]`);
      expect(label).toBeTruthy();
      expect(label.textContent.trim().length).toBeGreaterThan(0);
    }
  });

  it("fieldsets tem role=group + aria-labelledby apontando para legend", async () => {
    const model = makeMockModel(null);
    const v = await buildView(model);

    const fieldsets = v.el.querySelectorAll("fieldset[role=group]");
    expect(fieldsets.length).toBe(2);
    for (const fs of fieldsets) {
      const labelledBy = fs.getAttribute("aria-labelledby");
      expect(labelledBy).toBeTruthy();
      const legend = v.el.querySelector(`#${labelledBy}`);
      expect(legend).toBeTruthy();
      expect(legend.tagName).toBe("LEGEND");
    }
  });
});

describe("LembreteConfigView — interação", () => {
  it("toggle canal popup off persiste no model.set", async () => {
    const model = makeMockModel(null);
    const v = await buildView(model);

    const popup = v.el.querySelector('input[data-key="channels.popup"]');
    popup.checked = false;
    popup.dispatchEvent(new Event("change", { bubbles: true }));

    const stored = model._peek();
    expect(stored).toBeTruthy();
    expect(stored.channels.popup).toBe(false);
    expect(stored.channels.email).toBe(true); // outros defaults preservados
    expect(stored.marcos["D-1"]).toBe(true);
  });

  it("toggle marco D-1 off persiste com defaults dos outros", async () => {
    const model = makeMockModel(null);
    const v = await buildView(model);

    const d1 = v.el.querySelector('input[data-key="marcos.D-1"]');
    d1.checked = false;
    d1.dispatchEvent(new Event("change", { bubbles: true }));

    const stored = model._peek();
    expect(stored.marcos["D-1"]).toBe(false);
    expect(stored.marcos["D-7"]).toBe(true);
    expect(stored.marcos["D-3"]).toBe(true);
    expect(stored.marcos.status_dirigido).toBe(true);
  });

  it("toggle subsequente preserva config anterior + aplica nova mudança", async () => {
    const model = makeMockModel({
      channels: { popup: false, email: true },
      marcos: { "D-1": false, "D-7": true, "D-3": true, status_dirigido: true },
    });
    const v = await buildView(model);

    const email = v.el.querySelector('input[data-key="channels.email"]');
    email.checked = false;
    email.dispatchEvent(new Event("change", { bubbles: true }));

    const stored = model._peek();
    expect(stored.channels.popup).toBe(false); // preservado
    expect(stored.channels.email).toBe(false); // mudou
    expect(stored.marcos["D-1"]).toBe(false); // preservado
  });
});
