#!/usr/bin/env bash
# install.sh — Instalação one-shot do Togare (FR34)
#
# Para quem é: escritório de advocacia SEM equipe técnica. Roda num servidor
# Linux (VPS ou máquina própria). Em poucos comandos deixa a stack completa
# funcional: EspoCRM (CRM) + Nextcloud (arquivos) + banco + Caddy (HTTPS) +
# backup automático + os 6 módulos Togare instalados e validados.
#
# O que ele faz (7 passos):
#   1. Confere se a máquina aguenta (Linux, disco, memória, internet).
#   2. Instala o Docker se faltar (Linux: 100% automático).
#   3. Gera TODAS as senhas fortes sozinho e cria docker/.env +
#      docker/CREDENCIAIS-TOGARE.txt (guarde esse arquivo fora do servidor!).
#   4. Sobe a stack (imagens pinadas) e espera tudo ficar saudável.
#   5. Instala os 6 módulos Togare na ordem de dependência (idempotente).
#   6. Roda validações de saúde (serviços + HTTPS + módulos no lugar).
#   7. Mostra o resumo: endereços de acesso, login e próximos passos.
#
# Uso:
#   ./install.sh                         Instala em modo local (https://localhost).
#   ./install.sh --dominio crm.x.adv.br --email voce@x.adv.br
#                                        Instala com domínio real + HTTPS Let's Encrypt.
#   ./install.sh --sim                   Não pergunta nada (assume "sim" em tudo).
#   ./install.sh --pular-docker          Não mexe no Docker (já está instalado).
#   ./install.sh --dry-run               Mostra o que faria, sem executar.
#   ./install.sh -h | --help             Esta ajuda.
#
# Rodar de novo é SEGURO: se docker/.env já existe, o script entra em "modo
# retomada" — não regera senhas, só garante a stack no ar e os módulos
# instalados. Para ATUALIZAR uma instalação que já roda, use update.sh.
#
# Idioma de toda a saída: português. NÃO requer Node/PHP/Composer no servidor:
# os módulos vêm pré-empacotados em espocrm/<módulo>/build/*.zip, ou — quando
# faltam (clone novo do repo) — são buildados automaticamente em CONTAINER
# isolado pelo passo 4.5 via docker/scripts/build-modulos.sh. Único requisito
# do host é o Docker (já necessário para a stack).

set -euo pipefail

# Windows + MSYS/Git Bash converte argumentos tipo /tmp/... para path Windows
# antes de entregar ao docker. Inócuo no Linux (alvo); mantido por consistência
# com update.sh/restore.sh para quem testar a partir do Git Bash.
export MSYS_NO_PATHCONV=1
export MSYS2_ARG_CONV_EXCL='*'

readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly DOCKER_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
readonly REPO_DIR="$(cd "${DOCKER_DIR}/.." && pwd)"

# shellcheck source=lib-json.sh
. "${SCRIPT_DIR}/lib-json.sh"

# Ordem de instalação dos módulos = ordem de dependência (architecture.md
# §Cross-component dependencies): core é fundação; licensing é pré-req dos
# premium; djen depende de core+licensing+tpu. togare-portal-ui NÃO entra:
# Épico 7a (Portal) está congelado/diferido para Growth — instalá-lo agora
# não tem objeto no piloto interno.
readonly MODULOS=(togare-core togare-licensing togare-rbac togare-tpu togare-nextcloud-bridge togare-djen)

# Chaves do .env que recebem senha forte gerada automaticamente.
readonly SEGREDOS=(
  MARIADB_ROOT_PASSWORD
  ESPOCRM_DB_PASSWORD
  ESPOCRM_ADMIN_PASSWORD
  NEXTCLOUD_DB_PASSWORD
  NEXTCLOUD_ADMIN_PASSWORD
  REDIS_PASSWORD
  RESTIC_PASSWORD
)

# -----------------------------------------------------------------------------
# Flags
# -----------------------------------------------------------------------------
DRY_RUN=0
ASSUME_YES=0
SKIP_DOCKER=0
REBUILD_MODULES=0
DOMINIO=""
EMAIL=""
while [ $# -gt 0 ]; do
  case "$1" in
    --dry-run) DRY_RUN=1; shift ;;
    --sim|--yes) ASSUME_YES=1; shift ;;
    --pular-docker) SKIP_DOCKER=1; shift ;;
    --dominio) DOMINIO="${2:-}"; shift 2 ;;
    --email)   EMAIL="${2:-}"; shift 2 ;;
    --rebuild-modules)
      # Força rebuild via container isolado (docker/scripts/build-modulos.sh)
      # mesmo que os zips das versões atuais já existam. Útil para limpar
      # cache de build local. Sem a flag, o passo 4.5 só roda quando algum
      # zip está FALTANDO (cenário comum em clone fresh).
      REBUILD_MODULES=1; shift ;;
    -h|--help) sed -n '2,40p' "$0"; exit 0 ;;
    *) echo "Flag desconhecida: $1 (use -h para ajuda)" >&2; exit 1 ;;
  esac
