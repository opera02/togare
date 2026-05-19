/**
 * Testes da constante ACCEPT_ATTRIBUTE da view `upload-modal` (Story 5.2, AC #14).
 *
 * O modal usa `<input type="file" accept={ACCEPT_ATTRIBUTE}>` — a string
 * controla quais extensões aparecem no file picker do browser.
 *
 * Pegadinha B-NEW-B1: validação real é server-side (ValidateDocumentoFieldsHook
 * MIME allowlist) — accept é só hint cosmético.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";

// Story 5.7-followup gap (c) ROUND 3 — mock do ToastTogareView ANTES de
// importar upload-modal, pra interceptar a chamada static .show.
vi.mock("togare-core:views/common/toast-togare", () => ({
  default: { show: vi.fn() },
}));

import DocumentoUploadModalView, {
  ACCEPT_ATTRIBUTE,
} from "togare-core:views/document/upload-modal";
import ToastTogareView from "togare-core:views/common/toast-togare";

describe("document/upload-modal — ACCEPT_ATTRIBUTE", () => {
  it("inclui .pdf (extensão principal de peças processuais)", () => {
    expect(ACCEPT_ATTRIBUTE).toContain(".pdf");
  });

  it("inclui DOCX e DOC legacy", () => {
    expect(ACCEPT_ATTRIBUTE).toContain(".docx");
    expect(ACCEPT_ATTRIBUTE).toContain(".doc");
  });

  it("inclui XLSX e XLS legacy", () => {
    expect(ACCEPT_ATTRIBUTE).toContain(".xlsx");
    expect(ACCEPT_ATTRIBUTE).toContain(".xls");
  });

  it("inclui formatos de imagem (PNG/JPG/JPEG/TIFF/TIF)", () => {
    expect(ACCEPT_ATTRIBUTE).toContain(".png");
    expect(ACCEPT_ATTRIBUTE).toContain(".jpg");
    expect(ACCEPT_ATTRIBUTE).toContain(".jpeg");
    expect(ACCEPT_ATTRIBUTE).toContain(".tiff");
    expect(ACCEPT_ATTRIBUTE).toContain(".tif");
  });

  it("inclui .txt", () => {
    expect(ACCEPT_ATTRIBUTE).toContain(".txt");
  });

  it("NÃO inclui formatos rejeitados pelo MIME allowlist (.exe/.zip)", () => {
    expect(ACCEPT_ATTRIBUTE).not.toContain(".exe");
    expect(ACCEPT_ATTRIBUTE).not.toContain(".zip");
    expect(ACCEPT_ATTRIBUTE).not.toContain(".bat");
    // ".sh" não aparece como extensão (evita falso positivo: ".sheet" em MIME XLSX)
    const extensionParts = ACCEPT_ATTRIBUTE.split(",").filter((p) => p.startsWith("."));
    expect(extensionParts).not.toContain(".sh");
  });

  it("é uma string separada por vírgulas (formato HTML accept)", () => {
    expect(typeof ACCEPT_ATTRIBUTE).toBe("string");
    expect(ACCEPT_ATTRIBUTE).toMatch(/,/);
    const parts = ACCEPT_ATTRIBUTE.split(",");
    expect(parts.length).toBeGreaterThanOrEqual(10);
    parts.forEach((p) => {
      // extensões começam com "." — tipos MIME contêm "/" (ex: application/pdf)
      expect(p.trim()).toMatch(/^(\.|[a-z]+\/)/);
    });
  });

  it("inclui tipos MIME além das extensões", () => {
    expect(ACCEPT_ATTRIBUTE).toContain("application/pdf");
    expect(ACCEPT_ATTRIBUTE).toContain("image/png");
    expect(ACCEPT_ATTRIBUTE).toContain("image/jpeg");
  });
});

describe("document/upload-modal — onFileChange", () => {
  const makeView = () => {
    const mockText = vi.fn();
    const view = Object.create(DocumentoUploadModalView.prototype);
    view.selectedFile = null;
    view.$el = { find: vi.fn(() => ({ text: mockText, length: 1 })) };
    return { view, mockText };
  };

  it("atribui selectedFile quando arquivo presente no evento", () => {
    const { view } = makeView();
    const fakeFile = { name: "peticao.pdf", type: "application/pdf", size: 512 };
    view.onFileChange({ currentTarget: { files: [fakeFile] } });
    expect(view.selectedFile).toBe(fakeFile);
  });

  it("atualiza preview com nome do arquivo", () => {
    const { view, mockText } = makeView();
    const fakeFile = { name: "peticao.pdf" };
    view.onFileChange({ currentTarget: { files: [fakeFile] } });
    expect(mockText).toHaveBeenCalledWith("peticao.pdf");
  });

  it("limpa selectedFile quando evento sem arquivo", () => {
    const { view } = makeView();
    view.selectedFile = { name: "antigo.pdf" };
    view.onFileChange({ currentTarget: { files: [] } });
    expect(view.selectedFile).toBeNull();
  });
});

describe("document/upload-modal — setup() XOR triplo (Story 5.6)", () => {
  const makeView = (options) => {
    const view = new DocumentoUploadModalView(options);
    view.translate = vi.fn((k) => k);
    return view;
  };

  it("aceita prazoId sem erro e popula prazoName + contextLabel", () => {
    const view = makeView({
      prazoId: "prz-001",
      prazoName: "Cumprimento de Sentença",
    });
    expect(() => view.setup()).not.toThrow();
    expect(view.prazoId).toBe("prz-001");
    expect(view.prazoName).toBe("Cumprimento de Sentença");
    expect(view.processoId).toBeNull();
    expect(view.clienteId).toBeNull();
    expect(view.data().contextLabel).toBe("Cumprimento de Sentença");
  });

  it("rejeita processoId+prazoId simultâneo com Error pt-BR (XOR triplo)", () => {
    const view = makeView({ processoId: "p1", prazoId: "pz1" });
    expect(() => view.setup()).toThrow(
      /apenas um.*processoId\/clienteId\/prazoId.*XOR triplo/,
    );
  });

  it("rejeita todos null com Error pt-BR mencionando prazoId", () => {
    const view = makeView({});
    expect(() => view.setup()).toThrow(
      /processoId, clienteId OU prazoId é obrigatório/,
    );
  });

  it("rejeita clienteId+prazoId simultâneo", () => {
    const view = makeView({ clienteId: "c1", prazoId: "pz1" });
    expect(() => view.setup()).toThrow(/XOR triplo/);
  });
});

describe("document/upload-modal — actionUpload sem arquivo", () => {
  it("chama Espo.Ui.error e retorna cedo quando selectedFile é null", () => {
    const view = Object.create(DocumentoUploadModalView.prototype);
    view.selectedFile = null;
    view.isSubmitting = false;
    view.translate = vi.fn((k) => k);
    const mockError = vi.fn();
    globalThis.Espo = { Ui: { error: mockError } };
    view.actionUpload();
    expect(mockError).toHaveBeenCalledTimes(1);
    expect(view.isSubmitting).toBe(false);
  });
});

/**
 * Story 5.7-followup gap (c) ROUND 3 — toast pós-upload via ToastTogareView.
 *
 * Hist. dos rounds:
 *  - ROUND 1: setTimeout(close, 1200) + Espo.Ui.notify(success, 5000) —
 *    modal travou em 10s+ por conflito com trigger(after:save).
 *  - ROUND 2: removeu setTimeout, manteve Espo.Ui.notify direto — modal
 *    fechou OK mas toast invisível: o trigger(after:save) faz painel
 *    relacional re-renderizar e chamar Espo.Ui.notify(' ... ') (spinner)
 *    que decapita meu toast em ~1ms (Espo.Ui.notify faz remove() do
 *    #notification antes de criar o novo).
 *  - ROUND 3: trocar Espo.Ui.notify por ToastTogareView.show. Toast vai
 *    pra container SEPARADO (#togare-toast-stack), NÃO tocado pelo
 *    Espo.Ui.notify(remove + create). Sobrevive ao spinner de refresh.
 *    Felipe smoke 2026-05-12 ROUND 2 confirmou diagnóstico via
 *    monkey-patch do Espo.Ui.notify (3 chamadas em ~100ms: success →
 *    spinner ' ... ' → undefined limpa).
 */
