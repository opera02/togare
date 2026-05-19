#!/usr/bin/env bash
# Smoke test do validate-togare-naming.php.
#
# Cobre 4 cenários (classe ok/bad, migration ok/bad). Cria um sandbox
# dentro do repo simulando `espocrm/togare-smoke-sandbox/` e roda o validator
# contra cada fixture; limpa no final.
#
# Requisitos: bash + (PHP host OU Docker 24+).
# Uso: bash tools/tests/run.sh
# Exit 0 = todos passaram. Exit 1 = alguma divergência.

set -u
cd "$(dirname "$0")/../.." # → repo root

FIXTURES_DIR="tools/tests/fixtures-validate-togare-naming"
VALIDATOR="tools/validate-togare-naming.php"
SANDBOX="espocrm/togare-smoke-sandbox"

# Função runner: PHP host se disponível, senão Docker.
docker_pwd() {
  if command -v cygpath >/dev/null 2>&1; then
    cygpath -w "$(pwd)" | tr '\\' '/'
  else
    pwd
  fi
}

if command -v php >/dev/null 2>&1; then
  php_run() { php "$@"; }
elif command -v docker >/dev/null 2>&1; then
  php_run() {
    local host_pwd
    host_pwd="$(docker_pwd)"
    MSYS_NO_PATHCONV=1 docker run --rm -v "${host_pwd}:/app" -w /app php:8.3-cli php "$@"
  }
else
  echo "❌ Nem PHP nem Docker disponíveis — não consigo rodar os smoke tests."
  exit 2
fi

fails=0
declare -a results=()

cleanup() {
  rm -rf "$SANDBOX"
}
trap cleanup EXIT

run_case() {
  local name="$1"
  local fixture="$2"    # nome do arquivo em $FIXTURES_DIR
  local expected="$3"   # exit code esperado

  # Resetar sandbox.
  rm -rf "$SANDBOX"

  # Escolher subpath conforme o tipo de fixture. Migrations seguem convenção
  # V<N>__<descricao>.php e vivem em Migration/; demais classes em Services/.
  local sub_path
  case "$fixture" in
    V[0-9]*__*.php) sub_path="src/files/custom/Espo/Modules/Smoke/Migration" ;;
    *)              sub_path="src/files/custom/Espo/Modules/Smoke/Services" ;;
  esac

  local target_dir="$SANDBOX/$sub_path"
  mkdir -p "$target_dir"
  touch "$SANDBOX/README.md"  # evita violação R2 não relacionada
  cp "$FIXTURES_DIR/$fixture" "$target_dir/$fixture"

  local rel_file="$SANDBOX/$sub_path/$fixture"

  # Rodar validator em modo --staged passando o arquivo da sandbox.
  php_run "$VALIDATOR" --staged "$rel_file" > /tmp/togare-val-out 2>&1
  local got=$?

  if [ "$got" -eq "$expected" ]; then
    results+=("✓ $name  (exit=$got)")
  else
    results+=("✗ $name  (esperado=$expected, obtido=$got)")
    echo "--- output de '$name' ---"
    cat /tmp/togare-val-out
    echo "--- fim output ---"
    fails=$((fails + 1))
  fi
}

run_case "classe com prefixo Togare (R1 OK)"              "ok-classe-com-prefixo.php"              0
run_case "classe sem prefixo Togare (R1 BAD)"             "bad-classe-sem-prefixo.php"             1
run_case "namespace Espo\\Modules\\TogareCore (R1 OK)"      "ok-classe-namespace-espocrm.php"        0
run_case "migration tabela togare_* (R3 OK)"              "V001__create_togare_queue_items.php"    0
run_case "migration tabela sem prefixo (R3 BAD)"          "V002__create_queue_items.php"           1
run_case "error_log() sem escape (R5 BAD)"                "bad-uses-error-log.php"                 1
run_case "error_log() com escape hatch (R5 OK)"           "ok-error-log-with-escape.php"           0
run_case "INSERT direto togare_queue_items (R6 BAD)"      "bad-direct-queue-insert.php"            1
run_case "consumidor via QueueService (R6 OK)"            "ok-queue-via-service.php"               0

echo ""
echo "=== Resultados ==="
printf '%s\n' "${results[@]}"
echo ""
if [ "$fails" -eq 0 ]; then
  echo "✅ Todos os ${#results[@]} cenários passaram."
  exit 0
else
  echo "❌ $fails cenário(s) falharam."
  exit 1
fi