done

cd "$DOCKER_DIR"

# -----------------------------------------------------------------------------
# Log persistente — espelha tudo em docker/logs/install-<UTC>.log
# -----------------------------------------------------------------------------
readonly LOG_DIR="${DOCKER_DIR}/logs"
mkdir -p "$LOG_DIR"
readonly LOG_FILE="${LOG_DIR}/install-$(date -u +%Y%m%dT%H%M%SZ).log"
exec > >(tee -a "$LOG_FILE") 2>&1

CURRENT_STEP="início"
ts() { date -u +%Y-%m-%dT%H:%M:%SZ; }
step() { CURRENT_STEP="$1"; echo ""; echo "→ [$(ts)] $1"; }
run() { if [ "$DRY_RUN" -eq 1 ]; then echo "[DRY-RUN] $*"; else "$@"; fi; }

# retry <max> <base_seg> <cmd...> — repete o comando até <max> vezes, com
# espera crescente (base, 2*base, 3*base...). Existe porque o `docker compose
# pull` de um escritório pode levar um "connection reset" transitório no meio
# do download e NÃO pode derrubar a instalação inteira por isso. Não burla
# falha real: após <max> tentativas, falha com mensagem clara.
retry() {
  local max="$1" base="$2"; shift 2
  local n=1 wait
  while true; do
    if "$@"; then return 0; fi
    if [ "$n" -ge "$max" ]; then
      echo "  ✗ '$*' falhou após ${max} tentativas." >&2
      return 1
    fi
    wait=$(( base * n ))
    echo "  ⚠ tentativa ${n}/${max} falhou (rede instável?). Nova tentativa em ${wait}s..." >&2
    sleep "$wait"
    n=$(( n + 1 ))
  done
}

on_error() {
  local code=$?
  echo ""
  echo "✗ [$(ts)] FALHA no passo: ${CURRENT_STEP} (exit ${code})."
  echo ""
  echo "════════════════════════════════════════════════════════════════════"
  echo " O QUE FAZER AGORA"
  echo "════════════════════════════════════════════════════════════════════"
  echo " 1. Leia a última mensagem de erro acima — ela diz o que faltou."
  echo " 2. O log completo desta tentativa está em:"
  echo "      ${LOG_FILE}"
  echo " 3. Rodar de novo é seguro: ./install.sh continua de onde parou"
  echo "    (não regera senhas se docker/.env já existe)."
  echo " 4. Se a stack chegou a subir e quer começar do zero:"
  echo "      cd ${DOCKER_DIR} && docker compose down -v   # APAGA tudo"
  echo "      rm -f docker/.env docker/CREDENCIAIS-TOGARE.txt"
  echo " 5. Se travar e não souber seguir, mande este log para o suporte."
  echo "════════════════════════════════════════════════════════════════════"
  exit "$code"
}
trap on_error ERR

echo "════════════════════════════════════════════════════════════════════"
echo " INSTALADOR TOGARE   $([ "$DRY_RUN" -eq 1 ] && echo '[DRY-RUN]')"
echo " Início: $(ts)"
echo " Log:    ${LOG_FILE}"
echo "════════════════════════════════════════════════════════════════════"

# Pausa abortável (Ctrl-C) antes de uma ação que muda o sistema. Pula se
# --sim, se não-interativo, ou em dry-run.
confirmar() {
  local msg="$1"
  echo ""
  echo "  ⚠ $msg"
  if [ "$ASSUME_YES" -eq 1 ] || [ "$DRY_RUN" -eq 1 ] || [ ! -t 0 ]; then
    echo "    (prosseguindo automaticamente)"
    return 0
  fi
  echo "    Começando em 6s — tecle Ctrl-C agora para cancelar."
  sleep 6 || true
}

# Gera senha forte: 24 caracteres, SÓ letras e números (regra do .env.example
# — $ " ' \ ` { } quebram o parsing do Compose e o restic).
#
# IMPORTANTE: nada de `tr ... | head -c 24`. Sob `set -o pipefail`, o `head`
# fecha o pipe ao atingir 24 bytes, o `tr` (lendo /dev/urandom infinito) leva
# SIGPIPE e a pipeline retorna 141 → `set -e` aborta o instalador. Em vez
# disso, lê-se um bloco FINITO de /dev/urandom (head é o leitor, sai limpo),
# filtra-se, e fatia-se 24 chars com slice nativo do bash (sem 2º pipe).
gen_pwd() {
  local raw
  raw="$(LC_ALL=C tr -dc 'A-Za-z0-9' < <(head -c 8192 /dev/urandom) || true)"
  if [ "${#raw}" -lt 24 ]; then
    echo "FATAL: geração de senha produziu < 24 chars (entropia insuficiente?)." >&2
    return 1
  fi
  printf '%s' "${raw:0:24}"
}