describe("document/upload-modal — actionUpload success path: ToastTogareView + close imediato (Story 5.7-followup)", () => {
  let originalFileReader;
  let originalEspo;

  const flushMicrotasks = async () => {
    // 5 ciclos cobrem com folga a cadeia:
    //   postRequest(Attachment) → .then(response) → postRequest(Documento) → .then(created) → Espo.Ui.notify
    for (let i = 0; i < 5; i++) {
      await Promise.resolve();
    }
  };

  beforeEach(() => {
    originalFileReader = globalThis.FileReader;
    originalEspo = globalThis.Espo;
    // FileReader fake — invoca onload sincronamente quando readAsDataURL é chamado.
    // Usamos class direta (não vi.fn como constructor) para garantir new comporta-se
    // como construtor normal e o this dentro de readAsDataURL aponta para a instância.
    globalThis.FileReader = class {
      constructor() {
        this.onload = null;
        this.onerror = null;
      }
      readAsDataURL(/* file */) {
        if (typeof this.onload === "function") {
          this.onload({
            target: { result: "data:application/pdf;base64,Zm9v" },
          });
        }
      }
    };
    // Reset ToastTogareView.show mock entre testes.
    if (ToastTogareView && ToastTogareView.show && ToastTogareView.show.mockReset) {
      ToastTogareView.show.mockReset();
    }
  });

  afterEach(() => {
    globalThis.FileReader = originalFileReader;
    globalThis.Espo = originalEspo;
  });

  const setupView = () => {
    const mocks = {
      notify: vi.fn(),
      success: vi.fn(),
      error: vi.fn(),
      postRequest: vi
        .fn()
        .mockResolvedValueOnce({ id: "attach-1" }) // POST Attachment
        .mockResolvedValueOnce({ id: "doc-1" }), // POST Documento
    };

    globalThis.Espo = {
      Ui: { notify: mocks.notify, success: mocks.success, error: mocks.error },
      Ajax: { postRequest: mocks.postRequest },
    };

    const view = Object.create(DocumentoUploadModalView.prototype);
    view.selectedFile = {
      name: "peticao.pdf",
      type: "application/pdf",
      size: 4096,
    };
    view.isSubmitting = false;
    view.processoId = "proc-1";
    view.clienteId = null;
    view.prazoId = null;
    view.translate = vi.fn((k) => k);
    view.disableButton = vi.fn();
    view.enableButton = vi.fn();
    view.trigger = vi.fn();
    view.close = vi.fn();

    return { view, mocks };
  };

  it("chama ToastTogareView.show com variant=success + duração 5000ms após Documento criado", async () => {
    const { view, mocks } = setupView();
    void mocks;

    view.actionUpload();
    await flushMicrotasks();

    expect(ToastTogareView.show).toHaveBeenCalledTimes(1);
    const [opts] = ToastTogareView.show.mock.calls[0];
    expect(opts).toBeTruthy();
    expect(opts.variant).toBe("success");
    expect(typeof opts.message).toBe("string");
    expect(opts.message.length).toBeGreaterThan(0);
    expect(opts.duration).toBe(5000);
  });

  it("NÃO chama Espo.Ui.notify (toast nativo é decapitado pelo spinner do trigger after:save)", async () => {
    const { view, mocks } = setupView();

    view.actionUpload();
    await flushMicrotasks();

    // Round 2 usava Espo.Ui.notify mas era decapitado em ~1ms pelo
    // spinner que o trigger("after:save") dispara no painel relacional.
    // Round 3 usa ToastTogareView (container separado #togare-toast-stack).
    expect(mocks.notify).not.toHaveBeenCalled();
    expect(ToastTogareView.show).toHaveBeenCalledTimes(1);
  });

  it("mantém trigger/close se ToastTogareView.show falhar e cai no fallback nativo", async () => {
    const { view, mocks } = setupView();
    ToastTogareView.show.mockImplementationOnce(() => {
      throw new Error("toast failed");
    });

    view.actionUpload();
    await flushMicrotasks();

    expect(ToastTogareView.show).toHaveBeenCalledTimes(1);
    expect(mocks.notify).toHaveBeenCalledWith(
      expect.any(String),
      "success",
      5000,
    );
    expect(view.trigger).toHaveBeenCalledWith(
      "after:save",
      expect.objectContaining({ id: "doc-1" }),
    );
    expect(view.close).toHaveBeenCalledTimes(1);
    expect(mocks.error).not.toHaveBeenCalled();
  });

  it("dispara trigger('after:save', created) ANTES do close (painel atualiza imediato)", async () => {
    const { view } = setupView();

    view.actionUpload();
    await flushMicrotasks();

    expect(view.trigger).toHaveBeenCalledTimes(1);
    expect(view.trigger).toHaveBeenCalledWith(
      "after:save",
      expect.objectContaining({ id: "doc-1" }),
    );
    expect(view.close).toHaveBeenCalledTimes(1);
    expect(view.trigger.mock.invocationCallOrder[0]).toBeLessThan(
      view.close.mock.invocationCallOrder[0],
    );
  });

  it("chama view.close imediatamente após o success (sem setTimeout — toast persiste em #togare-toast-stack)", async () => {
    const { view } = setupView();

    view.actionUpload();
    await flushMicrotasks();

    expect(view.close).toHaveBeenCalledTimes(1);
  });
});
