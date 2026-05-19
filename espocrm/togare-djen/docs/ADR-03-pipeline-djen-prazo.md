# ADR-03 — Pipeline DJEN → Prazo persistido

**Status:** Aceito v1.0 (2026-05-03 — Story 4a.3) → Bump v1.1 (2026-05-04 — sprint-change-proposal-2026-05-04.md)
**Stories relacionadas:** 4a.1 (módulo + worker), 4a.2 (parser + DTO), 4a.3 (entity + creator), 4a.3.1 (migration status enum 6→8 + 5 campos novos), 4a.4 (CardDePrazo UI reescopada), 4b.2 (notificações ampliadas)
**Decisões antecedentes:** ADR-02 (regra de prazo versionada)
**ADRs derivados:** ADR-04 togare-core (subsistema Notifications & Reminders, novo em v1.1)
**Próximas dependências:** Story 4a.5 (BriefingDoDia), 4b.1 (ComparadorCandidatos), 4b.2 (alertas D-X com canal duplo)

---

## Contexto

A Story 4a.3 fecha o pipeline "publicação DJEN → Prazo persistido no CRM" — materializa o "aha moment" da jornada Ricardo do PRD. As stories anteriores entregaram:

- **4a.1**: módulo `togare-djen` + adapter Comunica API + scheduled job 06:00 BRT + worker dedicado consumindo fila `djen` com retry persistente. Handler stub (`handlePublicationStub`) só logava `djen.publication.received` e marcava como done.
- **4a.2**: `DjenParserService` puro (zero DB) que recebe payload da publicação, classifica o ato (regex+keyword determinístico, zero IA/LLM), aplica art. 5º Res. CNJ 455 e calcula `dataFatal`. Output: DTO `PrazoCalculado`. Handler `handlePublication` substituiu o stub e passou a logar `djen.publication.parsed`. **Mas não persistia nada.**

A ponte que faltava era criar uma **entidade Prazo** persistida no banco a partir do DTO `PrazoCalculado`. Sem isso, a UI 4a.4 (CardDePrazo) não tem o que exibir, BriefingDoDia 4a.5 não tem o que contar, alertas 4b.2 não têm o que alertar.

Quatro decisões emergiram naturalmente:

1. **Onde vive a entity Prazo?** togare-djen (junto com creator) ou togare-core (cross-cutting)?
2. **Como evitar duplicação** quando worker reprocessar a mesma publicação (re-fetch, re-instalação, race condition)?
3. **Quem é o `assignedUser`** quando a publicação não bate com nenhum Processo cadastrado?
4. **Qual a estratégia transacional** entre parser e creator no dispatch do worker?

---

## Decisão

### 1. Entity `Prazo` vive em **togare-core**, PrazoCreatorService vive em **togare-djen**

**Entity em togare-core porque:**
- **Cross-cutting universal**: Stories 4a.4 (CardDePrazo UI), 4a.5 (BriefingDoDia), 4b.2 (alertas), 7a (Portal cliente), 6.x (Financeiro condicionando honorário a prazo cumprido) — todos consomem.
- **DAG de deps (architecture L318)**: togare-djen DEPENDE de togare-core. Inverso seria ciclo.
- **Manual/CSV import futuro Growth**: prazos cadastrados manualmente (Growth) precisam da entity sem load do togare-djen.
- **Architecture L703-706**: lista Prazo explicitamente em `togare-core/Resources/metadata/entityDefs/`.
- **ADR-02 togare-core**: entity name = `Prazo` (sem prefixo Togare — entity de negócio igual Cliente/ParteContraria/Processo/Audiencia).

**PrazoCreatorService em togare-djen porque:**
- togare-djen é o **producer** específico DJEN. Cada módulo "fonte de prazo" (manual UI futuro, CSV import, AASP fallback) tem seu próprio creator.
- `PrazoCalculado` DTO vive em togare-djen — manter consumer no mesmo módulo.
- togare-core não conhece DJEN; importar `PrazoCalculado` de togare-djen criaria ciclo de deps.

