# ADR 0002 — Docker Compose para ambiente de desenvolvimento

**Data:** 2026-04-21
**Status:** Aceito

## Contexto

Precisamos de um ambiente de desenvolvimento local reproduzível para EspoCRM + Nextcloud + bancos, que seja próximo do que será produção e não dependa de instalação nativa no Windows.

## Decisão

Usar **Docker Compose** via Docker Desktop (WSL2) como ambiente de dev. Serviços mínimos previstos:

- `espocrm` (imagem oficial `espocrm/espocrm`)
- `mariadb` (banco do EspoCRM)
- `nextcloud` (imagem oficial `nextcloud:apache`)
- `postgres` (banco do Nextcloud)
- `redis` (cache/sessões)

Detalhes finais do compose virão na Fase 3 do kickoff.

## Consequências

- ✅ Ambiente idêntico entre máquinas e próximo de produção Linux.
- ✅ `docker compose up -d` sobe a stack inteira.
- ✅ Volumes nomeados garantem persistência; dados ficam em `docker/data/` (gitignore).
- ⚠️ Hospedagem de produção ficou adiada — decisão será tomada em ADR futuro quando o escopo estiver mais maduro.
- ⚠️ Docker Desktop for Windows exige licença para empresas grandes (>250 funcionários / >10M USD). Para o escritório atual não é restrição, mas registrar para o caso de escalar.