# Escreve KEY=VALUE no docker/.env (substitui a linha existente). VALUE aqui é
# sempre alfanumérico (senha), domínio [A-Za-z0-9.-] ou e-mail — seguro p/ sed.
set_env() {
  local key="$1" val="$2" f="${DOCKER_DIR}/.env"
  if grep -qE "^${key}=" "$f"; then
    sed -i.bak "s|^${key}=.*|${key}=${val}|" "$f" && rm -f "${f}.bak"
  else
    echo "${key}=${val}" >> "$f"
  fi
}

# `docker compose` com ou sem sudo (definido no passo 2).
DC_SUDO=""
dc() { run ${DC_SUDO:+$DC_SUDO }docker compose "$@"; }
dc_q() { ${DC_SUDO:+$DC_SUDO }docker compose "$@"; }   # sem run/dry, p/ leitura

# =============================================================================
# 1/7 — Pré-checagem do sistema
# =============================================================================
step "1/7 Conferindo se o servidor aguenta"

so="$(uname -s 2>/dev/null || echo desconhecido)"
if [ "$so" != "Linux" ]; then
  echo "FATAL: este instalador é para servidor Linux (detectado: ${so})." >&2
  echo "       Em Windows/Mac use Docker Desktop e siga docker/README.md." >&2
  exit 1
fi
arch="$(uname -m 2>/dev/null || echo ?)"
case "$arch" in
  x86_64|amd64|aarch64|arm64) echo "  Arquitetura: ${arch} (suportada)." ;;
  *) echo "  AVISO: arquitetura ${arch} não testada — pode falhar." >&2 ;;
esac

if ! command -v curl >/dev/null 2>&1; then
  echo "FATAL: 'curl' não encontrado. Instale-o e rode de novo:" >&2
  echo "       Debian/Ubuntu: sudo apt-get update && sudo apt-get install -y curl" >&2
  exit 1
fi

# Disco: stack + imagens + 1ª janela de backup. Mínimo prático ~10 GB livres.
avail_kb="$(df -Pk "$DOCKER_DIR" 2>/dev/null | awk 'NR==2 {print $4}')"
if [ -n "${avail_kb:-}" ]; then
  avail_gb=$((avail_kb / 1024 / 1024))
  if [ "$avail_kb" -lt 10485760 ]; then
    echo "FATAL: só ${avail_gb} GB livres (mínimo recomendado: 10 GB)." >&2
    echo "       Libere espaço ou use um servidor maior." >&2
    exit 1
  fi
  echo "  Disco livre: ${avail_gb} GB (OK)."
fi

# RAM: aviso (não fatal) abaixo de ~3,5 GB.
mem_kb="$(awk '/MemTotal/ {print $2}' /proc/meminfo 2>/dev/null || echo 0)"
if [ "${mem_kb:-0}" -gt 0 ]; then
  mem_gb=$((mem_kb / 1024 / 1024))
  if [ "$mem_kb" -lt 3670016 ]; then
    echo "  AVISO: RAM total ~${mem_gb} GB. Recomendado ≥ 4 GB; abaixo disso a" >&2
    echo "         stack roda mas pode ficar lenta sob carga." >&2
  else
    echo "  RAM: ~${mem_gb} GB (OK)."
  fi
fi

# sudo disponível? (necessário p/ instalar Docker; não p/ rodar como root).
SUDO=""
if [ "$(id -u)" -ne 0 ]; then
  if command -v sudo >/dev/null 2>&1; then SUDO="sudo"; fi
fi
echo "  Pré-checagem OK."

# =============================================================================
# 2/7 — Docker
# =============================================================================
step "2/7 Verificando / instalando o Docker"

docker_ok() { ${1:+$1 }docker info >/dev/null 2>&1; }

if [ "$SKIP_DOCKER" -eq 1 ]; then
  echo "  --pular-docker: assumindo Docker já instalado e no ar."
  if ! docker_ok "" && ! docker_ok "$SUDO"; then
    echo "FATAL: --pular-docker foi usado mas o Docker não responde." >&2
    exit 1
  fi
elif docker_ok ""; then
  echo "  Docker já instalado e acessível (sem sudo). $(docker --version 2>/dev/null)"
elif docker_ok "$SUDO"; then
  echo "  Docker já instalado (acessível via sudo)."
else
  echo "  Docker não encontrado — vou instalar a versão oficial."
  confirmar "Vou instalar o Docker Engine neste servidor (script oficial get.docker.com)."
  if [ "$DRY_RUN" -eq 0 ]; then
    run curl -fsSL https://get.docker.com -o /tmp/get-docker.sh
    run $SUDO sh /tmp/get-docker.sh
    run $SUDO systemctl enable --now docker 2>/dev/null || true
    # Permite rodar docker sem sudo nos próximos logins (não afeta esta sessão).
    if [ -n "${SUDO}" ] && [ -n "${USER:-}" ]; then
      run $SUDO usermod -aG docker "$USER" 2>/dev/null || true
      echo "  (Usuário '${USER}' adicionado ao grupo docker — vale no próximo login.)"
    fi
  fi
