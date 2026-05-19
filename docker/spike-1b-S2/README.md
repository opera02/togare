# Spike 1b.S2 — Pools segregados do espocrm-daemon por queue_name

> **⚠️ THROWAWAY — uso restrito à Spike 1b.S2.**
>
> **Fase 1 (sanity local, atual):** validação funcional de pools por `queue_name`.
> **Fase 2 (bench VPS, pendente Epic 10):** esta pasta será reutilizada para rodar k6/loadgen em VPS baseline.
>
> **NÃO subir esses recursos no compose principal** (`docker/docker-compose.yml`).
> Isolamento garantido: compose em `docker-compose.spike.yml` (arquivo diferente).
>
> Esta pasta permanece no repo até a Fase 2 fechar; só então é removida de vez.

## O que esta spike valida

Funcionalmente: **workers com `TOGARE_QUEUE_NAME=djen` consomem APENAS items
de `queue_name='djen'`; workers com `TOGARE_QUEUE_NAME=internal` consomem
APENAS items de `queue_name='internal'`; um worker DJEN ocupado (sleep 30s/job)
NÃO bloqueia o worker INTERNAL em paralelo (sleep 1s/job).**

Isso é a premissa-chave do [ADR 0005](../../docs/decisoes/0005-outbox-queue-mariadb.md)
item 4 (pools segregados → NFR3). Se passar, o ADR vira "Aceito condicionalmente" e
a [Story 4a.1 (togare-djen adapter)](../../_bmad-output/planning-artifacts/epics.md)
destrava.

## Arquitetura do spike (Variante B da Task 3.2)

```
                            ┌────────────────────────┐
                            │   mariadb-spike        │
                            │   (MariaDB 11.4 tmpfs) │
                            │   togare_queue_items   │
                            └────────────────────────┘
                                ▲         ▲
              filtra queue_name │         │ filtra queue_name
              = 'djen'          │         │ = 'internal'
                                │         │
    ┌───────────────────────────┴─┐   ┌───┴──────────────────────────┐
    │ worker-djen                 │   │ worker-internal              │
    │ image: espocrm/espocrm:9.3  │   │ image: espocrm/espocrm:9.3   │
    │ cmd:   php queue-worker.php │   │ cmd:   php queue-worker.php  │
    │ env:   TOGARE_QUEUE_NAME=   │   │ env:   TOGARE_QUEUE_NAME=    │
    │        djen                 │   │        internal              │
    └─────────────────────────────┘   └──────────────────────────────┘
```

Por que 1 container por fila em vez de um único daemon multi-fila:

- **O `espocrm-daemon` nativo (`/var/www/html/daemon.php`) spawna `cron.php` que
  chama `Espo\Core\Job\JobManager::process()`** — processa a tabela `job` do
  EspoCRM (emails, cleanup, etc.), não o `togare_queue_items`. Não há config
  nativa `poolByQueue`; nenhuma variante A/B "config only" existe no core.
- Segregação por container é a forma mais simples de garantir isolamento
  real: kernel scheduler + SKIP LOCKED fazem o trabalho. Sem PHP compartilhado
  entre filas, sem ponte de código entre elas.
- Reutiliza 100% o `QueueService` da Story 1a.4c sem patch. Nenhuma linha
  a mais de togare-core em produção.
- Se a Fase 2 mostrar que precisa de escala dentro de uma fila, basta subir
  `docker compose up -d --scale worker-djen=N` — workers adicionais competem
  via SKIP LOCKED.

## Arquivos

- `docker-compose.spike.yml` — compose isolado (4 serviços: mariadb-spike,
  worker-djen, worker-internal, spike-cli com profile `cli`).
- `docker-compose.fallback.yml` — override que troca os 2 workers por 1
  container supervisord com 2 workers internos (Plano B / ADR 0005b draft).
  **A ser criado na Task 6** — ainda não existe nesta fase.
