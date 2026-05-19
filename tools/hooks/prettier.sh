#!/usr/bin/env bash
# Hook: prettier --check.

set -u

if [ "$#" -eq 0 ]; then
  exit 0
fi

if [ -x node_modules/.bin/prettier ]; then
  exec node_modules/.bin/prettier --check "$@"
fi

if command -v npx >/dev/null 2>&1; then
  exec npx --yes prettier --check "$@"
fi

echo "ℹ️  prettier: node_modules/ ausente e npx indisponível — rodar 1x:"
echo "    npm install"
echo "    ou: docker run --rm -v \"\$(pwd):/app\" -w /app node:20-alpine npm install"
exit 0
