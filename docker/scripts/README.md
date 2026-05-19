# Scripts operacionais — Togare / nextcloud-crm

Scripts manuais executados pelo operador (Felipe) que **não cabem em
Migration / EspoCRM AfterInstall** porque exigem privilégios fora do app
(senha root do MariaDB, parar a stack inteira, etc.).

Convenção: cada script segue `set -euo pipefail`, valida env vars com
`: "${VAR:?msg}"` e é idempotente quando faz sentido.

## install.sh (instalação one-shot — FR34)

Instala a stack **do zero** num servidor Linux para escritório sem equipe
técnica. Atalho na raiz do projeto: `bash instalar.sh` (repassa para este
script). 7 passos: (1) pré-checagem do servidor (Linux/arquitetura/disco/RAM);
(2) instala o Docker via `get.docker.com` se faltar (Linux 100% automático;
configura `sudo docker` na sessão se o grupo `docker` ainda não valer);
(3) gera as 7 senhas fortes (alfanuméricas, regra do `.env.example`), cria
`docker/.env` e `docker/CREDENCIAIS-TOGARE.txt` (chmod 600, **gitignored**);
(4) `pull` + `up -d --build` + espera healthy; (5) instala os **6** módulos
Togare na ordem de dependência (`togare-core` → `licensing` → `rbac` → `tpu`
→ `nextcloud-bridge` → `djen`; `togare-portal-ui` **não** entra — Portal é
Growth/congelado) via `command.php extension --file=` (idempotente) + `rebuild`

- `clear-cache`; (6) validações de saúde (serviços, HTTPS 200/302, Nextcloud,
  6 módulos presentes); (7) resumo com URLs e próximos passos.

Idempotente: se `docker/.env` já existe entra em **modo retomada** (não regera
senhas, só garante stack no ar + módulos). NÃO requer Node/PHP/Composer no
servidor (usa os `.zip` já versionados em `espocrm/<módulo>/build/`). Flags:
`--dominio`, `--email` (obrigatório com domínio real, p/ Let's Encrypt),
`--sim` (não pergunta nada), `--pular-docker`, `--dry-run`, `-h`. Para
**atualizar** uma instalação existente NÃO é este script — é `update.sh`.

## update.sh (Story 10.6)

Atualiza a stack para as versões pinadas no `docker/.env`, com **backup
implícito antes** de qualquer mutação e refresh do EspoCRM depois. Idempotente
(rodar 2× sem nova versão = no-op, exit 0) e reversível (em falha, imprime o
rollback manual — NFR33). Flags `--dry-run`, `-h`. Detalhes no cabeçalho do
script e runbook em `docker/README.md` → "Operação: atualizar, reverter e
restaurar". Roteiro de uso operacional é o do `docker/README.md` (público
não-engenheiro).

## restore.sh (Story 1a.7, finalizado na 10.6)

Restaura snapshot do `togare-backup` (restic) para a stack local. Fluxo de
7 passos, guarda de serviços no ar, confirmação textual `RESTAURAR`. Story 10.6
finalizou: log persistente em `docker/logs/`, sem dependência de `jq` no host
(via `lib-json.sh` — motor python; ambiente Windows/MSYS não tem jq),
`MSYS_NO_PATHCONV`, e flag opt-in `--yes` (pula a confirmação — só para o smoke
automatizado do gate; o default continua interativo). Para detalhes ver
cabeçalho do próprio script.

## lib-json.sh (Story 10.6)

Não é executável — é uma **biblioteca sourced** por `update.sh` e `restore.sh`.
Helpers de parsing JSON em `python` (não `jq`, ausente no host alvo). Mantém os
mesmos valores extraídos que o `jq` original; só troca o motor.

## audit-log-lockdown.sh (Story 2.4)

Cria triggers `BEFORE UPDATE` e `BEFORE DELETE` em `togare_audit_log` que
rejeitam qualquer tentativa com `SIGNAL SQLSTATE '45000'`. Atende NFR10 do
PRD (append-only por desenho de banco, não por convenção de PHP).

Por que triggers em vez de `REVOKE`: o app user do compose tem
`ALL PRIVILEGES ON db.*` herdado de schema-level, o que faz `REVOKE` em
nível de tabela falhar silenciosamente em MariaDB. Triggers bloqueiam
`UPDATE/DELETE` independente do nível de privilégio da sessão (vale para
app user E para root). Em produção com separação total de privilégios
(app user sem `ALL PRIVILEGES`), trocar para
`REVOKE UPDATE, DELETE ON db.togare_audit_log FROM appuser`.

### Pré-requisitos

- `.env` da raiz do projeto com `MARIADB_ROOT_PASSWORD` e
  `ESPOCRM_DB_NAME` (Story 1a.1).
- Container `nextcloud-crm-mariadb-1` rodando (`docker compose ps mariadb`
  mostra `running` / `healthy`).
- Story 2.4 com Migration V006 já aplicada (tabela `togare_audit_log` existe).

### Como rodar

```bash
cd ~/projetos/nextcloud-crm
set -a && source .env && set +a
bash docker/scripts/audit-log-lockdown.sh
```

### Critério OK

`stdout` termina com:

```
[togare] audit-log-lockdown OK — triggers append-only ativos em togare_audit_log (2/2).
```

E os 2 triggers `togare_audit_log_prevent_update` e
`togare_audit_log_prevent_delete` aparecem em `information_schema.TRIGGERS`
(o próprio script valida isso e aborta com exit 1 se a contagem ≠ 2).

### Critério NOK

| Erro                                                         | Causa provável                          | Próximo passo                                                  |
| ------------------------------------------------------------ | --------------------------------------- | -------------------------------------------------------------- |
| `Access denied for user 'root'`                              | `MARIADB_ROOT_PASSWORD` errado/ausente  | Reverificar `.env` e `set -a && source .env && set +a`         |
| `Unknown table 'togare_audit_log'`                           | Migration V006 ainda não rodou          | Reinstalar `togare-core-0.8.0.zip` (a migration é idempotente) |
| `[togare] ERRO: esperado 2 triggers ... encontrado 0` (ou 1) | Triggers não foram criados parcialmente | Ver mensagem do `mariadb` acima — provavelmente erro de SQL    |

### Quando rodar

- 1× pós primeira instalação de `togare-core 0.8.0`.
- Pode rodar de novo sem dano (`CREATE OR REPLACE TRIGGER` é idempotente).
- Após `restore.sh` que recria o banco do zero — os triggers somem com o
  schema; reaplicar lockdown.

### Smoke negativo (validação manual)

Confirma que os triggers pegaram: deve falhar com `ERROR 1644 (45000)`
(SIGNAL emitido pelo trigger). Vale para qualquer usuário, inclusive root —
triggers não são contornáveis por privilégio.

```bash
docker exec -i nextcloud-crm-mariadb-1 \
    mariadb -u"$ESPOCRM_DB_USER" -p"$ESPOCRM_DB_PASSWORD" "$ESPOCRM_DB_NAME" \
    -e "DELETE FROM togare_audit_log LIMIT 1"
```

Saída esperada:

```
ERROR 1644 (45000) at line 1: togare_audit_log is append-only: DELETE not permitted
```

Se o comando funcionar (sem erro), o lockdown não pegou — reportar.
