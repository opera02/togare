#!/usr/bin/env bash
# Spike 1b.S2 — Fase 2 (bench VPS, Epic 10).
#
# Captura N publicações reais do DJEN via Comunica API pública (API CNJ, sem
# autenticação necessária). As publicações viram fixtures para o bench de
# 50 advogados × payload DJEN real na VPS baseline.
#
# NÃO EXECUTAR NA FASE 1 — o schema da Comunica API pode mudar entre abril
# e a execução do Epic 10. Rodar este script no momento de preparar a VPS.
#
# Uso:
#   ./capture-djen-payloads.sh [--count=50] [--tribunal=TJSP]
#
# Default: captura 50 publicações do TJSP dos últimos 7 dias.
# Saída: docker/spike-1b-S2/fixtures/djen-payloads/{1..N}.json

set -euo pipefail

COUNT="${COUNT:-50}"
TRIBUNAL="${TRIBUNAL:-TJSP}"
DAYS_BACK="${DAYS_BACK:-7}"

# Parse args estilo --key=value
for arg in "$@"; do
  case $arg in
    --count=*)    COUNT="${arg#*=}" ;;
    --tribunal=*) TRIBUNAL="${arg#*=}" ;;
    --days-back=*) DAYS_BACK="${arg#*=}" ;;
    *) echo "unknown arg: $arg" >&2; exit 2 ;;
  esac
done

BASE_URL="https://comunicaapi.pje.jus.br/api/v1/comunicacao"
DATE_INI=$(date -u -d "${DAYS_BACK} days ago" '+%Y-%m-%d')
DATE_FIM=$(date -u '+%Y-%m-%d')

OUT_DIR="$(dirname "$0")/djen-payloads"
mkdir -p "$OUT_DIR"

echo "[capture] Capturando ${COUNT} publicações do ${TRIBUNAL} entre ${DATE_INI} e ${DATE_FIM}…"

# Paginação: API retorna N por página; acumulamos até COUNT.
page=1
captured=0
while [ "$captured" -lt "$COUNT" ]; do
  resp=$(curl -sS --fail --max-time 30 \
    "${BASE_URL}?siglaTribunal=${TRIBUNAL}&dataDisponibilizacaoInicio=${DATE_INI}&dataDisponibilizacaoFim=${DATE_FIM}&pagina=${page}&itensPorPagina=50" \
    || { echo "[capture] curl falhou na pagina ${page}" >&2; exit 1; })

  # Extrai items do array `items` (ajustar conforme schema real da API).
  # TODO Fase 2: verificar schema atual no site oficial antes de rodar.
  n_items=$(echo "$resp" | jq '.items | length')

  if [ "$n_items" -eq 0 ]; then
    echo "[capture] página ${page} vazia — parando"
    break
  fi

  for i in $(seq 0 $((n_items - 1))); do
    if [ "$captured" -ge "$COUNT" ]; then break; fi
    captured=$((captured + 1))
    echo "$resp" | jq ".items[${i}]" > "${OUT_DIR}/${captured}.json"
  done

  page=$((page + 1))
done

if [ "$captured" -eq 0 ]; then
  echo "[capture] ERRO: zero publicações capturadas — verifique os parâmetros e a disponibilidade da API." >&2
  exit 2
fi

echo "[capture] OK — ${captured} publicações salvas em ${OUT_DIR}/"
