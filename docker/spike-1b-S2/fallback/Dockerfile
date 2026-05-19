# Spike 1b.S2 — fallback supervisord. Valida o plano B do ADR 0005b draft.
#
# Container único com supervisord (PID 1 via `init: true` do compose → tini)
# gerenciando N worker processes, 1 por fila. Cada worker é um CLI PHP
# standalone sem framework EspoCRM.

FROM php:8.3-cli-alpine

RUN apk add --no-cache supervisor \
 && docker-php-ext-install pdo_mysql

COPY supervisord.conf /etc/supervisord.conf
COPY queue-worker.php /usr/local/bin/queue-worker.php

CMD ["supervisord", "-c", "/etc/supervisord.conf", "-n"]
