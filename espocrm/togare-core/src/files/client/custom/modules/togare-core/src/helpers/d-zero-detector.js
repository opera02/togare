/**
 * d-zero-detector — função pura de detecção D-0 (Story 4b.3, UX-DR10).
 *
 * Retorna `true` quando a `dataFatal` recai NO MESMO DIA que `now`,
 * comparando ambos no fuso `America/Sao_Paulo` (BRT). Alinha com a chave
 * de comparação do `BrazilianBusinessCalendar` (PHP) e do `formatDateBR`
 * do `card-de-prazo-renderer`.
 *
 * Pegadinhas pré-mapeadas:
 *  - `Date` em JS é UTC internamente; getDate()/getMonth() retornam valores
 *    locais (do fuso da máquina que corre o código). Em CI/jsdom, o fuso
 *    pode ser UTC, em browser pode ser BRT, em smoke F1 pode ser qualquer
 *    coisa. Precisamos NORMALIZAR para BRT *explicitamente*.
 *  - `Intl.DateTimeFormat('en-CA', {timeZone:'America/Sao_Paulo'})` produz
 *    YYYY-MM-DD nativamente (locale 'en-CA' = ISO 8601-like).
 *  - `dataFatal` em runtime EspoCRM é frequentemente string `YYYY-MM-DD`
 *    (sem hora). Tratamento: se for "YYYY-MM-DD" puro, NÃO criamos `Date`
 *    (evita ambiguidade UTC midnight vs local midnight) — usamos a string
 *    direto como YMD-BRT. Para outros formatos (Date, ISO com Z), fazemos
 *    via `Intl`.
 *
 * Uso:
 *   import { isVenceHoje } from "togare-core:helpers/d-zero-detector";
 *   if (isVenceHoje(prazo.dataFatal)) { ... }
 *
 *   // Testes determinísticos passam `now` explícito:
 *   isVenceHoje("2026-06-01", new Date("2026-06-01T15:00:00-03:00"));  // → true
 */

const BRT_TZ = "America/Sao_Paulo";

/**
 * Normaliza valor para string YYYY-MM-DD em fuso BRT.
 *
 * @param {*} value Date | string ISO | string YYYY-MM-DD | null/undefined.
 * @returns {string|null} YYYY-MM-DD em BRT, ou null se inválido.
 */
export function toBrtYmd(value) {
  if (value === null || value === undefined) return null;

  // String "YYYY-MM-DD" puro: trata como literal BRT (sem fuso conversion).
  if (typeof value === "string") {
    const literalMatch = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
    if (literalMatch) {
      return `${literalMatch[1]}-${literalMatch[2]}-${literalMatch[3]}`;
    }
  }

  let date;
  if (value instanceof Date) {
    date = value;
  } else {
    date = new Date(value);
  }
  if (Number.isNaN(date.getTime())) return null;

  // Intl.DateTimeFormat com timeZone — produz YYYY-MM-DD em BRT mesmo se
  // host roda em UTC (CI), Pacific (dev), etc. 'en-CA' garante ordem ISO.
  try {
    const fmt = new Intl.DateTimeFormat("en-CA", {
      timeZone: BRT_TZ,
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
    });
    const parts = fmt.formatToParts(date);
    const y = parts.find((p) => p.type === "year")?.value;
    const m = parts.find((p) => p.type === "month")?.value;
    const d = parts.find((p) => p.type === "day")?.value;
    if (!y || !m || !d) return null;
    return `${y}-${m}-${d}`;
  } catch (_) {
    // Fallback: jsdom muito antigo ou ambiente sem Intl com timeZone.
    // Aplica offset BRT-3h fixo (não cobre DST mas BRT removeu DST em 2019).
    const brt = new Date(date.getTime() - 3 * 60 * 60_000);
    const y = String(brt.getUTCFullYear());
    const m = String(brt.getUTCMonth() + 1).padStart(2, "0");
    const d = String(brt.getUTCDate()).padStart(2, "0");
    return `${y}-${m}-${d}`;
  }
}

/**
 * Retorna `true` quando `dataFatal` recai NO MESMO DIA que `now` em BRT.
 *
 * @param {*} dataFatal Date | string ISO | string YYYY-MM-DD | null/undefined.
 * @param {Date} [now=new Date()] Referência temporal (default: agora).
 * @returns {boolean}
 */
export function isVenceHoje(dataFatal, now = new Date()) {
  const target = toBrtYmd(dataFatal);
  if (target === null) return false;
  const today = toBrtYmd(now);
  return target === today;
}
