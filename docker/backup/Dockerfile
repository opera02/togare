# Imagem custom togare-backup (Story 1a.7).
# Base: restic oficial (alpine). Adiciona:
#   - mariadb-client (mariadb-dump)
#   - postgresql-client (pg_dump)
#   - supercronic (cron PID-1 friendly, log JSON) — vendored localmente
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
ARG SUPERCRONIC_SHA256=feefa310da569c81b99e1027b86b27b51e6ee9ab647747b49099645120cfc671

# Pacotes Alpine: clientes de BD + utilitários para os scripts bash.
# - tzdata e jq JÁ VÊM na imagem base do restic; não reinstalar.
# - postgresql16-client casa com POSTGRES_VERSION=16 da stack.
# - mariadb-client da família 11.4 casa com MARIADB_VERSION=11.4.
RUN apk add --no-cache \
        bash \
        coreutils \
        mariadb-client \
        postgresql16-client

# supercronic vendored em docker/backup/supercronic-linux-amd64 (não baixa do
# GitHub no build — `release-assets.githubusercontent.com` falha em DNS de
# alguns provedores, achado rodando install em Linux do Felipe 2026-05-19).
# Verificação SHA256 obrigatória para integridade do binário vendored.
# Versão pinada em SUPERCRONIC_VERSION; pra bumpar, atualizar o binário do
# repo + recalcular SHA256SUMS_supercronic (sha256sum no host) e editar o
# valor de SUPERCRONIC_SHA256 acima.
# Limitação conhecida: linux-amd64 apenas (ARM64 ficaria como follow-up).
COPY supercronic-linux-amd64 /usr/local/bin/supercronic
RUN echo "${SUPERCRONIC_SHA256}  /usr/local/bin/supercronic" | sha256sum -c - \
    && chmod +x /usr/local/bin/supercronic \
    && /usr/local/bin/supercronic -version

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
