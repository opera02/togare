# ADR 0009 — Alinhar retry do worker com fechamento do circuit breaker do adapter

**Data:** 2026-05-09
**Status:** Aceito

## Contexto

A integração com a Comunica API do CNJ (DJEN) tem **duas camadas independentes de proteção contra falha** que hoje **não conversam entre si**:

1. **Circuit breaker (CB) do `DjenAdapter`** (togare-djen, [`Services/DjenAdapter.php:45-48`](../../espocrm/togare-djen/src/files/custom/Espo/Modules/TogareDjen/Services/DjenAdapter.php#L45-L48)): 5 falhas em 300s abrem o CB por **600s (10 min)**. Estado persistido em flat-file (`sys_get_temp_dir() . '/togare-djen-circuit-breaker.json'`).

2. **Retry da fila `togare_queue_items`** ([`QueueService::markFailed`](../../espocrm/togare-core/src/files/custom/Espo/Modules/TogareCore/Services/QueueService.php#L233-L326)): `DjenWorkerService::processOne` captura `DjenAdapterUnavailableException` e chama `markFailed(itemId, reason, false, 3600)` — agendando `next_retry_at = now + 1h` (Decisão #5 da Story 4a.1).

O **gap problemático**: quando o CB abre, ele se recupera em 600s, mas qualquer item enfileirado na janela (sync_window do cron `0 6 * * 1-5` BRT, sync manual via UI futuro, etc.) fica preso por **até 1h** mesmo que a Comunica API tenha voltado em 10min. No piloto m4 com Felipe revisando publicações pela manhã, esse lag silencioso é inaceitável — o advogado abre o Togare às 09:00, vê "0 publicações novas", e na verdade o sistema está esperando 50 minutos para tentar de novo enquanto a fonte já está saudável.

A retrospectiva do Epic 4a (2026-05-06) catalogou esse desalinhamento como Decisão A5 e bloqueou o início da Story 4b.4 (banner "DJEN indisponível >30min") até este ADR ser escrito — porque o banner reflete um estado UI que precisa coincidir com a realidade da fila, não com o cronograma de retry pessimista.

Restrições adicionais que moldam a decisão:

- **Sem dependência de Redis para coordenação**: Redis é exclusivo do togare-tpu (cache TPU mensal). Trazer Redis para coordenação CB×worker seria expandir a superfície operacional sem ROI no MVP.
- **Sem broker de eventos externo**: o `EventBusContract` da togare-core (Story 1a.4a) é síncrono, in-process — útil para hooks, mas não atravessa o boundary process (worker × adapter rodam no mesmo container `togare-djen-worker`, então in-process é suficiente, mas ainda assim queremos minimizar acoplamento).
- **Estado do CB hoje vive em flat-file local** ao container do worker. Não há tabela `togare_circuit_breaker_state`. Mover para DB é uma decisão paralela (escopo do Epic 10 talvez).
- **Padrão potencialmente generalizável**: o `PdpjAdapter` (togare-tpu, Story 3.3) tem CB idêntico. Mas TPU sincroniza 1× por mês — gap de 1h vs 10min é irrelevante. AASP futuro (Epic Growth) pode reaproveitar o pattern.

## Decisão

**Adotar reagendamento ativo do worker no fechamento do CB**, com 5 sub-decisões vinculantes:

### 1. Detecção da transição open→closed via worker tick (não via callback do adapter)

No início de cada `DjenWorkerService::processOne()`, **antes do `claim()`**, o worker:

1. Lê o state-file do CB do `DjenAdapter`.
2. Se `state.open_until > 0 AND state.open_until <= time()`:
   - Detectou transição open→closed desde o último tick.
   - Dispara `QueueService::rescheduleAfterCircuitBreakerClose(queueName: 'djen', failureCategory: 'adapter_unavailable')`.
   - Limpa `state.open_until` (zera flag — `state.failures` permanece pra contagem da próxima janela).
3. Em seguida, prossegue com `claim()` normal.

**Por quê worker tick e não callback no adapter?** O adapter é stateless por chamada; introduzir callback acopla a vida do CB ao ciclo de retry da fila. Worker tick é leitura barata (1 fopen + json_decode em arquivo de poucos KB), roda no mesmo loop que já dorme 5s na fila vazia, e tem lag máximo de 5s. Para integrações de minutos/horas, lag de segundos é invisível.

**Concorrência:** se 2 workers detectarem a transição simultaneamente, ambos disparam o reschedule. O UPDATE é idempotente (cláusula `WHERE next_retry_at > :now`) — segundo worker faz no-op. Sem lock distribuído.

### 2. Categorização semântica de falha via novo campo `failure_category`

**Migration V017 em togare-core** adiciona coluna `failure_category VARCHAR(40) NULL` em `togare_queue_items` + `INDEX idx_togare_queue_failure_category (queue_name, status, failure_category)`. NULL é o default — items pré-V017 não recebem categoria, comportamento histórico preservado. (V016 está reservada para `togare_prazo_lembrete` da Story 4b.2 — verificado em [`Migration/`](../../espocrm/togare-core/src/files/custom/Espo/Modules/TogareCore/Migration/) ao escrever este ADR.)

`QueueService::markFailed()` ganha **6º parâmetro opcional** `?string $failureCategory = null`. Quando informado, é gravado na coluna. Isso é cross-cutting:

- `DjenWorkerService::processOne` passa `'adapter_unavailable'` no catch `DjenAdapterUnavailableException`.
- `DjenWorkerService::processOne` passa `'license_expired'` ou `'forbidden'` (via `classifyForbidden`) no catch `Forbidden`.
- Catch `Throwable` genérico passa `null` (categoria desconhecida — não habilita reschedule).

**Por quê coluna nova e não filtragem por `last_error LIKE`?** O `reason` é texto livre destinado a operador humano; depender dele como chave semântica é frágil (qualquer reescrita de mensagem quebra o reschedule silenciosamente). Coluna explícita custa 1 migration trivial e habilita filtro por índice.

**Por quê `VARCHAR(40)` e não enum?** Categorias evoluem por adapter — adicionar valor não pode exigir migration. Convenção de naming: snake_case lowercase, prefixo opcional do domínio (`djen_*`, `tpu_*`) quando a categoria não for genérica. Validação fica como contrato no código que chama `markFailed`, não no schema.

### 3. Reschedule transacional via SQL único

`QueueService::rescheduleAfterCircuitBreakerClose(string $queueName, string $failureCategory): int` executa **um único UPDATE**:

```sql
UPDATE togare_queue_items
SET next_retry_at = :now,
    updated_at = :now
WHERE queue_name = :q
  AND status = 'failed_retry'
  AND failure_category = :cat
  AND next_retry_at > :now
```

Retorna `rowCount()` para o worker logar `djen.queue.rescheduled_after_cb_close` com `count=N`. Não toca `retry_count` (a tentativa atual permaneceu legítima — só o agendamento mudou). Não toca `last_error` (preserva diagnóstico).

**Sem transação explícita**: UPDATE atômico no MariaDB. O `claim()` subsequente do mesmo worker (ou de paralelo) competirá normalmente via `FOR UPDATE SKIP LOCKED` — items reagendados ficarão imediatamente elegíveis, sem corrida.

### 4. Idempotência por construção, não por lock

A combinação `WHERE next_retry_at > :now` garante que reschedule rodado N vezes em N workers gere o mesmo resultado final. Não há necessidade de:

- Lock distribuído (advisory lock MariaDB seria a alternativa — descartada por ser overhead para problema sem corrida real).
- Coluna `last_reschedule_at` (poderia evitar UPDATE redundante, mas o cost da query no-op é trivial vs complexidade adicional).

### 5. Escopo DJEN-only no MVP; generalização TPU/AASP fica para Growth

A coluna `failure_category` em `togare_queue_items` é **genérica desde o nascimento** — qualquer adapter futuro pode usar. Mas o método `rescheduleAfterCircuitBreakerClose` e o tick check no worker são **escopo DJEN no MVP**. Razões:

- TPU sincroniza mensalmente. Mesmo se CB abrir, o próximo cron é 30 dias depois — lag de 1h é invisível. ROI de tornar tpu-worker tick-aware do CB = zero.
- AASP é Epic Growth (não-MVP). Quando chegar, replica o pattern em ~30 linhas.

Não inverter prematuramente (criar `AbstractWorkerWithCbAlignment` em togare-core) — o Togare segue a regra de 3 do CLAUDE.md ("três linhas similares é melhor que abstração prematura"). Quando AASP virar realidade, refactor é local e barato.

## Consequências

- ✅ **Lag máximo entre CB-recupera e retry = 5s** (intervalo do `sleep` do worker em fila vazia), vs até 1h hoje.
- ✅ **Story 4b.4 desbloqueada**: o banner "DJEN pausada há {N}min. Próxima tentativa às {HH:MM}" agora reflete realidade — quando CB fecha, próximo tentativa é "agora", não "daqui 50min" enganador.
- ✅ **Sem nova dependência operacional**: zero Redis, zero broker, zero advisory lock. Continua sendo flat-file + MariaDB.
- ✅ **Cross-cutting limpo via `failure_category`**: futuras categorias (`rate_limit_exceeded`, `payload_invalid`, `dns_failure`) podem disparar políticas distintas sem nova coluna.
- ✅ **Observabilidade reforçada**: log event `djen.queue.rescheduled_after_cb_close` com `count` permite monitorar quão frequente é o gap em produção (sinal pra calibrar `CIRCUIT_BREAKER_OPEN_DURATION_SECONDS` ou o `CUSTOM_DELAY_SECONDS` do worker no Epic 10).
- ⚠️ **Trade-off**: worker faz 1 leitura de arquivo por tick mesmo quando CB nunca abriu (99% do tempo). Custo medido: ~0.5ms por tick em SSD. Aceitável.
- ⚠️ **Risco residual**: se o flat-file do CB for corrompido ou deletado entre ticks, o worker vê `state = []` e não detecta transição. Mitigação: o próprio CB já tolera state vazio (recomeça contagem); o pior caso é um ciclo de retry pessimista — exatamente o comportamento atual.
- ⚠️ **Migration V017 toca tabela quente** (`togare_queue_items`). Em volume MVP (centenas de rows) é instantâneo. Em Growth (>1M rows), `ADD COLUMN ... NULL` no MariaDB ≥10.3 usa `ALGORITHM=INSTANT` — operacional mesmo em produção carregada. Validar via `SHOW CREATE TABLE` pós-install.
- ⚠️ **Acoplamento contratual entre togare-djen e togare-core**: o worker lê path de state-file do adapter (concrete coupling). Mitigação: expor `DjenAdapter::getCircuitBreakerState(): ?array` como método público + interface, em vez de o worker ler arquivo direto. Detalhe de implementação deixado para Story 4b.4 — o ADR fixa o contrato comportamental, não a assinatura.

## Alternativas consideradas

**A) Adapter dispara evento via `EventBusContract` quando CB fecha.**
Mais "limpo" arquiteturalmente (worker desacoplado do state-file). Descartada porque o EventBus síncrono in-process exige que o adapter saiba **quando** verificar a transição — só dá pra detectar no próximo `guardCircuitBreaker()` chamado por novo `fetchPublications()`. Mas se a fila está vazia, ninguém chama `fetchPublications()` — o evento nunca dispara. Worker tick resolve sem essa armadilha.

**B) Job scheduled separado (`*/1 * * * *`) que monitora CB e reagenda.**
Adiciona um cron novo no `scheduledJobs.json` + uma classe Job + execução condicional. Sobrecarrega o `espocrm-daemon` com tick que 99% das vezes é no-op. Worker tick reaproveita loop já existente.

**C) Reduzir `CUSTOM_DELAY_SECONDS` do worker de 3600 para 600.**
Faria retry sempre dentro do CB cooldown — mas perderia a lógica de "se a fonte está fora do ar de verdade, não martele 5x em 10min". O CUSTOM_DELAY=3600 protege contra adapter inventando falhas espúrias (rate-limit do upstream, DNS instável, etc.). Reduzir é jogar fora proteção real para resolver caso minoritário.

**D) Filtrar por `last_error LIKE 'djen sync window failed:%'`.**
Funciona sem migration. Descartada por fragilidade — qualquer i18n ou refactor da mensagem quebra o reschedule sem compile-time check. Coluna explícita custa 1 migration trivial.

