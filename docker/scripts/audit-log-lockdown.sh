#!/usr/bin/env bash
# docker/scripts/audit-log-lockdown.sh — Story 2.4
#
# Garante append-only em togare_audit_log via triggers BEFORE UPDATE/DELETE
# (NFR10). Triggers são o mecanismo correto quando o app user tem
# ALL PRIVILEGES ON db.* — REVOKE de privilégio herdado de schema-level falha
# em MariaDB silenciosamente; triggers bloqueiam qualquer tentativa de
# UPDATE/DELETE independente do nível de privilégio da sessão.
#
# Em produção com separação total de privilégios (app user sem ALL PRIVILEGES),
# substituir por: REVOKE UPDATE, DELETE ON db.togare_audit_log FROM appuser.
#
# Por que é manual (não Migration V006):
#   A migration roda como app user — CREATE TRIGGER requer SUPER ou TRIGGER
#   privilege (root). Operação de DBA / operador.
#
# Idempotência:
#   CREATE OR REPLACE TRIGGER é idempotente no MariaDB ≥10.1.
#
# Uso:
#   set -a && source docker/.env && set +a
#   bash docker/scripts/audit-log-lockdown.sh
#
# Critério OK:
#   stdout termina com "[togare] audit-log-lockdown OK ... (2/2)" e o script
#   valida via information_schema.TRIGGERS que ambos triggers existem
#   (aborta com exit 1 se a contagem ≠ 2).
#   Negative test: DELETE de row existente → ERROR 1644 (45000).

set -euo pipefail

: "${MARIADB_ROOT_PASSWORD:?MARIADB_ROOT_PASSWORD precisa estar exportado (source docker/.env)}"
: "${ESPOCRM_DB_NAME:?ESPOCRM_DB_NAME precisa estar exportado (source docker/.env)}"

readonly MARIADB_CONTAINER="${MARIADB_CONTAINER:-nextcloud-crm-mariadb-1}"

echo "[togare] aplicando triggers append-only em ${ESPOCRM_DB_NAME}.togare_audit_log..."

# MYSQL_PWD via env var evita expor a senha em `ps aux` / /proc/<pid>/cmdline.
# `mariadb` cli aborta o script SQL no primeiro erro por padrão; combinado
# com `set -e`, qualquer falha de SQL ou de docker exec interrompe o script.
docker exec -i \
    -e MYSQL_PWD="${MARIADB_ROOT_PASSWORD}" \
    "${MARIADB_CONTAINER}" \
    mariadb -uroot "${ESPOCRM_DB_NAME}" <<'SQL'
CREATE OR REPLACE TRIGGER togare_audit_log_prevent_update
BEFORE UPDATE ON togare_audit_log
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'togare_audit_log is append-only: UPDATE not permitted';

CREATE OR REPLACE TRIGGER togare_audit_log_prevent_delete
BEFORE DELETE ON togare_audit_log
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'togare_audit_log is append-only: DELETE not permitted';
SQL

# Validação independente: confirma que ambos triggers de fato existem em
# information_schema. Sem isso, falha silenciosa do `docker exec` deixaria
# o script imprimir "OK" sem garantia real de proteção (NFR10).
trigger_count=$(docker exec -i \
    -e MYSQL_PWD="${MARIADB_ROOT_PASSWORD}" \
    "${MARIADB_CONTAINER}" \
    mariadb -uroot -N -B -e "
        SELECT COUNT(*) FROM information_schema.TRIGGERS
        WHERE TRIGGER_SCHEMA = '${ESPOCRM_DB_NAME}'
          AND EVENT_OBJECT_TABLE = 'togare_audit_log'
          AND TRIGGER_NAME IN ('togare_audit_log_prevent_update', 'togare_audit_log_prevent_delete')
    ")

if [[ "${trigger_count}" -ne 2 ]]; then
    echo "[togare] ERRO: esperado 2 triggers em togare_audit_log, encontrado ${trigger_count}." >&2
    exit 1
fi

echo "[togare] audit-log-lockdown OK — triggers append-only ativos em togare_audit_log (${trigger_count}/2)."
