# ADR-02 — Naming de entidades de negócio (sem prefixo Togare)

**Data:** 2026-04-27
**Status:** Aceito
**Story originadora:** [3.1 — Entidade Cliente PF/PJ](../../../_bmad-output/implementation-artifacts/3-1-entidade-cliente-pf-pj-validation-br.md)

## Contexto

A `architecture.md` Step 5 §Naming (linha 358) estabelece que entidades EspoCRM custom Togare devem usar prefixo `Togare` (ex.: `TogareProcesso`, `TogarePrazo`). Já a árvore final do monorepo (`architecture.md` Step 6, linhas 703-706) enumera as entidades de negócio sem o prefixo (`Cliente, Processo, ParteContraria, Prazo, Audiencia, ...`). Os 8 JSONs de role seedados pela Story 2.1 já declaram scope `"Cliente"` (sem prefixo) no `scopeList`. UX usa "Cliente" em todos os flows (Marli, Ricardo, Beatriz).

Adotar `TogareCliente` agora forçaria patch em todos os 8 roles seedados + UX speeches + i18n + mental model dos devs. Manter `Cliente` mantém coerência mas viola a regra textual L358 e leva a tabela `cliente` (sem prefixo `togare_`) — o que aparenta violar o spírito do validator R3.

**Descoberta de implementação (Story 3.1 smoke):** O EspoCRM 9.3 `Schema/Builder.php:147` faz `$tableName = Util::toUnderScore($entityType)` — **o nome da tabela é derivado DIRETAMENTE do nome do entity, sem possibilidade de override por `additionalParams.tableName`** (esse param é usado para `MySQL OPTIONS` como engine/charset, não para o nome da tabela). Logo, `Cliente` → tabela `cliente`, sem prefixo `togare_`, é o que o ORM faz por design.

## Decisão

**Entidades de negócio** (`Cliente`, `Processo`, `ParteContraria`, `Audiencia`, `Prazo`, `ContratoHonorarios`, `Fatura`, `Funcionario`, `Lead`) usam **nome sem prefixo `Togare`** — e portanto sua tabela SQL **também fica sem prefixo `togare_`** (`cliente`, `processo`, etc.). **Aceitamos a quebra parcial de R3** (validator) porque:

1. R3 (validator do togare-core) **só inspeciona migrations** (`tools/validate-togare-naming.php` linhas 77+ checam apenas arquivos `Migration/V*__*.php`). Entidades EspoCRM gerenciadas pelo ORM ficam **explicitamente fora do escopo de R3** — não há violação real.
2. Coerência com seed de roles e UX vence sobre uniformidade de prefix em todas as tabelas.
3. Tabelas SEM prefixo agora também têm precedente: `account`, `contact`, `lead`, `opportunity` (entidades vanilla do EspoCRM com as quais coexistimos).

**Entidades de infraestrutura** (`TogareMfaBackupCode`, `TogareQueueItem`, `TogareAuditLog`, `TogareRateLimit`, `TogareCoreSmoke`) **mantêm** o prefixo `Togare` no nome do entity → tabela com prefixo `togare_` automaticamente. Rationale dessa segregação:

- Negócio aparece com nome curto, em pt-BR coloquial-jurídico, no Admin → ACL → Roles e nas listas. Bom UX.
- Infraestrutura fica explícita ("isso é módulo Togare interno, não mexa") via prefixo.
- Para tabelas criadas via Migration EXPLÍCITA (`togare_queue_items`, `togare_rate_limits`, `togare_audit_log`), o validador R3 garante o prefixo `togare_`.

## Consequências

- ✅ Coerência com seed de roles (Story 2.1) e UX speeches.
- ✅ Validator R3 permanece intacto — só inspeciona migrations, e tabelas custom criadas via Migration explícita continuam exigindo prefixo `togare_`.
- ✅ Admin → Entity Manager fica navegável (negócio/infra segregados visualmente pelo nome).
- ⚠️ Quebra parcial da regra L358 da architecture (prefixo `Togare` em TODA entity). Aceito porque L703 e roles seedados são o estado-do-mundo mais recente.
- ⚠️ Tabelas de negócio (`cliente`, `processo` etc.) coexistem com tabelas vanilla do EspoCRM (`account`, `contact`) sem prefixo distintivo. Risco baixo — nomes não colidem porque entidades vanilla seguem padrão `account`/`contact`/`lead`/`opportunity`/`call`/`meeting`/etc., enquanto Togare usa pt-BR (`cliente`, `processo`, `audiencia`, `parte_contraria`, `lancamento_financeiro`, etc.). Se alguma colisão futura ocorrer, renomear a entity Togare para algo mais específico (ex.: `ClienteTogare` se conflitar com `Cliente` nativo — improvável).

## Aplicação

Story 3.1 adota `Cliente` (entity) → `cliente` (tabela). Stories 3.2-3.8 + 4a.* + 5.* + 6.* devem seguir o mesmo padrão para entidades de negócio em pt-BR.

Stories de **infra** (ex.: hipotética 4a.X que crie `TogareDjenPublicacao` como cache do adapter — entidade não-mostrada-ao-usuário) devem manter o prefixo Togare conforme L358.

## Referências

- [architecture.md Step 5 — Naming](../../../_bmad-output/planning-artifacts/architecture.md) (linha 358).
- [architecture.md Step 6 — Árvore final](../../../_bmad-output/planning-artifacts/architecture.md) (linhas 703-706).
- [Story 2.1](../../../_bmad-output/implementation-artifacts/2-1-roles-pre-configurados-espocrm-acl.md) — seed de roles com scopeList=`"Cliente"`.
- [Story 3.1](../../../_bmad-output/implementation-artifacts/3-1-entidade-cliente-pf-pj-validation-br.md) — primeira aplicação.
- [tools/validate-togare-naming.php](../../../tools/validate-togare-naming.php) — R3 (migration table prefix `togare_`) e seu escopo.
- EspoCRM 9.3 `application/Espo/Core/Utils/Database/Schema/Builder.php:147` — `Util::toUnderScore($entityType)` é a fonte do nome da tabela.