**E) Tabela `togare_circuit_breaker_state` em DB (substitui flat-file).**
Removeria o acoplamento worker↔arquivo do adapter. Mas é refactor cross-module independente desta decisão; pode acontecer no Epic 10 sem invalidar este ADR. Por ora, flat-file continua adequado (1 worker, 1 container).

## Referências

- [Decisão A5 da retro Epic 4a](../../_bmad-output/implementation-artifacts/epic-4a-retro-2026-05-06.md) (2026-05-06) — origem desta decisão.
- [ADR 0005 — Outbox queue MariaDB](./0005-outbox-queue-mariadb.md) — define `togare_queue_items` e o contrato de retry.
- [ADR 0003 — EspoCRM extensions pattern](./0003-espocrm-extensions-pattern.md) — namespace e empacotamento dos módulos togare-*.
- Story 4a.1 ([epic-4a](../../_bmad-output/planning-artifacts/epics.md)) — origem do `customDelaySeconds` em `markFailed` (togare-core 0.15.0) e da política de retry de 1h pra `DjenAdapterUnavailable`.
- Story 4a.6 — rate-limit DJEN explícito; documentou que rate-limit DB-down é fail-open e **não** conta para o CB (separação de sinais que este ADR preserva).
- Story 4b.4 (ready-for-dev após este ADR) — implementa o tick check no worker, a Migration V017, o método `rescheduleAfterCircuitBreakerClose` e o banner UI consumidor.
- Spike 1b.S2 ([relatório](../../_bmad-output/implementation-artifacts/1b-S2-spike-pools-daemon-relatorio.md)) — confirma que pools segregados via N containers (Variante B) significa que o CB do DjenAdapter vive em 1 container só; este ADR assume essa topologia.
