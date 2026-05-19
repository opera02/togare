# TogareDjen

## 1. O que faz

Importa publicações do **Diário de Justiça Eletrônico Nacional (DJEN)** via Comunica API PJe (`comunicaapi.pje.jus.br/api/v1`) com degradação graciosa: timeout 30s, retry 3x backoff exponencial, circuit breaker, fila persistente para retry em 1h.

Story **4a.1** entrega:

- Adapter Comunica API resiliente.
- Scheduled job diário 06:00 BRT seg-sex que enfileira **1 janela de sync por advogado** com OAB cadastrado.
- Worker dedicado (`togare-djen-worker`) que consome a fila `djen`, dispatcher por `payload.type` (`sync_window` → busca pubs e enfileira itens `publication`; `publication` → handler stub aplicado nesta story; **substituído por handler real na Story 4a.2 — ver §7**).
- Tabela auxiliar `togare_djen_user_state` para rastrear `last_synced_at` por advogado.
- Patch entityDefs/User adicionando 2 fields opcionais `oabNumber` + `oabUf`.

**FRs/NFRs:** FR12 parcial (fetch + enfileiramento), NFR18 (zero perda silenciosa), NFR24 (`PublicationSourceAdapterContract` pluggable), NFR27 (timeout/retry/CB universal).

**O que NÃO faz** (próximas stories do Epic 4a):

- ~~Parser Res. CNJ 455 art. 5º (dataFatal calculada) → 4a.2.~~ ✅ **Entregue na 4a.2 v0.2.0 — ver §7.**
- Criação automática de Prazo vinculado a Processo → 4a.3.
- CardDePrazo + Confirmar 1-clique → 4a.4.
- BriefingDoDia + alertas D-7/D-3/D-1 → 4a.5 / 4b.2.
- Banner DJEN indisponível >30min → 4b.4.
- ~~Rate-limit explícito 30 req/min → 4a.6.~~ ✅ **Entregue na 4a.6 v0.5.0 — ver §10.**

## 2. Escopo Story 4a.1

| Categoria | Entregue |
|---|---|
| Contract pluggable | `Contracts/PublicationSourceAdapterContract` |
| Adapter HTTP | `Services/DjenAdapter` (cURL + retry + CB file-based + httpExecutor injetável) |
| Scheduled job | `Jobs/TogareDjenSyncJob` (cron `0 6 * * 1-5` America/Sao_Paulo) |
| Window enqueuer | `Services/DjenWindowEnqueuer` (1 item `sync_window` por advogado) |
| Worker dispatcher | `Services/DjenWorkerService` (dispatch sync_window/publication + try/catch hierárquico) |
| User state repo | `Services/DjenUserStateRepository` (`togare_djen_user_state`) |
| Migration | `Migration/V001__create_togare_djen_user_state` |
| User entity patch | `Resources/metadata/entityDefs/User.json` (oabNumber/oabUf opcionais) |
| Worker entrypoint | `scripts/queue-worker.php` |

## 3. Dependências de versão

| Pacote | Versão mínima | Por quê |
|---|---|---|
| EspoCRM | 9.3.0 | Hooks, Job, Forbidden, Binding |
| PHP | 8.3 | readonly props, enums, match, named args |
| MariaDB | 10.6 | `SELECT FOR UPDATE SKIP LOCKED` no QueueService |
| `togare-core` | **0.16.0** | `QueueService::markFailed($id, $reason, $permanent, $customDelaySeconds)` (Story 4a.1, v0.15.0) + `BrazilianBusinessCalendar` (Story 4a.2, v0.16.0 — núcleo do parser CNJ 455) |
| `togare-licensing` | 0.2.1 | `ReadOnlyGate::isBlocked('togare-djen')` no DjenSyncJob |

## 4. Arquitetura interna

```
                       ┌─────────────────────────┐
                       │  TogareDjenSyncJob      │  cron `0 6 * * 1-5`
                       │  (espocrm-daemon)       │  America/Sao_Paulo
                       └──────────┬──────────────┘
                                  │ guard ReadOnlyGate
                                  ▼
                       ┌─────────────────────────┐
                       │  DjenWindowEnqueuer     │  itera advs c/ OAB
                       └──────────┬──────────────┘
                                  │ enqueue type=sync_window
                                  ▼
                  ┌──────────────────────────────────┐
                  │  togare_queue_items              │  outbox MariaDB
                  │  queue_name='djen'               │  SKIP LOCKED
                  └──────────────┬───────────────────┘
                                 │ claim
                                 ▼
                       ┌─────────────────────────┐
                       │  DjenWorkerService      │  container
                       │  (togare-djen-worker)   │  TOGARE_QUEUE_NAME=djen
                       └──────────┬──────────────┘
                                  │ dispatch payload.type
                       ┌──────────┴──────────┐
                       ▼                     ▼
            type=sync_window           type=publication
                  │                          │
                  ▼                          ▼
            DjenAdapter              handlePublicationStub
            (Comunica API)           (loga djen.publication.received,
                  │                   markDone — handler real chega
            yields N pubs              em 4a.2/4a.3 com parser CNJ 455)
                  │
                  └─→ enqueue type=publication (idempotency=djen.pub.<id>)
```

