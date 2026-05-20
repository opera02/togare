#!/usr/bin/env bash
# docker/scripts/validar-seguranca.sh — Checklist A2 (gate antes de dados reais)
#
# Origem: Action Item A2 da retrospectiva do Épico 10.
# Roda 5 verificações automáticas de segurança do ambiente e imprime no fim
# o passo-a-passo dos 3 itens que NÃO dão para automatizar (porque seriam
# destrutivos ou dependem de tempo real).
#
# Modo padrão: NÃO TRAVA — só relata ✓/✗. Use --bloqueante para sair com
# exit ≠ 0 se algum check falhar (útil em CI/gates).
#
# Uso:
#   cd <raiz_do_projeto>/docker
#   set -a && source .env && set +a
#   bash scripts/validar-seguranca.sh
#   bash scripts/validar-seguranca.sh --bloqueante       # falha se algum NOK
#   bash scripts/validar-seguranca.sh -h | --help
#
# Idempotente. Não cria nenhuma conta/recurso destrutivo.

set -uo pipefail
# Nota: -e desligado de propósito. Cada check decide se NOK é fatal; o exit
# code final agrega tudo respeitando --bloqueante.

readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly DOCKER_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

BLOQUEANTE=0
while [ $# -gt 0 ]; do
  case "$1" in
    --bloqueante|--strict) BLOQUEANTE=1; shift ;;
    -h|--help)
      sed -n '2,21p' "$0" | sed 's/^# \{0,1\}//'
      exit 0 ;;
    *) echo "Argumento desconhecido: $1" >&2; exit 2 ;;
  esac
done

# .env é OBRIGATÓRIO (precisa de MARIADB_ROOT_PASSWORD, ESPOCRM_DB_NAME etc.).
# Tenta auto-carregar se variáveis ainda não estão no ambiente.
if [ -z "${MARIADB_ROOT_PASSWORD:-}" ] || [ -z "${ESPOCRM_DB_NAME:-}" ]; then
  if [ -f "${DOCKER_DIR}/.env" ]; then
    set -a; . "${DOCKER_DIR}/.env"; set +a
  fi
fi
: "${MARIADB_ROOT_PASSWORD:?MARIADB_ROOT_PASSWORD não definido (rode 'set -a && source docker/.env && set +a' antes)}"
: "${ESPOCRM_DB_NAME:?ESPOCRM_DB_NAME não definido}"

readonly DOMINIO="${TOGARE_DOMAIN:-localhost}"
readonly MODO_LOCALHOST=$( [ "${DOMINIO}" = "localhost" ] && echo 1 || echo 0 )

# Cores apenas em TTY (pipe → texto puro, fica legível no log).
if [ -t 1 ]; then
  readonly C_OK=$'\e[32m'; readonly C_NOK=$'\e[31m'; readonly C_SKIP=$'\e[33m'
  readonly C_DIM=$'\e[2m'; readonly C_RESET=$'\e[0m'
else
  readonly C_OK=""; readonly C_NOK=""; readonly C_SKIP=""; readonly C_DIM=""; readonly C_RESET=""
fi

declare -a RESULTADOS=()
NOK_COUNT=0

registrar() {  # registrar OK|NOK|SKIP "Item 1" "detalhe"
  local status="$1" titulo="$2" detalhe="${3:-}"
  case "$status" in
    OK)   RESULTADOS+=("${C_OK}✓${C_RESET} ${titulo}${detalhe:+ ${C_DIM}— ${detalhe}${C_RESET}}") ;;
    NOK)  RESULTADOS+=("${C_NOK}✗${C_RESET} ${titulo}${detalhe:+ ${C_DIM}— ${detalhe}${C_RESET}}")
          NOK_COUNT=$((NOK_COUNT + 1)) ;;
    SKIP) RESULTADOS+=("${C_SKIP}–${C_RESET} ${titulo}${detalhe:+ ${C_DIM}— ${detalhe}${C_RESET}}") ;;
  esac
}