### 2. Idempotência via UNIQUE INDEX em `source_pub_id`

**Mecanismo:**
- Coluna `source_pub_id` (INT, nullable) preserva o `payload.id` da Comunica API — único por publicação no DJEN.
- Migration V008 cria `UNIQUE INDEX prazo_source_pub_id_unique ON prazo(source_pub_id)`.
- Em MariaDB/MySQL, UNIQUE com nullable PERMITE múltiplas linhas com NULL (semântica padrão SQL) — Prazos manuais futuros (Growth) com `sourcePubId=NULL` não colidem entre si.

**Defense em profundidade (PrazoCreatorService):**
1. **Antes do save**: `findOne(sourcePubId=)` → se retorna entity, log `djen.prazo.deduped` + retorna existing (NO-OP).
2. **No save**: try/catch `PDOException` SQLSTATE 23000 (duplicate key) → refetch `findOne(sourcePubId=)`, retorna concorrente, log `djen.prazo.deduped_via_constraint` (race condition).
3. **DB-level**: UNIQUE INDEX previne duplicação mesmo se ambas defesas falharem (segurança final).

**Por que NÃO tabela auxiliar de dedup:** premature optimization — `sourcePubId` já é único por natureza no DJEN; coluna na própria tabela `prazo` é mais simples e indexável.

### 3. Heurística `assignedUser` cascade

Quando a publicação NÃO bate com nenhum Processo (`status=rascunho_nao_vinculado`), o `assignedUser` segue a cascade:

1. **Match Processo encontrado**: `processo.assignedUserId` (titular do Processo).
2. **Sem match + `payload.userId` presente + User ativo**: `payload.userId` (advogado dono do sync_window — Story 4a.1 garante que está populado).
3. **Fallback defensivo**: query `Repository<User>->where(role.name='Sócio/Admin', isActive=true, type='regular')->findOne()` — primeiro Sócio/Admin ativo. Loga warning `djen.prazo.assignee_fallback_socio_admin`.
4. **Final (cenário catastrófico)**: escritório sem nenhum Sócio/Admin ativo → `assignedUserId=NULL` + log warning `djen.prazo.no_assignee_fallback`. Validação no hook permite NULL para `status=rascunho_nao_vinculado` (vs `status=pendente` que EXIGE assignedUser).

**Por que esse cascade:**
- PRD epic 4a.3 menciona "heurística por OAB citada na publicação". Mas o sync_window 4a.1 JÁ filtra por OAB do advogado → `payload.userId` é precisamente o advogado da OAB. Esta story só consome.
- Sócio/Admin é fallback razoável: ele tem ACL=all, vê todos os prazos, pode redistribuir manualmente.
- NULL final aceito para preservar invariante "nunca perder publicação silenciosamente" (decisão Winston): mesmo sem assignee, a entity é criada e fica visível em audit + listagem geral.

### 4. Estratégia transacional: parser + creator no mesmo dispatch síncrono

**Sequência no `DjenWorkerService::handlePublication`:**

```
1. parser.parse(payload) → PrazoCalculado | null
2. Se null (ato certificatório) → log djen.publication.unparsed + return (markDone normal no chamador)
3. log djen.publication.parsed (info, com PrazoCalculado completo)
4. (se confidence=low) log djen.parser.classifier_lowconfidence (warning)
5. creator.create(payload, prazoCalculado) → Prazo persistido
   - creator dispara djen.prazo.created_bound | created_unbound | deduped | deduped_via_constraint
6. processOne chama markDone() (no chamador)
```

**Throwable do creator NÃO é capturado em handlePublication** — sobe para o catch hierárquico do `processOne`:
- DjenAdapterUnavailable → markFailed customDelay 3600 (AC3 4a.1)
- Forbidden license_expired → markFailed customDelay 3600 (AC5.1 4a.1)
- Throwable outros → markFailed delay padrão (AC7.1 da Story 4a.3 — preserva invariante Spike 1b.S3)