fi

# Define se os comandos docker desta sessão precisam de sudo.
if docker_ok ""; then
  DC_SUDO=""
elif docker_ok "$SUDO"; then
  DC_SUDO="$SUDO"
  echo "  Usando 'sudo docker' nesta sessão (grupo docker só vale no próximo login)."
elif [ "$DRY_RUN" -eq 1 ]; then
  DC_SUDO=""
else
  echo "FATAL: Docker instalado mas não respondeu. Reinicie a sessão (logout/login)" >&2
  echo "       ou o servidor, e rode ./install.sh de novo." >&2
  exit 1
fi

# `docker compose` (plugin v2) tem que existir.
if [ "$DRY_RUN" -eq 0 ] && ! dc_q version >/dev/null 2>&1; then
  echo "FATAL: 'docker compose' (plugin v2) não disponível. O get.docker.com" >&2
  echo "       oficial já o inclui — verifique a instalação do Docker." >&2
  exit 1
fi

# =============================================================================
# 3/7 — Configuração (.env + credenciais)
# =============================================================================
step "3/7 Configuração e geração de senhas"

MODO_RETOMADA=0
if [ -f "${DOCKER_DIR}/.env" ]; then
  MODO_RETOMADA=1
  echo "  docker/.env já existe → MODO RETOMADA: não regero senhas nem"
  echo "  sobrescrevo nada. Só garanto a stack no ar e os módulos instalados."
  # shellcheck disable=SC1091
  . "${DOCKER_DIR}/.env"
else
  if [ ! -f "${DOCKER_DIR}/.env.example" ]; then
    echo "FATAL: docker/.env.example não encontrado — repositório incompleto." >&2
    exit 1
  fi

  # Domínio: argumento, ou pergunta (default localhost), ou localhost.
  if [ -z "$DOMINIO" ]; then
    if [ -t 0 ] && [ "$ASSUME_YES" -eq 0 ] && [ "$DRY_RUN" -eq 0 ]; then
      echo ""
      echo "  Domínio de acesso ao sistema."
      echo "    • Deixe VAZIO para teste local  → https://localhost"
      echo "    • Ou digite o domínio real (ex.: crm.seuescritorio.adv.br)"
      printf "  Domínio [localhost]: "
      read -r DOMINIO || true
    fi
    [ -z "$DOMINIO" ] && DOMINIO="localhost"
  fi
  # Validação com regex nativo do bash ([[ =~ ]]) — sem pipe, evita o mesmo
  # foot-gun de SIGPIPE+pipefail de `printf | grep -q`.
  if ! [[ "$DOMINIO" =~ ^[A-Za-z0-9.-]+$ ]]; then
    echo "FATAL: domínio inválido: '${DOMINIO}' (use só letras, números, . e -)." >&2
    exit 1
  fi

  # E-mail só importa se há domínio real (Let's Encrypt). localhost usa CA
  # interna do Caddy (certificado auto-assinado) e não precisa de e-mail.
  if [ "$DOMINIO" != "localhost" ]; then
    if [ -z "$EMAIL" ]; then
      if [ -t 0 ] && [ "$ASSUME_YES" -eq 0 ] && [ "$DRY_RUN" -eq 0 ]; then
        printf "  E-mail para o certificado HTTPS (Let's Encrypt): "
        read -r EMAIL || true
      fi
    fi
    if ! [[ "$EMAIL" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]]; then
      echo "FATAL: com domínio real é obrigatório um e-mail válido para o" >&2
      echo "       certificado HTTPS. Use: --email voce@seudominio.adv.br" >&2
      exit 1
    fi
  fi

  echo "  Criando docker/.env a partir do modelo..."
  run cp "${DOCKER_DIR}/.env.example" "${DOCKER_DIR}/.env"

  if [ "$DRY_RUN" -eq 0 ]; then
    echo "  Gerando senhas fortes (24 caracteres, alfanuméricas)..."
    declare -A GERADAS=()
    for k in "${SEGREDOS[@]}"; do
      v="$(gen_pwd)"; GERADAS["$k"]="$v"; set_env "$k" "$v"
    done
    set_env TOGARE_DOMAIN "$DOMINIO"
    [ -n "$EMAIL" ] && set_env CADDY_TLS_EMAIL "$EMAIL"
    # Admin do CRM e do Nextcloud (logins; senhas já geradas acima).
    set_env ESPOCRM_ADMIN_USERNAME admin
    set_env NEXTCLOUD_ADMIN_USER admin

    # Arquivo de credenciais legível pelo Sócio (NÃO versionado; chmod 600).
    cred="${DOCKER_DIR}/CREDENCIAIS-TOGARE.txt"
    proto_host="https://${DOMINIO}"
    {
      echo "==================================================================="
      echo " CREDENCIAIS TOGARE — gerado em $(ts)"
      echo "==================================================================="
      echo ""
      echo " GUARDE ESTE ARQUIVO FORA DO SERVIDOR (gerenciador de senhas do"
      echo " Sócio). Quem tiver ele acessa tudo. Ele NÃO vai para o Git."
      echo ""
      echo " ACESSO AO CRM (EspoCRM) ........ ${proto_host}/"
      echo "   usuário ...................... admin"
      echo "   senha ........................ ${GERADAS[ESPOCRM_ADMIN_PASSWORD]}"
      echo ""
      echo " ACESSO AOS ARQUIVOS (Nextcloud)  ${proto_host%/}/  (via app/abas do CRM)"
      echo "   usuário ...................... admin"
      echo "   senha ........................ ${GERADAS[NEXTCLOUD_ADMIN_PASSWORD]}"
      echo ""
      echo " SENHA DO BACKUP (restic) — CRÍTICA"
      echo "   ${GERADAS[RESTIC_PASSWORD]}"
      echo "   SEM esta senha é IMPOSSÍVEL restaurar um backup. Copie-a para"
      echo "   um lugar seguro AGORA, separado do servidor."
      echo ""
      echo " Senhas internas (banco/redis) ficam em docker/.env. Você não"
      echo " precisa delas no dia a dia, mas não apague o .env."
      echo "==================================================================="
    } > "$cred"
    chmod 600 "$cred"
    echo "  Credenciais salvas em: ${cred}  (permissão 600)"
  else
    echo "[DRY-RUN] (geraria 7 senhas + TOGARE_DOMAIN=${DOMINIO} + CREDENCIAIS-TOGARE.txt)"
  fi
  # shellcheck disable=SC1091
  [ "$DRY_RUN" -eq 0 ] && . "${DOCKER_DIR}/.env"
