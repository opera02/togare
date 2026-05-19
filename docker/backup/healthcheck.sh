#!/usr/bin/env bash
# healthcheck.sh — Story 1a.7
# Lê /var/backups/togare/last-success.json e calcula idade do último backup.
# Limiar: 26h. Acima → exit 1 (unhealthy). Abaixo → exit 0 (healthy).
#
# Sentinela ausente → exit 1 (Docker honra start_period: 25h no compose,
# então primeiro dia passa sem marcar unhealthy).

set -euo pipefail

readonly SENTINEL="${RESTIC_REPOSITORY:-/var/backups/togare}/last-success.json"
readonly THRESHOLD_HOURS=26

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

if [ ! -f "$SENTINEL" ]; then
  log_json error healthcheck.fail --arg reason "sentinela ausente" >&2
  exit 1
fi

last_ts="$(jq -r '.timestamp' "$SENTINEL")"
if [ -z "$last_ts" ] || [ "$last_ts" = "null" ]; then
  log_json error healthcheck.fail --arg reason "timestamp inválido em sentinela" >&2
  exit 1
fi

now_secs="$(date +%s)"
last_secs="$(date -d "$last_ts" +%s 2>/dev/null || echo 0)"
if [ "$last_secs" -eq 0 ]; then
  log_json error healthcheck.fail --arg reason "timestamp ilegível: $last_ts" >&2
  exit 1
fi

age_seconds=$(( now_secs - last_secs ))
age_hours=$(( age_seconds / 3600 ))

if [ "$age_seconds" -gt $(( THRESHOLD_HOURS * 3600 )) ]; then
  log_json error healthcheck.fail \
    --argjson age_hours "$age_hours" \
    --argjson threshold_hours "$THRESHOLD_HOURS" >&2
  exit 1
fi

log_json info healthcheck.pass --argjson age_hours "$age_hours"
exit 0
