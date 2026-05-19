#!/usr/bin/env bash
# instalar.sh — atalho de instalação do Togare (raiz do projeto).
#
# Para instalar o sistema do zero num servidor Linux, baixe/clone o projeto e
# rode, na pasta do projeto:
#
#     bash instalar.sh
#
# Para domínio real com HTTPS automático:
#
#     bash instalar.sh --dominio crm.seuescritorio.adv.br --email voce@seuescritorio.adv.br
#
# Sem perguntas (assume "sim" em tudo):  bash instalar.sh --sim
# Ajuda completa:                        bash instalar.sh --help
#
# Este arquivo só repassa para docker/scripts/install.sh, que faz o trabalho
# (confere o servidor, instala o Docker se faltar, gera as senhas, sobe a
# stack, instala os módulos e valida tudo). Rodar de novo é seguro.

set -euo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
exec bash "${DIR}/docker/scripts/install.sh" "$@"
