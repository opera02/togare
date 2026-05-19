# ADR 0005 — Outbox pattern em MariaDB com SKIP LOCKED como fila de integrações

**Data:** 2026-04-22 (atualizado 2026-04-24 — Spike 1b.S2 Fase 1 concluída)
**Status:** Aceito condicionalmente — pools segregados validados funcionalmente em sanity local (2026-04-24 via Spike 1b.S2 Fase 1, Variante B). NFR3 quantitativo (DJEN sync 50adv ≤ 30min sem bloquear `internal`) pendente bench VPS Fase 2 (Epic 10).

## Contexto

O Togare precisa processar integrações externas (DJEN, TPU, futuras AASP/DataJud/IA) com garantias de resiliência:

- NFR18: zero perda silenciosa — falhas ficam em fila persistente, admin é notificado.
- NFR24: adapter plugável — trocar DJEN por outra fonte sem refactor.
- NFR27: degradação graciosa — timeout 30s, retry 3x exp backoff, circuit breaker.
- Filas concorrentes: sync DJEN, sync TPU, jobs internos EspoCRM, purge LGPD manual.

Riscos reais a mitigar:
- **Dupla-execução silenciosa** tem consequência jurídica (peticionar duas vezes).
- **Broker externo** (Redis Streams, RabbitMQ) adiciona peça operacional extra — overkill para piloto self-hosted de escritório pequeno.

## Decisão

1. **Outbox pattern** em tabela MariaDB `togare_queue_items` (criada na Story 1a.4c).
2. **Colunas obrigatórias:** `id`, `queue_name` (djen/tpu/internal/lgpd_purge), `idempotency_key` (UNIQUE — previne duplicação), `payload` JSON, `status` (pending/processing/done/failed_retry/failed_dead_letter), `retry_count`, `next_retry_at`, `created_at`, `updated_at`, `correlation_id`.
3. **Consumo via `SELECT ... FOR UPDATE SKIP LOCKED`** — requer **MariaDB ≥10.6**. Versão pinada em `.env` da stack (`MARIADB_VERSION`).
4. **Pools segregados por queue_name via containers consumer dedicados** — N containers, 1 por fila, cada rodando `queue-worker.php` standalone (reusa `QueueService::claim($queueName)` da Story 1a.4c). O `espocrm-daemon` nativo continua separado processando a tabela `job` do core (scheduled jobs nativos — emails, cleanup). Descoberto em Spike 1b.S2: o daemon nativo **não toca** `togare_queue_items`; segregação é sempre código Togare. Variante B (receita canônica) validada funcionalmente 2026-04-24 — ver seção "Validação funcional Fase 1" abaixo.
5. **QueueService centralizado** em `togare-core/Services/QueueService.php`. Todo módulo que produz item da fila escreve via `QueueService::enqueue(queueName, payload, idempotencyKey)`. Nunca `INSERT` direto na tabela.
6. **Limpeza periódica:** scheduled job mensal remove items `status='done'` com mais de 90 dias para evitar crescimento indefinido.

**Plano B documentado (caso Fase 2 falhe):** adapter próprio por fila com PHP-worker dedicado + supervisord em container minimalista (alpine + php-cli, sem framework EspoCRM). Perde integração com framework nativo de scheduled jobs mas preserva isolamento; acionado via feature flag `TOGARE_QUEUE_BACKEND`. Draft do ADR 0005b pré-redigido em [docs/decisoes/drafts/0005b-php-worker-supervisord-por-fila.md](./drafts/0005b-php-worker-supervisord-por-fila.md); sanity funcional do plano B também passou na Fase 1 (reserva pronta para Fase 2 se precisar).

## Consequências

- ✅ Zero broker externo — `mysqldump` cobre backup da fila junto com audit log.
- ✅ `idempotency_key` UNIQUE previne dupla-execução silenciosa em nível de banco.
- ✅ Retry natural via `next_retry_at` + contador de tentativas; backoff exponencial na aplicação.
- ✅ Correlation id propagado do Caddy ao log do worker — debug forense completo.
- ✅ Filas nomeadas permitem separação de workers → DJEN travado não bloqueia jobs internos do EspoCRM (NFR3).
- ⚠️ Requer MariaDB ≥10.6 (SKIP LOCKED não existe em versões anteriores). Imagem docker pinada.
- ⚠️ Pools são N containers consumer (Variante B da Spike 1b.S2) — contenção ainda é possível em picos porque compartilham o mesmo MariaDB. **Fase 2 da Spike 1b.S2 (bench VPS no Epic 10)** valida quantitativamente se sync DJEN de 50 advogados + jobs internos cabem em 30min com p95 `internal` ≤ 10s em hardware baseline. Resultado da Fase 1 (sanity local, 2026-04-24) já confirmou isolamento funcional — ver seção abaixo.
- ⚠️ Ausência de dead letter queue distinta de `failed_dead_letter` pode mascarar falhas silenciosas se admin não verificar o painel TogareHealth. Mitigação: alerta visual no HealthPanel quando qualquer fila tem `failed_dead_letter` > 0.

## Validação funcional (Fase 1 — sanity local, 2026-04-24)

Spike 1b.S2 Fase 1 concluída em laptop Felipe (Windows 11 + Docker Desktop + WSL2 + MariaDB 11.4 + EspoCRM 9.3 + togare-core 0.7.1). Ambiente isolado em `docker/spike-1b-S2/` permanece no repo até Fase 2 fechar.

**Receita validada (Variante B — canônica para produção):** N containers consumer, 1 por fila, cada rodando `queue-worker.php` standalone que invoca `QueueService::claim($queueName)` da Story 1a.4c. Zero modificação em togare-core. O `espocrm-daemon` nativo continua separado, sem interação com `togare_queue_items`.

**Sanity AC2 (pools Variante B):** 5 jobs `djen` (sleep 30s cada) + 5 jobs `internal` (sleep 1s cada) enfileirados na mesma janela de ~80ms. Resultado: último `internal` terminou às 15:56:13; primeiro `djen` terminou às 15:56:37 (gap de 24s). Zero contaminação cruzada nos logs (`grep "spike-internal" worker-djen` = 0 matches; `grep "spike-djen" worker-internal` = 0).

**Sanity AC3 (plano B supervisord):** mesmo workload com container alpine + supervisord + 2 workers standalone (sem framework EspoCRM). Resultado: último `internal` às 16:02:05; primeiro `djen` às 16:02:30 (gap de 25s). Isolamento confirmado — plano B pronto para promoção se Fase 2 reprovar Variante B.

**Conclusão:** isolamento é **intrínseco ao design do outbox + `claim()` com SKIP LOCKED filtrado por `queue_name`**, não dependente da topologia escolhida. Ambas as variantes servem; Variante B é a default por simplicidade (imagem única espocrm/espocrm:9.3, sem Dockerfile custom).

**Relatório completo + evidência:** [1b-S2-spike-pools-daemon-relatorio.md](../../_bmad-output/implementation-artifacts/1b-S2-spike-pools-daemon-relatorio.md).

**Pendência Fase 2:** bench quantitativo em VPS baseline (4vCPU/8GB/SSD NVMe Ubuntu 22.04) durante Epic 10 — consolidado com Spike 1b.S1 numa story 10.X-bench-nfr-spikes. Promoção para "Aceito" definitivo depende de DJEN sync ≤ 30min E p95 `internal` ≤ 10s.
