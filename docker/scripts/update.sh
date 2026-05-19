#!/usr/bin/env bash
# update.sh — Story 10.6 (NFR33: update idempotente e reversível)
#
# Atualiza a stack Togare para as versões pinadas no docker/.env, com backup
# implícito ANTES de qualquer mutação e refresh do EspoCRM (rebuild + cache)
# DEPOIS de subir as imagens novas. Idempotente: rodar 2× sem nova versão é
# um no-op limpo (exit 0). Reversível: em qualquer falha o script PARA e
# imprime instruções de rollback copy-paste (rollback é MANUAL e documentado,
# nunca automático — decisão do Épico 10).
#
# Fluxo (6 passos do AC1):
#   1. Valida pré-condições (Docker up, .env, stack já inicializada, disco).
#   2. Backup implícito via togare-backup + verifica sentinela fresca.
#   3. docker compose pull das imagens pinadas.
#   4. docker compose up -d + espera serviços healthy.
#   5. Refresh EspoCRM: command.php rebuild + clear-cache (aplica metadata/ORM
#      das imagens/módulos novos) + checagem de drift de versão dos módulos
#      togare-* (avisa, com o comando exato de upgrade — NÃO instala sozinho).
#   6. Smoke pós-update mínimo (stack healthy + HTTP 200 na raiz).
#
# Uso:
#   ./update.sh              Atualiza a stack.
#   ./update.sh --dry-run    Mostra o que faria, sem executar nada.
#   ./update.sh -h|--help    Esta ajuda.
#
# ATENÇÃO: o Passo 2 cria um backup novo. Se o backup falhar, o update NÃO
# prossegue (sem rede de segurança = sem update).

set -euo pipefail

# Windows + MSYS/Git Bash converte argumentos que parecem path absoluto
# (ex.: /app/backup.sh, /var/backups/togare) para path Windows ANTES de
# entregar ao docker — quebrando comandos dentro do container. Desligar a
# conversão é obrigatório aqui (mesma razão da Dev Notes §11 da Story 1a.7).
export MSYS_NO_PATHCONV=1
export MSYS2_ARG_CONV_EXCL='*'

readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly DOCKER_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

# shellcheck source=lib-json.sh
. "${SCRIPT_DIR}/lib-json.sh"

# -----------------------------------------------------------------------------
# Parse de flags
# -----------------------------------------------------------------------------
DRY_RUN=0
while [ $# -gt 0 ]; do
  case "$1" in
    --dry-run)  DRY_RUN=1; shift ;;
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

cd "$DOCKER_DIR"

# -----------------------------------------------------------------------------
# Logging persistente — tudo que sai no terminal também vai para
# docker/logs/update-<UTC-ISO>.log (docker/logs/ é gitignored por `logs/`).
# -----------------------------------------------------------------------------
readonly LOG_DIR="${DOCKER_DIR}/logs"
mkdir -p "$LOG_DIR"
readonly LOG_FILE="${LOG_DIR}/update-$(date -u +%Y%m%dT%H%M%SZ).log"
exec > >(tee -a "$LOG_FILE") 2>&1

CURRENT_STEP="início"
ts() { date -u +%Y-%m-%dT%H:%M:%SZ; }
step() { CURRENT_STEP="$1"; echo "→ [$(ts)] $1"; }

run() {
  if [ "$DRY_RUN" -eq 1 ]; then
    echo "[DRY-RUN] $*"
  else
    "$@"
  fi
}

