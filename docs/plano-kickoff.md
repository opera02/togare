# Plano de Kickoff

Plano vivo do kickoff da plataforma. Atualizar ao final de cada sessão de trabalho para preservar continuidade entre conversas.

**Última atualização:** 2026-04-21 (BMAD-METHOD instalado; Fase 4 pronta para começar via workflow BMAD)

## Fases

### ✅ Fase 1 — Monorepo e esqueleto de pastas

- [ADR 0001](decisoes/0001-monorepo.md) registrado.
- Pastas criadas: `espocrm/`, `nextcloud/`, `portal-cliente/`, `integracoes/`, `docs/`.

### ✅ Fase 2 — Decisão de Docker Compose para dev

- [ADR 0002](decisoes/0002-docker-compose-dev.md) registrado.
- Serviços previstos: `espocrm`, `mariadb`, `nextcloud`, `postgres`, `redis`.

### ✅ Fase 3 — Implementar ambiente Docker local

- [x] `docker/docker-compose.yml` com os 5 serviços (+ `espocrm-daemon` para cron).
- [x] `docker/.env.example` com variáveis padrão e placeholders de senha.
- [x] `docker/README.md` com passo-a-passo de subida e URLs/credenciais default.
- [x] Validado `docker compose up -d` — 6 contêineres `Up`, EspoCRM auto-instalado, daemon estável.
- [x] Portas definidas em **18080 (EspoCRM)** e **18081 (Nextcloud)** — 8080/8081 colidiam com outro EspoCRM que roda nativamente na distro Ubuntu/WSL do usuário.

### ✅ Fase 3.5 — Instalar BMAD-METHOD

- [x] `npx bmad-method install` com módulos `core` + `bmm`, IDEs `antigravity` + `claude-code`, idioma pt-BR.
- [x] Variável `output_folder: _bmad-output` adicionada em [\_bmad/core/config.yaml](../_bmad/core/config.yaml) (corrigindo bug do installer v6.3.0 que não substituía o placeholder).
- [x] Diretório de outputs em [\_bmad-output/](../_bmad-output/) com `planning-artifacts/` e `implementation-artifacts/` versionados.
- [x] 41 skills BMAD disponíveis em `.claude/skills/` e `.agent/skills/`.

### ⏳ Fase 4 — Primeiro ciclo BMAD (modelagem)

**Observação:** com BMAD instalado, a modelagem agora segue o workflow oficial (Analyst → PM → Architect → SM). O draft manual em [entidades.md](entidades.md) vira **insumo** para o Analyst, não o artefato final.

- [ ] Rodar `bmad-generate-project-context` (ou `bmad-agent-analyst`) para consolidar o contexto do projeto a partir do que já existe ([CLAUDE.md](../CLAUDE.md), [arquitetura.md](arquitetura.md), [entidades.md](entidades.md), decisões ADR).
- [ ] Criar PRD via `bmad-create-prd` (PM). Entregável em [\_bmad-output/planning-artifacts/](../_bmad-output/planning-artifacts/).
- [ ] Criar Architecture via `bmad-create-architecture` (Architect).
- [ ] Gerar épicos e histórias via `bmad-create-epics-and-stories` (PO/SM).
- [ ] Entidades detalhadas + contratos de API + abstração de publicações + sincronização Nextcloud caem naturalmente como épicos/stories dentro do workflow.
- [ ] Contrato da API consumida pelo Portal do Cliente (read-only).
- [ ] Abstração de provedores de publicações (AASP primário + fallbacks).
- [ ] Estratégia de sincronização EspoCRM ↔ Nextcloud para documentos vinculados a processos.
- [ ] Definir stack do Portal do Cliente.

## Decisões pendentes (fora das fases acima)

- Hospedagem de produção — adiada, abrir ADR quando escopo maduro.
- Adoção de CrewAI ou LangChain — só quando houver necessidade concreta (conforme CLAUDE.md).
- Escopo inicial do bot de prazos: canais de entrada (email? WhatsApp? upload manual?) e formato das publicações.

## Princípios a preservar (do [CLAUDE.md](../CLAUDE.md))

- EspoCRM é a fonte da verdade.
- Customizações em módulos, nunca no core.
- Integrações externas toleram falha (timeout, retry, degradação).
- IA é enriquecimento, nunca bloqueador de fluxo.
