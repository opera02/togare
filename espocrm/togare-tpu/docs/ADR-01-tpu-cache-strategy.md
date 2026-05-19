# ADR-01 — TPU cache strategy (Redis com TTL longo + fallback DB)

**Status:** Aceita
**Data:** 2026-04-28
**Story:** 3.3 (Sync mensal TPU + cache Redis)

## Contexto

O módulo togare-tpu sincroniza mensalmente ~3.000 classes + ~5.000 assuntos
+ ~1.000 movimentos do CNJ (catálogo TPU). A entidade Processo (Story 3.4)
fará lookups frequentes desses códigos durante:

- Cadastro/edição de processos (validar `classeCodigo` contra catálogo).
- Renderização da timeline (resolver `movimentoCodigo` em label exibível).
- Estatísticas e relatórios (Epic 9/10).

Estimativa: ~3.000 lookups/dia em escritório típico (30 advogados × 10
processos abertos × 10 lookups). NFR17 alvo: **p95 ≤100ms**.

## Decisões

### 1. Redis com TTL 35 dias, NÃO MariaDB query cache

**Decisão:** Cache lookup-aside em Redis, chave
`togare:tpu:{tipo}:{codigo}`, TTL 3.024.000s (35 dias).

**Por quê não MariaDB query cache:**
- MariaDB ≥10.6 desativa query cache por default (deprecated em MySQL 8.0).
- Mesmo com query cache ativo, lookups por PRIMARY KEY já são <5ms — mas
  Redis fica em ~1ms consistentemente, com folga grande sobre o alvo.
- 30 lookups por timeline de processo + dezenas de processos visíveis
  simultaneamente → Redis sustenta carga sem contenção.

**Por quê TTL 35 dias:**
- Sync mensal (cron `0 3 1 * *`) → TTL precisa cobrir 1 mês + folga.
- 35 = 30 + 5d folga. Curto bastante para auto-purga se o sync nunca
  rodar (degradação detectável); longo bastante para sobreviver a 1 mês.

### 2. predis/predis (PHP puro), NÃO phpredis (PECL extension)

**Decisão:** Composer dep `predis/predis ^2.2`.

**Por quê:**
- Portabilidade entre imagens Docker — não depende de extension PECL na
  imagem oficial do EspoCRM.
- Performance suficiente: ~10-30k ops/s (vs ~100-200k de phpredis), folga
  de ordem de magnitude sobre os ~3k lookups/dia.
- Composer-managed → versões pinadas no `composer.lock` (vs build flags
  PECL no Dockerfile, mais frágil).

**Quando reconsiderar:** se cache TPU virar hot-path real (improvável) OU
se DJEN sync (Epic 4a) precisar de Redis pesado, avaliar phpredis.

### 3. DB index 1 (NÃO 0 — Nextcloud usa 0)

**Decisão:** `TOGARE_REDIS_DB=1` em `docker-compose.yml` para EspoCRM
(via env vars `TOGARE_REDIS_*` prefixadas — convenção ADR-04).

**Por quê:**
- Isolamento: `FLUSHDB` em DB 0 (típico ao resetar Nextcloud) não purga
  cache TPU em DB 1.
- DB 0 é o default — Nextcloud já está lá. Mantemos togare consciente
  por convenção.

### 4. Fallback DB direto se Redis cair (NFR18 — degradação graciosa)

**Decisão:** Em qualquer falha do Redis (timeout, conexão recusada,
indisponível), `TpuCacheService::resolve*()` cai para query MariaDB direto
e loga `tpu.cache.miss.redis_unavailable` em level=warning. NÃO lança
exceção ao usuário.

**Por quê:**
- TPU é catálogo somente-leitura. DB direto continua respondendo (PRIMARY
  KEY lookup ~5-50ms — bem dentro do alvo NFR17).
- Cair com erro 500 só porque Redis está down quebra o produto inteiro
  (qualquer cadastro de processo passaria por aqui). Inaceitável.
- Logs estruturados sinalizam o evento → Health Panel (Story 10.2)
  exibe banner para o admin.

### 5. Cache invalidation pós-sync usa SCAN+DEL (NUNCA `KEYS *`)

**Decisão:** `TpuSyncService::invalidateCachePattern()` usa SCAN
cursor-based com COUNT=100 + DEL em batch.

**Por quê:**
- `KEYS togare:tpu:classe:*` em produção com 3.000 chaves bloqueia o
  Redis durante a varredura completa (Redis é single-threaded). Hot-path
  proibido.
- SCAN é não-bloqueante — varre em batches, libera entre iterações.
- Falha do SCAN é log warning (não erro) — TTL 35d auto-expira mesmo
  sem invalidação ativa. Pior caso: lookups nos próximos 35d retornam
  dado da sync anterior. TPU muda raramente; nomes de classe são estáveis.

## Consequências

**Positivas:**
- p95 lookup ≤100ms com folga de ordem de magnitude.
- Resiliente a Redis down (fallback DB transparente).
- Sem dependência de extension PECL (portabilidade).
- Isolamento entre togare-tpu e Nextcloud no Redis (DB 0/1).

**Negativas / trade-offs:**
- Cache pode servir dado obsoleto por até 35 dias se um sync falhar e
  invalidação também falhar — aceito (TPU muda raramente).
- predis é mais lento que phpredis — não é gargalo no MVP.
- DB direto em fallback não cacheia (não popula Redis enquanto Redis está
  down) — sync seguinte repopula.

## Referências

- Story 3.3 — `_bmad-output/implementation-artifacts/3-3-sync-mensal-tpu-cache-redis.md`
- Architecture L919, L926, L942 — TpuCacheService no fluxo central
- Architecture L181 — fixtures VCR-style
- predis docs — https://github.com/predis/predis
- ADR-04 — Convenção env vars `TOGARE_*` (em planning-artifacts)
