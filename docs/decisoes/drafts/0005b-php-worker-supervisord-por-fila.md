# ADR 0005b (draft) — PHP-worker standalone + supervisord como fallback para pools segregados

**Data:** 2026-04-24 (status final atualizado 2026-04-25)
**Status:** Não promovido — Variante B canônica (N containers consumer com `queue-worker.php` standalone) validada em sanity local 2026-04-24 (Spike 1b.S2 Fase 1). Bench VPS Fase 2 (Epic 10) reavalia se houver regressão de throughput.

> **Histórico:** este draft existiu **antes** da Spike 1b.S2 rodar (regra do PM John, epics.md linha 1806).
> Propósito: ter o plano B redigido em momento calmo, caso a Spike falhasse em Fase 1 ou Fase 2.
> A Fase 1 PASSOU em 2026-04-24, mas com uma descoberta importante: o `espocrm-daemon` nativo **não toca** `togare_queue_items` (processa só a tabela `job` do core). A receita canônica adotada — Variante B do plano da Spike — é **N containers consumer**, cada rodando `queue-worker.php` standalone que invoca `QueueService::claim($queueName)`. Topologicamente próxima à supervisord deste draft (workers standalone), mas mantém a imagem oficial `espocrm/espocrm:9.3` em vez de alpine + supervisord — operacionalmente mais simples. Este documento permanece arquivado em `drafts/` como contexto histórico.

## Contexto

Complementa o [ADR 0005](../0005-outbox-queue-mariadb.md), que escolheu outbox pattern em MariaDB
com `SELECT ... FOR UPDATE SKIP LOCKED` e **pools segregados por `queue_name` no `espocrm-daemon`**
— com a aposta de que o daemon nativo do EspoCRM 9.3 aceita configurar workers dedicados por fila
(djen, tpu, internal, lgpd_purge).

A aposta do 0005 carrega dois riscos:

1. **Risco funcional:** o `espocrm-daemon` é o cron daemon nativo da imagem `espocrm/espocrm:9.3`
   e **não está claro na docs oficial** se ele expõe configuração de pools por `queue_name` — pode
   ser single-pool global, exigindo patch leve no togare-core (variante C da Task 3.2 da Spike 1b.S2)
   ou, na pior hipótese, não suportar segregação real mesmo com patch.
2. **Risco quantitativo (NFR3):** mesmo com pools funcionais, a métrica a atender é **DJEN sync
   sintético de 50 advogados em ≤ 30 min sem causar latência > 10s nos jobs `internal`**, medido
   em VPS Linux baseline (4vCPU/8GB/SSD NVMe Ubuntu 22.04). Se pools nativos/patchados não
   isolarem sob carga real, `djen` bloqueia `internal` e viola o NFR3.

Se **qualquer** um desses riscos se materializar, o Togare precisa processar filas segregadas por
outro mecanismo sem refazer o contrato do `QueueService` (Story 1a.4c) nem o schema de
`togare_queue_items`. Este ADR descreve essa rota alternativa, já desenhada, para ser ativada via
feature flag.

Motivação secundária: supervisord garante restart-on-crash por worker independentemente, oferecendo
**isolamento em nível de processo** — um worker djen consumindo 100% CPU por 30min não afeta o
worker internal, que continua rodando em sua própria PID. Isso é uma garantia mais forte que
qualquer segregação cooperativa dentro de um único job runner PHP.

## Decisão alternativa

Se acionada, a fallback:

1. **`QueueService` do togare-core permanece inalterado** — `enqueue()`, `claim()`, `markDone()`,
   `markFailed()`, `reclaimStuck()` continuam sendo o contrato oficial. Nenhum módulo consumidor
   (Epic 4a togare-djen, Epic 3 sync TPU) precisa mudar uma linha.
2. **Container `espocrm-daemon` é substituído** no `docker-compose.yml` principal pelo container
   `togare-workers` (imagem construída a partir de `docker/togare-workers/Dockerfile`):

   ```dockerfile
   FROM php:8.3-cli-alpine
   RUN apk add --no-cache supervisor pdo_mysql
   COPY supervisord.conf /etc/supervisord.conf
   COPY queue-worker.php /usr/local/bin/queue-worker.php
   CMD ["supervisord", "-c", "/etc/supervisord.conf", "-n"]
   ```