fi

# =============================================================================
# 4/7 — Subir a stack
# =============================================================================
step "4/7 Baixando imagens e subindo a stack"

echo "  Versões pinadas: MariaDB=${MARIADB_VERSION:-?} EspoCRM=${ESPOCRM_VERSION:-?} \
Nextcloud=${NEXTCLOUD_VERSION:-?} Postgres=${POSTGRES_VERSION:-?} \
Redis=${REDIS_VERSION:-?} Caddy=${CADDY_VERSION:-?}"
echo "  Baixando imagens (pode levar alguns minutos na primeira vez)..."
if ! retry 4 10 dc pull; then
  echo "FATAL: não consegui baixar as imagens após várias tentativas." >&2
  echo "       Quase sempre é a conexão de internet do servidor. Verifique" >&2
  echo "       a rede e rode ./instalar.sh de novo — ele continua de onde" >&2
  echo "       parou (não regera senhas; reaproveita o que já baixou)." >&2
  exit 1
fi
# --build: togare-backup é imagem `build:` (pull a ignora). Também sob retry —
# o build/criação puxa camadas e pode tropeçar na mesma instabilidade de rede.
if ! retry 3 10 dc up -d --build; then
  echo "FATAL: não consegui subir a stack após várias tentativas." >&2
  echo "       Verifique a rede/disco e rode ./instalar.sh de novo." >&2
  exit 1
fi

wait_healthy() {
  local svc="$1" i status
  echo "  Aguardando '$svc' ficar saudável..."
  for i in $(seq 1 80); do
    status="$(dc_q ps "$svc" --format json 2>/dev/null | json_compose_health || true)"
    [ "$status" = "healthy" ] && { echo "    $svc OK."; return 0; }
    sleep 3
  done
  echo "FATAL: '$svc' não ficou saudável a tempo. Veja: docker compose logs $svc" >&2
  return 1
}

if [ "$DRY_RUN" -eq 0 ]; then
  wait_healthy mariadb
  wait_healthy postgres
  wait_healthy redis
  wait_healthy espocrm
  wait_healthy nextcloud
else
  echo "[DRY-RUN] (esperaria mariadb/postgres/redis/espocrm/nextcloud healthy)"
fi

# =============================================================================
# 4.5 — Garantir os zips dos módulos (auto-build em container se faltarem)
# =============================================================================
# Por que existe: o `.gitignore` de cada espocrm/togare-*/ exclui build/, então
# clones frescos do repo NÃO têm os zips. Em vez de exigir Node/PHP/Composer
# no host (contraria o desenho one-shot), buildamos em CONTAINER isolado via
# docker/scripts/build-modulos.sh (image togare-builder), gerando os zips
# para `espocrm/togare-*/build/`. Idempotente: pula módulo com zip já
# presente na versão atual; --rebuild-modules força rebuild.
step "4.5/7 Verificando os zips dos módulos Togare"

