#!/usr/bin/env bash
# restore.sh — Story 1a.7 (finalizado na Story 10.6)
#
# ATENÇÃO: este script PARA a stack e SOBRESCREVE dados. Só rodar em staging
# ou em recuperação real de desastre. Exige confirmação textual.
#
# Fluxo (7 passos do AC4 da 1a.7 — inalterado):
#   1. Valida que mariadb/postgres/nextcloud/espocrm não estão rodando.
#   2. Lista snapshots restic; aborta se repo vazio.
#   3. Pede confirmação textual ("RESTAURAR").
#   4. Restaura dumps SQL do snapshot para staging local (./restore-tmp/).
#   5. Sobe somente mariadb + postgres, aguarda healthy, aplica .sql.gz.
#   6. Restaura volume Nextcloud + chown www-data:www-data.
#   7. Sobe restante da stack e imprime instrução de smoke test.
#
# Uso:
#   ./restore.sh --latest                Restaura último snapshot.
#   ./restore.sh --snapshot <id>         Restaura snapshot específico.
#   ./restore.sh --dry-run               Lista o que faria, sem executar.
#   ./restore.sh --latest --yes          Pula a confirmação textual (AUTOMAÇÃO
#                                        — usado pelo smoke do gate 10.6; o
#                                        default continua interativo).
#
# Finalização Story 10.6 (sem mudar o fluxo/guardas/confirmação default):
#   - Logging persistente em docker/logs/restore-<UTC-ISO>.log (tee).
#   - Sem dependência de `jq` no host (ambiente alvo Windows/MSYS não tem jq;
#     era o motivo de o restore destrutivo end-to-end nunca ter rodado —
#     ver lib-json.sh). Mesmos valores extraídos, motor python.
#   - MSYS_NO_PATHCONV (paths absolutos de container não podem ser convertidos).
#   - Flag opcional --yes para o smoke automatizado do gate (default interativo).

set -euo pipefail

# Windows + MSYS/Git Bash converte argumentos tipo /restore, /nextcloud-data
# para path Windows antes de entregar ao docker, quebrando os comandos dentro
# do container. Desligar a conversão é obrigatório (Dev Notes §11 da 1a.7).
export MSYS_NO_PATHCONV=1
export MSYS2_ARG_CONV_EXCL='*'

readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly DOCKER_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

# shellcheck source=lib-json.sh
. "${SCRIPT_DIR}/lib-json.sh"

# -----------------------------------------------------------------------------
# Parse de flags
# -----------------------------------------------------------------------------
SNAPSHOT="latest"
DRY_RUN=0
ASSUME_YES=0

while [ $# -gt 0 ]; do
  case "$1" in
    --latest)             SNAPSHOT="latest"; shift ;;
    --snapshot)           SNAPSHOT="${2:-}"; shift 2 ;;
    --dry-run)            DRY_RUN=1; shift ;;
    --yes|--assume-yes)   ASSUME_YES=1; shift ;;
    -h|--help)
      sed -n '2,30p' "$0"
      exit 0
      ;;
    *)
      echo "Flag desconhecida: $1" >&2
      exit 1
      ;;
  esac
done

if [ -z "$SNAPSHOT" ]; then
  echo "FATAL: --snapshot exige um id (ou use --latest)." >&2
  exit 1
fi

cd "$DOCKER_DIR"

# -----------------------------------------------------------------------------
# Logging persistente — tudo no terminal também vai para
# docker/logs/restore-<UTC-ISO>.log. O prompt interativo do Passo 3 continua
# funcionando (read lê do terminal; só stdout/stderr são duplicados via tee).
# -----------------------------------------------------------------------------
readonly LOG_DIR="${DOCKER_DIR}/logs"
mkdir -p "$LOG_DIR"
readonly LOG_FILE="${LOG_DIR}/restore-$(date -u +%Y%m%dT%H%M%SZ).log"
exec > >(tee -a "$LOG_FILE") 2>&1
echo "→ [restore.sh] Log desta execução: ${LOG_FILE}"

