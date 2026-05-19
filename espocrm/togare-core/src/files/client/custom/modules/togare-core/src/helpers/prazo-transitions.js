/**
 * Tabela canônica de transições de status do Prazo (Story 4a.4 Dev Notes §1).
 *
 * Espelha tabela "Estados visuais" da UX spec v1.1 (linhas 645-654) + 9 status
 * reais do enum (entityDefs/Prazo.json::status, definidos pela Story 4a.3.1
 * com `descartado` reincorporado pós fix-pass D7).
 *
 * Single source of truth tanto para o StatusSelector (views/prazo/fields/
 * status-selector.js) quanto para os testes unit. Espelha — em comportamento
 * — o lado servidor `Entities/Prazo.php` constants STATUS_* e o
 * ValidatePrazoFieldsHook::VALID_STATUSES (validação dupla server-side).
 *
 * Notas vinculantes (Story 4a.4 D9):
 * - "Reverter" (protocolado/ciencia_renuncia → pendente) está disponível
 *   para QUALQUER user com edit. Restrição por role fica como follow-up se
 *   Felipe pedir após smoke F1.
 * - `descartado` é terminal: sem transições saindo. Reverter é via Admin →
 *   Trash (UX spec linha 656, Decisão UX-2).
 */

export const PRAZO_TRANSITIONS = Object.freeze({
  rascunho: ['pendente', 'acompanhamento', 'descartado'],
  pendente: ['atrasado_reagendado', 'aguardando_cliente', 'aguardando_correcao', 'protocolado', 'ciencia_renuncia', 'descartado'],
  atrasado_reagendado: ['pendente', 'protocolado', 'ciencia_renuncia', 'descartado'],
  aguardando_cliente: ['pendente', 'atrasado_reagendado', 'protocolado', 'descartado'],
  aguardando_correcao: ['pendente', 'protocolado', 'ciencia_renuncia', 'descartado'],
  protocolado: ['pendente'],
  ciencia_renuncia: ['pendente'],
  acompanhamento: ['pendente', 'protocolado'],
  descartado: [],
});

/**
 * Status que abrem dialog modal de motivoReagendamento (≥10 chars trim) ANTES
 * do save. Espelha `Prazo::MOTIVO_REAGENDAMENTO_MIN_LEN` PHP + a regra do
 * ValidatePrazoFieldsHook::validateMotivoReagendamento. Validação client-side
 * é primeira camada; backend valida em segunda camada (Story 4a.4 D7).
 */
export const STATUSES_REQUIRING_MOTIVO = Object.freeze(['atrasado_reagendado']);

/**
 * Status que abrem confirmation dialog leve (Espo.Ui.confirm) ANTES do save —
 * audit log permanente, ação semanticamente irreversível pela UI normal
 * (revert exige Sócio/Admin → Trash ou role privileged).
 */
export const STATUSES_REQUIRING_CONFIRMATION = Object.freeze([
  'protocolado',
  'ciencia_renuncia',
  'descartado',
]);

/**
 * Min chars de motivoReagendamento — espelha `Prazo::MOTIVO_REAGENDAMENTO_MIN_LEN`
 * PHP. NÃO mudar sem mudar o lado PHP em paralelo + atualizar i18n
 * `messages.motivoReagendamentoRequerido` em pt-BR.
 */
export const MOTIVO_REAGENDAMENTO_MIN_LEN = 10;

/**
 * Helper utilitário: retorna lista de transições válidas a partir de um
 * status corrente. Status desconhecido → []. Imutável.
 */
export function getValidTransitions(currentStatus) {
  if (typeof currentStatus !== 'string') return [];
  return [...(PRAZO_TRANSITIONS[currentStatus] ?? [])];
}

/**
 * Helper utilitário: status alvo exige dialog motivoReagendamento?
 */
export function requiresMotivo(targetStatus) {
  return STATUSES_REQUIRING_MOTIVO.includes(targetStatus);
}

/**
 * Helper utilitário: status alvo exige confirmation dialog?
 */
export function requiresConfirmation(targetStatus) {
  return STATUSES_REQUIRING_CONFIRMATION.includes(targetStatus);
}

/**
 * Valida se o texto de motivoReagendamento atende ao comprimento mínimo.
 * Espelha ValidatePrazoFieldsHook::validateMotivoReagendamento (trim ≥ 10 chars).
 * Primeira camada client-side; backend valida em segunda camada.
 */
export function validateMotivo(text) {
  if (typeof text !== 'string') return false;
  return text.trim().length >= MOTIVO_REAGENDAMENTO_MIN_LEN;
}