# docker compose helpers que aceitam ambos os layouts (rodando da raiz ou de docker/).
dc() { ( cd "${DOCKER_DIR}" && docker compose "$@" ); }
dc_q() { dc "$@" >/dev/null 2>&1; }

echo "════════════════════════════════════════════════════════════════════"
echo " A2 — Validação de segurança do ambiente (Togare)"
echo "    Domínio: ${DOMINIO}  ·  Bloqueante: $([ $BLOQUEANTE -eq 1 ] && echo sim || echo não)"
echo "════════════════════════════════════════════════════════════════════"
echo ""

# -----------------------------------------------------------------------------
# Check 1 — audit-log-lockdown (triggers append-only + teste negativo DELETE)
# -----------------------------------------------------------------------------
echo "[1/5] Travas append-only em togare_audit_log..."
if bash "${SCRIPT_DIR}/audit-log-lockdown.sh" >/dev/null 2>&1; then
  # Teste NEGATIVO: tentar DELETE deve falhar com 1644 (45000).
  delete_saida=$(docker exec -i \
      -e MYSQL_PWD="${MARIADB_ROOT_PASSWORD}" \
      "${MARIADB_CONTAINER:-nextcloud-crm-mariadb-1}" \
      mariadb -uroot "${ESPOCRM_DB_NAME}" \
      -e "DELETE FROM togare_audit_log LIMIT 1" 2>&1 || true)
  if echo "${delete_saida}" | grep -q "append-only: DELETE not permitted"; then
    registrar OK "Item 1: audit-log-lockdown" "triggers ativos + DELETE rejeitado"
  else
    registrar NOK "Item 1: audit-log-lockdown" "triggers OK mas DELETE não foi bloqueado"
  fi
else
  registrar NOK "Item 1: audit-log-lockdown" "script audit-log-lockdown.sh falhou — rode-o manualmente para ver o erro"
fi

# -----------------------------------------------------------------------------
# Check 2 — HSTS em produção (skip em localhost por design)
# -----------------------------------------------------------------------------
echo "[2/5] HSTS no domínio público..."
if [ "${MODO_LOCALHOST}" -eq 1 ]; then
  registrar SKIP "Item 2: HSTS" "modo localhost — HSTS desligado de propósito"
else
  hsts_crm=$(curl -sI --max-time 10 "https://${DOMINIO}/" 2>/dev/null | tr -d '\r' | grep -i '^strict-transport-security:' || true)
  hsts_files=$(curl -sI --max-time 10 "https://files.${DOMINIO}/" 2>/dev/null | tr -d '\r' | grep -i '^strict-transport-security:' || true)
  if [ -n "${hsts_crm}" ] && [ -n "${hsts_files}" ]; then
    registrar OK "Item 2: HSTS" "presente em https://${DOMINIO}/ e https://files.${DOMINIO}/"
  elif [ -n "${hsts_crm}" ] || [ -n "${hsts_files}" ]; then
    falta=$( [ -z "${hsts_crm}" ] && echo "CRM" || echo "files" )
    registrar NOK "Item 2: HSTS" "ausente em ${falta}.${DOMINIO} (Let's Encrypt pode estar propagando — tente de novo em 5 min)"
  else
    registrar NOK "Item 2: HSTS" "header ausente nos 2 domínios — confirme TOGARE_DOMAIN no docker/.env e DNS/portas 80/443"
  fi
fi

# -----------------------------------------------------------------------------
# Check 3a — HTTP → HTTPS (redirect 301/308)
# -----------------------------------------------------------------------------
echo "[3/5] Redirect HTTP → HTTPS..."
if [ "${MODO_LOCALHOST}" -eq 1 ]; then
  registrar SKIP "Item 3a: HTTP→HTTPS" "modo localhost — Caddy não emite redirect aqui"
