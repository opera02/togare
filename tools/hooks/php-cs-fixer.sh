#!/usr/bin/env bash
# Hook: php-cs-fixer em modo dry-run.
# Recebe arquivos staged como argumentos (via lefthook {staged_files}).

set -u

if [ "$#" -eq 0 ]; then
  exit 0
fi

if command -v php >/dev/null 2>&1 && [ -f vendor/bin/php-cs-fixer ]; then
  exec vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.dist.php --path-mode=intersection "$@"
fi

if [ -f vendor/bin/php-cs-fixer ] && command -v docker >/dev/null 2>&1; then
  MSYS_NO_PATHCONV=1 exec docker run --rm \
    -v "$(pwd):/app" -w /app php:8.3-cli \
    php vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.dist.php --path-mode=intersection "$@"
fi

echo "ℹ️  php-cs-fixer: vendor/ ausente — rodar 1x:"
echo "    composer install"
echo "    ou: docker run --rm -v \"\$(pwd):/app\" -w /app composer:2 composer install"
# Não bloqueia commit quando deps ainda não foram instaladas — isso fica sob responsabilidade do dev.
exit 0
