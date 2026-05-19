# ADR 0003 — EspoCRM extensions como padrão de empacotamento + namespace para terceiros

**Data:** 2026-04-22
**Status:** Aceito

## Contexto

O Togare é construído como customizações do EspoCRM. O CLAUDE.md do projeto fixa o princípio "customizações vivem em módulos, nunca no core" — preserva caminho de atualização. O PRD estabelece um modelo open-core modular com módulos premium licenciados separadamente via JWT. O PRD também prevê marketplace de terceiros em Fase 2.

Precisamos de um padrão técnico claro para empacotar customizações e uma convenção de nomes que evite colisão com EspoCRM vanilla e com futuras extensões de parceiros.

## Decisão

1. **Scaffold:** cada módulo custom Togare é criado a partir de [github.com/espocrm/ext-template](https://github.com/espocrm/ext-template), o template oficial da equipe EspoCRM.
2. **Empacotamento:** cada módulo é instalável como extensão EspoCRM via Admin → Extensions (gera `.zip` com `manifest.json` + `src/files/` + `scripts/`).
3. **Prefixo oficial `Togare`:** toda entidade, serviço, controller ou namespace PHP de módulos Togare oficiais começa com `Togare` (ex.: `TogareProcesso`, `Togare\Core\Services\QueueService`, `TogareDjenSyncJob`).
4. **Pasta no monorepo:** `espocrm/togare-<nome-kebab>/` (ex.: `espocrm/togare-core/`, `espocrm/togare-djen/`).
5. **Tabelas SQL custom não-entidade:** prefixo `togare_` em `snake_case` (ex.: `togare_queue_items`, `togare_audit_log`).
6. **Variáveis de ambiente:** prefixo `TOGARE_` (ex.: `TOGARE_LICENSE_KEY`).
7. **Headers HTTP custom:** prefixo `X-Togare-` (ex.: `X-Togare-Correlation-Id`).
8. **Namespace reservado para terceiros:** `TogareExt_<Vendor>_*` (ex.: `TogareExt_Acme_CustomEntity`, `TogareExt_Linx_IntegrationService`). Marketplace Fase 2 validará submissões contra esse padrão.
9. **README obrigatório por módulo** seguindo template fixo de 5 seções: _O que faz / Como instalar / Entidades expostas / Hooks disparados / Como testar_. Pre-commit (lefthook) bloqueia módulo novo sem README.

## Consequências

- ✅ Caminho de atualização do core EspoCRM preservado (nenhuma modificação em `files/core/`).
- ✅ Prefixos evitam colisão com extensões EspoCRM da comunidade.
- ✅ Modelo open-core sustentável — cada módulo é instalável/desinstalável independentemente; módulos premium podem ser licenciados separadamente via JWT (ADR 0006).
- ✅ Namespace `TogareExt_*` abre porta para marketplace Fase 2 sem retrabalho.
- ✅ README template + validator pre-commit garantem consultabilidade em sessões longas de Claude.
- ⚠️ Cada módulo exige setup inicial (scaffold ext-template + manifest.json + README + migrations). Custo aceito para manter isolamento.
- ⚠️ Remover o prefixo `Togare` posteriormente seria custoso (tabelas, classes PHP, arquivos JSON, i18n). Decisão precisa ser mantida por todo o ciclo de vida do produto.
