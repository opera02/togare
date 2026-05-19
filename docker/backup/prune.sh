#!/usr/bin/env bash
# prune.sh — Story 1a.7
# Manutenção semanal do repositório restic. NÃO atualiza last-success.json
# (esse sentinela é só do backup diário).
#
# Por que separado de backup.sh:
# - prune faz repack de packs órfãos: caro em I/O e degrada a janela de 26h
#   do healthcheck em repos grandes;
# - check --read-data-subset detecta bit rot, complementar a forget;
# - cron semanal (default domingo 03:00) é momento de baixa atividade.

set -euo pipefail

# log_json LEVEL EVENT [extra-jq-args...]
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
log_json info prune.started

# Reclama espaço físico de packs marcados como removíveis pelo forget diário.
# --max-unused=5% evita repack agressivo (default seria 0% = compactação total).
if ! restic prune --max-unused=5%; then
  log_json error prune.failed --arg step prune --arg error "restic prune exit $?"
  exit 1
fi

# Verifica integridade de 5% dos data blobs (sample) — detecta bit rot no disco.
# 100% custaria muito; 5% semanal cobre o repo inteiro em ~20 ciclos.
if ! restic check --read-data-subset=5%; then
  log_json error prune.failed --arg step check --arg error "restic check exit $?"
  exit 2
fi

end_ts="$(date +%s)"
log_json info prune.completed --argjson duration_seconds "$(( end_ts - start_ts ))"
