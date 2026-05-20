#!/usr/bin/env bash
# docker/scripts/build-modulos.sh — gera os 6 zips de extensão Togare.
#
# Por quê: o `.gitignore` de cada togare-*/ exclui `build/`, então os zips
# pré-empacotados que o install.sh consome NÃO viajam pelo git. Em vez de
# exigir Node/PHP/Composer no servidor (o install.sh é one-shot para
# escritório de advocacia SEM toolchain), buildamos os 6 zips em container
# isolado via `docker/scripts/build-modulos.Dockerfile`.
#
# Chamado por:
#   - install.sh (passo 4.5, automático quando algum zip está faltando)
#   - manualmente: `bash docker/scripts/build-modulos.sh`
#   - manualmente forçando rebuild: `bash docker/scripts/build-modulos.sh --force`
#
# Idempotente: por default, pula módulo se o zip já existe na versão
# declarada do package.json. `--force` rebuilda todos.
#
# Exit code: 0 ok, 1 se algum build/empacotamento falhar.

set -euo pipefail

readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly REPO_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

# Windows + MSYS/Git Bash converte argumentos `/work` (do `docker run -w`) em
# `C:\Program Files\Git\work` e quebra o WORKDIR do container Linux. A flag
# MSYS_NO_PATHCONV=1 é necessária — mas SÓ no `docker run`, NÃO no
# `docker build` (que precisa do caminho do contexto convertido p/ Windows).
# Por isso aplicamos inline em cada `docker run`, em vez de exportar global.
# Inócuo no Linux (a flag é no-op fora de MSYS).

# Mesma lista do install.sh (ordem de dependência: core → licensing → rbac
# → tpu → nextcloud-bridge → djen). togare-portal-ui NÃO entra: Épico 7a
# congelado (Growth).
readonly MODULOS=(togare-core togare-licensing togare-rbac togare-tpu togare-nextcloud-bridge togare-djen)

readonly BUILDER_IMAGE="togare-builder"
readonly BUILDER_DOCKERFILE="${SCRIPT_DIR}/build-modulos.Dockerfile"
# Volume Docker nomeado: cache do ~/.npm entre runs E entre módulos. Primeiro
# pull popula; runs seguintes reusam tarballs locais. Reduz drasticamente
# dependência de rede instável (achado validando install no Linux do Felipe
# 2026-05-20: npm ci ETIMEDOUT no togare-licensing).
readonly NPM_CACHE_VOLUME="togare_builder_npm_cache"

# Limites para tolerância de rede ruim. npm default é 60s/2 retries; aqui:
# 5min por fetch, 5 retries com backoff exponencial 20s→120s.
readonly NPM_FETCH_TIMEOUT_MS="300000"
readonly NPM_FETCH_RETRIES="5"
readonly NPM_FETCH_RETRY_MINTIMEOUT_MS="20000"
readonly NPM_FETCH_RETRY_MAXTIMEOUT_MS="120000"

# Cada módulo pode falhar até MAX_BUILD_RETRIES vezes antes do script abortar.
# Entre tentativas: backoff de 30s × tentativa.
readonly MAX_BUILD_RETRIES=3

FORCE=0
while [ $# -gt 0 ]; do
  case "$1" in
    --force) FORCE=1; shift ;;
    -h|--help)
      sed -n '2,21p' "$0" | sed 's/^# \{0,1\}//'
      exit 0 ;;
    *) echo "Argumento desconhecido: $1" >&2; exit 2 ;;
  esac
done

# Lê version do package.json sem precisar de jq/node no host.
ver_de() {
  awk -F'"' '/"version"[[:space:]]*:/{print $4; exit}' "$1"
}

step() { echo ""; echo "→ $*"; }

# -----------------------------------------------------------------------------
# 1. Imagem builder (só rebuilda se o Dockerfile mudou).
# -----------------------------------------------------------------------------
step "Builder image: ${BUILDER_IMAGE}"
docker build -f "${BUILDER_DOCKERFILE}" -t "${BUILDER_IMAGE}" "${SCRIPT_DIR}" >/dev/null
echo "  ✓ ${BUILDER_IMAGE} pronto"

