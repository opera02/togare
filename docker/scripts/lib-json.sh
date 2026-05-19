# lib-json.sh — Story 10.6
#
# Helpers JSON SEM dependência de `jq` no host. Motivo: o ambiente operacional
# alvo (Windows + MSYS/Git Bash, e também o piloto interno) NÃO tem `jq`
# instalado, mas tem `python3`/`python` garantido. O `restore.sh` da Story 1a.7
# dependia de `jq` no host — por isso o restore destrutivo end-to-end ficou
# DEFERIDO (nunca rodou de fato). A Story 10.6 fecha esse débito: estes helpers
# preservam exatamente os valores extraídos (mesmo fluxo/guardas), só trocam o
# motor de parsing de `jq` → `python`.
#
# Sourced por update.sh e restore.sh. Sem `set -e` aqui (a lib não é executável).

# Resolve o interpretador python disponível.
_py() {
  if command -v python3 >/dev/null 2>&1; then python3 "$@";
  else python "$@"; fi
}

# Sanity-check no source: estes helpers SÓ funcionam com Python 3 real. No
# Windows o `python` pode ser o stub da Microsoft Store (abre a Store, não é
# Python) ou Python 2 — nesses casos os helpers retornariam o fallback
# silenciosamente (restore acharia o repo "vazio", update pularia migrations).
# Falhar alto aqui é muito melhor que silencioso e errado.
if ! _py -c 'import sys,json; assert sys.version_info[0]>=3' >/dev/null 2>&1; then
  echo "FATAL: Python 3 não encontrado/funcional no host." >&2
  echo "       lib-json.sh exige python3 (ou 'python' = Python 3 real)." >&2
  echo "       No Windows, instale Python 3 e garanta que 'python3 -c ...' roda" >&2
  echo "       (NÃO o stub da Microsoft Store)." >&2
  return 1 2>/dev/null || exit 1
fi

# stdin: array JSON → imprime o número de elementos. Vazio/erro → 0.
json_array_len() {
  _py -c 'import sys,json
try: print(len(json.load(sys.stdin)))
except Exception: print(0)'
}

# stdin: array JSON; $1 = nome do campo → imprime <campo> do ÚLTIMO elemento.
json_last_field() {
  _py -c 'import sys,json
f=sys.argv[1]
try:
 a=json.load(sys.stdin); print(a[-1].get(f,"") if a else "")
except Exception: print("")' "$1"
}

# stdin: array de snapshots restic; $1 = id curto/longo → imprime .time do
# snapshot cujo short_id == $1 OU id[0:8] == $1. Nada encontrado → vazio.
json_restic_time_for_id() {
  _py -c 'import sys,json
sid=sys.argv[1]
try:
 for s in json.load(sys.stdin):
  if s.get("short_id")==sid or (s.get("id","")[:8])==sid:
   print(s.get("time","")); break
 else: print("")
except Exception: print("")' "$1"
}

# stdin: objeto JSON único de `docker compose ps <svc> --format json`
# → imprime Health, ou State se Health vazio (equivale a `.Health // .State`).
json_compose_health() {
  _py -c 'import sys,json
try:
 d=json.load(sys.stdin); print(d.get("Health") or d.get("State") or "")
except Exception: print("")'
}

# stdin: NDJSON de `docker compose ps --format json` (1 objeto por linha)
# → imprime, separados por espaço, os Service cujo (Health|State) NÃO é
#   "healthy" nem "running".
json_compose_unhealthy() {
  _py -c 'import sys,json
bad=[]
for line in sys.stdin:
 line=line.strip()
 if not line: continue
 try:
  d=json.loads(line)
  st=d.get("Health") or d.get("State") or ""
  if st not in ("healthy","running"): bad.append(d.get("Service",""))
 except Exception: pass
print(" ".join(bad))'
}

# stdin: JSON de `docker compose config --format json`; $1 = chave do volume;
# $2 = default → imprime .volumes[$1].name ou o default.
json_compose_volume_name() {
  _py -c 'import sys,json
k=sys.argv[1]; dflt=sys.argv[2]
try:
 d=json.load(sys.stdin)
 print((d.get("volumes",{}).get(k,{}) or {}).get("name") or dflt)
except Exception: print(dflt)' "$1" "$2"
}

# $1 = caminho de arquivo JSON; $2.. = chaves aninhadas → imprime o valor
# (string). Arquivo/chave ausente → vazio. Usado para extension.json/module.json.
json_file_get() {
  local f="$1"; shift
  _py -c 'import sys,json
f=sys.argv[1]; keys=sys.argv[2:]
try:
 d=json.load(open(f,encoding="utf-8"))
 for k in keys: d=d[k]
 print(d if d is not None else "")
except Exception: print("")' "$f" "$@"
}

# stdin: sentinela last-success.json → imprime a idade em segundos do campo
# .timestamp (ISO-8601 UTC) em relação a agora. Ausente/inválido → -1.
json_sentinel_age_seconds() {
  _py -c 'import sys,json,datetime
try:
 d=json.load(sys.stdin); ts=d.get("timestamp")
 if not ts: print(-1); sys.exit(0)
 ts=ts.replace("Z","+00:00")
 t=datetime.datetime.fromisoformat(ts)
 if t.tzinfo is None: t=t.replace(tzinfo=datetime.timezone.utc)
 now=datetime.datetime.now(datetime.timezone.utc)
 print(int((now-t).total_seconds()))
except Exception: print(-1)'
}