3. **`init: true` no compose** — tini vira PID 1 para reapear zombies. supervisord (PID 2) gerencia
   os workers como children. Sem `init: true`, supervisord como PID 1 não tem reaper de zombies e
   workers zumbis acumulam.
4. **1 worker process por fila** definido no `supervisord.conf`:

   ```ini
   [supervisord]
   nodaemon=true
   user=root
   logfile=/dev/null
   pidfile=/tmp/supervisord.pid

   [program:worker-djen]
   command=php /usr/local/bin/queue-worker.php
   environment=TOGARE_QUEUE_NAME="djen",TOGARE_DB_HOST="%(ENV_TOGARE_DB_HOST)s",TOGARE_DB_NAME="%(ENV_TOGARE_DB_NAME)s",TOGARE_DB_USER="%(ENV_TOGARE_DB_USER)s",TOGARE_DB_PASSWORD="%(ENV_TOGARE_DB_PASSWORD)s"
   autorestart=true
   startretries=5
   stopwaitsecs=60
   stdout_logfile=/dev/stdout
   stdout_logfile_maxbytes=0
   stderr_logfile=/dev/stderr
   stderr_logfile_maxbytes=0

   [program:worker-internal]
   command=php /usr/local/bin/queue-worker.php
   environment=TOGARE_QUEUE_NAME="internal",TOGARE_DB_HOST="%(ENV_TOGARE_DB_HOST)s",TOGARE_DB_NAME="%(ENV_TOGARE_DB_NAME)s",TOGARE_DB_USER="%(ENV_TOGARE_DB_USER)s",TOGARE_DB_PASSWORD="%(ENV_TOGARE_DB_PASSWORD)s"
   autorestart=true
   startretries=5
   stopwaitsecs=60
   stdout_logfile=/dev/stdout
   stdout_logfile_maxbytes=0
   stderr_logfile=/dev/stderr
   stderr_logfile_maxbytes=0

   # [program:worker-tpu] — adicionar quando Epic 3 story 3.3 habilitar TPU sync
   # [program:worker-lgpd_purge] — adicionar quando Epic 8 habilitar purge LGPD
   ```

5. **`queue-worker.php` é um CLI standalone**, sem framework EspoCRM. Conecta no MariaDB via PDO
   direto, instancia `QueueService` com esse PDO, loopa:

   ```php
   while (true) {
       $items = $queueService->claim($queueName, batchSize: 1);
       if ($items === []) { sleep(1); continue; }
       foreach ($items as $item) {
           try {
               // Dispatch para handler registrado por queue_name + payload.
               // Handler é resolvido via convenção: \Togare\Workers\Handlers\{QueueName}Handler
               // ou map estático (ver --handlers-map na seção Consequências).
               $handler = HandlerRegistry::resolve($item['queue_name'], $item['payload']);
               $handler->handle($item);
               $queueService->markDone($item['id']);
           } catch (\Throwable $e) {
               $queueService->markFailed($item['id'], $e->getMessage());
           }
       }
   }
   ```

6. **Feature flag:** `TOGARE_QUEUE_BACKEND=supervisord` (ou `=espocrm-daemon` — default). No
   `docker-compose.yml`, a env controla qual container sobe: `espocrm-daemon` (default) OU
   `togare-workers` (fallback). Troca de implementação via rebuild + restart, sem schema/API mudar.
7. **Scheduled jobs nativos do EspoCRM** (cron entries do EspoCRM core: cleanupExpiredAuth,
   processEmailQueue, etc.) continuam tendo container `espocrm-scheduler` separado com o
   `docker-daemon.sh` original — **apenas as filas do outbox togare_queue_items migram para
   supervisord**. Isso evita perda de funcionalidade nativa.

## Consequências

- ✅ **Isolamento em nível de processo** — worker djen a 100% CPU não afeta worker internal.
  Kernel scheduler garante fatia justa.
- ✅ **Restart on crash independente** — supervisord recicla worker morto sem afetar os demais.
- ✅ **Contrato `QueueService` preservado** — zero impacto em módulos consumidores (togare-djen,
  togare-tpu, togare-lgpd, togare-nextcloud-bridge, …). Feature flag troca implementação sem
  refactor.
- ✅ **Observabilidade clara** — 1 PID por worker; `docker stats` mostra CPU/RAM por fila
  diretamente. Logs separados por worker no stdout.
