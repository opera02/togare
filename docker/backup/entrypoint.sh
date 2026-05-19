#!/usr/bin/env bash
# entrypoint.sh — Story 1a.7
# Boot do container togare-backup:
#   1. Valida RESTIC_PASSWORD (presente + sem chars proibidos pelo Compose).
#   2. Inicializa repo restic se não existir (file-check, não restic-cat-config).
#   3. Expande variáveis de cron no template e inicia supercronic como PID 1.

set -euo pipefail

readonly REPO="${RESTIC_REPOSITORY:-/var/backups/togare}"
export RESTIC_REPOSITORY="$REPO"

# -----------------------------------------------------------------------------
# 1. Validação RESTIC_PASSWORD
# -----------------------------------------------------------------------------
if [ -z "${RESTIC_PASSWORD:-}" ]; then
  echo "FATAL: RESTIC_PASSWORD não definida. Edite docker/.env (ver cabeçalho)." >&2
  exit 1
fi

# Caracteres proibidos: $ " ' \ ` { } — quebram parsing do Docker Compose.
# Sem essa validação, senha é silenciosamente truncada/corrompida e backups
# viram irrecuperáveis (chave de descriptografia errada).
if [[ "$RESTIC_PASSWORD" =~ [\$\"\'\\\`\{\}] ]]; then
  echo "FATAL: RESTIC_PASSWORD contém caracteres incompatíveis com parsing do Docker Compose." >&2
  echo "       Use somente alfanumérico, 20+ chars. Ver cabeçalho de docker/.env.example." >&2
  exit 1
fi

# -----------------------------------------------------------------------------
# 2. Inicialização do repo (file-check, não restic-cat-config)
# -----------------------------------------------------------------------------
# `restic cat config` também falha quando a senha está errada — usar o file-check
# em $REPO/config separa "repo nunca inicializado" de "senha errada num repo
# existente". Sem isso, um restic init num repo já existente daria erro genérico
# "config already exists" mascarando a causa raiz.
mkdir -p "$REPO"
if [ ! -f "$REPO/config" ]; then
  echo "Repo restic ausente em $REPO. Inicializando..." >&2
  restic init
fi

# -----------------------------------------------------------------------------
# 3. Modo de operação
# -----------------------------------------------------------------------------
# Sem argumentos → modo daemon (supercronic + crontab) — uso normal do compose.
# Com argumentos → executa o comando passado (ex.: `docker compose run --rm
# togare-backup restic snapshots`). Necessário para uso operacional do CLI restic
# e para o restore.sh listar/extrair snapshots sem subir o daemon.
if [ $# -gt 0 ]; then
  exec "$@"
fi

backup_cron="${BACKUP_CRON_EXPRESSION:-0 2 * * *}"
prune_cron="${PRUNE_CRON_EXPRESSION:-0 3 * * 0}"

sed \
  -e "s|{{BACKUP_CRON_EXPRESSION}}|${backup_cron}|g" \
  -e "s|{{PRUNE_CRON_EXPRESSION}}|${prune_cron}|g" \
  /app/crontab.template > /tmp/crontab

echo "togare-backup pronto. TZ=${TZ:-UTC}. Crontab:" >&2
cat /tmp/crontab >&2

exec supercronic -json /tmp/crontab
