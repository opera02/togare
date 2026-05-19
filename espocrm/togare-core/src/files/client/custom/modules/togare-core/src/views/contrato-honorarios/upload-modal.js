/**
 * ContratoHonorariosUploadModalView — modal "Anexar contrato" (Story 6.1).
 *
 * Dev Decision D11.1.1 (simplificação da spec): em vez de construir um modal
 * full-custom (~300+ linhas como o Documento upload-modal), estendemos
 * `views/modals/edit` stock do EspoCRM 9.x — o stock já renderiza:
 *   - Todos os fields da entity via entityDefs/ContratoHonorarios.json + layout/edit.
 *   - DynamicLogic modalidade ↔ valor/percentual (vem do clientDefs).
 *   - Field `uploadedAttachment` type=file com uploader nativo Espo.
 *   - Botões Save/Cancel nativos.
 *
 * O que esta subclasse adiciona:
 *   - `scope: 'ContratoHonorarios'` fixo.
 *   - `relate: { model: <Cliente>, link: 'contratosHonorarios' }` quando aberto
 *     a partir do panel do Cliente — pré-fixa o link cliente naturalmente.
 *   - `processoId` opcional vindo do GateBanner (Story 6.2) — pré-fixa o
 *     linkMultiple `processos`.
 *   - Header label pt-BR "Anexar contrato".
 *
 * Para multi-vinculo Processos via search-as-you-type (Discovery #1 retro Epic 5),
 * o field `processos` usa linkMultiple com uma field view propria que filtra
 * resultados pelo `clienteId` selecionado.
 *
 * Pattern Documento upload-modal é mais complexo porque (a) XOR triplo
 * Processo|Cliente|Prazo, (b) upload de qualquer extensão, (c) Documento entity
 * é fundamentalmente "anexo de contexto". ContratoHonorarios é uma entity
 * cheia de metadados → faz mais sentido usar a stock edit modal.
 */
import ModalsEditView from "views/modals/edit";

export default class ContratoHonorariosUploadModalView extends ModalsEditView {
    /**
     * Sobrescreve scope para garantir que o stock create flow use ContratoHonorarios
     * mesmo se houver mismatch no caller.
     */
    setup() {
        this.scope = "ContratoHonorarios";
        this.options = this.options || {};
        this.options.scope = "ContratoHonorarios";
        this.options.entityType = "ContratoHonorarios";

        // Pré-fixa o link cliente quando aberto de um panel de Cliente.
        if (this.options && this.options.clienteId) {
            this.options.attributes = this.options.attributes || {};
            this.options.attributes.clienteId = this.options.clienteId;
            if (this.options.clienteName) {
                this.options.attributes.clienteName = this.options.clienteName;
            }
        }

        // Pré-fixa o linkMultiple `processos` quando aberto via GateBanner com
        // contexto de Processo. Espo espera `<link>Ids` + `<link>Names`.
        if (this.options && this.options.processoId) {
            this.options.attributes = this.options.attributes || {};

            const processoId = this.options.processoId;
            const processosIds = Array.isArray(this.options.attributes.processosIds)
                ? [...this.options.attributes.processosIds]
                : [];
            if (!processosIds.includes(processoId)) {
                processosIds.push(processoId);
            }

            const processosNames =
                this.options.attributes.processosNames &&
                typeof this.options.attributes.processosNames === "object"
                    ? { ...this.options.attributes.processosNames }
                    : {};
            if (!processosNames[processoId]) {
                processosNames[processoId] = this.options.processoName || processoId;
            }

            this.options.attributes.processosIds = processosIds;
            this.options.attributes.processosNames = processosNames;
        }

        super.setup();

        // Header label pt-BR.
        const label =
            (typeof this.translate === "function" &&
                this.translate("Anexar contrato", "labels", "ContratoHonorarios")) ||
            "Anexar contrato";
        this.headerText = label;
        this.headerHtml = null; // garante que headerText prevalece sobre default scope
    }
}