modulos_faltantes=""
for m in "${MODULOS[@]}"; do
  pkg="${REPO_DIR}/espocrm/${m}/package.json"
  if [ ! -f "$pkg" ]; then
    echo "FATAL: ${pkg} não encontrado (módulo ${m} ausente do repo)." >&2
    exit 1
  fi
  ver="$(json_file_get "$pkg" version)"
  zip="${REPO_DIR}/espocrm/${m}/build/${m}-${ver}.zip"
  if [ ! -f "$zip" ]; then
    modulos_faltantes="${modulos_faltantes} ${m}-${ver}"
  fi
done

if [ "$REBUILD_MODULES" -eq 1 ] || [ -n "${modulos_faltantes// /}" ]; then
  if [ "$DRY_RUN" -eq 1 ]; then
    echo "[DRY-RUN] rodaria docker/scripts/build-modulos.sh$( [ $REBUILD_MODULES -eq 1 ] && echo ' --force')"
  else
    if [ -n "${modulos_faltantes// /}" ]; then
      echo "  Zips ausentes:${modulos_faltantes}"
      echo "  Buildando em container isolado (Node+PHP+Composer em togare-builder)..."
    else
      echo "  --rebuild-modules ativo. Forçando rebuild dos 6 zips."
    fi
    build_args=""
    [ "$REBUILD_MODULES" -eq 1 ] && build_args="--force"
    if ! bash "${SCRIPT_DIR}/build-modulos.sh" ${build_args}; then
      echo "FATAL: build-modulos.sh falhou. Veja a saída acima." >&2
      echo "       Diagnóstico: bash docker/scripts/build-modulos.sh" >&2
      exit 1
    fi
  fi
else
  echo "  ✓ Os 6 zips já existem (espocrm/togare-*/build/*.zip)."
fi

# =============================================================================
# 5/7 — Instalar os módulos Togare (ordem de dependência, idempotente)
# =============================================================================
step "5/7 Instalando os 6 módulos Togare"

instalar_modulo() {
  local m="$1" idx="$2" total="$3"
  local pkg="${REPO_DIR}/espocrm/${m}/package.json"
  local extf="${REPO_DIR}/espocrm/${m}/extension.json"
  [ -f "$pkg" ]  || { echo "FATAL: ${pkg} não encontrado." >&2; return 1; }
  [ -f "$extf" ] || { echo "FATAL: ${extf} não encontrado." >&2; return 1; }
  local ver mod zip base
  ver="$(json_file_get "$pkg" version)"
  mod="$(json_file_get "$extf" module)"
  zip="${REPO_DIR}/espocrm/${m}/build/${m}-${ver}.zip"
  base="$(basename "$zip")"
  if [ ! -f "$zip" ]; then
    echo "FATAL: pacote do módulo não encontrado: ${zip}" >&2
    echo "       (esperado já versionado no repositório)." >&2
    return 1
  fi
  echo "  [${idx}/${total}] ${m} v${ver} → módulo ${mod}"
  if [ "$DRY_RUN" -eq 1 ]; then
    echo "  [DRY-RUN] copiaria ${base} e rodaria command.php extension --file=/tmp/${base}"
    return 0
  fi
  dc_q cp "$zip" "espocrm:/tmp/${base}"
  # extension --file é idempotente (AfterInstall de cada módulo é rerun-safe;
  # ver project_status_implementacao / smokes "reinstalação idempotente").
  dc_q exec -T espocrm php command.php extension --file="/tmp/${base}"
  dc_q exec -T espocrm rm -f "/tmp/${base}" 2>/dev/null || true
}

i=0
for m in "${MODULOS[@]}"; do
  i=$((i + 1))
  instalar_modulo "$m" "$i" "${#MODULOS[@]}"
done

step "    Reconstruindo o EspoCRM (rebuild + limpar cache)"
dc exec -T espocrm php command.php rebuild
dc exec -T espocrm php command.php clear-cache
echo "  (togare-portal-ui NÃO instalado — Portal é Growth, congelado.)"

# Story 4a.1 — togare-djen-worker pode ter ficado em crash-loop até este
# momento (sobe junto com a stack ANTES do extension install do togare-djen).
# Restart explícito zera o backoff de restart do Docker e faz o worker subir
# limpo agora que /var/www/html/custom/Espo/Modules/TogareDjen/scripts/
# queue-worker.php existe no volume `espocrm_data`. Sem isso, o passo 6a
# pode pegar o worker ainda em `state=restarting` e levantar falso positivo.
if [ "$DRY_RUN" -eq 0 ]; then
  echo "    Reiniciando togare-djen-worker (limpa backoff pós-install)"
  dc restart togare-djen-worker >/dev/null 2>&1 || true
