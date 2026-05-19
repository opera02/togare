# Imagem custom togare-backup (Story 1a.7).
# Base: restic oficial (alpine). Adiciona:
#   - mariadb-client (mariadb-dump)
#   - postgresql-client (pg_dump)
#   - supercronic (cron PID-1 friendly, log JSON)
#   - bash, jq, curl, coreutils (date --iso-8601=seconds; busybox não tem)
#
# Build:
#   docker compose build togare-backup
# Inspecionar tamanho final:
#   docker images togare-backup --format "{{.Size}}"
# Alvo: <150MB.

ARG RESTIC_VERSION=0.17.3
ARG SUPERCRONIC_VERSION=0.2.33

FROM restic/restic:${RESTIC_VERSION}

ARG SUPERCRONIC_VERSION

# Pacotes Alpine: clientes de BD + utilitários para os scripts bash.
# - tzdata e jq JÁ VÊM na imagem base do restic; não reinstalar.
# - curl é virtual: baixa o supercronic e é removido na mesma layer.
# - postgresql16-client casa com POSTGRES_VERSION=16 da stack.
# - mariadb-client da família 11.4 casa com MARIADB_VERSION=11.4.
RUN apk add --no-cache \
        bash \
        coreutils \
        mariadb-client \
        postgresql16-client \
    && apk add --no-cache --virtual .build-deps curl \
    && curl -fsSL \
        "https://github.com/aptible/supercronic/releases/download/v${SUPERCRONIC_VERSION}/supercronic-linux-amd64" \
        -o /usr/local/bin/supercronic \
    && chmod +x /usr/local/bin/supercronic \
    && /usr/local/bin/supercronic -version \
    && apk del .build-deps

# Scripts e templates do projeto.
COPY backup.sh        /app/backup.sh
COPY prune.sh         /app/prune.sh
COPY healthcheck.sh   /app/healthcheck.sh
COPY entrypoint.sh    /app/entrypoint.sh
COPY crontab.template /app/crontab.template

RUN chmod 755 /app/*.sh

# Diretório default do repositório restic (pode ser sobrescrito por bind mount).
RUN mkdir -p /var/backups/togare

ENTRYPOINT ["/app/entrypoint.sh"]
