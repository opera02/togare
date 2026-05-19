# ADR 0001 — Monorepo único

**Data:** 2026-04-21
**Status:** Aceito

## Contexto

A solução é composta por múltiplos componentes (EspoCRM customizado, Nextcloud, Portal do Cliente, integrações) que precisam evoluir de forma coordenada. Precisamos escolher entre um único repositório versionado (monorepo) ou repositórios separados por componente.

## Decisão

Usar **monorepo único**, com subpastas por componente:

- `espocrm/` — customizações do CRM (módulos, extensões, docs)
- `nextcloud/` — configurações do Nextcloud
- `portal-cliente/` — aplicação web do portal
- `integracoes/` — DataJud, AASP, bot de prazos
- `docker/` — orquestração de dev
- `docs/` — arquitetura e ADRs

## Consequências

- ✅ Mudanças que cruzam componentes ficam em um único PR/commit, facilitando revisão.
- ✅ BMAD-METHOD gerencia planejamento e artefatos de um ponto só.
- ✅ CI/CD pode ser centralizado.
- ⚠️ Quando um componente crescer ao ponto de ter ciclo de vida próprio, considerar extração para repo separado.
- ⚠️ `.gitignore` precisa ser cuidadoso para não versionar artefatos de build de cada subprojeto.
