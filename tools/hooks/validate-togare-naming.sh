#!/usr/bin/env bash
# Hook: validador de convenções Togare.
# PHP no host (rápido) → senão Docker (lento mas funciona).

set -u

if [ "$#" -eq 0 ]; then
  exit 0
fi

if command -v php >/dev/null 2>&1; then
  exec php tools/validate-togare-naming.php --staged "$@"
fi

if command -v docker >/dev/null 2>&1; then
  MSYS_NO_PATHCONV=1 exec docker run --rm \
    -v "$(pwd):/app" -w /app php:8.3-cli \
    php tools/validate-togare-naming.php --staged "$@"
fi

echo "❌ Nem PHP nem Docker disponíveis — validator Togare não pode rodar."
echo "   Instale PHP ≥8.2 (scoop install php) OU Docker 24+."
exit 1