fi

# =============================================================================
# 6/7 — Validações de saúde
# =============================================================================
step "6/7 Validando a instalação"

if [ "$DRY_RUN" -eq 0 ]; then
  # 6a. Saúde dos serviços — regra correta para o PÓS-INSTALAÇÃO.
  #
  # NÃO usar json_compose_unhealthy (lib-json, compartilhado com update.sh/
  # restore.sh): ele reprova qualquer Health != "healthy", incluindo
  # "starting". Mas "starting" = container RODANDO dentro do start_period do
  # healthcheck, não é falha. Em especial `togare-backup` tem
  # `start_period: 25h` no compose — ele fica "starting" por design até o
  # 1º backup rodar; ele JAMAIS está "healthy" logo após instalar. Reprovar
  # a instalação por isso era um bug (achado rodando de verdade).
  #
  # Regra: FALHA só se algum serviço está com State != running OU
  # Health == unhealthy. "starting" ganha carência (re-checagem); o
  # togare-backup é OK enquanto estiver "running" (starting esperado).
  #
  # togare-djen-worker em `restarting` também é OK durante install fresco:
  # o container sobe junto com a stack ANTES do extension install do
  # togare-djen (que só roda no passo 5). Até lá, queue-worker.php ainda
  # não existe no volume `espocrm_data`, o entrypoint sai com "Could not
  # open input file" e Docker reagenda restart. No passo 5 nós forçamos
  # `restart togare-djen-worker` para zerar o backoff e ele sobe limpo.
  # Damos a mesma carência do "starting" — falha real só se persistir
  # restarting por mais de ~3min.
  health_scan() {  # ecoa: "FAILED:<svc...>|PENDING:<svc...>"
    local failed="" pending="" line svc state health
    while IFS='|' read -r svc state health; do
      [ -z "$svc" ] && continue
      if [ "$svc" = "togare-djen-worker" ] && [ "$state" = "restarting" ]; then
        pending="${pending} ${svc}"
      elif [ "$state" != "running" ] || [ "$health" = "unhealthy" ]; then
        failed="${failed} ${svc}"
      elif [ "$health" = "starting" ] && [ "$svc" != "togare-backup" ]; then
        pending="${pending} ${svc}"
      fi
    done < <(dc_q ps --format '{{.Service}}|{{.State}}|{{.Health}}' 2>/dev/null)
    echo "FAILED:${failed# }|PENDING:${pending# }"
  }
  hs=""
  for _try in $(seq 1 18); do          # até ~3 min de carência p/ "starting"
    hs="$(health_scan)"
    hs_failed="${hs#FAILED:}"; hs_failed="${hs_failed%%|PENDING:*}"
    hs_pending="${hs##*|PENDING:}"
    if [ -n "${hs_failed// /}" ]; then
      echo "FATAL: serviço(s) em estado ruim (parado/unhealthy): ${hs_failed}" >&2
      echo "       Diagnóstico: docker compose logs ${hs_failed}" >&2
      exit 1
    fi
    [ -z "${hs_pending// /}" ] && break
    echo "  aguardando serviço(s) ainda iniciando: ${hs_pending} ..."
    sleep 10
  done
  if [ -n "${hs_pending// /}" ]; then
    echo "FATAL: serviço(s) não saíram de 'iniciando' a tempo: ${hs_pending}" >&2
    echo "       Diagnóstico: docker compose logs ${hs_pending}" >&2
    exit 1
  fi
  echo "  ✓ Serviços essenciais saudáveis (togare-backup fica 'iniciando'"
  echo "    até o 1º backup rodar — isso é normal e esperado)."

  # 6b. EspoCRM responde via Caddy (HTTPS). -k aceita o cert interno do
  #     localhost; em domínio real o Let's Encrypt já é válido.
  #
  # RETRY obrigatório: logo após o `clear-cache` do passo 5, a 1ª requisição
  # web do EspoCRM reconstrói `data/cache/application` e pode devolver 500
  # transitório por alguns segundos (cache frio) antes de estabilizar em 200.
  # Um curl único pega justamente essa janela e reprova a instalação por
  # engano (achado rodando de verdade). Tenta por até ~3 min.
  code="000"
  for _try in $(seq 1 18); do
    code="$(curl -k -s -o /dev/null -w '%{http_code}' "https://${TOGARE_DOMAIN:-localhost}/" 2>/dev/null || true)"
    case "${code:-000}" in
      200|302) break ;;
    esac
    echo "  CRM ainda aquecendo (HTTP ${code:-000}); nova checagem em 10s..."
    sleep 10
  done
  case "${code:-000}" in
    200|302) echo "  ✓ CRM responde em https://${TOGARE_DOMAIN:-localhost}/ (HTTP ${code})." ;;
    *) echo "FATAL: CRM não respondeu como esperado após ~3 min (HTTP ${code:-000}; esperado 200/302)." >&2
       echo "       Veja: docker compose logs caddy espocrm" >&2
       exit 1 ;;
  esac

  # 6c. Nextcloud vivo.
  if dc_q exec -T nextcloud sh -c 'curl -fsS http://localhost/status.php >/dev/null' 2>/dev/null; then
    echo "  ✓ Nextcloud (arquivos) respondendo."
  else
    echo "  AVISO: status.php do Nextcloud não confirmou agora (pode estar" >&2
    echo "         terminando de iniciar). Cheque depois: docker compose ps" >&2
  fi

  # 6d. Os 6 módulos foram realmente instalados (diretório no container).
  faltando=""
  for m in "${MODULOS[@]}"; do
    mod="$(json_file_get "${REPO_DIR}/espocrm/${m}/extension.json" module)"
    if ! dc_q exec -T espocrm sh -c "[ -d /var/www/html/custom/Espo/Modules/${mod} ]" 2>/dev/null; then
      faltando="${faltando} ${mod}"
    fi
  done
  if [ -n "${faltando// /}" ]; then
    echo "FATAL: módulos não encontrados após instalação:${faltando}" >&2
    exit 1
  fi
  echo "  ✓ Os 6 módulos Togare estão instalados."

  # 6e. Validação de segurança do ambiente (Action Item A2 da retro Épico 10).
  #     NÃO-BLOQUEANTE por design: o instalador segue mesmo se algum check der
  #     NOK (HSTS pode estar propagando, backup pode demorar o 1º run etc.).
  #     O resumo no fim já chama a atenção pros 3 manuais residuais.
  step "    Validando segurança do ambiente (gate A2 — não-bloqueante)"
  if ! bash "${SCRIPT_DIR}/validar-seguranca.sh"; then
    echo "  (validar-seguranca.sh retornou erro; instalação não trava por isso." >&2
    echo "   Reveja a saída acima — o relatório com ✓/✗ por item é o oficial.)" >&2
  fi
