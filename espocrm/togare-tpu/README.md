# TogareTpu

## O que faz

Sincroniza mensalmente as **Tabelas Processuais Unificadas (TPU) do CNJ** via
PDPJ (`gateway.cloud.pje.jus.br/tpu`) e expõe lookup com cache Redis (p95
≤100ms, NFR17). Habilita validação dos campos `classe`/`assunto`/`movimento`
na entidade Processo (Story 3.4) sem nenhuma chamada síncrona ao PDPJ em
tempo de uso (FR8/FR9, NFR4/NFR17/NFR25).

**Components:**

- `Services/PdpjAdapter` — implementa `Contracts/TpuSourceAdapterContract`.
  Consome HTTP REST com timeout 30s, retry 3x backoff exponencial (1/4/16s)
  e circuit breaker (5 falhas em 5min → abre 5min).
- `Services/RedisConnection` — factory predis singleton; lê env vars
  `TOGARE_REDIS_*`; método `isAvailable()` defensivo (NUNCA lança).
- `Services/TpuCacheService` — implementa os 3 ResolverContracts
  (`Classe`/`Assunto`/`MovimentoResolverContract`). Cache-aside Redis (TTL
  35d) + fallback DB direto se Redis indisponível. Desde 0.2.0 também expõe
  `searchByName()` para autocomplete TPU com cache Redis 1h.
- `Services/TpuSyncService` — orquestra sync das 3 tabelas via
  `INSERT...ON DUPLICATE KEY UPDATE` em batches de 500. Idempotente.
  Per-table failure isolation (uma tabela falhando não trunca as outras).
- `Jobs/TogareTpuSyncJob` — scheduled job mensal `0 3 1 * *` que invoca
  `TpuSyncService::syncAll()` e captura qualquer Throwable (cron não pode
  quebrar o daemon).

**Contracts** em `Contracts/`:
`ClasseResolverContract`, `AssuntoResolverContract`,
`MovimentoResolverContract`, `TpuSourceAdapterContract`. Versionados via
`Resources/contracts/VERSION.txt` (semver).

## Como instalar

**Pré-requisitos:**

- EspoCRM ≥ 9.3.0
- PHP ≥ 8.3 (com `ext-curl`, `ext-pdo_mysql`)
- MariaDB ≥ 10.6
- **togare-core ≥ 0.10.0** instalado e migrations aplicadas (Processo vive no
  togare-core; migrations base continuam vindo do 0.9.2+).
  Story 3.3 — `togare_migrations_applied` é criada por togare-core/V001;
  togare-tpu falha em SELECT se a tabela não existe ainda)
- Redis 7+ acessível (compose já provê — ver `docker/README.md` seção
  "Redis — segregação por DB")

**Build e instalação:**

```bash
cd espocrm/togare-tpu
npm install
composer install
npm run extension              # gera build/togare-tpu-0.2.0.zip
```

No EspoCRM rodando, via **Admin → Extensions → Upload** selecione o `.zip`,
ou cópia direta:

```bash
docker cp build/togare-tpu-0.2.0.zip nextcloud-crm-espocrm-1:/tmp/
docker exec nextcloud-crm-espocrm-1 php install-extension.php
```

`AfterInstall` aplica V001-V003 (cria `togare_tpu_classe`,
`togare_tpu_assunto`, `togare_tpu_movimento`). Sync inicial é manual ou
aguarda o primeiro cron mensal:

```bash
docker exec nextcloud-crm-espocrm-daemon-1 php cron.php --run-once-job=TogareTpuSync
```

## Entidades expostas

**Nenhuma entidade EspoCRM.** O togare-tpu NÃO cria entityDefs; expõe os 3
ResolverContracts via DI binding e registra o scope não-entidade
`TogareTpuCatalog` para o controller REST de autocomplete.

3 tabelas de catálogo (acessadas via PDO direto):
- `togare_tpu_classe` — Classes processuais CNJ.
- `togare_tpu_assunto` — Assuntos processuais CNJ.
- `togare_tpu_movimento` — Movimentos processuais CNJ.

## Hooks disparados / consumidos

**Hooks ORM consumidos:**

- `Hooks/Processo/ResolveTpuFieldsHook` (BeforeSave, order 30) — escuta saves
  da entidade `Processo` publicada pelo togare-core. Valida
  `classeCodigo`/`assuntoCodigo`/`movimentoCodigo` contra as tabelas TPU e
  denormaliza `classeNome`/`assuntoNome`/`movimentoNome`. Código inexistente
  lança `TpuCodeNotFoundException` (HTTP 400).

## Endpoint search

Autocomplete interno do CRM, bloqueado para portal:

- `GET /api/v1/TogareTpuCatalog/action/searchClasses?q=comum&limit=20`
- `GET /api/v1/TogareTpuCatalog/action/searchAssuntos?q=civil&limit=20`
- `GET /api/v1/TogareTpuCatalog/action/searchMovimentos?q=recebimento&limit=20`

Resposta:

```json
[
  {"codigo": 436, "nome": "Procedimento Comum Cível", "paiCodigo": 7, "ativo": true}
]
```

`q` precisa ter 3+ caracteres. `limit` é limitado a 100. Wildcards SQL são
escapados como literais.

**Eventos de log estruturado** (via `TogareLogger`):
- `tpu.sync.started`
- `tpu.sync.{classes,assuntos,movimentos}.completed` — por tabela, com
  `count` e `durationMs`.
- `tpu.sync.completed` — totalCount + totalDurationMs + failures.
- `tpu.sync.failed` — uma tabela falhou (per-table isolation; `tipo` no contexto).
- `tpu.sync.job_failed` — job catch-all (NÃO relança).
- `tpu.sync.duration.over_budget` — sync excedeu 15min (NFR4).
- `tpu.cache.hit` — Redis hit.
- `tpu.cache.miss.db_hit` — Redis miss → DB hit → populate cache.
- `tpu.cache.miss.code_not_found` — código fora do catálogo (retorna null,
  NÃO cacheia o miss; AC6).
- `tpu.cache.miss.redis_unavailable` — Redis fora do ar (fallback DB; AC7).
- `tpu.cache.miss.json_corrupt` — JSON em cache corrompido (descarta + DB).
- `tpu.cache.set.failed` — falha ao popular cache (best-effort).
- `tpu.cache.invalidation.failed` — falha SCAN/DEL (TTL 35d cobre).
- `tpu.adapter.attempt.{success,failed}` — tentativa HTTP individual.
- `tpu.adapter.circuit_breaker.{opened,half_open}` — estado do CB.
- `tpu.redis.{connected,unavailable}` — health do RedisConnection.

## Como testar

```bash
composer install
vendor/bin/phpunit                 # ≥14 testes verdes (suite do togare-tpu)
```

Para integration (depende do site/ EspoCRM e Redis rodando):
```bash
node build --copy && cd site && php vendor/bin/phpunit tests/integration/Espo/Modules/TogareTpu
```

**Em CI / dev sem rede:** o `PdpjAdapter` aceita `TOGARE_TPU_BASE_URL` com
scheme `file://` apontando para fixtures locais — ver
`tests/contracts/fixtures/pdpj/` (Dev Notes §5 da Story 3.3).