run() {
  if [ "$DRY_RUN" -eq 1 ]; then
    echo "[DRY-RUN] $*"
  else
    "$@"
  fi
}

# -----------------------------------------------------------------------------
# 1. Pré-condição: serviços de dados parados
# -----------------------------------------------------------------------------
# Filtro explícito (não `docker compose ps` genérico) porque o próprio script
# vai subir togare-backup via `run --rm` nos passos seguintes — o que daria
# falso-positivo num check ingênuo.
echo "→ 1/7 Validando que serviços de dados estão parados..."
running="$(docker compose ps --status=running --services 2>/dev/null \
  | grep -E '^(mariadb|postgres|nextcloud|espocrm|espocrm-daemon)$' || true)"

if [ -n "$running" ]; then
  echo "FATAL: serviços ainda rodando — restore sobrescreveria dados ao vivo:" >&2
  echo "$running" | sed 's/^/  - /' >&2
  echo "" >&2
  echo "Pare a stack antes de continuar:" >&2
  echo "  docker compose down" >&2
  exit 1
fi

# -----------------------------------------------------------------------------
# 2. Listar snapshots
# -----------------------------------------------------------------------------
echo "→ 2/7 Listando snapshots disponíveis..."
snapshots_json="$(docker compose run --rm -T togare-backup restic snapshots --json 2>/dev/null || echo '[]')"
snapshot_count="$(echo "$snapshots_json" | json_array_len)"

if [ "$snapshot_count" -eq 0 ]; then
  echo "FATAL: repositório restic está vazio. Não há nada para restaurar." >&2
  exit 1
fi

if [ "$SNAPSHOT" = "latest" ]; then
  snapshot_id="$(echo "$snapshots_json" | json_last_field short_id)"
  snapshot_time="$(echo "$snapshots_json" | json_last_field time)"
else
  snapshot_id="$SNAPSHOT"
  snapshot_time="$(echo "$snapshots_json" | json_restic_time_for_id "$SNAPSHOT")"
  if [ -z "$snapshot_time" ]; then
    echo "FATAL: snapshot $SNAPSHOT não encontrado no repositório." >&2
    exit 1
  fi
fi

echo "  Snapshot escolhido: $snapshot_id"
echo "  Timestamp:          $snapshot_time"

# -----------------------------------------------------------------------------
# 3. Confirmação textual
# -----------------------------------------------------------------------------
echo "→ 3/7 Confirmação..."
echo ""
echo "  ATENÇÃO: este restore vai SOBRESCREVER bancos e arquivos do Nextcloud."
if [ "$ASSUME_YES" -eq 1 ]; then
  echo "  --yes informado: confirmação textual PULADA (modo automação/gate)."
else
  echo "  Para confirmar, digite RESTAURAR (em maiúsculas, sem aspas):"
  read -r -p "  > " confirm
  if [ "$confirm" != "RESTAURAR" ]; then
    echo "Confirmação não recebida ('RESTAURAR' não digitado). Abortando." >&2
    exit 1
  fi
fi

# -----------------------------------------------------------------------------
# 4. Restaurar dumps SQL para staging local
# -----------------------------------------------------------------------------
# Bind mount de uma pasta do host pra o togare-backup escrever os dumps
# extraídos. Caminho RELATIVO (./restore-tmp) — o compose resolve a partir do
# diretório do compose file; evita o problema de path Windows absoluto em -v.
#
# Semântica restic: `restic restore <id>:/tmp --target /restore` extrai o
# CONTEÚDO de /tmp do snapshot direto em /restore (forma <snap>:<subpath> —
# correta; a forma antiga `--include` aninhava o path e o restore destrutivo
# nunca chegou a ser validado de fato — débito 1a.7 que o gate 10.6 fecha).
readonly STAGING_REL="./restore-tmp"
readonly STAGING_HOST="${DOCKER_DIR}/restore-tmp"
echo "→ 4/7 Restaurando dumps SQL para $STAGING_HOST..."
run rm -rf "$STAGING_HOST"
run mkdir -p "$STAGING_HOST"
run chmod 700 "$STAGING_HOST"

