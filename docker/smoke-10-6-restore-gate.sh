#!/usr/bin/env bash
# smoke-10-6-restore-gate.sh — Story 10.6, AC4 (GATE DE DADOS REAIS)
#
# Prova end-to-end que um desastre é reversível ANTES de carregar dados reais
# no piloto interno. Ciclo:
#   seed controles → BASELINE → backup → docker compose down -v (destrói TUDO)
#   → restore.sh --latest --yes → POST → assert(baseline == post)
#
# Critério "gate passou" (decisão A2 do Felipe):
#   - contadores cliente/processo/prazo/user batem exatamente;
#   - audit log batendo + sem evento posterior ao backup;
#   - linha-sentinela conhecida em togare_core_smoke recuperada;
#   - arquivo Nextcloud conhecido recuperado com MESMO sha256.
#
# É DESTRUTIVO. A2 autorizou rodar em stack LOCAL + fixtures, antes de dado
# real. NÃO rodar com dados reais carregados.
#
# Uso: bash docker/smoke-10-6-restore-gate.sh

set -euo pipefail
export MSYS_NO_PATHCONV=1
export MSYS2_ARG_CONV_EXCL='*'

readonly DOCKER_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$DOCKER_DIR"
# shellcheck source=scripts/lib-json.sh
. "${DOCKER_DIR}/scripts/lib-json.sh"
# shellcheck disable=SC1091
set -a && . ./.env && set +a

readonly DB="${ESPOCRM_DB_NAME:-espocrm}"
readonly SENTINEL_TS='2010-01-06 10:06:00'           # marcador improvável
readonly NC_USER="${NEXTCLOUD_ADMIN_USER:-admin}"
readonly NC_PASS="${NEXTCLOUD_ADMIN_PASSWORD}"
readonly NC_FILE="gate-10-6.txt"
readonly NC_URL="https://files.localhost/remote.php/dav/files/${NC_USER}/${NC_FILE}"
readonly NC_CONTENT="TOGARE-10-6-RESTORE-GATE token=7a2c9e1f-restore-proof"
readonly LOG_DIR="${DOCKER_DIR}/logs"
mkdir -p "$LOG_DIR"
readonly LOG_FILE="${LOG_DIR}/smoke-10-6-restore-gate-$(date -u +%Y%m%dT%H%M%SZ).log"