# -----------------------------------------------------------------------------
# Trap de erro: em qualquer falha, imprime o passo e o rollback manual.
# -----------------------------------------------------------------------------
on_error() {
  local exit_code=$?
  echo ""
  echo "✗ [$(ts)] FALHA no passo: ${CURRENT_STEP} (exit ${exit_code})."
  echo ""
  echo "════════════════════════════════════════════════════════════════════"
  echo " ROLLBACK MANUAL (NFR33 — reversível, NÃO automático)"
  echo "════════════════════════════════════════════════════════════════════"
  echo " O backup implícito do Passo 2 (se chegou até lá) é o ponto de"
  echo " retorno. Para voltar à versão anterior:"
  echo ""
  echo "   1. Reverter as tags de imagem do .env para as anteriores:"
  echo "        cd ${DOCKER_DIR}"
  echo "        git checkout -- .env          # se as versões vieram de git"
  echo "        # OU edite docker/.env manualmente de volta às versões antigas"
  echo ""
  echo "   2. Derrubar a stack:"
  echo "        docker compose down"
  echo ""
  echo "   3. Restaurar o último backup (o que o Passo 2 acabou de criar):"
  echo "        ./scripts/restore.sh --latest"
  echo ""
  echo "   4. Validar: docker compose ps  (tudo healthy) + abrir https://${TOGARE_DOMAIN:-localhost}/"
  echo ""
  echo " Log completo desta execução: ${LOG_FILE}"
  echo "════════════════════════════════════════════════════════════════════"
  exit "$exit_code"
}
trap on_error ERR

echo "════════════════════════════════════════════════════════════════════"
echo " update.sh — Togare (Story 10.6)   $([ "$DRY_RUN" -eq 1 ] && echo '[DRY-RUN]')"
echo " Início: $(ts)   Log: ${LOG_FILE}"
echo "════════════════════════════════════════════════════════════════════"

# -----------------------------------------------------------------------------
# 1. Pré-condições
# -----------------------------------------------------------------------------
step "1/6 Validando pré-condições"

if ! docker info >/dev/null 2>&1; then
  echo "FATAL: Docker não está acessível. Suba o Docker Desktop / daemon." >&2
  exit 1
fi

if [ ! -f "${DOCKER_DIR}/.env" ]; then
  echo "FATAL: ${DOCKER_DIR}/.env não existe. Copie de .env.example e configure." >&2
  exit 1
fi

# shellcheck disable=SC1091
. ./.env

# Stack já inicializada? O volume do MariaDB precisa existir (senão é um
# install do zero, não um update — abortar para não mascarar erro). Match por
# sufixo evita depender do nome exato do projeto compose.
if ! docker volume ls --format '{{.Name}}' | grep -qE '_mariadb_data$'; then
  echo "FATAL: nenhum volume *_mariadb_data existe — a stack nunca foi" >&2
  echo "       inicializada. update.sh atualiza uma stack EXISTENTE." >&2
  echo "       Para a primeira subida use: docker compose up -d" >&2
  exit 1
fi

# Espaço em disco: backup implícito + imagens novas. Heurística: ≥5 GB livres
# no filesystem do repositório de backup. Aviso (não fatal) se abaixo.
backup_path="${TOGARE_BACKUP_LOCAL_PATH:-./backup-data}"
mkdir -p "$backup_path" 2>/dev/null || true
avail_kb="$(df -Pk "$backup_path" 2>/dev/null | awk 'NR==2 {print $4}')"
if [ -n "${avail_kb:-}" ] && [ "$avail_kb" -lt 5242880 ]; then
  echo "  AVISO: < 5 GB livres em $backup_path ($((avail_kb/1024)) MB)." >&2
  echo "         Backup + pull de imagens podem encher o disco." >&2
fi
echo "  Pré-condições OK (Docker up, .env presente, stack inicializada)."

# -----------------------------------------------------------------------------
# 2. Backup implícito + verificação de sucesso
# -----------------------------------------------------------------------------
step "2/6 Backup implícito (togare-backup) — rede de segurança do rollback"
run docker compose run --rm -T togare-backup /app/backup.sh