**Por que síncrono no mesmo dispatch:**
- Latência: cada publicação vira 1 item na fila (vs re-enqueue como `type=publication_parsed` que duplicaria items). NFR3 (≤30min ciclo completo) já apertada.
- Atomicidade: parsed + creator + markDone formam unidade lógica. Se creator falhar, item vira `failed_retry` e na próxima rodada o parser parseia de novo + creator tenta de novo (idempotência via UNIQUE INDEX impede duplicação).

---

## Trade-offs aceitos para MVP

| Decisão | Trade-off MVP | Roadmap Growth |
|---|---|---|
| Entity em togare-core | Bump togare-core 0.16.0→0.17.0 acopla todos os módulos | Aceitável — togare-core já é dep universal |
| UNIQUE simples (nullable) vs partial WHERE NOT NULL | Funciona para djen (sourcePubId sempre populado); manuais aceitam NULLs | Migration alternativa se Growth precisar reforço |
| Heurística `assignedUser` cascade | Sócio/Admin recebe rascunhos órfãos (precisa redistribuir manual) | Story 4b.1 ComparadorCandidatos pode evoluir para auto-distribuição via OAB extraída do texto |
| `publicacaoOrigemRaw` como text JSON | Sem queries indexáveis em campos do payload original | Story 4b.1 pode parsear via JSON_EXTRACT SQL para ComparadorCandidatos |
| Parser + creator síncronos | Creator falha → item retry inteiro; parsea de novo (CPU desperdiçada) | Cache do parser por sourcePubId em RAM (Redis) se piloto mostrar gargalo |

---

## Consequências

**Positivas:**
- Pipeline DJEN → Prazo end-to-end fechado em 3 stories (4a.1 + 4a.2 + 4a.3).
- Invariante "nunca perder publicação silenciosamente" garantida em DB-level + log estruturado + UNIQUE INDEX.
- Stories 4a.4/4a.5/4b.x destravadas (têm entity para consumir).
- Pattern de creator + idempotência via UNIQUE INDEX + cascade de assignedUser pode ser reusado para futuras fontes (manual, AASP, Growth).

**Negativas:**
- Bump cross-módulo (togare-core 0.17.0 + togare-djen 0.3.0 + togare-rbac 0.8.0) — install requer ordem estrita.
- Adiciona 22 fields novos na tabela `prazo` (necessários, mas amplia surface area de regressões — mitigado por PrazoMetadataTest).
- `publicacaoOrigemRaw` como text JSON ocupa ~2-4KB por publicação (50 advs × 10/dia × 365 = ~600MB/ano — MariaDB lida tranquilo).

**Neutros (v1.0):**
- ~~Status enum 6 valores declarados; só 2 emitidos nesta story (`pendente`, `rascunho_nao_vinculado`).~~ **Substituído em v1.1** — ver §1 atualizada.

---

## Atualizações em v1.1 (2026-05-04)

Smoke F1 da Story 4a.3 e o sprint-change-proposal-2026-05-04 (workflow `bmad-correct-course` aprovado por Felipe) revelaram que o status enum 6 valores é insuficiente para o domínio jurídico real. PRD bumped para v1.3.1 com **status enum 8 valores canônicos** + 5 campos novos no Prazo + canal duplo de notificação. ADR-03 bumped para v1.1 nas decisões §1 e §3:

### §1 atualização — Status enum 6 → 8 valores

**Antes (v1.0):** 6 valores (`pendente`, `rascunho_nao_vinculado`, `confirmado`, `descartado`, `cumprido`, `revertido`).

**Depois (v1.1, decisão D3 do sprint change proposal):** 8 valores (`rascunho`, `pendente`, `atrasado_reagendado`, `aguardando_cliente`, `aguardando_correcao`, `protocolado`, `ciencia_renuncia`, `acompanhamento`).