run docker compose run --rm -T \
  -v "${STAGING_REL}:/restore" \
  togare-backup \
    restic restore "${snapshot_id}:/tmp" --target=/restore

# Os dumps saem em /restore/backup-staging-<pid>/ — encontrar o mais recente.
mariadb_gz=""
postgres_gz=""
if [ "$DRY_RUN" -eq 0 ]; then
  mariadb_gz="$(find "$STAGING_HOST" -name 'mariadb-*.sql.gz' 2>/dev/null | sort | tail -n1)"
  postgres_gz="$(find "$STAGING_HOST" -name 'postgres-*.sql.gz' 2>/dev/null | sort | tail -n1)"

  if [ -z "$mariadb_gz" ]; then
    echo "FATAL: dump mariadb não encontrado no snapshot." >&2
    exit 1
  fi
  if [ -z "$postgres_gz" ]; then
    echo "FATAL: dump postgres não encontrado no snapshot." >&2
    exit 1
  fi
  echo "  mariadb dump:  $mariadb_gz"
  echo "  postgres dump: $postgres_gz"
fi

# -----------------------------------------------------------------------------
# 5. Subir mariadb + postgres e aplicar dumps
# -----------------------------------------------------------------------------
echo "→ 5/7 Subindo mariadb + postgres..."
run docker compose up -d mariadb postgres

# Aguardar healthy (depends_on não funciona aqui — serviços já estão "started").
wait_healthy() {
  local svc="$1"
  echo "  Aguardando $svc healthy..."
  for _ in $(seq 1 30); do
    local status
    status="$(docker compose ps "$svc" --format json 2>/dev/null | json_compose_health)"
    [ "$status" = "healthy" ] && return 0
    sleep 2
  done
  echo "FATAL: $svc não ficou healthy em 60s." >&2
  return 1
}

if [ "$DRY_RUN" -eq 0 ]; then
  wait_healthy mariadb
  wait_healthy postgres
fi

# shellcheck disable=SC1091
[ "$DRY_RUN" -eq 0 ] && . ./.env

echo "  Aplicando dump MariaDB..."
# `set -o pipefail` no shell INTERNO: sem isso um .gz corrompido (gunzip
# falha) com o cliente lendo stdin vazio e saindo 0 mascararia um restore
# parcial silencioso.
run bash -c "set -o pipefail; gunzip -c '$mariadb_gz' | docker compose exec -T mariadb \
  mariadb -uroot -p'${MARIADB_ROOT_PASSWORD:-PLACEHOLDER}' '${ESPOCRM_DB_NAME:-espocrm}'"

# pg_dumpall (cluster) → aplicar conectado ao DB de manutenção `postgres`:
# o dump traz CREATE ROLE (incl. oc_admin com hash de senha), CREATE DATABASE
# e \connect. psql continua em erro por default ("role/database already
# exists" do bootstrap do POSTGRES_* são esperados e inofensivos).
echo "  Aplicando dump Postgres (pg_dumpall — cluster: roles + db + grants)..."
run bash -c "set -o pipefail; gunzip -c '$postgres_gz' | docker compose exec -T \
  -e PGPASSWORD='${NEXTCLOUD_DB_PASSWORD:-PLACEHOLDER}' postgres \
  psql -U '${NEXTCLOUD_DB_USER:-nextcloud}' -d postgres"

# Sanity-probe pós-aplicação: ON_ERROR_STOP fica off de propósito (erros
# "already exists" são esperados), então um dump truncado passaria batido.
# Confere que o DB do Nextcloud tem schema (≥1 tabela oc_*) — senão o
# restore do Postgres falhou de fato.
if [ "$DRY_RUN" -eq 0 ]; then
  nc_tables="$(docker compose exec -T -e PGPASSWORD="${NEXTCLOUD_DB_PASSWORD:-PLACEHOLDER}" postgres \
    psql -U "${NEXTCLOUD_DB_USER:-nextcloud}" -d "${NEXTCLOUD_DB_NAME:-nextcloud}" -tAc \
    "SELECT count(*) FROM information_schema.tables WHERE table_schema='public' AND table_name LIKE 'oc_%';" 2>/dev/null | tr -d '\r ' || echo 0)"
  if ! [ "${nc_tables:-0}" -gt 0 ] 2>/dev/null; then
    echo "FATAL: pós-restore o DB Nextcloud não tem tabelas oc_* (dump Postgres" >&2
    echo "       truncado/corrompido?). Restore NÃO confiável — abortando." >&2
    exit 1
  fi
  echo "  Postgres OK (${nc_tables} tabelas oc_* no DB Nextcloud)."