else
  redirect_code=$(curl -sI --max-time 10 "http://${DOMINIO}/" 2>/dev/null | head -n1 | awk '{print $2}')
  redirect_loc=$(curl -sI --max-time 10 "http://${DOMINIO}/" 2>/dev/null | tr -d '\r' | grep -i '^location:' | head -n1)
  if [[ "${redirect_code:-}" =~ ^(301|308)$ ]] && echo "${redirect_loc}" | grep -qi "https://${DOMINIO}"; then
    registrar OK "Item 3a: HTTP→HTTPS" "${redirect_code} → ${redirect_loc#*: }"
  else
    registrar NOK "Item 3a: HTTP→HTTPS" "esperado 301/308 com Location HTTPS, veio: ${redirect_code:-sem-resposta}"
  fi
fi

# -----------------------------------------------------------------------------
# Check 4 — authTokenMaxIdleTime (parte configuracional da Story 2.5)
# -----------------------------------------------------------------------------
echo "[4/5] Sessão expira em 30 min (authTokenMaxIdleTime=0.5)..."
idle=$(dc exec -T espocrm php command.php -r \
  "echo (new \Espo\Core\Application())->getContainer()->getByClass(\Espo\Core\Utils\Config::class)->get('authTokenMaxIdleTime');" \
  2>/dev/null | tr -d '[:space:]')
case "${idle}" in
  0.5|.5)    registrar OK  "Item 3c-config: authTokenMaxIdleTime" "${idle} (30 min)" ;;
  "")        registrar NOK "Item 3c-config: authTokenMaxIdleTime" "não conseguiu ler — EspoCRM rodando? ver 'docker compose ps espocrm'" ;;
  *)         registrar NOK "Item 3c-config: authTokenMaxIdleTime" "esperado 0.5, está '${idle}'" ;;
esac

# -----------------------------------------------------------------------------
# Check 5 — togare-backup healthy + sentinela last-success.json fresca
# -----------------------------------------------------------------------------
echo "[5/5] Backup automático (togare-backup)..."
backup_state=$(dc ps --format '{{.State}}' togare-backup 2>/dev/null | head -n1)
backup_health=$(dc ps --format '{{.Health}}' togare-backup 2>/dev/null | head -n1)

if [ "${backup_state}" != "running" ]; then
  registrar NOK "Item 4b: togare-backup" "container não está rodando (${backup_state:-ausente})"
else
  # Tenta ler a sentinela. Se ausente, força um backup manual (4a) UMA VEZ.
  if ! dc run --rm -T togare-backup cat /var/backups/togare/last-success.json >/dev/null 2>&1; then
    echo "       (sentinela ausente — rodando backup manual para validar 4a/4b...)"
    if dc run --rm togare-backup /app/backup.sh >/dev/null 2>&1; then
      registrar OK "Item 4a: backup manual" "primeiro backup gerado com sucesso"
    else
      registrar NOK "Item 4a: backup manual" "/app/backup.sh falhou — ver 'docker compose logs togare-backup'"
    fi
  else
    registrar OK "Item 4a: backup manual" "sentinela last-success.json presente"
  fi

  # 4b — depois do backup ter rodado pelo menos 1×, healthy é esperado.
  if [ "${backup_health}" = "healthy" ] || [ "${backup_health}" = "starting" ]; then
    registrar OK "Item 4b: togare-backup container" "state=${backup_state} health=${backup_health:-N/A}"
  else
    registrar NOK "Item 4b: togare-backup container" "health=${backup_health:-vazio} (esperado 'healthy')"
  fi
fi

# -----------------------------------------------------------------------------
# Relatório final
# -----------------------------------------------------------------------------
echo ""
echo "────────────────────────────────────────────────────────────────────"
echo " Resultado dos checks automáticos"
echo "────────────────────────────────────────────────────────────────────"
for r in "${RESULTADOS[@]}"; do echo "  ${r}"; done
echo ""