- `scripts/init-schema.php` — cria `togare_queue_items` (reusa Migration V004).
- `scripts/queue-worker.php` — worker standalone, lê `TOGARE_QUEUE_NAME` do env
  e loopa `claim → sleep(payload.simulatedSleepSeconds) → markDone`.
- `scripts/seed-jobs.php` — enfileira N jobs sintéticos por fila via
  `QueueService::enqueue()`.
- `fallback/Dockerfile`, `fallback/supervisord.conf`, `fallback/queue-worker.php`
  — a ser criado na Task 6.
- `fixtures/capture-djen-payloads.sh` — script para capturar payloads DJEN reais
  da Comunica API pública (API pública CNJ). **Não executa na Fase 1**; serve
  como insumo da Fase 2 (bench VPS).
- `sanity-results/` — logs e outputs SQL capturados durante sanity. Evidência
  empírica anexada ao relatório `_bmad-output/implementation-artifacts/1b-S2-spike-pools-daemon-relatorio.md`.

## Receita operacional (sanity local)

```bash
cd docker/spike-1b-S2

# 1) Sobe só o DB.
docker compose -f docker-compose.spike.yml up -d mariadb-spike

# 2) Cria schema (idempotente; --reset dropa antes).
docker compose -f docker-compose.spike.yml run --rm spike-cli \
  /opt/togare-spike/init-schema.php --reset

# 3) Sobe os 2 workers (1 por fila).
docker compose -f docker-compose.spike.yml up -d worker-djen worker-internal

# 4) Enfileira jobs sintéticos (5 DJEN sleep 30s + 5 INTERNAL sleep 1s).
docker compose -f docker-compose.spike.yml run --rm spike-cli \
  /opt/togare-spike/seed-jobs.php --djen=5 --internal=5

# 5) Observa logs lado a lado por ~3 min.
docker compose -f docker-compose.spike.yml logs -f --timestamps \
  worker-djen worker-internal

# 6) Confere tempos via SQL.
docker compose -f docker-compose.spike.yml exec mariadb-spike \
  mariadb -uspike -pspike spike_db -e "
    SELECT queue_name, status, processing_started_at, completed_at,
           TIMESTAMPDIFF(SECOND, processing_started_at, completed_at) AS duration_s
      FROM togare_queue_items
     ORDER BY queue_name, id;
  "

# 7) Teardown completo (preserva esta pasta no repo).
docker compose -f docker-compose.spike.yml down -v
```

## Critério de sucesso sanity (AC2)

A sanity passa SE:

1. Todos os 5 items `internal` têm `status='done'` e `completed_at`
   **antes** de qualquer item `djen` ter `completed_at`. (Prova que
   INTERNAL não fica bloqueado por DJEN.)
2. `SELECT ... WHERE queue_name='djen' AND status='done'` count == 5.
3. `SELECT ... WHERE queue_name='internal' AND status='done'` count == 5.
4. Logs de `worker-djen` NÃO mencionam `spike-internal-*`.
5. Logs de `worker-internal` NÃO mencionam `spike-djen-*`.

Se os 5 pontos acima baterem, ADR 0005 vira condicional e Story 4a.1 destrava.

Se falhar (ex.: worker-internal consumiu item djen, ou internal demorou >30s),
ADR 0005 é substituído pelo ADR 0005b (draft pré-pronto em
`docs/decisoes/drafts/0005b-php-worker-supervisord-por-fila.md`) e
o plano supervisord é promovido.

## Notas de plataforma

- **Windows + Docker Desktop + WSL2 (laptop Felipe):** I/O tmpfs ~2-3× mais
  lento que Linux nativo; sanity é **qualitativa** (isolamento on/off), não
  quantitativa. Números absolutos (segundos até completar) servem só de
  referência. Bench quantitativo fica para Fase 2 em VPS.
- **MSYS_NO_PATHCONV=1** pode ser obrigatório para `docker compose exec` em
  alguns comandos com caminhos (gotcha conhecida dos spikes anteriores).
