# ADR 0006-extra (draft) — Advisory lock MariaDB para coordenação RevalidateLicensesJob × QueueService workers

**Data:** 2026-04-24 (status final atualizado 2026-04-25)
**Status:** Pendente — reavaliar na Story 4a.1 (worker DJEN). Spike 1b.S3 (2026-04-24) aceitou o design natural do ReadOnlyGate condicionalmente; invariante transferida pro worker futuro está documentada no [ADR 0006 §Plano B](../0006-lgpd-module-mvp-reduzido.md#decisão) e no [relatório da Spike 1b.S3](../../../../_bmad-output/implementation-artifacts/1b-S3-spike-licensing-expiration-relatorio.md). Se a Story 4a.1 violar a invariante, este draft é promovido a ADR `0008-licensing-advisory-lock`.

> **Histórico:** este draft existiu **antes** da Spike 1b.S3 rodar (regra do PM John, epics.md linha 1806).
> Propósito: ter o plano B (advisory lock GET_LOCK em MariaDB) redigido em momento calmo, caso a Spike mostrasse que o design natural do `ReadOnlyGate` não atende NFR20 sob expiração mid-execution.
> Decisão Felipe 2026-04-25 (Story 1b.2 Opção A): manter arquivado, não promover. A invariante "worker captura `Forbidden` + chama `markFailed(reason='license_expired')`" virou contrato vinculante pra Story 4a.1 (implementação do worker DJEN) — registrada na memory `project_status_implementacao.md` e no ADR 0006 (linha 57).

## Contexto

Complementa o [ADR 0006 — LGPD module MVP reduzido](../0006-lgpd-module-mvp-reduzido.md) (linha 57 cita este plano B inline) e o Licensing JWT descrito em [architecture.md L239](../../../_bmad-output/planning-artifacts/architecture.md).

A Story 1b.1 entregou `ReadOnlyGate` como hook `beforeSave`/`beforeRemove` que bloqueia alterações em entidades marcadas com `togarePremium.module=<X>` quando `togare_module_status[X].status = 'read_only'`. Transição para `read_only` ocorre via `RevalidateLicensesJob` (cron diário às 04:00) que atualiza a coluna `expires_at` e deriva `status`.

**NFR20** exige: *"expiração de licença NUNCA corrompe dados — read-only sem rollback destrutivo"*.

O risco coberto por esta spike é a **janela de race** entre:

1. **Worker do QueueService** (`espocrm-daemon` ou consumer standalone após ADR 0005b) processando item em `togare_queue_items[queue=djen]`: chamou `claim()`, está no meio do handler que executa UPSERT em entidade premium (ex.: `Processo` do módulo `togare-djen`).
2. **`RevalidateLicensesJob`** rodando em paralelo: transita `togare_module_status[togare-djen].status` de `active` para `read_only` pelo vencimento do `expires_at`.

Se o handler do worker executar o UPSERT **depois** da transição, o `ReadOnlyGate` dispara `Forbidden` e a transação do EspoCRM faz rollback. A pergunta é: o worker **captura** essa exceção limpo e deixa o item em `failed_retry`/`failed_dead_letter`? Ou o item fica órfão em `processing` (pior caso), forçando intervenção manual e arriscando perda de dados silenciosa?

A hipótese de trabalho (pode estar errada) é que **o design natural da Story 1b.1 + Story 1a.4c (QueueService com `claim()` em transação local) atende** porque:

- `claim()` é transação MariaDB curta — só para `SELECT ... FOR UPDATE SKIP LOCKED` + `UPDATE status='processing'`.
- Handler roda **fora** da transação do `claim`. UPSERT em entidade premium abre nova transação gerenciada pelo EspoCRM ORM.
- Rollback da transação do UPSERT **não toca** em `togare_queue_items` nem em `togare_module_status`.
- Worker captura `Forbidden` e chama `markFailed()`/`release()` — item volta para `failed_retry` ou `pending`.

Se essa hipótese for verdadeira, **nenhum lock é necessário**. A Spike 1b.S3 valida empiricamente.

Se a hipótese falhar (item fica em `processing`, ou entidade premium entra parcial, ou alguma outra inconsistência), **este ADR é promovido** e vira patch retroativo nas Stories 1b.1 e 1a.4c.

## Decisão alternativa (se promovida)

Coordenar transições de status de licença e reivindicações de items premium via **advisory lock nomeado do MariaDB** (`GET_LOCK`/`RELEASE_LOCK`).

### Recipe

**No `RevalidateLicensesJob`:**

```php
$lockName = "togare-licensing-revalidate";
$acquired = $pdo->query("SELECT GET_LOCK('{$lockName}', 5)")->fetchColumn();
if ($acquired !== 1) {
    $this->logger->warning("RevalidateLicensesJob: não adquiriu lock em 5s; próximo ciclo tenta novamente");
    return;
}
try {
    // transição de status como hoje: UPDATE togare_module_status SET status='read_only' ...
} finally {
    $pdo->query("SELECT RELEASE_LOCK('{$lockName}')");
}
```

**No `QueueService::claim()` (togare-core 0.7.x):**

```php
$moduleName = $this->getPremiumModuleForHandler($handlerClassName);
if ($moduleName !== null) {
    $lockName = "togare-licensing-active-tx-{$moduleName}";
    // timeout=0 = poll, não espera
    $acquired = $pdo->query("SELECT GET_LOCK('{$lockName}', 0)")->fetchColumn();
    if ($acquired !== 1) {
        // Revalidate em andamento — devolve o item pra pending sem logar como falha
        $this->release($itemId);
        return null;
    }
    // ...claim normal + handler + markDone (tudo dentro desse lock)
    $pdo->query("SELECT RELEASE_LOCK('{$lockName}')");
}
```

### Justificativa

- **Simples e local** — `GET_LOCK` é SQL standard do MariaDB, disponível em 11.4 pinado. Sem extensões, sem dependência externa, sem coordenação distribuída.
- **Granularidade por módulo** (`togare-active-tx-<moduleName>`) — serializa apenas workers do mesmo módulo premium. Worker de `togare-djen` não bloqueia worker de `togare-tpu`.
- **Timeout curto no RevalidateJob** (5s) + **poll no claim** (0s) = nunca causa starvation nem deadlock. Se o revalidate não consegue o lock em 5s, loga warning e tenta no próximo ciclo (próximo dia). Worker que perdeu o lock volta item pra `pending` e dorme 1s antes do próximo claim.
- **`RELEASE_LOCK` no finally** — lock nunca vaza mesmo em caso de exception.

## Consequências

### Positivas

- Fecha a race empiricamente se a spike mostrar que o design natural não atende.
- Implementação retroativa mínima: 2 bumps de patch (togare-licensing 0.1.x → 0.2.0, togare-core 0.7.x → 0.8.0) + Story 1b.1.1-patch-licensing-advisory-lock.
- Testa-se com PHPUnit trivial em ambiente single-node; spike permanece como artefato de validação.

### Negativas (conhecidas antes de promover)

1. **Latência por claim** aumenta ~5–10ms (aquisição + release do lock). Pra jobs DJEN que processam ~1 publicação/segundo por worker, é marginal. Pra jobs `internal` de alta frequência, pode ser percebido.
2. **Lock global por módulo** serializa claims do mesmo módulo. Em pico com 10+ workers no mesmo módulo (cenário Epic 10 VPS), vira gargalo. Mitigação: lock por `queue_name` dentro do módulo (`togare-licensing-active-tx-<module>-<queue>`). Custo: mais locks, mais complexidade.
3. **MariaDB-specific.** Equivalente Postgres é `pg_advisory_lock(key)`. Togare opera sobre MariaDB no MVP (ADR 0005), então não é bloqueante agora. Porém, se algum dia a arquitetura suportar Postgres (ex.: Nextcloud já usa Postgres), esse código precisa de camada de abstração.
4. **Debugging mais difícil.** Lock contention aparece como "handler parado fazendo nada" — diagnóstico exige `SHOW PROCESSLIST` e/ou log explícito de `GET_LOCK` timeouts. Mitigação: logar todas as aquisições/releases via `TogareLogger.event=licensing.lock.*`.

### Trade-off explícito

Se a Spike 1b.S3 mostrar que **uma** das 3 situações (AC2/AC3/AC4 da story) entra em estado inconsistente, aceitamos todas as consequências negativas acima. NFR20 é non-negotiable (*"expiração NUNCA corrompe dados"*). Um patch que serializa alguns claims no pior caso é preferível a perda silenciosa de dados de cliente.

## Plano de promoção (se spike falhar)

1. Mover este arquivo para `docs/decisoes/0006-extra-licensing-advisory-lock.md` com status `Aceito`.
2. Criar Story `1b.1.1-patch-licensing-advisory-lock` no sprint-status.yaml.
3. Bumps: togare-licensing 0.1.x → 0.2.0, togare-core 0.7.x → 0.8.0.
4. Implementação + PHPUnit específico (simular race via mock PDO + verificar chamadas de GET_LOCK).
5. Atualizar [architecture.md L239] substituindo "NFR20 atendido pelo design natural" por "NFR20 atendido via advisory lock MariaDB (ADR 0006-extra) validado pela Spike 1b.S3".
6. Atualizar [ADR 0006](../0006-lgpd-module-mvp-reduzido.md) linha 57 trocando "Plano B (caso Spike 1b.S3 falhe)..." por "Plano B promovido a definitivo em YYYY-MM-DD — ver ADR 0006-extra".

## Plano de descarte (se spike passar)

> ⚠️ **2026-04-25 — Spike 1b.S3 PASSOU, mas este draft foi MANTIDO (Story 1b.2, Opção A).** A decisão de Felipe foi arquivar em vez de deletar — draft serve como gate de promoção pré-aprovado caso Story 4a.1 viole a invariante. Ver `## Resultado` abaixo.

1. ~~Deletar este draft.~~ — **NÃO executado** (draft mantido como contexto histórico + gate de promoção condicional)
2. Atualizar [architecture.md L239] com sufixo "NFR20 confirmado pela Spike 1b.S3 (YYYY-MM-DD) — sem lock advisory necessário".
3. Atualizar [ADR 0006](../0006-lgpd-module-mvp-reduzido.md) linha 57 trocando "Plano B (caso Spike 1b.S3 falhe)..." por referência ao relatório da spike que provou a desnecessidade.

## Resultado (Spike 1b.S3 — 2026-04-24)

Spike 1b.S3 validou empiricamente o isolamento entre `togare_module_status` e `togare_queue_items` e concluiu:

- **Design natural do ReadOnlyGate atende NFR20 condicionalmente.** O que vai ser exercitado em runtime real é a captura de `Forbidden` pelo worker — quando ele chamar `claim()` e processar um item premium, se a licença expirou no meio do tempo, `beforeSave` lança `Forbidden`, transação faz rollback, e o handler precisa traduzir isso em `markFailed(reason='license_expired')`.
- **Não há janela de race genuína na arquitetura outbox + SKIP LOCKED**, porque `togare_module_status` é tabela isolada (sem FK pra outras entidades) e `RevalidateLicensesJob` faz UPDATE atômico por linha. Workers leem o status fresco a cada `claim()`.

Portanto, **advisory lock seria reforço prematuro**. A invariante crítica é puramente **comportamental do handler** do worker — coberta pelo contrato da Story 4a.1.

**Caso a Story 4a.1 (worker DJEN) violar a invariante** (ex.: handler genérico não diferencia `Forbidden` de outras exceções, item fica órfão em `processing` ou em `failed_dead_letter` sem chance de recuperação automática quando licença for renovada), este documento é reativado:

1. Promover este draft como ADR `0008-licensing-advisory-lock.md` em `docs/decisoes/` raiz, seguindo a convenção de numeração (4 dígitos, sequencial — verificar se `0008` já foi alocado antes de usar).
2. Implementar `GET_LOCK('togare-licensing-revalidate', 0)` em `RevalidateLicensesJob` + `QueueService::claim()` para módulos premium — coordenação cross-process via MariaDB advisory lock.
3. Atualizar [ADR 0006](../0006-lgpd-module-mvp-reduzido.md) §Decisão Plano B trocando "Spike 1b.S3 validou..." por referência a este novo ADR oficial.

Relatório completo da Spike 1b.S3: [1b-S3-spike-licensing-expiration-relatorio.md](../../../../_bmad-output/implementation-artifacts/1b-S3-spike-licensing-expiration-relatorio.md).

## Referências

- [ADR 0006 — LGPD module MVP reduzido](../0006-lgpd-module-mvp-reduzido.md) (linha 57, plano B inline)
- [architecture.md — Licensing + NFR20](../../../_bmad-output/planning-artifacts/architecture.md) (linha 239)
- [prd.md — NFR20](../../../_bmad-output/planning-artifacts/prd.md) (linha 1020)
- [Story 1b.S3 — Spike licensing expiration](../../../_bmad-output/implementation-artifacts/1b-S3-spike-licensing-expiration-transacao-aberta.md)
- [Relatório Spike 1b.S3](../../../_bmad-output/implementation-artifacts/1b-S3-spike-licensing-expiration-relatorio.md)
- [MariaDB GET_LOCK docs](https://mariadb.com/kb/en/get_lock/)
- [Postgres pg_advisory_lock](https://www.postgresql.org/docs/current/explicit-locking.html#ADVISORY-LOCKS) (equivalência futura)
