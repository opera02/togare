#!/bin/sh
# Story 6.5 — REST smoke (roda DENTRO do container espocrm).
set -eu

: "${ESPO_USER:?Defina ESPO_USER para o smoke REST}"
: "${ESPO_PASSWORD:?Defina ESPO_PASSWORD para o smoke REST}"

A=$(printf '%s' "$ESPO_USER:$ESPO_PASSWORD" | base64)
BASE=${ESPO_BASE:-http://localhost/api/v1/Funcionario}

make_cpf() {
  awk -v seed="$(date +%s)$$" '
    BEGIN {
      base = sprintf("%09d", seed % 1000000000);
      repeated = 1;
      for (k = 2; k <= 9; k++) {
        if (substr(base, k, 1) != substr(base, 1, 1)) repeated = 0;
      }
      if (repeated == 1) base = "123456789";
      cpf = base;
      for (j = 9; j <= 10; j++) {
        sum = 0;
        for (i = 1; i <= j; i++) {
          sum += substr(cpf, i, 1) * (j + 2 - i);
        }
        dv = (sum * 10) % 11;
        if (dv == 10) dv = 0;
        cpf = cpf dv;
      }
      print cpf;
    }
  '
}

CPF_OK=$(make_cpf)
RUN_ID=$(date +%s)-$$
TMP_DIR=${TMPDIR:-/tmp}
OK_PAYLOAD="$TMP_DIR/smoke-6-5-ok-payload-$RUN_ID.json"
BAD_PAYLOAD="$TMP_DIR/smoke-6-5-bad-payload-$RUN_ID.json"
OK_BODY="$TMP_DIR/smoke-6-5-ok-$RUN_ID.json"
BAD_BODY="$TMP_DIR/smoke-6-5-bad-$RUN_ID.json"
GET_BODY="$TMP_DIR/smoke-6-5-get-$RUN_ID.json"

cleanup() {
  rm -f "$OK_PAYLOAD" "$BAD_PAYLOAD" "$OK_BODY" "$BAD_BODY" "$GET_BODY"
}
trap cleanup EXIT

cat > "$OK_PAYLOAD" <<JSON
{"nome":"REST Smoke 65 $RUN_ID","cpf":"$CPF_OK","cargo":"RH","salario":3000,"salarioCurrency":"BRL","dataAdmissao":"2026-05-16"}
JSON

cat > "$BAD_PAYLOAD" <<'JSON'
{"nome":"REST Bad 65","cpf":"12345678900","cargo":"RH","dataAdmissao":"2026-05-16"}
JSON

request() {
  method=$1
  url=$2
  body_file=$3
  out_file=$4

  if [ "$body_file" = "-" ]; then
    curl -s -o "$out_file" -w "%{http_code}" -X "$method" "$url" \
      -H "Espo-Authorization: $A"
  else
    curl -s -o "$out_file" -w "%{http_code}" -X "$method" "$url" \
      -H "Espo-Authorization: $A" -H "Content-Type: application/json" \
      --data-binary @"$body_file"
  fi
}

assert_status() {
  label=$1
  got=$2
  expected=$3
  body_file=$4

  if [ "$got" != "$expected" ]; then
    echo "[FAIL] $label: HTTP $got, esperado $expected"
    head -c 600 "$body_file" || true
    echo
    exit 1
  fi

  echo "[PASS] $label: HTTP $got"
}

echo "=== A) POST CPF valido + BRL (espera 200 + cpf so digitos + salarioCurrency BRL) ==="
STATUS=$(request POST "$BASE" "$OK_PAYLOAD" "$OK_BODY")
assert_status "POST valido" "$STATUS" "200" "$OK_BODY"
grep -q "\"cpf\":\"$CPF_OK\"" "$OK_BODY"
grep -q '"salarioCurrency":"BRL"' "$OK_BODY"
head -c 600 "$OK_BODY"
echo

echo "=== B) POST CPF invalido (espera 400 + mensagem pt-BR/body estruturado) ==="
STATUS=$(request POST "$BASE" "$BAD_PAYLOAD" "$BAD_BODY")
assert_status "POST CPF invalido" "$STATUS" "400" "$BAD_BODY"
grep -q '"reason":"invalid"' "$BAD_BODY"
grep -q 'CPF' "$BAD_BODY"
head -c 600 "$BAD_BODY"
echo

echo "=== C) GET lista (espera 200) ==="
STATUS=$(request GET "$BASE?maxSize=2&orderBy=createdAt&order=desc" - "$GET_BODY")
assert_status "GET lista" "$STATUS" "200" "$GET_BODY"
head -c 600 "$GET_BODY"
echo