**Mapping legacy V009 (Migration togare-core 0.18.0, destrutivo):**
- `pendente` → `pendente` (preservado)
- `rascunho_nao_vinculado` → `rascunho` (renomeado)
- `confirmado` → `pendente` (D2 Felipe — `confirmado` semanticamente = "já foi para Pendente após bind")
- `cumprido` → `protocolado` (renomeado para vocabulário BR-jurídico)
- `descartado` → mantido como valor oculto do dropdown UI (decidido no drafting da Story 4a.3.1; queries audit ainda funcionam)
- `revertido` → vira evento puro `audit.prazo.revertido`, **não estado** (reversão = volta para `pendente`)

**PrazoCreatorService** renomeia constants em togare-djen 0.4.0: `STATUS_RASCUNHO_NAO_VINCULADO` → `STATUS_RASCUNHO`. Default branch usa novos valores.

**Hooks atualizados em togare-core 0.18.0:**
- `ValidatePrazoFieldsHook.VALID_STATUSES` → 8 valores; constantes `Prazo::STATUS_*` no Entity stub atualizar; validação condicional `atrasado_reagendado` EXIGE `motivoReagendamento` NOT NULL.
- `AuditPrazoHook.derivedEventMap`: 4 → 7 eventos. `audit.prazo.confirmed` vira `audit.prazo.bound`; novos: `reagendado`, `aguardando_cliente`, `acompanhamento`. `revertido` deixa de ser status e vira evento puro disparado por transição `protocolado→pendente`.

### §3 atualização — Auto-vínculo Cliente/ParteContraria delegado a hook em togare-core (não embutido no creator)

**Antes (v1.0):** PrazoCreatorService resolvia `assignedUserId` via cascade (3 níveis); não tratava `cliente`/`parteContraria`.

**Depois (v1.1):** PrazoCreatorService **continua resolvendo apenas `assignedUserId` e `processoId`**. O auto-vínculo de `cliente` e `parteContraria` (FR14 PRD v1.3.1, F1.9) é responsabilidade de **AutoLinkClientHook** novo em `togare-core/Hooks/Prazo/`, BeforeSave, que lê `processo->cliente` e `processo->parteContraria` quando o Prazo é associado a um Processo. **Por que delegar:** o hook aplica a TODO Prazo (manual + DJEN + CSV import futuro), não apenas ao caminho DJEN. Mantém o creator djen-específico magro. Trade-off: ~5ms a mais por save (aceitável dentro do envelope NFR3 ≤30min sync).

**Comportamento N:N (clarificado em FR14 PRD v1.3.1):** se o Processo tem múltiplos clientes ou partes contrárias, o hook deixa os campos NULL no Prazo e a UI exige seleção manual com hint visual. AutoLinkClientHook NÃO infere preferência.

### Nova §5 — Subsistema de Notificação delegado para togare-core (não togare-djen)

Lembretes de prazo (FR15 + NFR37 PRD v1.3.1) são cross-cutting (Audiencia também consome). Subsistema vive em **togare-core** (não togare-djen). ADR-04 novo formaliza essa decisão.

---

## Histórico

| Data | Versão | Mudança |
|---|---|---|
| 2026-05-03 | 1.0 | ADR criado durante Story 4a.3 (bmad-dev-story); pipeline DJEN→Prazo formalizado; 4 decisões registradas. |
| 2026-05-04 | 1.1 | Bump pós smoke F1 (sprint-change-proposal-2026-05-04 aprovado). §1 status enum 6→8 valores + mapping legacy V009 destrutivo. §3 auto-vínculo Cliente/ParteContraria delegado para AutoLinkClientHook em togare-core (não embutido em creator). §5 nova: subsistema notificação delegado para togare-core (vide ADR-04). Bumps togare-core 0.18.0 / togare-djen 0.4.0 / togare-rbac 0.9.0. |
