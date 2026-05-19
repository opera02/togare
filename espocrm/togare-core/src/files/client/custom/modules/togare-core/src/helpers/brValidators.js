/**
 * Helpers de validação BR no cliente — implementação inline 1:1 dos validators
 * PHP em Espo\Modules\TogareCore\Validators\BrValidator (Story 1a.5 — UX-DR12).
 *
 * Sem dependência npm para evitar problemas de bundling em extension EspoCRM
 * (validation-br não estava sendo empacotado no module-togare-core.js, gerando
 * 404 em /client/lib/transpiled/src/validation-br.js — Story 3.1 hotfix v0.9.1).
 *
 * Storage sempre só dígitos. Todos os helpers removem máscara antes de validar.
 * Server (BrValidator.php) é a fonte da verdade — client só dá feedback rápido.
 */

export function digitsOnly(value) {
  return String(value ?? "").replace(/\D+/g, "");
}

/**
 * Valida CPF (11 dígitos, DV mod 11, rejeita todos iguais).
 * Algoritmo idêntico ao BrValidator::isValidCpf.
 */
export function isValidCpf(value) {
  const d = digitsOnly(value);
  if (d.length !== 11) return false;
  if (/^(\d)\1{10}$/.test(d)) return false;

  for (let j = 9; j <= 10; j++) {
    let sum = 0;
    for (let i = 0; i < j; i++) {
      sum += parseInt(d[i], 10) * (j + 1 - i);
    }
    let dv = (sum * 10) % 11;
    if (dv === 10) dv = 0;
    if (dv !== parseInt(d[j], 10)) return false;
  }
  return true;
}

/**
 * Valida CNPJ (14 dígitos, DV mod 11 com pesos específicos).
 * Algoritmo idêntico ao BrValidator::isValidCnpj.
 */
export function isValidCnpj(value) {
  const d = digitsOnly(value);
  if (d.length !== 14) return false;
  if (/^(\d)\1{13}$/.test(d)) return false;

  const weights1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
  const weights2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

  let sum1 = 0;
  for (let i = 0; i < 12; i++) {
    sum1 += parseInt(d[i], 10) * weights1[i];
  }
  let dv1 = sum1 % 11;
  dv1 = dv1 < 2 ? 0 : 11 - dv1;
  if (dv1 !== parseInt(d[12], 10)) return false;

  let sum2 = 0;
  for (let i = 0; i < 13; i++) {
    sum2 += parseInt(d[i], 10) * weights2[i];
  }
  let dv2 = sum2 % 11;
  dv2 = dv2 < 2 ? 0 : 11 - dv2;
  return dv2 === parseInt(d[13], 10);
}

/**
 * Valida CEP (exatamente 8 dígitos, sem DV — é formato).
 */
export function isValidCep(value) {
  return digitsOnly(value).length === 8;
}

/**
 * Valida telefone BR — fixo (10 dígitos) ou celular (11 com nono '9').
 * DDD entre 11 e 99. Idêntico a BrValidator::isValidPhone.
 */
export function isValidPhone(value) {
  const d = digitsOnly(value);
  if (d.length !== 10 && d.length !== 11) return false;

  const ddd = parseInt(d.slice(0, 2), 10);
  if (ddd < 11 || ddd > 99) return false;

  if (d.length === 11 && d[2] !== "9") return false;

  return true;
}

/**
 * Valida número CNJ (20 dígitos, DV mod 97 da Res. CNJ 65/2008).
 * Aceita formato puro ou com máscara NNNNNNN-DD.AAAA.J.TR.OOOO.
 *
 * Algoritmo: concatena NNNNNNN+AAAA+J+TR+OOOO+"00", calcula mod 97,
 * DV esperado = 98 - resultado. Mod 97 em chunks para evitar overflow
 * (20 dígitos > Number.MAX_SAFE_INTEGER).
 */
export function isValidCnj(value) {
  const d = digitsOnly(value);
  if (d.length !== 20) return false;

  const seq = d.slice(0, 7);
  const dv = d.slice(7, 9);
  const ano = d.slice(9, 13);
  const j = d.slice(13, 14);
  const tr = d.slice(14, 16);
  const orig = d.slice(16, 20);

  const base = seq + ano + j + tr + orig + "00";

  // Mod 97 incremental — processa 7 dígitos por vez (10^7 < 2^53).
  let mod = 0;
  for (let i = 0; i < base.length; i += 7) {
    const chunk = base.slice(i, i + 7);
    mod = parseInt(String(mod) + chunk, 10) % 97;
  }

  const expectedDv = 98 - mod;
  return parseInt(dv, 10) === expectedDv;
}

/**
 * Formata 20 dígitos CNJ para NNNNNNN-DD.AAAA.J.TR.OOOO. Retorna null
 * se o input não tem 20 dígitos ou DV inválido.
 */
export function formatCnj(value) {
  const d = digitsOnly(value);
  if (d.length !== 20) return null;
  if (!isValidCnj(d)) return null;
  return (
    d.slice(0, 7) +
    "-" +
    d.slice(7, 9) +
    "." +
    d.slice(9, 13) +
    "." +
    d.slice(13, 14) +
    "." +
    d.slice(14, 16) +
    "." +
    d.slice(16, 20)
  );
}