# -----------------------------------------------------------------------------
# Passo-a-passo dos 3 itens MANUAIS residuais
# -----------------------------------------------------------------------------
cat <<EOF
════════════════════════════════════════════════════════════════════
 PASSOS MANUAIS RESIDUAIS — não dá pra automatizar aqui
════════════════════════════════════════════════════════════════════

Os 3 itens abaixo precisam ser feitos POR VOCÊ porque (a) criam lixo
operacional ou (b) dependem de tempo real (31 min / 24h). Marque cada
um no A2-checklist-seguranca-ambiente.md quando passar.

─────────────────────────────────────────────────────────────
 [M1] Bloqueio após 5 senhas erradas (lockout) — Story 2.5 §3b
─────────────────────────────────────────────────────────────
Use uma conta de TESTE (não admin — fica 15 min bloqueada).
No CRM (https://${DOMINIO}/), logado como admin, crie um usuário simples
chamado 'teste_lockout' com qualquer senha. Depois, no terminal:

    for i in 1 2 3 4 5; do
      curl -s -o /dev/null -w "tentativa \$i -> %{http_code}\\n" \\
        -H "Authorization: Basic \$(printf 'teste_lockout:senhaERRADA%s' "\$i" | base64)" \\
        https://${DOMINIO}/api/v1/App/user
    done
    echo "--- 6a tentativa (deve ser 403 com mensagem) ---"
    curl -s -w "\\nHTTP %{http_code}\\n" \\
      -H "Authorization: Basic \$(printf 'teste_lockout:senhaERRADA6' | base64)" \\
      https://${DOMINIO}/api/v1/App/user

✓ Esperado:
  - tentativas 1..5: HTTP 401
  - 6ª tentativa: HTTP 403 + texto:
    "Conta temporariamente bloqueada. Tente novamente em 15 minutos."

Depois do teste, o usuário desbloqueia sozinho em 15 min — pode excluí-lo.

─────────────────────────────────────────────────────────────
 [M2] Sessão expira após 31 min parado — Story 2.5 §3c (comportamental)
─────────────────────────────────────────────────────────────
A configuração (authTokenMaxIdleTime=0.5) JÁ foi validada acima [4/5].
Falta provar o comportamento real:

  1. Abra https://${DOMINIO}/ e logue como admin.
  2. Deixe a aba parada SEM CLICAR EM NADA por 31 minutos.
  3. Clique em qualquer menu.

✓ Esperado: o sistema te derruba para a tela de login.
✗ NOK: se continuar logado normalmente — me avise.

─────────────────────────────────────────────────────────────
 [M3] Cron real das 02:00 BRT — Story 2.5 §4c (24h)
─────────────────────────────────────────────────────────────
O backup manual foi validado acima [5/5]. Falta provar que o agendador
dispara sozinho. AMANHÃ DE MANHÃ, rode no terminal (na pasta docker/):

    docker compose run --rm -T togare-backup cat /var/backups/togare/last-success.json

✓ Esperado: data/hora do último sucesso é de HOJE, entre 02:00 e 02:05
  (horário de Brasília) — prova que o agendador disparou sozinho.

✗ NOK: data continua sendo a do backup manual (não rodou à noite) —
  mande-me 'docker compose logs togare-backup' das últimas 24h.

════════════════════════════════════════════════════════════════════

Resumo: ${#RESULTADOS[@]} checks automáticos · ${NOK_COUNT} NOK · 3 manuais pendentes
EOF

# Exit code: 0 padrão; ≠0 só com --bloqueante e NOK > 0.
if [ ${BLOQUEANTE} -eq 1 ] && [ ${NOK_COUNT} -gt 0 ]; then
  echo ""
  echo "FATAL: ${NOK_COUNT} check(s) NOK e modo --bloqueante ativo." >&2
  exit 1
fi
exit 0
