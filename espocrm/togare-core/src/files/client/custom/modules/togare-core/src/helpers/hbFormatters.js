/**
 * Helpers Handlebars para formatação BR — aplicam máscara na apresentação.
 *
 * Storage sempre só dígitos (arquitetura Step 5). Estes helpers recebem
 * o valor do banco e produzem a versão mascarada para a UI. São puros:
 * input inválido (null, tamanho errado, não-string) retorna o input
 * original inalterado — evita quebrar a renderização por dado incompleto.
 *
 * Usage (em .tpl):
 *   <span>{{formatCpf cliente.cpf}}</span>
 *   <span>{{formatPhone cliente.telefone}}</span>
 */

function digitsOnly(value) {
  if (value === null || value === undefined) return "";
  return String(value).replace(/\D+/g, "");
}

/**
 * 11 dígitos → XXX.XXX.XXX-XX
 */
export function formatCpf(value) {
  const d = digitsOnly(value);
  if (d.length !== 11) return value;
  return `${d.slice(0, 3)}.${d.slice(3, 6)}.${d.slice(6, 9)}-${d.slice(9, 11)}`;
}

/**
 * 14 dígitos → XX.XXX.XXX/XXXX-XX
 */
export function formatCnpj(value) {
  const d = digitsOnly(value);
  if (d.length !== 14) return value;
  return `${d.slice(0, 2)}.${d.slice(2, 5)}.${d.slice(5, 8)}/${d.slice(8, 12)}-${d.slice(12, 14)}`;
}

/**
 * 8 dígitos → XXXXX-XXX
 */
export function formatCep(value) {
  const d = digitsOnly(value);
  if (d.length !== 8) return value;
  return `${d.slice(0, 5)}-${d.slice(5, 8)}`;
}

/**
 * 10 dígitos → (DD) XXXX-XXXX (fixo)
 * 11 dígitos → (DD) XXXXX-XXXX (celular com nono dígito)
 */
export function formatPhone(value) {
  const d = digitsOnly(value);
  if (d.length === 10) {
    return `(${d.slice(0, 2)}) ${d.slice(2, 6)}-${d.slice(6, 10)}`;
  }
  if (d.length === 11) {
    return `(${d.slice(0, 2)}) ${d.slice(2, 7)}-${d.slice(7, 11)}`;
  }
  return value;
}

/**
 * 20 dígitos → NNNNNNN-DD.AAAA.J.TR.OOOO
 */
export function formatCnj(value) {
  const d = digitsOnly(value);
  if (d.length !== 20) return value;
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