else
  echo "[DRY-RUN] (validaria: serviços healthy + HTTPS 200/302 + Nextcloud + 6 módulos + gate A2)"
fi

# =============================================================================
# 7/7 — Resumo final
# =============================================================================
trap - ERR
host="https://${TOGARE_DOMAIN:-localhost}"
step "7/7 Concluído"
echo ""
echo "════════════════════════════════════════════════════════════════════"
echo " ✓ TOGARE INSTALADO E VALIDADO"
echo "════════════════════════════════════════════════════════════════════"
echo ""
echo "  Acesse o CRM:        ${host}/"
echo "  Usuário admin:       admin"
if [ "$MODO_RETOMADA" -eq 1 ]; then
  echo "  Senha admin:         (a que já estava no docker/.env / seu arquivo de credenciais)"
else
  echo "  Senha admin + tudo:  docker/CREDENCIAIS-TOGARE.txt"
  echo ""
  echo "  ⚠ AÇÃO IMPORTANTE: abra docker/CREDENCIAIS-TOGARE.txt, copie a"
  echo "    SENHA DO BACKUP (restic) para um lugar seguro FORA do servidor."
  echo "    Sem ela, um backup não pode ser restaurado."
fi
echo ""
if [ "${TOGARE_DOMAIN:-localhost}" = "localhost" ]; then
  echo "  Modo LOCAL: o navegador vai avisar do certificado (normal em teste"
  echo "  local — é a CA interna do Caddy). Para uso real, reinstale com"
  echo "  --dominio e --email, ou ajuste docker/.env e rode update.sh."
else
  echo "  Domínio real: confirme que o DNS de ${TOGARE_DOMAIN} aponta para o"
  echo "  IP deste servidor e que as portas 80 e 443 estão liberadas no"
  echo "  firewall — o HTTPS (Let's Encrypt) depende disso."
fi
echo ""
echo "  Próximos passos recomendados (segurança antes de dados reais):"
echo "    • Os 5 checks automáticos do A2 já rodaram acima (gate A2)."
echo "    • Faltam 3 PASSOS MANUAIS impressos pelo gate — releia a seção"
echo "      \"PASSOS MANUAIS RESIDUAIS\" mais acima: bloqueio de login,"
echo "      sessão de 31 min e cron do backup das 02:00 (no dia seguinte)."
echo "    • Re-rodar a qualquer momento: ./scripts/validar-seguranca.sh"
echo "    • Manter docker/CREDENCIAIS-TOGARE.txt só com o Sócio."
echo ""
echo "  Comandos do dia a dia:"
echo "    Atualizar o sistema ...... ./scripts/update.sh"
echo "    Restaurar um backup ...... ./scripts/restore.sh --latest"
echo "    Ver se está tudo no ar ... docker compose ps"
echo ""
echo "  Log desta instalação: ${LOG_FILE}"
echo "════════════════════════════════════════════════════════════════════"