# -----------------------------------------------------------------------------
# 2. Buildar cada módulo.
# -----------------------------------------------------------------------------
faltam=()
ja_existem=()
gerados=()

for m in "${MODULOS[@]}"; do
  pkg="${REPO_DIR}/espocrm/${m}/package.json"
  if [ ! -f "$pkg" ]; then
    echo "  ✗ ${m}: package.json não encontrado em ${pkg}" >&2
    exit 1
  fi

  ver="$(ver_de "$pkg")"
  if [ -z "$ver" ]; then
    echo "  ✗ ${m}: não consegui extrair version de ${pkg}" >&2
    exit 1
  fi

  zip="${REPO_DIR}/espocrm/${m}/build/${m}-${ver}.zip"

  if [ "${FORCE}" -eq 0 ] && [ -f "${zip}" ]; then
    ja_existem+=("${m}-${ver}")
    continue
  fi

  step "[${m}] build v${ver}"
  # Volume monta o REPO inteiro como /work; WORKDIR no módulo específico.
  # Volume `togare_builder_npm_cache` em /root/.npm cacheia tarballs do
  # registry entre runs (mitigação primária pra ETIMEDOUT em rede instável).
  # npm_config_* desliga audit/fund e estende timeouts/retries (mitigação
  # secundária — npm default 60s/2 retries é apertado pra link doméstico).
  # Composer fica disponível no builder caso algum módulo passe a precisar.
  build_attempt=0
  while true; do
    build_attempt=$((build_attempt + 1))
    if MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*' docker run --rm \
        -v "${REPO_DIR}:/work" \
        -v "${NPM_CACHE_VOLUME}:/root/.npm" \
        -w "/work/espocrm/${m}" \
        -e npm_config_audit=false \
        -e npm_config_fund=false \
        -e npm_config_fetch_timeout="${NPM_FETCH_TIMEOUT_MS}" \
        -e npm_config_fetch_retries="${NPM_FETCH_RETRIES}" \
        -e npm_config_fetch_retry_mintimeout="${NPM_FETCH_RETRY_MINTIMEOUT_MS}" \
        -e npm_config_fetch_retry_maxtimeout="${NPM_FETCH_RETRY_MAXTIMEOUT_MS}" \
        "${BUILDER_IMAGE}" \
        bash -lc '
          set -e
          if [ -f package-lock.json ]; then
            npm ci --no-audit --no-fund
          else
            npm install --no-audit --no-fund
          fi
          npm run build
        '; then
      break
    fi

    if [ "${build_attempt}" -ge "${MAX_BUILD_RETRIES}" ]; then
      echo "  ✗ ${m}: build falhou após ${MAX_BUILD_RETRIES} tentativas." >&2
      echo "       Provável: rede instável (npm registry inalcançável)." >&2
      echo "       Reproduzir manualmente: bash docker/scripts/build-modulos.sh" >&2
      echo "       (idempotente — pula módulos com zip já gerado)" >&2
      exit 1
    fi

    espera=$(( 30 * build_attempt ))
    echo "  ⚠ ${m}: tentativa ${build_attempt}/${MAX_BUILD_RETRIES} falhou (rede?). Nova tentativa em ${espera}s..." >&2
    sleep "${espera}"
  done

  if [ ! -f "${zip}" ]; then
    echo "  ✗ ${m}: zip não foi gerado em ${zip}" >&2
    exit 1
  fi

  gerados+=("${m}-${ver}")
  echo "  ✓ ${zip} (${m}-${ver})"
done

# -----------------------------------------------------------------------------
# 3. Resumo.
# -----------------------------------------------------------------------------
echo ""
echo "════════════════════════════════════════════════════════════════════"
echo " Build de módulos Togare"
echo "════════════════════════════════════════════════════════════════════"
echo "  Gerados agora .... ${#gerados[@]}  ${gerados[*]:-(nenhum)}"
echo "  Já existiam ...... ${#ja_existem[@]}  ${ja_existem[*]:-(nenhum)}"
echo "  Total ............ ${#MODULOS[@]}"
echo "════════════════════════════════════════════════════════════════════"