say() { echo "▶ [$(date -u +%H:%M:%SZ)] $*"; }
mariadb_q() {
  docker compose exec -T mariadb mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -N -e "$1"
}
counts() {
  mariadb_q "
   SELECT CONCAT('cliente=', (SELECT COUNT(*) FROM \`$DB\`.cliente WHERE deleted=0));
   SELECT CONCAT('processo=',(SELECT COUNT(*) FROM \`$DB\`.processo WHERE deleted=0));
   SELECT CONCAT('prazo=',   (SELECT COUNT(*) FROM \`$DB\`.prazo WHERE deleted=0));
   SELECT CONCAT('user=',    (SELECT COUNT(*) FROM \`$DB\`.user WHERE deleted=0));
   SELECT CONCAT('audit=',   (SELECT COUNT(*) FROM \`$DB\`.togare_audit_log));
   SELECT CONCAT('probe=',   (SELECT CONCAT(COUNT(*),':',COALESCE(MAX(marker),'-'),':',COALESCE(MAX(created_at),'-')) FROM \`$DB\`.togare_gate_probe WHERE marker='GATE-10-6'));
  " | tr -d '\r' | sort
}
wait_stack() {
  local rc=0
  for svc in mariadb postgres espocrm nextcloud; do
    echo "  aguardando $svc healthy..."
    local ok=0
    for _ in $(seq 1 80); do
      st="$(docker compose ps "$svc" --format json 2>/dev/null \
        | python -c 'import sys,json
try:
 d=json.loads(sys.stdin.read().splitlines()[0]); print(d.get("Health") or d.get("State") or "")
except Exception: print("")' 2>/dev/null || echo "")"
      [ "$st" = "healthy" ] && { ok=1; break; }
      case "$st" in exited|dead) break;; esac   # crash-loop: não queimar timeout
      sleep 3
    done
    if [ "$ok" -ne 1 ]; then
      echo "  ✗ $svc não ficou healthy (estado: ${st:-?})"
      rc=1
    fi
  done
  return $rc
}
nc_sha() { curl -k -s -u "${NC_USER}:${NC_PASS}" "$NC_URL" | sha256sum | awk '{print $1}'; }

# P3 — smoke pós-restore do docker/README.md: HTTP 200 EspoCRM + TLS 1.3 OK +
# TLS 1.2 DEVE falhar (handshake rejeitado) + header x-togare-correlation-id.
readme_smoke() {
  local rc=0 code tls12 corr
  code="$(curl -k -s -o /dev/null -w '%{http_code}' --tls-max 1.3 "https://${TOGARE_DOMAIN:-localhost}/" 2>/dev/null)" || true
  case "${code:-000}" in 200|302) echo "  ✓ EspoCRM HTTP $code (TLS 1.3)";; *) echo "  ✗ EspoCRM HTTP ${code:-000} (esperado 200/302)"; rc=1;; esac
  if curl -k -s -o /dev/null --tls-max 1.2 --tlsv1.2 "https://${TOGARE_DOMAIN:-localhost}/" 2>/dev/null; then
    echo "  ✗ TLS 1.2 foi aceito (deveria ser rejeitado — NFR7)"; rc=1
  else
    echo "  ✓ TLS 1.2 rejeitado (handshake falhou, como esperado)"
  fi
  corr="$(curl -k -s -I "https://${TOGARE_DOMAIN:-localhost}/" 2>/dev/null | grep -i 'x-togare-correlation-id' || true)"
  if [ -n "$corr" ]; then echo "  ✓ header x-togare-correlation-id presente"; else echo "  ✗ header x-togare-correlation-id ausente"; rc=1; fi
  return $rc
}

main() {
echo "════════════════════════════════════════════════════════════════════"
echo " GATE 10.6 — restore destrutivo end-to-end   $(date -u +%FT%TZ)"
echo " Log: $LOG_FILE"
echo "════════════════════════════════════════════════════════════════════"

# P4 — guarda programática contra destruição acidental de dado real.
# Este script roda `docker compose down -v` (apaga TODOS os volumes). Só
# prossegue com confirmação EXPLÍCITA via env GATE_CONFIRM_DESTROY=1.
if [ "${GATE_CONFIRM_DESTROY:-}" != "1" ]; then
  echo "✗ ABORTADO: este gate é DESTRUTIVO (docker compose down -v apaga todos"
  echo "  os volumes). Confirme explicitamente que NÃO há dado real e rode:"
  echo "      GATE_CONFIRM_DESTROY=1 bash docker/smoke-10-6-restore-gate.sh"
  echo "  (decisão A2: rodar só em stack local + fixtures, antes de dado real)."
  return 2
fi

# -----------------------------------------------------------------------------
say "0/8 Garantindo stack no ar e saudável"
docker compose up -d >/dev/null 2>&1
wait_stack || { echo "✗ Stack não saudável antes do gate — abortando."; return 1; }

# -----------------------------------------------------------------------------
say "1/8 Semeando controles determinísticos (sentinela DB + arquivo Nextcloud)"
# Tabela-sonda própria do gate (auto-contida, idempotente) — não depende de
# internals de módulo. Token único e improvável; comprova restore byte-fiel
# de uma linha conhecida no MariaDB após destruição total.
mariadb_q "CREATE TABLE IF NOT EXISTS \`$DB\`.togare_gate_probe (
             id INT AUTO_INCREMENT PRIMARY KEY,
             marker VARCHAR(64) NOT NULL,
             created_at DATETIME NOT NULL);
           DELETE FROM \`$DB\`.togare_gate_probe WHERE marker='GATE-10-6';
           INSERT INTO \`$DB\`.togare_gate_probe (marker, created_at)
             VALUES ('GATE-10-6', '$SENTINEL_TS');"
http="$(printf '%s' "$NC_CONTENT" | curl -k -s -o /dev/null -w '%{http_code}' \
  -u "${NC_USER}:${NC_PASS}" -T - "$NC_URL")"
case "$http" in 200|201|204) echo "  upload Nextcloud OK ($http)";;
  *) echo "FALHA: upload Nextcloud HTTP $http"; return 1;; esac
expected_sha="$(printf '%s' "$NC_CONTENT" | sha256sum | awk '{print $1}')"
got_sha="$(nc_sha)"
[ "$got_sha" = "$expected_sha" ] || { echo "FALHA: sha do arquivo semeado não confere ($got_sha != $expected_sha)"; return 1; }
echo "  sha256 esperado do arquivo Nextcloud: $expected_sha"

# -----------------------------------------------------------------------------
say "2/8 BASELINE (pré-backup)"
baseline="$(counts)"
echo "$baseline" | sed 's/^/  /'
# P1 — baseline tem de ser real (não vazio + tokens esperados); senão um
# counts() que errou produziria baseline==post vazio = PASS falso.
case "$baseline" in
  *cliente=*probe=*|*probe=*cliente=*) : ;;
  *) echo "✗ BASELINE inválido (counts() não retornou tokens esperados) — abortando."; return 1;; esac
echo "$baseline" | grep -q 'probe=1:GATE-10-6:' || { echo "✗ Sonda não semeada no baseline — abortando."; return 1; }

# -----------------------------------------------------------------------------
say "3/8 Backup (togare-backup /app/backup.sh)"
docker compose run --rm -T togare-backup /app/backup.sh
backup_ts="$(docker compose run --rm -T togare-backup \
  cat /var/backups/togare/last-success.json 2>/dev/null \
  | python -c 'import sys,json;print(json.load(sys.stdin).get("timestamp",""))')"
echo "  timestamp do backup: $backup_ts"
# P2 — NÃO destruir sem confirmar que o backup desta rodada existe/é fresco.
bk_age="$(docker compose run --rm -T togare-backup cat /var/backups/togare/last-success.json 2>/dev/null | json_sentinel_age_seconds)"
if [ -z "$backup_ts" ] || [ -z "$bk_age" ] || [ "$bk_age" -lt 0 ] || [ "$bk_age" -gt 900 ]; then
  echo "✗ Backup desta rodada não confirmado (ts='$backup_ts' age='${bk_age:-?}') — NÃO destruindo."; return 1
fi
echo "  backup confirmado (sentinela ${bk_age}s)"

# -----------------------------------------------------------------------------
say "4/8 DESTRUIÇÃO TOTAL — docker compose down -v (apaga todos os volumes)"
docker compose down -v

# -----------------------------------------------------------------------------
say "5/8 RESTORE — ./scripts/restore.sh --latest --yes"
restore_rc=0
bash "${DOCKER_DIR}/scripts/restore.sh" --latest --yes || restore_rc=$?
if [ "$restore_rc" -ne 0 ]; then
  echo "✗ restore.sh saiu com código ${restore_rc} — GATE FALHOU (não confiar nos contadores)."; return 1
fi

# -----------------------------------------------------------------------------
say "6/8 Aguardando stack pós-restore + reaplicando lockdown de audit"
post_stack_ok=1
wait_stack || post_stack_ok=0
bash "${DOCKER_DIR}/scripts/audit-log-lockdown.sh" || \
  echo "  AVISO: audit-log-lockdown retornou erro (checar manualmente)"
echo "  -- smoke pós-restore (README: HTTP/TLS1.3/correlation-id) --"
readme_ok=1
readme_smoke || readme_ok=0

# -----------------------------------------------------------------------------
say "7/8 POST (pós-restore)"
post="$(counts)"
echo "$post" | sed 's/^/  /'
# Nextcloud precisa aquecer após o restart do restore (filecache/opcache).
# Retry até bater o sha esperado ou ~120s — operador também espera "healthy".
post_sha=""
for _ in $(seq 1 24); do
  post_sha="$(nc_sha || true)"
  [ "$post_sha" = "$expected_sha" ] && break
  sleep 5
done
echo "  sha256 do arquivo Nextcloud pós-restore: $post_sha"
backup_dt="$(echo "$backup_ts" | sed 's/T/ /; s/\.[0-9]*//; s/[+-][0-9][0-9]:[0-9][0-9]$//; s/Z$//')"
audit_after_backup="$(mariadb_q "SELECT COUNT(*) FROM \`$DB\`.togare_audit_log WHERE occurred_at > '${backup_dt}';" | tr -d '\r ' || echo '?')"

# -----------------------------------------------------------------------------
say "8/8 VEREDITO"
fail=0
if [ "${post_stack_ok:-1}" -ne 1 ]; then
  echo "  ✗ Stack não ficou healthy pós-restore"; fail=1
else
  echo "  ✓ Stack healthy pós-restore"
fi
if [ "${readme_ok:-1}" -ne 1 ]; then
  echo "  ✗ Smoke pós-restore do README falhou (HTTP/TLS1.3/correlation-id)"; fail=1
else
  echo "  ✓ Smoke pós-restore do README OK (HTTP 200/302 + TLS 1.3 + correlation-id)"
fi
if [ "$baseline" = "$post" ]; then
  echo "  ✓ Contadores idênticos (cliente/processo/prazo/user/audit/sentinela)"
else
  echo "  ✗ Contadores DIVERGEM:"
  diff <(echo "$baseline") <(echo "$post") | sed 's/^/      /' || true
  fail=1
fi
if [ "$post_sha" = "$expected_sha" ]; then
  echo "  ✓ Arquivo Nextcloud recuperado com sha256 idêntico"
else
  echo "  ✗ Arquivo Nextcloud divergente ($post_sha != $expected_sha)"
  fail=1
fi
# Informativo apenas: a igualdade exata de contadores (incl. audit) acima já
# prova que nenhum evento vazou nem se perdeu — restaurar de um backup com
# count idêntico ⇒ sem eventos pós-backup. O filtro por occurred_at é
# sensível a timezone/formato e NÃO decide o gate.
echo "  ℹ audit pós-backup (informativo, occurred_at>bkp): ${audit_after_backup:-?} — veredito é por igualdade de contadores"

echo "════════════════════════════════════════════════════════════════════"
if [ "$fail" -eq 0 ]; then
  echo " ✅ GATE 10.6 PASSOU — restore destrutivo comprovado end-to-end."
  echo "    Log: $LOG_FILE"
  echo "════════════════════════════════════════════════════════════════════"
  return 0
else
  echo " ❌ GATE 10.6 FALHOU — NÃO liberar dados reais. Ver log: $LOG_FILE"
  echo "════════════════════════════════════════════════════════════════════"
  return 1
fi
}

# Pipe normal (não process substitution) para logging robusto sob set -e/MSYS;
# PIPESTATUS preserva o exit real do main através do tee.
set +e
main "$@" 2>&1 | tee "$LOG_FILE"
exit "${PIPESTATUS[0]}"