# Verificar a sentinela last-success.json (escrita atomicamente pelo backup.sh
# da Story 1a.7) — só prossegue se o backup terminou nos últimos 15 min.
if [ "$DRY_RUN" -eq 0 ]; then
  age="$(docker compose run --rm -T togare-backup cat /var/backups/togare/last-success.json 2>/dev/null \
    | json_sentinel_age_seconds)"
  if [ -z "$age" ] || [ "$age" -lt 0 ]; then
    echo "FATAL: sentinela last-success.json ausente/ilegível após backup." >&2
    echo "       O backup implícito não confirmou sucesso — update abortado." >&2
    exit 1
  fi
  if [ "$age" -gt 900 ]; then
    echo "FATAL: último backup com sucesso foi há ${age}s, não nos últimos" >&2
    echo "       15 min. O backup implícito falhou — update abortado." >&2
    exit 1
  fi
  echo "  Backup confirmado (sentinela com ${age}s)."
else
  echo "[DRY-RUN] (pularia verificação da sentinela last-success.json)"
fi

# -----------------------------------------------------------------------------
# 3. Pull das imagens pinadas
# -----------------------------------------------------------------------------
step "3/6 docker compose pull (imagens pinadas do .env)"
echo "  Versões alvo: MariaDB=${MARIADB_VERSION:-?} EspoCRM=${ESPOCRM_VERSION:-?} \
Nextcloud=${NEXTCLOUD_VERSION:-?} Postgres=${POSTGRES_VERSION:-?} \
Redis=${REDIS_VERSION:-?} Caddy=${CADDY_VERSION:-?} Restic=${RESTIC_VERSION:-?}"
run docker compose pull

# -----------------------------------------------------------------------------
# 4. up -d + espera healthy
# -----------------------------------------------------------------------------
# --build: togare-backup é imagem `build:` (não `image:`), então `pull` a
# pula. Sem --build, uma correção nos scripts do container de backup (ex.:
# backup.sh) NÃO entraria no update. Build com cache é no-op rápido quando
# nada mudou (idempotência preservada).
step "4/6 docker compose up -d --build"
run docker compose up -d --build

wait_healthy() {
  local svc="$1"
  echo "  Aguardando $svc healthy..."
  for _ in $(seq 1 60); do
    local status
    status="$(docker compose ps "$svc" --format json 2>/dev/null | json_compose_health)"
    [ "$status" = "healthy" ] && return 0
    sleep 3
  done
  echo "FATAL: $svc não ficou healthy a tempo." >&2
  return 1
}

if [ "$DRY_RUN" -eq 0 ]; then
  wait_healthy mariadb
  wait_healthy postgres
  wait_healthy espocrm
  wait_healthy nextcloud
else
  echo "[DRY-RUN] (pularia espera de healthy: mariadb/postgres/espocrm/nextcloud)"
fi

# -----------------------------------------------------------------------------
# 5. Refresh EspoCRM (rebuild + cache) + drift de versão dos módulos togare-*
# -----------------------------------------------------------------------------
# IMPORTANTE: subir uma imagem nova NÃO aplica metadata/ORM nem migrations de
# módulo sozinho. `command.php rebuild` re-sincroniza schema/metadata com o
# código presente (idempotente). Migrations de MÓDULO togare-* só rodam quando
# a extensão é (re)instalada via `command.php extension --file=<zip>` — o
# AfterInstall de cada módulo chama o MigrationRunner (idempotente via
# togare_migrations_applied). update.sh NÃO adivinha qual .zip instalar;
# ele DETECTA drift e imprime o comando exato (decisão de upgrade de módulo
# é do operador — ver runbook).
step "5/6 Refresh EspoCRM (rebuild + clear-cache) e checagem de módulos"
run docker compose exec -T espocrm php command.php rebuild
run docker compose exec -T espocrm php command.php clear-cache

if [ "$DRY_RUN" -eq 0 ]; then
  drift=0
  mods_checked=0
  for mod_dir in ../espocrm/togare-*/ ; do
    [ -f "${mod_dir}extension.json" ] || continue
    mods_checked=$((mods_checked + 1))
    mod_name="$(json_file_get "${mod_dir}extension.json" name)"
    src_ver="$(json_file_get "${mod_dir}extension.json" version)"
    espo_mod="$(json_file_get "${mod_dir}extension.json" module)"
    [ -n "$espo_mod" ] && [ -n "$src_ver" ] || continue
    inst_ver="$(docker compose exec -T espocrm sh -c \
      "cat /var/www/html/custom/Espo/Modules/${espo_mod}/Resources/module.json 2>/dev/null" \
      | _py -c 'import sys,json