fi

# -----------------------------------------------------------------------------
# 6. Restaurar volumes EspoCRM + Nextcloud
# -----------------------------------------------------------------------------
# O backup.sh faz snapshot de /espocrm-data E /nextcloud-data. O restore PRECISA
# dos DOIS: sem espocrm-data, após um `down -v` o app + módulos custom togare-*
# somem e o banco (restaurado) referencia tabelas sem código → CRM quebrado.
# (A 1a.7 só restaurava nextcloud-data — defeito que o gate destrutivo 10.6
# expôs e esta finalização corrige.)
#
# Semântica: monta o volume-alvo (rw) num path dedicado e usa
# `restic restore <id>:/<subpath> --target /dst` → conteúdo do subpath cai
# direto na raiz do volume (igual ao layout original do container).
nextcloud_vol="$(docker compose config --format json \
  | json_compose_volume_name nextcloud_data nextcloud-crm_nextcloud_data)"
espocrm_vol="$(docker compose config --format json \
  | json_compose_volume_name espocrm_data nextcloud-crm_espocrm_data)"

echo "→ 6/7 Restaurando volume EspoCRM ($espocrm_vol)..."
run docker compose run --rm -T \
  -v "${espocrm_vol}:/dst" \
  togare-backup \
    restic restore "${snapshot_id}:/espocrm-data" --target=/dst

echo "  Restaurando volume Nextcloud ($nextcloud_vol)..."
run docker compose run --rm -T \
  -v "${nextcloud_vol}:/dst" \
  togare-backup \
    restic restore "${snapshot_id}:/nextcloud-data" --target=/dst

echo "  Ajustando ownership pós-restore..."
run docker compose run --rm -T --user root nextcloud \
  chown -R www-data:www-data /var/www/html/data /var/www/html/config 2>/dev/null || \
  echo "  AVISO: chown Nextcloud falhou (paths podem não existir ainda)." >&2
run docker compose run --rm -T --user root espocrm \
  chown -R www-data:www-data /var/www/html/data /var/www/html/custom 2>/dev/null || \
  echo "  AVISO: chown EspoCRM falhou (paths podem não existir ainda)." >&2

# -----------------------------------------------------------------------------
# 7. Subir restante da stack
# -----------------------------------------------------------------------------
echo "→ 7/7 Subindo stack completa..."
run docker compose up -d

# Cleanup do staging local (dumps já aplicados no banco).
run rm -rf "$STAGING_HOST"

echo ""
echo "✓ Restore concluído. Snapshot restaurado: $snapshot_id, timestamp: $snapshot_time."
echo "  Log desta execução: ${LOG_FILE}"
echo ""
echo "Smoke test pós-restore — rode os 4 comandos do docker/README.md → 'Smoke test pós-instalação':"
echo "  1) docker compose ps  (todos healthy?)"
echo "  2) curl -k --tls-max 1.3 https://localhost/ -o /dev/null -w \"%{http_code}\\n\""
echo "  3) curl -k --tls-max 1.2 --tlsv1.2 https://localhost/ ...  (deve falhar handshake)"
echo "  4) curl -k -sI https://localhost/ | grep -i \"x-togare-correlation-id\""
echo ""
echo "Se algum falhar:"
echo "  - logs:     docker compose logs -f <serviço>"
echo "  - mariadb:  docker compose exec mariadb mariadb -uroot -p"
echo "  - postgres: docker compose exec postgres psql -U <user> -d <db>"
echo ""
echo "Lembrete: triggers append-only de togare_audit_log somem com o restore do"
echo "schema. Reaplicar: bash docker/scripts/audit-log-lockdown.sh (idempotente)."
