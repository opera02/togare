/**
 * Dictionary `atoCodigo` (snake_case interno) → label pt-BR amigável
 * (Story 4a.4 F1.2 / Dev Notes §2).
 *
 * Origem dos 11 valores: extraídos de
 * `espocrm/togare-djen/src/files/custom/Espo/Modules/TogareDjen/Services/
 * DjenAtoClassifier.php` (10 patterns + fallback `manifestacao_generica`).
 *
 * **MANTER SINCRONIZADO** com:
 * - i18n `Resources/i18n/pt_BR/Prazo.json::options.atoCodigo` (mesmas 11 entries).
 * - `js/bootstrap-formatters.js::ATO_CODIGO_LABELS` (helper Handlebars global —
 *   mesmas entries; usado por templates que não passam pelo field view).
 *
 * Quando o parser DJEN ganhar novos atos, atualizar os 3 lugares
 * juntos. PrazoMetadataTest valida o cruzamento i18n × dictionary.
 *
 * Valores desconhecidos retornam o input original — graceful fallback alinhado
 * a `formatCpf`/`formatCnj` em `hbFormatters.js`.
 */

export const ATO_CODIGO_LABELS = Object.freeze({
  impugnacao_cumprimento: 'Impugnação ao cumprimento de sentença',
  cumprimento_sentenca: 'Cumprimento de sentença',
  embargos_declaracao: 'Embargos de Declaração',
  agravo_instrumento: 'Agravo de Instrumento',
  agravo_interno: 'Agravo Interno',
  quesitos_pericia: 'Quesitos de perícia',
  replica: 'Réplica',
  recurso_apelacao: 'Recurso de Apelação',
  contestacao: 'Contestação',
  manifestacao_geral_intimacao: 'Manifestação geral / intimação',
  manifestacao_generica: 'Manifestação genérica',
});

/**
 * Retorna o label pt-BR para um `atoCodigo`. null/empty → string vazia;
 * desconhecido → input cru (graceful fallback).
 */
export function formatAtoCodigo(value) {
  if (value === null || value === undefined || value === '') return '';
  return ATO_CODIGO_LABELS[value] ?? value;
}