try: print(json.load(sys.stdin).get("version",""))
except Exception: print("")')"
    if [ -n "$inst_ver" ] && [ "$inst_ver" != "$src_ver" ]; then
      drift=1
      echo "  ⚠ DRIFT: módulo ${mod_name:-$espo_mod} instalado=${inst_ver} fonte=${src_ver}"
      newest_zip="$(ls -1 "${mod_dir}build/"*-"${src_ver}".zip 2>/dev/null | head -n1 || true)"
      if [ -n "$newest_zip" ]; then
        echo "      Para aplicar migrations deste módulo (rodar como operador):"
        echo "        docker compose cp \"${newest_zip#../}\" espocrm:/tmp/$(basename "$newest_zip")"
        echo "        docker compose exec espocrm php command.php extension --file=/tmp/$(basename "$newest_zip")"
        echo "        docker compose exec espocrm php command.php rebuild"
      else
        echo "      (zip ${src_ver} não encontrado em ${mod_dir}build/ — buildar antes:"
        echo "       cd ${mod_dir} && npm run build)"
      fi
    fi
  done
  if [ "$mods_checked" -eq 0 ]; then
    echo "  ⚠ ATENÇÃO: nenhum módulo togare-* inspecionado (glob não casou em" >&2
    echo "    ../espocrm/togare-*/ — layout do repo inesperado?). NÃO é um" >&2
    echo "    'sem drift' — a checagem não rodou. Verifique migrations à mão." >&2
  elif [ "$drift" -eq 0 ]; then
    echo "  Módulos togare-* sem drift de versão (${mods_checked} checados, nada a migrar)."
  else
    echo "  ⚠ Há módulos com drift acima. update.sh NÃO instala extensões"
    echo "    automaticamente (decisão de upgrade é do operador). Rode os"
    echo "    comandos indicados e veja docker/README.md → 'Atualizar o sistema'."
  fi
fi

# -----------------------------------------------------------------------------
# 6. Smoke pós-update mínimo
# -----------------------------------------------------------------------------
step "6/6 Smoke pós-update"
if [ "$DRY_RUN" -eq 0 ]; then
  not_healthy="$(docker compose ps --format json 2>/dev/null | json_compose_unhealthy)"
  if [ -n "${not_healthy// /}" ]; then
    echo "FATAL: serviços não-saudáveis após update: ${not_healthy}" >&2
    exit 1
  fi
  http_code="$(curl -k -s -o /dev/null -w '%{http_code}' \
    "https://${TOGARE_DOMAIN:-localhost}/" 2>/dev/null)" || true
  # Só 200 (raiz EspoCRM) ou 302 (redirect de login legítimo). 301/308 não:
  # um loop de redirect permanente pós-update (Caddy/EspoCRM mal-configurado)
  # responderia 301/308 e passaria como saudável, anulando o smoke.
  case "${http_code:-000}" in
    200|302) : ;;
    *)
      echo "FATAL: HTTP ${http_code:-000} em https://${TOGARE_DOMAIN:-localhost}/ (esperado 200 ou 302)." >&2
      exit 1
      ;;
  esac
  echo "  Stack saudável + HTTP ${http_code} na raiz. OK."
else
  echo "[DRY-RUN] (pularia smoke: ps healthy + curl https://${TOGARE_DOMAIN:-localhost}/)"
fi

trap - ERR
echo ""
echo "✓ [$(ts)] update.sh concluído com sucesso."
echo "  Log: ${LOG_FILE}"
echo "  Se algo parecer errado, o rollback está em docker/README.md → 'Rollback'."
