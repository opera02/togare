#!/usr/bin/env bash
# backup.sh — Story 1a.7
# Executa as 3 etapas do backup diário em ordem: mariadb-dump, pg_dump, restic snapshot.
# Em sucesso, grava /var/backups/togare/last-success.json (sentinela) e log JSON.
# Em falha de qualquer etapa, sai com exit code distinto (1=mariadb, 2=postgres, 3=restic, 4=sentinela).

set -euo pipefail

readonly STAGING="/tmp/backup-staging-$$"
readonly REPO="${RESTIC_REPOSITORY:-/var/backups/togare}"
readonly SENTINEL="${REPO}/last-success.json"
readonly DATE_TAG="$(date +%F)"

mkdir -p "$STAGING"
trap 'rm -rf "$STAGING"' EXIT

# log_json LEVEL EVENT [extra-jq-args...]
# Compõe 1 linha JSON via jq -c -n. Sem extra: só timestamp+level+event.
log_json() {
  local level="$1"; shift
  local event="$1"; shift
  jq -c -n \
    --arg timestamp "$(date --iso-8601=seconds)" \
    --arg level "$level" \
    --arg event "$event" \
    "$@" \
    '{timestamp: $timestamp, level: $level, event: $event} + (
      [$ARGS.named | to_entries[] | select(.key as $k | ["timestamp","level","event"] | index($k) | not)]
      | from_entries
    )'
}

start_ts="$(date +%s)"
log_json info backup.started >&1

# -----------------------------------------------------------------------------
# Step 1 — mariadb-dump (EspoCRM)
# -----------------------------------------------------------------------------
mariadb_file="${STAGING}/mariadb-${DATE_TAG}.sql.gz"
if ! mariadb-dump \
        --single-transaction \
        --quick \
        --host=mariadb \
        --user=root \
        --password="${MARIADB_ROOT_PASSWORD}" \
        "${ESPOCRM_DB_NAME}" \
      | gzip -6 > "$mariadb_file"
then
  log_json error backup.failed --arg step mariadb --arg error "mariadb-dump exit ${PIPESTATUS[0]}" >&2
  exit 1
fi
mariadb_bytes="$(stat -c %s "$mariadb_file")"

# -----------------------------------------------------------------------------
# Step 2 — pg_dumpall (Nextcloud) — CLUSTER INTEIRO
# -----------------------------------------------------------------------------
# `pg_dumpall` (não `pg_dump <db>`): um dump de DB único NÃO preserva ROLES,
# OWNERSHIP nem GRANTs. O Nextcloud cria e usa o role `oc_admin` (config.php
# dbuser); após um restore "do zero" (down -v) esse role somia e o Nextcloud
# quebrava com auth failure — defeito de DR exposto pelo gate da Story 10.6.
# pg_dumpall captura roles (com hash de senha), CREATE DATABASE, ownership e
# grants — restore fiel. Restaurado com `psql -d postgres` (ver restore.sh).
postgres_file="${STAGING}/postgres-${DATE_TAG}.sql.gz"
if ! PGPASSWORD="${NEXTCLOUD_DB_PASSWORD}" pg_dumpall \
        --host=postgres \
        --username="${NEXTCLOUD_DB_USER}" \
      | gzip -6 > "$postgres_file"
then
  log_json error backup.failed --arg step postgres --arg error "pg_dumpall exit ${PIPESTATUS[0]}" >&2
  exit 2
fi
postgres_bytes="$(stat -c %s "$postgres_file")"

# -----------------------------------------------------------------------------
# Step 3 — restic snapshot (staging SQL + volumes Nextcloud + EspoCRM)
# -----------------------------------------------------------------------------
restic_summary="${STAGING}/restic-summary.json"
if ! restic backup \
        --tag daily \
        --tag mvp \
        --host togare-mvp \
        --json \
        "$STAGING" \
        /espocrm-data \
        /nextcloud-data \
      > "$restic_summary"
then
  log_json error backup.failed --arg step restic --arg error "restic backup exit ${PIPESTATUS[0]}" >&2
  exit 3
fi

snapshot_id="$(jq -r 'select(.message_type=="summary") | .snapshot_id | .[0:8]' "$restic_summary")"
data_added="$(jq -r 'select(.message_type=="summary") | .data_added' "$restic_summary")"

# Tamanhos de volume calculados com du (restic não emite por-path no summary).
espocrm_bytes="$(du -sb /espocrm-data 2>/dev/null | cut -f1 || echo 0)"
nextcloud_bytes="$(du -sb /nextcloud-data 2>/dev/null | cut -f1 || echo 0)"

# -----------------------------------------------------------------------------
# Retenção (forget diário, sem prune — prune é semanal via prune.sh)
# -----------------------------------------------------------------------------
if ! restic forget \
        --keep-daily="${BACKUP_RETENTION_DAYS:-30}" \
        --tag daily \
      >/dev/null
then
  # forget falhar é warning, não fatal — backup já está no repo.
  log_json warn backup.forget_failed >&2
fi

# -----------------------------------------------------------------------------
# Sentinela (escrita atômica: write-then-rename)
# -----------------------------------------------------------------------------
end_ts="$(date +%s)"
duration=$(( end_ts - start_ts ))

if ! jq -n \
      --arg timestamp "$(date --iso-8601=seconds)" \
      --arg snapshot_id "$snapshot_id" \
      --argjson mariadb_bytes "$mariadb_bytes" \
      --argjson postgres_bytes "$postgres_bytes" \
      --argjson espocrm_bytes "$espocrm_bytes" \
      --argjson nextcloud_bytes "$nextcloud_bytes" \
      --argjson data_added "${data_added:-0}" \
      --argjson duration "$duration" \
      '{
        timestamp: $timestamp,
        snapshot_id: $snapshot_id,
        sizes: {
          mariadb_bytes: $mariadb_bytes,
          postgres_bytes: $postgres_bytes,
          espocrm_bytes: $espocrm_bytes,
          nextcloud_bytes: $nextcloud_bytes,
          restic_data_added: $data_added
        },
        duration_seconds: $duration
      }' > "${SENTINEL}.tmp"
then
  log_json error backup.failed --arg step sentinela --arg error "jq sentinela falhou" >&2
  exit 4
fi
mv "${SENTINEL}.tmp" "$SENTINEL"

log_json info backup.completed \
  --arg snapshot_id "$snapshot_id" \
  --argjson mariadb_bytes "$mariadb_bytes" \
  --argjson postgres_bytes "$postgres_bytes" \
  --argjson espocrm_bytes "$espocrm_bytes" \
  --argjson nextcloud_bytes "$nextcloud_bytes" \
  --argjson duration_seconds "$duration"
