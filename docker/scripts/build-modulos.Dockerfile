# Builder offline-friendly para os 6 zips dos módulos Togare.
#
# Por que existe: os zips em `espocrm/togare-*/build/*.zip` estão no
# `.gitignore` de cada módulo (artefato de build, não versionado no repo).
# No servidor de produção (Linux, sem toolchain de dev), `install.sh`
# precisa dos zips prontos. Esta imagem builda os zips em container
# isolado — Felipe NÃO precisa instalar Node/PHP/Composer no host.
#
# Build:
#   docker build -f docker/scripts/build-modulos.Dockerfile \
#                -t togare-builder docker/scripts/
# Uso: ver docker/scripts/build-modulos.sh.

FROM node:20-bookworm-slim

# PHP + Composer: necessários porque cada togare-* tem composer.json com
# dependências (ex.: lcobucci/jwt no togare-licensing, validadores etc.).
# php-zip + unzip: ext-template empacota o .zip; sem unzip o `node build`
# falha no passo de inspeção. Sem ext-mbstring: composer reclama.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        ca-certificates \
        curl \
        php-cli \
        php-zip \
        php-mbstring \
        php-curl \
        php-xml \
        php-bcmath \
    && curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php \
    && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && rm -f /tmp/composer-setup.php \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /work