**Try/catch hierárquico do worker** (Decisão #5 — fecha invariante Spike 1b.S3):

1. `DjenAdapterUnavailableException` → `markFailed(reason, permanent=false, customDelaySeconds=3600)` (AC3).
2. `\Espo\Core\Exceptions\Forbidden` (license_expired pelo ReadOnlyGate hook em entidades premium) → `markFailed('license_expired', permanent=false, customDelaySeconds=3600)` (AC5.1; NÃO `dead_letter` — quando licença renovar, próximo claim recupera).
3. `\Throwable` outros → `markFailed(reason, permanent=false, customDelaySeconds=null)` (backoff padrão 60s→120s→...→960s).

## 5. Logs JSON estruturados (TogareLogger)

| Event | Level | Onde |
|---|---|---|
| `djen.sync.started` | info | DjenSyncJob início |
| `djen.sync.window_enqueued` | info | por advogado enfileirado |
| `djen.sync.window_capped` | info | quando dataInicio estoura cap D-7 |
| `djen.sync.skipped_license_expired` | warning | ReadOnlyGate bloqueou |
| `djen.sync.completed` | info | totals no fim |
| `djen.sync.job_failed` | error | catch-all do job (NÃO relança) |
| `djen.adapter.attempt.success` | info | tentativa HTTP OK |
| `djen.adapter.attempt.failed` | warning | tentativa HTTP falhou |
| `djen.adapter.circuit_breaker.opened` | error | 5 falhas em 5min |
| `djen.adapter.circuit_breaker.half_open` | info | sucesso após reset |
| `djen.adapter.payload_malformed` | error | JSON inválido / shape inesperado |
| `djen.publication.received` | info | handler stub processou pub |
| `djen.worker.license_expired_retry` | warning | item re-enfileirado por licença |
| `djen.worker.unknown_payload_type` | error | payload sem `type` válido |

## 6. Pendências (próximas stories)

- 4a.3: criar entidade Prazo com `togarePremium.module='togare-djen'` (ativa ReadOnlyGate automaticamente) — consome `PrazoCalculado` da Story 4a.2; cria Prazo em rascunho quando `confidence=low` ou sem match Processo.
- 4a.4: CardDePrazo + Confirmar 1-clique + ToastTogare undo 10s — usa `dataFatal` + `confidence` + `fonteExcerpt` da Story 4a.2.
- ~~4a.6: integrar `RateLimiter::peek('djen', user)` no DjenAdapter antes de cada request HTTP.~~ ✅ **Entregue na 4a.6 v0.5.0 — ver §10.**
- Epic 10: scheduled job mensal de housekeeping do `togare_queue_items` (ADR 0005 — purga `done` >90d).
- Epic 10: re-cálculo histórico ao bumpar `DjenPrazoRules::REGRA_VERSAO` para 2.x (ADR-02).

## 7. DjenParserService — art. 5º Res. CNJ 455 (Story 4a.2, v0.2.0)

**Núcleo jurídico do módulo.** Substitui o `handlePublicationStub` da
4a.1 por um pipeline determinístico que calcula `dataFatal` de cada
publicação aplicando estritamente o art. 5º com **regra inclusiva**
(disponibilização não conta; D+1 dia útil forense é o **1º dia da
contagem** inclusive — confirmado por Felipe 2026-05-03):

```
// Informativo (1º dia da contagem inclusive — exibido na UI 4a.4):
dataInicioPrazo = nextBusinessDay(dataDisponibilizacao)

// Cálculo do prazo legal final:
dataFatal = (contagem === 'uteis')
    ? addBusinessDays(dataDisponibilizacao, prazoDias)
    : addCalendarDays(dataInicioPrazo, prazoDias - 1)
```

**Por que `addBusinessDays(dataDisp, prazoDias)` direto para úteis?**
A semântica exclusiva do método ("n-ésimo útil DEPOIS de start") é
exatamente equivalente, com `start = dataDisp`, a "1º útil seguinte =
dia 1 + (N-1) úteis adicionais". Não precisa de passo intermediário.

**Para corridos (cumprimento de sentença CPC art. 523)** o ajuste é
diferente: `nextBusinessDay(disp)` para descobrir o 1º dia + `addCalendarDays(start, N-1)`
para o N-ésimo dia inclusive (calendar puro não pula nada).

**Exemplo AC6 (contestação)**: disp sex 15/05/2026 + 15 úteis → fatal
seg 08/06/2026 (Corpus Christi 04/06 pulado dentro da contagem).

**Exemplo AC7 (cumprimento sentença)**: disp sex 15/05/2026 + 15 corridos
→ dataInicioPrazo seg 18/05 + 14 corridos = seg 01/06/2026.

### 7.1 Componentes

| Classe | Papel |
|---|---|
| `BrazilianBusinessCalendar` (togare-core 0.16.0) | Feriados nacionais BR + aritmética dia útil/corrido. |
| `DjenAtoClassifier` | Regex+keyword 100% determinístico (ZERO IA/LLM, princípio CLAUDE.md). Retorna `DjenAtoClassificacao` com `atoCodigo`, `confidence` (high/medium/low), `fonteExcerpt` (≤100 chars), `prazoDiasOverride` (opcional). |
| `DjenPrazoRules` | Dicionário estático CPC com 11 atos. `REGRA_VERSAO=1.0.0` (semver — ADR-02). |
| `DjenParserService` | Orquestra: classifier → lookup rule → calendar.calc. Service puro (zero dep DB/HTTP). |
| `PrazoCalculado` | DTO readonly de saída. Consumido por `DjenWorkerService::handlePublication` e por `Story 4a.3` (criação de Prazo). |

### 7.2 Atos cobertos no MVP (DjenPrazoRules v1.0.0)

| atoCodigo | Dias | Contagem | Referência |
|---|---|---|---|
| `contestacao` | 15 | úteis | CPC art. 335 |
| `recurso_apelacao` | 15 | úteis | CPC art. 1003 |
| `embargos_declaracao` | 5 | úteis | CPC art. 1023 |
| `agravo_instrumento` | 15 | úteis | CPC art. 1003 §5º |
| `agravo_interno` | 15 | úteis | CPC art. 1021 |
| `cumprimento_sentenca` | 15 | **corridos** | CPC art. 523 |
| `impugnacao_cumprimento` | 15 | úteis | CPC art. 525 |
| `replica` | 15 | úteis | CPC art. 350 |
| `quesitos_pericia` | 15 | úteis | CPC art. 465 §1º |
| `manifestacao_geral_intimacao` | 15 (override do texto) | úteis | CPC art. 218 |
| `manifestacao_generica` (fallback) | 15 | úteis | CPC art. 218 |

### 7.3 Decisões vinculantes

1. **Calendário em togare-core, não em togare-djen** — cross-cutting (Audiência, Financeiro também usam).
2. **Classificação 100% determinística** — CLAUDE.md "IA não trava o bot" + custo LLM proibitivo + auditável.
3. **Dicionário estático em código (não DB)** — override por escritório fica para Growth.
4. **Override de prazo do texto** vale APENAS para `manifestacao_geral_intimacao` (atos legalmente fixos como contestação ignoram texto contraditório E logam warning `djen.parser.text_overrides_law`).
5. **Parser retorna DTO em memória** — não cria entity Prazo (4a.3) nem re-enfileira (atende NFR3).
6. **`regraVersao` no output** — ADR-02 documenta política semver e re-cálculo histórico.

### 7.4 Eventos novos da Story 4a.2

| Evento | Nível | Quando |
|---|---|---|
| `djen.publication.parsed` | info | parser calculou `dataFatal` (campos completos do PrazoCalculado) |
| `djen.parser.classifier_lowconfidence` | warning | confidence=low (fallback `manifestacao_generica`) |
| `djen.publication.unparsed` | info | ato puramente certificatório (parser retornou null) |
| `djen.parser.text_overrides_law` | warning | texto cita prazo divergente da lei aplicável; parser aplicou lei |

### 7.5 Limitações conhecidas (Open Questions Story 4a.2)

- **Recesso forense fim de ano** (CPC art. 220, 20/12-6/1) — semântica diferente (suspensão ≠ feriado). MVP trata como dia útil normal. Story 4b.x ou Epic 10.
- **Feriados estaduais/municipais** — variam por OAB.uf. MVP só nacionais (parser conservador). Growth.
- **Múltiplos prazos no mesmo texto** — parser pega o primeiro match em ordem de patterns. Se 2 prazos relevantes, `confidence=high` mesmo assim — Growth detecta e cria 2 prazos distintos.
- **Texto contradiz lei** — parser aplica lei e loga warning. Sócio/Admin investiga manualmente.

Ver [`docs/ADR-02-djen-parser-regra-evolucao.md`](docs/ADR-02-djen-parser-regra-evolucao.md) para política completa de versionamento da regra.

---

## 9. Story 4a.3.1 — rename STATUS_RASCUNHO_NAO_VINCULADO → STATUS_RASCUNHO (v0.4.0)

Patch mínimo cross-módulo alinhado à V009 mapping destrutiva da togare-core 0.18.0:

- `PrazoCreatorService::create()` substitui `Prazo::STATUS_RASCUNHO_NAO_VINCULADO` por `Prazo::STATUS_RASCUNHO` (1 linha; mesma assinatura — não-breaking).
- Auto-vínculo `cliente`/`parteContraria` **NÃO** é responsabilidade do creator djen — delegado ao `AutoLinkClientHook` em togare-core (BeforeSave order=20). Decisão ADR-03 v1.1 §3: hook aplica a TODO Prazo (DJEN + manual UI futuro + CSV import futuro), não apenas ao caminho DJEN.
- Bump de dependência: requer togare-core ≥ 0.18.0 (entity Prazo expandida + AutoLinkClientHook + V009/V010).

## 8. PrazoCreatorService — entity Prazo + match CNJ + idempotência (Story 4a.3, v0.3.0)

`PrazoCreatorService` toma o output do `DjenParserService` (`PrazoCalculado` DTO) +
payload da publicação (DTO `PublicationSourceAdapterContract`) e CRIA uma entidade
`Prazo` persistida em togare-core 0.17.0. **Fecha o pipeline DJEN → Prazo.**

### 8.1 Pipeline (síncrono dentro de `DjenWorkerService::handlePublication`)

```
parser.parse(payload) ──┐
                        ▼
            ┌──────────────────────┐
            │ PrazoCreatorService  │  ← Story 4a.3 NEW
            │   .create()          │
            └──────────┬───────────┘
                       │
                       ├──→ EntityManager.findOne(Prazo, sourcePubId) (idempotência)
                       │
                       ├──→ EntityManager.findOne(Processo, numeroCnj=digits) (match)
                       │
                       ├──→ EntityManager.getNewEntity(Prazo) + saveEntity
                       │
                       ▼
              tabela prazo (togare-core 0.17.0)
              status=pendente OU rascunho_nao_vinculado
```

### 8.2 Caminhos cobertos

| Caminho | Resultado | Log |
|---|---|---|
| sourcePubId já existe | NO-OP, retorna existing | `djen.prazo.deduped` |
| CNJ bate com Processo | Prazo `pendente` + processoId set + assignedUserId herda do Processo | `djen.prazo.created_bound` |
| CNJ não bate / inválido / ausente | Prazo `rascunho_nao_vinculado` + payload preservado | `djen.prazo.created_unbound` |
| Race condition (PDOException 23000) | Refetch retorna concorrente | `djen.prazo.deduped_via_constraint` |
| CNJ ≠ 20 dígitos após `digitsOnly` | match=null + warning + segue como rascunho | `djen.prazo.invalid_cnj_format` |

### 8.3 Heurística `assignedUser` cascade

1. Match Processo → `processo.assignedUserId` (titular).
2. Sem match + `payload.userId` (User ativo) → `payload.userId`.
3. Fallback: primeiro Sócio/Admin ativo via JOIN role.
4. Final: `NULL` (escritório sem Sócio/Admin) + log `djen.prazo.no_assignee_fallback`.

### 8.4 Idempotência (3 níveis)

- Migration **V008 togare-core** cria `UNIQUE INDEX prazo_source_pub_id_unique ON prazo(source_pub_id)`.
- Creator faz `findOne(sourcePubId=)` ANTES do save (defensivo).
- Creator captura `PDOException` 23000 (race condition) → refetch + retorna concorrente.

### 8.5 Decisões vinculantes (ver ADR-03)

1. **Entity Prazo em togare-core, creator em togare-djen** — cross-cutting cobre 4a.4/5, 4b.2, 7a, 6.x.
2. **UNIQUE INDEX em sourcePubId** — coluna nullable aceita múltiplos NULLs (manuais futuros).
3. **Heurística `assignedUser` cascade** — match → payload.userId → Sócio/Admin → NULL.
4. **Status enum 6 valores declarados** — apenas `pendente`/`rascunho_nao_vinculado` emitidos nesta story.
5. **`publicacaoOrigemRaw` como text JSON** — JSON_UNESCAPED_UNICODE preserva acentos.
6. **Parser + creator síncronos** — mesmo dispatch transacional; falha → markFailed default delay (Spike 1b.S3).

### 8.6 Eventos novos da Story 4a.3

| Evento | Nível | Quando |
|---|---|---|
| `djen.prazo.created_bound` | info | Prazo criado vinculado ao Processo (status=pendente) |
| `djen.prazo.created_unbound` | info | Prazo criado em rascunho (sem match — status=rascunho_nao_vinculado) |
| `djen.prazo.deduped` | info | Re-fetch detectado: sourcePubId já existe, NO-OP |
| `djen.prazo.deduped_via_constraint` | warning | Race condition (PDOException 23000) — refetch retornou concorrente |
| `djen.prazo.invalid_cnj_format` | warning | CNJ ≠ 20 dígitos após digitsOnly — match=null, segue como rascunho |
| `djen.prazo.assignee_fallback_socio_admin` | warning | Heurística caiu em fallback — primeiro Sócio/Admin |
| `djen.prazo.no_assignee_fallback` | warning | Cascade exauriu — escritório sem Sócio/Admin ativo |

### 8.7 Listagens (boolFilter classes em togare-core)

| boolFilter | Visão | whereClause |
|---|---|---|
| `naoVinculadas` | Sócio/Admin (triagem global) | `status='rascunho_nao_vinculado'` |
| `meusPendentes` | Advogado | `status='pendente' AND assignedUserId=self` |
| `meusRascunhos` | Advogado | `status='rascunho_nao_vinculado' AND assignedUserId=self` |

Ver [`docs/ADR-03-pipeline-djen-prazo.md`](docs/ADR-03-pipeline-djen-prazo.md) para decisões arquiteturais completas.

## 10. Story 4a.6 — Rate-limit DJEN explícito (v0.5.0)

Aplica gate ≤30 requests/60s contra a Comunica API PJe **antes de cada chamada HTTP** do `DjenAdapter`. Reusa o `RateLimiter` da Story 1a.4c (sliding window persistido em `togare_rate_limits` — mesmo motor já usado pelo lockout de auth da Story 2.5).

### 10.1 Decisões vinculantes

- **D1** Gate aplicado imediatamente antes de cada tentativa HTTP dentro de `DjenAdapter::fetchWithRetry()`. Cada chamada real à Comunica API consome 1 slot do rate-limiter; retries internos também consomem cota adicional.
- **D2** Comportamento "throttled" do AC: sleep adaptativo de 1s/iteração até janela liberar, com **cap de 90s** (1.5× a janela de 60s — permite ≥1 reset completo). Após cap, lança `DjenAdapterUnavailableException` capturada pelo `DjenWorkerService::processOne()` que faz `markFailed customDelay=3600s` (comportamento existente da Story 4a.1, **reusado sem alteração**).
- **D3** Constantes em `Services/DjenRateLimitConfig` (classe `final` privada, espelho de `AuthRateLimitConfig` da 2.5): `RATE_KEY='djen:comunica-api'`, `LIMIT=30`, `WINDOW_SECONDS=60`, `CAP_SECONDS=90`. Chave **global por módulo** (NÃO por advogado) — limite é da Comunica contra o IP do Togare.
- **D4** 4º parâmetro do construtor: `?RateLimiter $rateLimiter = null` (opcional, retrocompat dos testes da 4a.1). Em produção, a injeção é forçada em `Binding.php` via `bindCallback`, porque o smoke F1 mostrou que o autowire silencioso não resolve parâmetros `?ClassName $x = null`.
- **D5** `Binding.php` registra `$binder->for(DjenAdapter::class)->bindCallback(RateLimiter::class, ...)` para construir o `RateLimiter` com o `EntityManager` do container. API EspoCRM 9.x verificada: não existe `bindParameter`; as opções são `bindImplementation`, `bindService`, `bindValue`, `bindInstance`, `bindCallback` e `bindFactory`.
- **D6** Fail-open quando RateLimiter está indisponível: se `peek()`/`check()` lança `\Throwable` (DB down, deadlock), loga `djen.adapter.ratelimit.unavailable` (warning) e retorna early. Princípio CLAUDE.md "infra de telemetria não trava bot". **NÃO** chama `recordFailure()` do circuit breaker (rate-limit DB down ≠ Comunica API down — não confundir sinais).
- **D7** Variável `TOGARE_DJEN_DISABLE_RATELIMIT=1` apenas para testes — separada de `TOGARE_DJEN_DISABLE_BACKOFF=1` da 4a.1 (queremos flexibilidade de testar cap sem dormir mas com gate ativo).

### 10.2 Arquivos novos

- `Services/DjenRateLimitConfig.php` — 4 constantes vinculantes + construtor private + class final.
- `tests/unit/.../Services/DjenRateLimitConfigTest.php` — 3 testes (constantes literais + estrutura final/private + invariante CAP > WINDOW).

### 10.3 Arquivos modificados

- `Services/DjenAdapter.php`:
  - Import `RateLimiter`.
  - Construtor ganha 6º/5º/4º parâmetros opcionais: `?RateLimiter $rateLimiter`, `?callable $clock`, `?callable $sleeper` (clock e sleeper são para testes; default usam `microtime(true)` e `\sleep()` real).
  - Método novo `guardRateLimit(): void` — chamado antes de cada tentativa HTTP em `fetchWithRetry()`.
- `tests/unit/.../Services/DjenAdapterTest.php` — testes do guard (sob limite, env disable, throttle até reset, cap excedido, fail-open por exceção, 1× incremento sem retry, retries consumindo 1 slot por tentativa HTTP).

### 10.4 Eventos de log novos

| Event | Level | Mensagem |
|---|---|---|
| `djen.adapter.ratelimit.released` | info | "Rate limit DJEN liberou após {N}s de espera" — só após throttle |
| `djen.adapter.ratelimit.throttled` | warning | "Rate limit DJEN saturado — aguardando janela liberar" |
| `djen.adapter.ratelimit.exceeded` | error | "Rate limit DJEN excedeu cap de espera (90s) — adapter indisponível" |
| `djen.adapter.ratelimit.unavailable` | warning | "RateLimiter indisponível — sync DJEN prosseguirá sem gate (fail-open)" |

### 10.5 Validação

- **PHPUnit togare-djen:** 95 testes verdes (78 anteriores + 14 DjenAdapterTest [7 da 4a.1 + 7 da 4a.6] + 3 DjenRateLimitConfigTest).
- **Smoke F1 SQL:** após primeiro sync DJEN, `togare_rate_limits` ganha linha `rate_key='djen:comunica-api'` com `counter≥1`.

## 11. Variáveis de ambiente (testes apenas)

⚠️ **NÃO setar em produção.** Estas variáveis desativam controles de NFR e existem apenas para deixar a suite PHPUnit rápida e determinística.

| Variável | Story | Função |
|---|---|---|
| `TOGARE_DJEN_DISABLE_BACKOFF=1` | 4a.1 | Pula `\sleep()` em retries do `DjenAdapter::fetchWithRetry()` (entre tentativas) e no loop de espera do `guardRateLimit()`. |
| `TOGARE_DJEN_DISABLE_RATELIMIT=1` | 4a.6 | Pula totalmente o `guardRateLimit()` — não chama `peek()`/`check()`. **Desativa NFR de proteção da Comunica API.** |

Ambas são `putenv`-friendly e devem ser setadas/removidas em `setUp/tearDown` dos testes.

### Snippet de build/install (v0.5.0)

```bash
# Host
cd espocrm/togare-djen && npm run extension
# → gera build/togare-djen-0.5.0.zip

# Container (CLI)
docker exec nextcloud-crm-espocrm-1 sh -c "php command.php extension --file=/var/www/html/data/upload/extensions/togare-djen-0.5.0.zip"
docker exec nextcloud-crm-espocrm-1 sh -c "php clear_cache.php"

# Validação smoke (rate-limit ativo)
docker exec nextcloud-crm-mariadb-1 sh -c "mysql -uroot -p'${MARIADB_ROOT_PASSWORD}' espocrm -e \"SELECT * FROM togare_rate_limits WHERE rate_key='djen:comunica-api'\""
```

---

## 11. Story 4b.1b — PublicationMatcher 2-fase + AmbiguityResolverService transacional (v0.6.0)

**Decisões #2/#3/#4 da spec-mãe [4b.1](../../_bmad-output/implementation-artifacts/4b-1-queue-navegavel-comparador-candidatos.md).** Filha 2/3 do split — fundação backend para fila "Precisa sua leitura" do flow F3.

### O que entrega

- **`PublicationMatcher` (NEW)** — match em 2 fases entre payload DJEN e Processos cadastrados:
  1. **Fase 1**: CNJ exato 20 dígitos via `findOne(numeroCnj=)`. Defensivo `find()->limit(2)` se 2+ retornados.
  2. **Fase 2** (apenas se Fase 1 = 0 hits): para cada `payload.destinatarios[].nome` distinto, query `Cliente.name = $nome` + `ParteContraria.name = $nome` simultaneamente; agrega Processos via N:N (`processo->clientes` / `processo->partesContrarias`); de-dup por processoId; limita pull a `MAX_NAME_MATCH_CANDIDATES + 1 = 6` para detectar `too_many`.
  - Retorna `MatchResult` com `kind: 'single'|'none'|'multiple'|'too_many'`. Snapshot `candidatos` denormalizado (8 fields: processoId, numeroCnj, clienteNome, parteContrariaNome, dataDistribuicao, area, fase, codigoCor) — ordem estável por `numeroCnj` ASC. Paleta `codigoCor`: `[azul, laranja, verde, roxo, vermelho]`.
  - Logs estruturados: `djen.match.namematch_resolved`, `djen.match.namematch_too_many`.
- **`PrazoCreatorService` refactored** — `create()` retorna `CreationResult` em vez de `Entity`. 4 ramos:
  - `prazo_bound` (kind=single)
  - `prazo_rascunho` (kind=none ou kind=too_many)
  - `publicacao_ambigua` (kind=multiple) — cria entity `PublicacaoAmbigua` com snapshot
  - `deduped` — sourcePubId já existe em **prazo OR publicacao_ambigua** (idempotência cross-table — log `djen.publication.deduped_via_ambigua_existing`)
  - **B20 endereçada por design**: `PublicationMatcher` é param OBRIGATÓRIO no construtor (não nullable + sem default).
  - **DjenWorkerService.handlePublication NÃO MUDA** — descarta retorno do `create()` (linha 256). Backward-compat preservada.
- **`AmbiguityResolverService` (NEW)** — 3 métodos públicos atomicamente envolvidos em `getTransactionManager()->run()`:
  - `resolve($pubId, $chosenProcessoId, $decidedByUserId): Entity` — cria Prazo `source=manual_ambiguo` + marca pub `status=resolvido` + grava `togare_ambiguity_log` (PDO direto). Lança `AlreadyResolvedException` se status != pendente_revisao; `InvalidCandidateException` se chosenProcessoId fora de candidatos.
  - `ignore($pubId, $decidedByUserId): void` — marca pub `status=ignorado` + grava log. Zero Prazo criado.
  - `bulkIgnoreProcesso($processoId, $decidedByUserId, ?callable $canEdit = null): int` — marca pendentes onde processoId aparece em `candidatos[]` como `status=bulk_ignorado`. Match inicial via `LIKE %"processoId":"<id>"%` (portável MariaDB+SQLite — OQ#1 fechada), com revalidação JSON exata antes de alterar cada row e filtro opcional de ACL record-level. Retorna count.
- **2 Exceptions tipadas**: `AlreadyResolvedException` (mensagem pt-BR com timestamp) e `InvalidCandidateException`. Convertidas pelo Controller `togare-djen/Controllers/TogareDjenPublicacaoAmbigua` para HTTP 409/400.
- **Logs novos**: `djen.publication.ambiguous_queued`, `djen.publication.deduped_via_ambigua_existing`, `djen.publication.deduped_via_constraint`, `djen.ambiguity.resolved`, `djen.ambiguity.ignored`, `djen.ambiguity.bulk_ignored`, `djen.match.namematch_resolved`, `djen.match.namematch_too_many`.

### Endpoints REST disponíveis (consumidos pela 4b.1c — filha 3/3)

```
POST /api/v1/TogareDjenPublicacaoAmbigua/action/resolve
  body: {"publicacaoAmbiguaId": "<pubId>", "chosenProcessoId": "<24-char>"}
  → 200 {"prazoId": "<novoId>"} | 400 InvalidCandidate | 409 AlreadyResolved | 403 Forbidden

POST /api/v1/TogareDjenPublicacaoAmbigua/action/ignore
  body: {"publicacaoAmbiguaId": "<pubId>"}
  → 200 {"success": true} | 409 | 403

POST /api/v1/TogareDjenPublicacaoAmbigua/action/bulkIgnoreProcesso
  body: {"processoId": "<24-char>"}
  → 200 {"count": N} | 400 InvalidCandidate (processoId vazio) | 403
```

### Snippet de build/install (v0.6.0)

```bash
# Monorepo (host)
cd espocrm/togare-djen && npm run extension
# → gera build/togare-djen-0.6.0.zip

cd ../togare-core && npm run extension
# → gera build/togare-core-0.24.0.zip

# Container (CLI) — instalar togare-core 0.24.0 ANTES de togare-djen 0.6.0
docker exec nextcloud-crm-espocrm-1 sh -c "php command.php extension --file=/var/www/html/data/upload/extensions/togare-core-0.24.0.zip"
docker exec nextcloud-crm-espocrm-1 sh -c "php command.php extension --file=/var/www/html/data/upload/extensions/togare-djen-0.6.0.zip"
docker exec nextcloud-crm-espocrm-1 sh -c "php clear_cache.php && php rebuild.php"

# Validação smoke (cenário ambiguidade fake)
docker exec nextcloud-crm-mariadb-1 sh -c "mysql -uroot -p'${MARIADB_ROOT_PASSWORD}' espocrm -e \"SELECT id, source_pub_id, ambiguity_reason, status, JSON_LENGTH(candidatos) AS n_cand FROM publicacao_ambigua ORDER BY created_at DESC LIMIT 5\""

# Smoke API resolve (curl)
curl -X POST -u admin:SmokeTest2026! \
  -H 'Content-Type: application/json' \
  -d '{"publicacaoAmbiguaId":"<pubId>","chosenProcessoId":"<P_A.id>"}' \
  http://crm.localhost/api/v1/TogareDjenPublicacaoAmbigua/action/resolve
```

### Cobertura testes (≥35 novos PHPUnit)

- `PublicationMatcherTest` — 12 cenários (CNJ exact 1/2+, namematch 0/1 cliente/1 PC/dedup mesmo proc/2 distintos/5 distintos/≥6 too_many/destinatarios vazio).
- `PrazoCreatorServiceTest` — testes existentes atualizados (passar `PublicationMatcher` mockado) + 3 novos (kind=multiple → publicacao_ambigua + kind=too_many + idempotência cross-table).
- `AmbiguityResolverServiceTest` — 8 cenários (resolve/ignore/bulkIgnore happy paths + 3 exception paths + transaction rollback).
- `CreationResultTest` + `MatchResultTest` — 4+5 cenários (factory methods + readonly).
- `PublicacaoAmbiguaControllerTest` — 10 cenários (3 actions × happy/badrequest/conflict + ACL forbidden).
- `DjenWorkerServiceTest` — 1 cenário novo end-to-end ambiguidade (worker descarta retorno + markDone).

Sem JS, sem migration, sem RBAC nesta filha. RBAC scope-level já entregue na 4b.1a.

## 12. Story 4b.4 — Tick check CB + snapshot endpoint + failure_category (v0.7.0, ADR 0009)

Cobre Decisão A5 da retro Epic 4a: alinhar retry × circuit breaker quando CB do `DjenAdapter` fecha após cooldown.

### Mudanças no `DjenAdapter`
- **`getCircuitBreakerState(): array{failures, open_until, opened_at}`** público — lê state-file via `loadState()` privado. Consumido pelo `DjenWorkerService` (tick check) e pelo Controller `TogareDjenStatus` (snapshot endpoint).
- **`clearCircuitBreakerOpenFlag(): void`** público — zera `open_until` e `opened_at` preservando `failures[]` para a próxima janela de contagem. Chamado pelo worker após disparar reschedule.
- **`recordFailure()`** privado: agora persiste `opened_at = $now` no momento exato em que `open_until` é setado. Campo NOVO no state-file. Backward-compat: leitura tolera ausência via `?? 0`.
- **Construtor**: aceita env var `TOGARE_DJEN_CB_STATE_PATH` (Decisão #10) para volume Docker compartilhado entre containers `espocrm` e `togare-djen-worker`. Sem env var, fallback para `sys_get_temp_dir()` (comportamento histórico).

### Mudanças no `DjenWorkerService`
- **Tick check pré-`claim()`**: novo `detectAndHandleCbCloseTransition()` chamado no início de `processOne()`. Lê CB state; se transição open→closed (`open_until > 0 && open_until <= now`), dispara `QueueService::rescheduleAfterCircuitBreakerClose('djen', 'adapter_unavailable')` + `clearCircuitBreakerOpenFlag()`. Loga `info` `djen.queue.rescheduled_after_cb_close` com count. Defensivo `Throwable` (NUNCA derruba o worker — princípio CLAUDE.md "infra de telemetria não trava bot").
- **3 catches passam `failure_category`** via constants públicas:
  - `DjenAdapterUnavailableException` → `'adapter_unavailable'` (única categoria que habilita reschedule).
  - `Forbidden` (license_expired) → `'license_expired'`.
  - `Forbidden` (forbidden) → `'forbidden'`.
  - `Throwable` genérico → NULL (categoria desconhecida não habilita reschedule).
- Construtor ganha 5º param opcional `?callable $clock = null` (mockável em testes; default `static fn(): int => time()`).

### Controller novo `TogareDjenStatus`
- Action `getActionSnapshot(Request): stdClass` em `Controllers/TogareDjenStatus.php`.
- Endpoint: `GET /api/v1/TogareDjenStatus/action/snapshot`.
- ACL via `$this->acl->checkScope('TogareDjenStatus')` (lança `Forbidden` se falha).
- Retorna JSON `{cbOpen: bool, openedAt: ISO8601 BRT|null, openUntil: ISO8601 BRT|null, minutesOpen: int, nextRetryHint: 'HH:MM' BRT|null}`.
- Scope `Resources/metadata/scopes/TogareDjenStatus.json`: `acl: "boolean"`, `aclPortal: false`, `entity: false`, `tab: false` (sem entity nem menu — endpoint puro). Roles operacionais (Sócio/Admin, Advogado, Assistente, Secretária) acessam por default; Cliente-portal recebe 403.

### Cobertura
- **+10 PHPUnit novos**: `DjenAdapterTest` +4 + `DjenWorkerServiceTest` +6 + `TogareDjenStatusControllerTest` 4.

### Dependência mínima
- **Requer `togare-core ≥ 0.28.0`** (`markFailed` 6º param + `rescheduleAfterCircuitBreakerClose` método novo). Instalação **fora de ordem** (djen primeiro, core depois) → erro fatal `Call to undefined method` no primeiro tick.