- ✅ **Escala horizontal simples** — se uma fila satura, aumentar concorrência é editar
  `supervisord.conf`: `numprocs=N` no program. Kernel + SKIP LOCKED garantem segurança.
- ⚠️ **Scheduled jobs nativos do EspoCRM** precisam mecanismo separado. Mitigação: container
  `espocrm-scheduler` dedicado rodando `docker-daemon.sh` original **sem processar** filas
  togare_queue_items (`queue_name NOT IN ('djen','tpu','internal','lgpd_purge')`) — apenas jobs
  EspoCRM nativos. Duas soluções convivem: supervisord para outbox Togare + daemon nativo para
  scheduled jobs EspoCRM. Complexidade operacional: +1 container.
- ⚠️ **supervisord adiciona peça operacional** — mas é mínima (~10 linhas de config por fila),
  bem documentada, e não requer expertise especial do admin TI. Alpine package oficial.
- ⚠️ **Cada worker é processo PHP completo** — overhead memória ~50 MB/worker. Com 4 filas
  (djen, tpu, internal, lgpd_purge), isso é ~200 MB residente. Aceitável no baseline 8 GB RAM.
- ⚠️ **Handler registry separado do container EspoCRM** — `queue-worker.php` não tem acesso aos
  repositórios/hooks/services do EspoCRM. Se um handler precisar de operações complexas no modelo
  EspoCRM (ex: togare-djen parser que chama `$entityManager->getEntity()`), a opção é:
  - (a) rodar as operações via HTTP interno para EspoCRM (worker → curl para container
    `espocrm` → lógica PHP nativa). Adiciona latência ~20 ms por chamada mas mantém handlers
    simples no worker.
  - (b) bootar `/var/www/html/bootstrap.php` no worker (replica approach atual do
    `SpikeQueueHandler`). Perde o benefício de CLI leve mas preserva acesso total ao EspoCRM.
  **Decisão deferida para o momento da promoção do ADR** — depende do handler concreto. Para
  MVP, opção (b) é mais pragmática; opção (a) vira backlog de refactor se latência começar
  a morder.
- ⚠️ **Logs de worker não aparecem no log aggregator do EspoCRM** — supervisord envia stdout
  direto pro docker log. Mitigação: `TogareLogger::event()` continua sendo chamado pelo
  `QueueService` (job claim/done/failed), o que preserva audit trail estruturado na tabela
  `togare_audit_log`. O stdout do worker é diagnóstico/debug, não source of truth.
- ⚠️ **Gracefully shutdown** — SIGTERM do docker stop precisa dar tempo do worker finalizar
  item atual e sair. `stopwaitsecs=60` no supervisord cobre jobs internal típicos; jobs djen
  podem levar até 30s (timeout Comunica API) — ajustável para 120s se preciso.
- ⚠️ **Pool contention dentro da mesma fila** — se numprocs > 1 no mesmo program, os N workers
  competem via `SKIP LOCKED` no mesmo `queue_name`. Comportamento OK (esse é o design do ADR 0005),
  mas se houver bug de item stale em processing, `reclaimStuck()` já cuida (Story 1a.4c).

## Alternativas consideradas (e descartadas aqui)

- **Criar múltiplos containers `espocrm-daemon`, 1 por fila, cada com env `QUEUE=<name>`:** é
  exatamente a abordagem primária da Spike 1b.S2 (variante B/C). Este ADR só é promovido se
  essa abordagem falhar.
- **Redis Streams / RabbitMQ como broker:** viola ADR 0005 princípio "zero broker externo".
  Custo operacional extra (mais peça, backup separado) alto demais para escritório de 5-30
  advogados auto-hosted. Mantido como opção futura se a base crescer para 100+ advogados.
- **Symfony Messenger standalone:** framework em cima de framework. Adiciona dependência
  pesada só para implementar o loop `claim → handle → done`, que cabem em 30 linhas de PHP.
- **Laravel Horizon ou outras soluções baseadas em Redis:** mesma razão + stack Redis não é
  pré-requisito do togare-core (usado hoje só pelo Nextcloud, não pelo EspoCRM).
- **systemd unit no host:** quebra portabilidade. Admin TI freelancer acostumado com docker
  perde o modelo "1 comando sobe tudo".

## Critério de promoção deste ADR para "Aceito"

Este ADR é promovido se **qualquer uma** das condições abaixo for verdadeira:

1. **Fase 1 falhou:** sanity local de pools nativos do `espocrm-daemon` (Spike 1b.S2, AC2)
   retornou isolamento quebrado — worker configurado para `queue_name=djen` consumiu items
   de `queue_name=internal`, OU items `internal` não finalizaram antes de items `djen` apesar
   de serem enfileirados juntos com sleep muito diferente. Decisão tomada agora; promoção
   imediata; ADR 0005 atualizado para status "Substituído por ADR 0005b".
2. **Fase 2 falhou:** bench VPS (Epic 10, story 10.X-bench-nfr-spikes a criar) retornou:
   - DJEN sync 50adv > 30 min (violação hard do NFR3), OU
   - p95 latência `internal` > 10s com `djen` em paralelo (contenção detectada),
   e essas violações não foram mitigáveis por ajuste trivial de concorrência (numprocs,
   batchSize) dentro do design nativo. Promoção é feita junto com abertura de PR na Story 4a.1
   (togare-djen) trocando env `TOGARE_QUEUE_BACKEND` e atualizando compose principal para
   subir `togare-workers` em vez de `espocrm-daemon`.

Critério negativo (NÃO promove): sanity passou E bench ficou dentro dos limites (DJEN ≤ 30min
E p95 internal ≤ 10s), mesmo que com folga justa. Nesses casos, ADR 0005 vira "Aceito" definitivo.

## Resultado (Spike 1b.S2 Fase 1 — 2026-04-24)

A Fase 1 da Spike 1b.S2 passou os 2 critérios funcionais de aceitação com **ambas** as topologias:

- **Sanity AC2 (Variante B canônica — N containers consumer + `espocrm-daemon` separado):** 5 jobs `djen` (sleep 30s) + 5 jobs `internal` (sleep 1s) enfileirados na mesma janela ~80ms. Último `internal` às 15:56:13; primeiro `djen` às 15:56:37 (gap 24s). Zero contaminação cruzada nos logs.
- **Sanity AC3 (Plano B supervisord — este draft):** mesmo workload com container alpine + supervisord + 2 workers standalone (sem framework EspoCRM). Último `internal` às 16:02:05; primeiro `djen` às 16:02:30 (gap 25s). Isolamento confirmado.

A descoberta-chave foi que o `espocrm-daemon` nativo **não toca** `togare_queue_items` — segregação é sempre código Togare. A Variante B foi adotada como canônica porque mantém imagem oficial (sem Dockerfile custom) e operacionalmente é equivalente a este draft com menor superfície. **Este documento NÃO foi promovido**, mas a infraestrutura mental está pronta caso a Fase 2 reprove a Variante B.

**Caso a Fase 2 (bench VPS no Epic 10) revele regressão de throughput** (DJEN sync > 30min OU p95 `internal` > 10s sustentado), este documento é reativado:
- Trocar imagem oficial por alpine + supervisord conforme decisão técnica.
- Atualizar `docker/docker-compose.yml` da stack principal.
- Atualizar status deste draft para `Aceito` + atualizar status do ADR 0005 para `Substituído por 0005b`.

Relatório completo da Fase 1: [1b-S2-spike-pools-daemon-relatorio.md](../../../_bmad-output/implementation-artifacts/1b-S2-spike-pools-daemon-relatorio.md).

## Referências

- [ADR 0005 — Outbox pattern em MariaDB com SKIP LOCKED](../0005-outbox-queue-mariadb.md)
- [Story 1b.S2 — Spike NFR3 pools daemon](../../../_bmad-output/implementation-artifacts/1b-S2-spike-pools-espocrm-daemon-djen-sync.md)
- [Relatório Spike 1b.S2 Fase 1](../../../_bmad-output/implementation-artifacts/1b-S2-spike-pools-daemon-relatorio.md)
- [PRD — NFR3 (DJEN sync 50adv ≤ 30min)](../../../_bmad-output/planning-artifacts/prd.md)
- [Architecture — Pools daemon + outbox](../../../_bmad-output/planning-artifacts/architecture.md)
- [QueueService — Story 1a.4c](../../../espocrm/togare-core/src/files/custom/Espo/Modules/TogareCore/Services/QueueService.php)
- [supervisord — docs oficiais](http://supervisord.org/)
- [tini (init: true) — docs docker](https://docs.docker.com/reference/compose-file/services/#init)
