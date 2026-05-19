# Decisões Arquiteturais (ADRs) — Togare

Esta pasta registra **decisões arquiteturais** do Togare no formato ADR (Architecture Decision Record), seguindo o padrão proposto por [Michael Nygard](https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions): cada decisão é um arquivo markdown numerado com 3 seções obrigatórias — **Contexto**, **Decisão**, **Consequências**.

Os ADRs vivem fora do BMAD planning porque registram decisões **vinculantes em produção** que sobrevivem aos artefatos de fase (PRD, architecture, epics). Quando a arquitetura BMAD muda no Step 5, os ADRs **vinculados** seguem regendo o código até serem explicitamente substituídos.

## ADRs principais

| ID | Título | Data | Status | Spike |
|---|---|---|---|---|
| [0001](./0001-monorepo.md) | Monorepo único | 2026-04-21 | Aceito | — |
| [0002](./0002-docker-compose-dev.md) | Docker Compose para ambiente de desenvolvimento | 2026-04-21 | Aceito | — |
| [0003](./0003-espocrm-extensions-pattern.md) | EspoCRM extensions como padrão de empacotamento + namespace para terceiros | 2026-04-22 | Aceito | — |
| [0004](./0004-caddy-reverse-proxy.md) | Caddy como reverse proxy único com TLS 1.3 automático e X-Accel-Redirect | 2026-04-22 | Aceito condicionalmente | [1b.S1](../../_bmad-output/implementation-artifacts/1b-S1-spike-x-accel-redirect-relatorio.md) ✅ Fase 1 |
| [0005](./0005-outbox-queue-mariadb.md) | Outbox pattern em MariaDB com SKIP LOCKED como fila de integrações | 2026-04-22 | Aceito condicionalmente | [1b.S2](../../_bmad-output/implementation-artifacts/1b-S2-spike-pools-daemon-relatorio.md) ✅ Fase 1 |
| [0006](./0006-lgpd-module-mvp-reduzido.md) | Módulo togare-lgpd reduzido no MVP (purga manual com PurgeLog ed25519) | 2026-04-22 | Aceito (versão reduzida) | [1b.S3](../../_bmad-output/implementation-artifacts/1b-S3-spike-licensing-expiration-relatorio.md) ✅ |
| [0007](./0007-vanilla-api-plus-correlation-header.md) | API vanilla EspoCRM + correlation via header `X-Togare-Correlation-Id` | 2026-04-22 | Aceito | — |
| [0009](./0009-alinhar-retry-circuit-breaker.md) | Alinhar retry do worker com fechamento do circuit breaker do adapter | 2026-05-09 | Aceito | — |

## Drafts (não promovidos)

ADRs que existiram como plano B redigido **antes** das spikes correspondentes rodarem (regra do PM John, epics.md L1806). Permanecem arquivados em [`drafts/`](./drafts/) como contexto histórico — são reativados apenas se a Fase 2 (bench VPS no Epic 10) ou implementação futura demonstrar que o plano A do ADR principal correspondente falhou.

| ID | Título | Data | Status final | Spike |
|---|---|---|---|---|
| [0004b](./drafts/0004b-php-proxy-fallback.md) | PHP-proxy como fallback para download de binários grandes | 2026-04-24 | Não promovido — receita primária Caddy validada | [1b.S1](../../_bmad-output/implementation-artifacts/1b-S1-spike-x-accel-redirect-relatorio.md) ✅ Fase 1 |
| [0005b](./drafts/0005b-php-worker-supervisord-por-fila.md) | PHP-worker standalone + supervisord como fallback para pools segregados | 2026-04-24 | Não promovido — Variante B canônica validada | [1b.S2](../../_bmad-output/implementation-artifacts/1b-S2-spike-pools-daemon-relatorio.md) ✅ Fase 1 |
| [0006-extra](./drafts/0006-extra-licensing-advisory-lock.md) | Advisory lock MariaDB para coordenação RevalidateLicensesJob × QueueService workers | 2026-04-24 | Pendente — reavaliar na Story 4a.1 | [1b.S3](../../_bmad-output/implementation-artifacts/1b-S3-spike-licensing-expiration-relatorio.md) ✅ |

## Convenções

### Numeração

- 4 dígitos zero-padded sequencial: `0001`, `0002`, …, `0042`.
- **Letras** (`0004b`, `0006-extra`) reservadas para suplementos/drafts vinculados a um ADR principal. O sufixo é livre — preferir letra única (`0004b`, `0004c`) para drafts complementares; `-extra` ou nome descritivo (`0006-extra`, `0008-licensing-worker-invariante`) para variantes que tratam aspecto distinto do mesmo problema.

### Status enum

| Status | Significado |
|---|---|
| `Aceito` | Decisão definitiva. Vincula o código em produção. |
| `Aceito condicionalmente` | Decisão funcionalmente validada, mas com gate quantitativo pendente (geralmente bench VPS no Epic 10). Promove a `Aceito` quando o gate fechar. |
| `Aceito (versão reduzida)` | Decisão aceita com escopo reduzido em relação ao plano original — parte da feature foi diferida para Growth ou fase posterior do MVP. |
| `Pendente` | ADR redigido mas aguardando spike, decisão de Felipe ou implementação futura. |
| `Não promovido` | Plano B que **não** foi necessário (spike correspondente passou). Mantido em `drafts/` como contexto. |
| `Substituído por XXXX` | ADR superado por outro mais recente. **Nunca deletado** — preserva linha do tempo da decisão. |

### Formato dos arquivos

```markdown
# ADR NNNN — Título curto

**Data:** YYYY-MM-DD
**Status:** <enum>

## Contexto

(2-4 parágrafos: o problema, restrições, motivações)

## Decisão

(decisão tomada — pode ter sub-bullets)

## Consequências

- ✅ Positivas
- ⚠️ Trade-offs / negativas
- ⚠️ Riscos residuais

## Alternativas consideradas (opcional)

(o que foi descartado e por quê)

## Referências (opcional)

- Link pra spike, story, PRD, doc externa.
```

ADRs vinculados a spikes podem ter seções extras `## Validação funcional` (resultado de Fase 1 sanity local) ou `## Resultado` (drafts pós-spike).

### Quando criar um ADR novo?

Quando uma decisão arquitetural:

1. **Vincula o código por > 1 release** (não é decisão tática de uma story).
2. **Tem alternativa séria** que poderia ter sido escolhida (se não tem alternativa, é só implementação).
3. **Afeta múltiplos módulos** ou contratos externos.
4. **Tem trade-off explícito** que vale ser registrado pra futuro reviewer entender o "por quê".

Decisões puramente locais a uma story (ex.: nome de coluna, biblioteca menor) ficam no Dev Notes da story — não viram ADR.
