# TogareCore

## O que faz

Módulo fundação do Togare. Provê os 4 serviços transversais que todos os
demais módulos consomem — mais 2 validators BR, 6 contracts inter-módulo
e 3 eventos canônicos.

**Services (PHP):**

- `Services\MigrationRunner` — migrations versionadas (V*__*.php) com rollback.
- `Services\TogareLogger` — log JSON estruturado (singleton estático) com
  auto-preenchimento de timestamp/service/correlationId/userId; propaga PSR-3
  para `data/logs/espo-YYYY-MM-DD.log`. **Único canal de log autorizado**
  (`error_log()` e `$GLOBALS['log']` bloqueados por pre-commit R5).
- `Services\QueueService` — outbox pattern em `togare_queue_items`
  (`enqueue/claim/markDone/markFailed/reclaimStuck`). Idempotente via
  UNIQUE constraint; consumo com `SELECT ... FOR UPDATE SKIP LOCKED` em
  MariaDB. Retry com backoff exponencial; dead letter após 5 tentativas.
  **Único ponto de INSERT em `togare_queue_items`** (pre-commit R6).
- `Services\RateLimiter` — sliding window em `togare_rate_limits`
  (`check/peek/reset`).
- `Events\EventDispatcher` — event bus in-process com 3 eventos:
  `EntityPurgedEvent`, `LicenseExpiredEvent`, `IntegrationFailedEvent`.
- `Validators\BrValidator` — CPF/CNPJ/CEP/telefone BR.
- `Validators\CnjNumberValidator` — número CNJ (Res. CNJ 65/2008, DV mod 97).
- `Services\AuditLogService` — implementa `Contracts\AuditLogContract`. Grava
  em `togare_audit_log` (V006) com `INSERT/UPDATE/DELETE` revogados no app
  user via `docker/scripts/audit-log-lockdown.sh` (NFR10 — append-only por
  desenho de banco). Não bloqueia o caller — falha silenciosa via
  `TogareLogger::event('audit.log.write.failed', ...)`. Story 2.4.

**Helpers (JS, client):**

- `helpers/brValidators.js` — wrapper ES6 sobre npm `validation-br` com API
  1:1 dos validators PHP.

**Contracts inter-módulo** em `Contracts/`:
`HealthCheckProviderContract`, `AuditLogContract`, `NotificationContract`,
`TenantContextResolverContract`, `FileStorageContract`,
`PurgeableStorageContract`, `EventBusContract`, `MigrationInterface`.
Versionados via `Resources/contracts/VERSION.txt` (semver).

## Como instalar

**Dependências:**

- EspoCRM ≥ 9.3.0
- PHP ≥ 8.3 (com `ext-pdo_mysql`)
- MariaDB ≥ 10.6 (exigência do `SELECT ... FOR UPDATE SKIP LOCKED`)

**Build e instalação:**

```bash
cd espocrm/togare-core
npm install
npm run extension              # gera build/togare-core-0.22.0.zip
```

No EspoCRM rodando, via **Admin → Extensions → Upload** selecione o `.zip`
gerado. Ou via CLI dentro do container:

```bash
docker compose cp espocrm/togare-core/build/togare-core-0.22.0.zip \
  espocrm:/tmp/TogareCore.zip
docker compose exec -T espocrm php command.php extension \
  --file=/tmp/TogareCore.zip
```

## Entidades expostas

**Tabelas de infra** criadas automaticamente pelo Migration Runner no
`AfterInstall`:

- `togare_migrations_applied` (V001) — controle do próprio runner.
- `togare_core_smoke` (V002/V003) — fixture viva que comprova o pipeline
  de migrations. Descartável quando Epic 2+ trouxer entidades reais.
- `togare_queue_items` (V004) — fila de trabalho assíncrono.
- `togare_rate_limits` (V005) — contadores de rate limit.
- `togare_audit_log` (V006) — append-only audit log (Story 2.4 — FR37, NFR10).

**Entidades de negócio EspoCRM** declaradas via `entityDefs/`:

- **`Cliente`** (Story 3.1, FR6) — Pessoa Física ou Jurídica do escritório.
  Tabela SQL `cliente` (EspoCRM 9.3 deriva snake_case do entity type — ver
  [docs/ADR-02](docs/ADR-02-entity-naming-business.md) sobre por que entidades
  de negócio NÃO levam prefixo `togare_` e a interação com R3). Stream nativo
  EspoCRM habilitado (`scopes/Cliente.json` `"stream": true`). Campos PF (cpf,
  rg, dataNascimento, estadoCivil, nacionalidade, profissao) + PJ (cnpj,
  razaoSocial, nomeFantasia, inscricaoEstadual) controlados por `tipoPessoa`
  enum + dynamic logic em `clientDefs/Cliente.json`. ACL via os 8 roles
  seedados pelo togare-rbac (Story 2.1+0.6.1).
- **`ParteContraria`** (Story 3.2, FR6/FR7) — contraparte processual
  (Pessoa Física, Jurídica ou Desconhecida). Tabela SQL `parte_contraria`
  (mesma regra ADR-02 do Cliente). Stream habilitado. CPF/CNPJ **opcionais**
  em todos os tipos — diferença chave em relação a Cliente. Tipo
  `desconhecida` permite parte sem documento (anônimas, ações de massa).
  Link N:N → `Processo` declarado (`relationshipName: "ParteContrariaProcesso"`)
  — join table `parte_contraria_processo` é criada pelo ORM no rebuild
  junto com o lado reverso em `entityDefs/Processo.json`. ACL via roles do
  togare-rbac (patch v0.6.2 alinha Assistente com FR7: read+edit+create=team).
- **`Processo`** (Story 3.4, FR7/FR8 + Story 3.5, FR11) — entidade central
  do MVP jurídico. Tabela SQL `processo`; `numeroCnj` armazena 20 dígitos
  puros e renderiza `NNNNNNN-DD.AAAA.J.TR.OOOO` por field view custom. Campos
  `classeCodigo`, `assuntoCodigo` e `movimentoCodigo` usam o field view
  `togare-tpu:views/fields/tpu-lookup` para busca por nome no catálogo TPU.
  Os nomes `classeNome`, `assuntoNome` e `movimentoNome` são denormalizados
  pelo hook `togare-tpu/Hooks/Processo/ResolveTpuFieldsHook`; sem togare-tpu
  instalado, o cadastro degrada sem lookup e sem nomes. Links N:N:
  `clientes`, `partesContrarias` e (Story 3.5) `collaborators` (User,
  relationshipName `ProcessoCollaborator`). **ACL by-assignment completa
  (Story 3.5):** `scopes.collaborators=true` + `aclDefs/Processo.json`
  declarando `assignedUser=true` e `collaborators=true` ativam o WHERE
  automático do EspoCRM com `assigned_user_id = :user OR EXISTS
  (processo_collaborator)` quando a role tem `read=own`.
  `EnforceAssignmentPolicyHook` (BeforeSave, order 5) auto-titulariza
  Advogado em create e bloqueia mudanças de atribuição por não-titulares
  com `Forbidden` + `X-Status-Reason: assignment-not-allowed`. **Reverse
  link `audiencias` (Story 3.6-magro):** `hasMany Audiencia` com `foreign
  processo` — painel relacional de audiências aparece automático no detail
  do processo.
- **`Audiencia`** (Story 3.6-magro, FR16) — versão MAGRO sem detecção
  automática de conflito de horário (cortado em D2). Tabela SQL `audiencia`;
  `belongsTo Processo` (required) com `foreign="audiencias"`. Campos:
  `dataHora` (datetime required), `duracaoMinutos` (int 15-480, default 60),
  `tribunal`/`vara`/`enderecoLink` (texto livre), `tipo` (enum: conciliacao /
  instrucao_julgamento / julgamento / una / conciliacao_mediacao / outras),
  `modalidade` (presencial / virtual / hibrida), `status` (agendada /
  realizada / cancelada / adiada), `participantes` (texto livre — Decisão
  #4: relacional vira Growth se piloto pedir), `observacoes`. **Calendar
  nativo:** `scopes.calendar=true` + `clientDefs.calendar.dateField=dataHora`
  fazem Audiencia aparecer em `#Calendar` lado a lado com Meeting/Call/Task
  — zero código de UI custom (Decisão #3, Story 3.7 cortada). **ACL
  by-assignment LIGHT:** `aclDefs/Audiencia.json` declara apenas
  `assignedUser=true` (sem `collaborators` — Audiencia é menos sensível
  que Processo). `EnforceAudienciaAssignmentHook` (BeforeSave, order 5) só
  faz auto-titular create — admins delegando audiência específica para
  outro advogado é fluxo legítimo (NÃO bloqueia mudança em update,
  diferente de Processo/3.5).

## Hooks disparados / consumidos

**EventDispatcher** do togare-core despacha:

- `EntityPurgedEvent` — emitido por `togare-lgpd` quando entidade é purgada.
- `LicenseExpiredEvent` — emitido por `togare-licensing` no ReadOnlyGate.
- `IntegrationFailedEvent` — emitido por adapters externos após retries.

**Scheduled Jobs** registrados:

- `TogareQueueCleanup` — cron `0 3 1 * *`. Limpa items `status='done'` com
  mais de 90 dias. Items `failed_dead_letter` preservados para auditoria.
- `TogareAuditLogArchiver` — cron `0 4 * * 0` (Domingo 04:00 BRT). Conta rows
  em `togare_audit_log` com `occurred_at` >24m e emite log `audit.archive.pending`
  (não deleta — admin arquiva manualmente via `mariadb-dump --where`).
  Story 2.4.

**Hooks do EspoCRM consumidos:**

- **`Hooks/Cliente/NormalizeBrFieldsHook`** (BeforeSave, order 10) — remove
  máscara de CPF/CNPJ/CEP/telefone/telefone2 antes de persistir (architecture
  L457: storage só dígitos). Story 3.1.
- **`Hooks/Cliente/ValidateBrFieldsHook`** (BeforeSave, order 20) — valida
  CPF/CNPJ DV + regras PF↔PJ + telefone DDD/9º + CEP 8 dígitos. Lança
  `BadRequest` em pt-BR (HTTP 400) se inválido. Story 3.1.
- **`Hooks/Cliente/AuditClienteHook`** (AfterSave, order 50) — emite
  `audit.cliente.created` / `audit.cliente.modified` via `AuditLogContract` em
  `togare_audit_log` (FR37 + NFR10). Story 3.1.
- **`Hooks/Processo/EnforceAssignmentPolicyHook`** (BeforeSave, order 5) —
  Story 3.5 / FR11. Auto-titulariza Advogado criador (se não-privileged sem
  `assignedUserId`); bloqueia mudança de `assignedUserId` ou
  `collaboratorsIds` por usuários que não são titular pré-existente nem
  privileged actor (Sócio/Admin / system superuser EspoCRM). Lança
  `Forbidden` com `X-Status-Reason: assignment-not-allowed`.
- **`Hooks/Processo/NormalizeCnjNumberHook`** (BeforeSave, order 10) —
  normaliza CNJ para 20 dígitos, valida DV mod 97 e bloqueia duplicidade com
  mensagem pt-BR antes do índice UNIQUE.
- **`Hooks/Processo/ValidateProcessoFieldsHook`** (BeforeSave, order 20) —
  valida enums, códigos positivos, valor da causa e coerência de datas.
- **`Hooks/Processo/AuditProcessoHook`** (AfterSave, order 50) — emite
  `audit.processo.created` / `audit.processo.modified`. Story 3.5 expandiu
  `SENSITIVE_FIELDS` com `collaboratorsIds` e o evento `modified` carrega
  listas granulares `addedCollaboratorIds` e `removedCollaboratorIds`
  computadas via diff de `getFetched` vs `get`.
- **`Hooks/Audiencia/EnforceAudienciaAssignmentHook`** (BeforeSave, order 5)
  — Story 3.6-magro / FR16. Versão LIGHT do EnforceAssignmentPolicyHook:
  apenas auto-titulariza Advogado criador (se não-privileged sem
  `assignedUserId`). NÃO bloqueia mudança de assignment em update — admins
  delegando audiência específica é fluxo legítimo.
- **`Hooks/Audiencia/ValidateAudienciaFieldsHook`** (BeforeSave, order 10) —
  valida enums (tipo/modalidade/status), duracaoMinutos entre 15 e 480,
  presença de processoId e dataHora.
- **`Hooks/Audiencia/AuditAudienciaHook`** (AfterSave, order 50) — emite
  `audit.audiencia.created` / `audit.audiencia.modified`. Quando `status`
  muda emite ALÉM eventos derivados `audit.audiencia.cancelled` (com
  `previousStatus`) ou `audit.audiencia.realized` (com `durationMinutes`).
  SENSITIVE_FIELDS = dataHora/tipo/modalidade/status/processoId/
  assignedUserId/tribunal/vara/observacoes.

Módulos consumidores externos (togare-djen, togare-lgpd, etc.) usam
`beforeSave`/`afterSave` nativos do EspoCRM para enfileirar trabalho via
`QueueService::enqueue()`.

## Como testar

- **PHPUnit** (202 tests — inclui Cliente, ParteContraria, Processo,
  EnforceAssignmentPolicyHook + ProcessoMetadata Story 3.5, validators
  BR/CNJ e Audiencia Story 3.6-magro):

  ```bash
  cd espocrm/togare-core
  # Se PHP está no host:
  vendor/bin/phpunit
  # Se não (Windows sem PHP):
  docker run --rm -v "${PWD}:/app" -w /app php:8.3-cli php vendor/phpunit/phpunit/phpunit
  ```

- **Validador de convenções Togare** (R1-R6): na raiz do monorepo,
  `bash tools/tests/run.sh` — 9 cenários cobrindo prefixo de classe,
  README obrigatório, prefixo de tabela, labels pt-BR, proibição de
  `error_log()`, proibição de INSERT direto em togare_queue_items.

- **Build do `.zip`**: `npm run extension` dentro desta pasta deve produzir
  `build/togare-core-0.22.0.zip` sem erro.

- **Instalação no EspoCRM**: upload via Admin → Extensions **ou** via CLI
  `php command.php extension --file=...`. Logs não podem ter `PHP Fatal`
  nem `Uncaught Exception`.

## Audit log append-only (Story 2.4)

Implementa FR37 + NFR10 do PRD: registra eventos sensíveis em
`togare_audit_log` com `UPDATE/DELETE` revogados no app user. Cumpre Marco
Civil art. 15 (guarda mínima 6m → Togare fixa **24m**) e LGPD art. 37
(registro das operações de tratamento).

### Como o consumer usa

Construtor recebe `AuditLogContract` por DI:

```php
use Espo\Modules\TogareCore\Contracts\AuditLogContract;

final class MeuHook
{
    public function __construct(
        private readonly AuditLogContract $auditLog,
    ) {}

    public function afterSave(Entity $entity): void
    {
        $this->auditLog->log(
            'cliente.criado',                // event dot-separated pt-BR
            'TogareCliente',                 // entityType (ou '*' para global)
            $entity->getId(),                // entityId (ou null)
            ['userName' => 'socio_smoke'],   // context (JSON)
        );
    }
}
```

`log()` **NUNCA** lança exceção pro caller — falha de PDO é roteada para
`TogareLogger::event('audit.log.write.failed', ...)`. Operação de negócio
nunca é bloqueada por audit.

### Catálogo de eventos persistidos

| Família | Origem | Quando dispara |
|---|---|---|
| `auth.login.success` / `auth.login.failed` | `Hooks/Authentication/AuthEventAudit` | Hook de autenticação `OnResult` (registrado em `authentication.json`) |
| `auth.lockout` | togare-rbac `Authentication/Hook/AuthLockoutEnforcer` | BeforeLogin — username atingiu limite de 5 falhas/15min (NFR11, Story 2.5) |
| `user.created` / `user.updated` / `user.deleted` | `Hooks/User/UserCrudAudit*` | CRUD em `User` |
| `role.created` / `role.updated` / `role.deleted` | `Hooks/Role/RoleCrudAudit*` | CRUD em `Role` |
| `user.mfa.enabled` / `user.mfa.disabled` | `Hooks/UserData/MfaConfigChangeAudit` | `UserData.auth2FA` muda |
| `config.security.changed` | `Hooks/Settings/SecurityConfigChangeAudit` | Mudança em allowlist de 10 chaves de segurança |
| `user.invited` | togare-rbac dual-write (Story 2.2) | Convite de usuário |
| `mfa.backup_codes.generated/consumed/regenerated` | togare-rbac dual-write (Story 2.3) | Operações de backup codes MFA |
| `mfa.backup_code.login` | togare-rbac dual-write (Story 2.3) | Login com backup code |
| `mfa.required_role.violation` | togare-rbac dual-write (Story 2.3) | Tentativa de desativar MFA em Sócio/Admin |

### Pendência operacional do Felipe

Após primeiro deploy do `togare-core 0.8.0`, **rodar 1×**:

```bash
cd ~/projetos/nextcloud-crm
set -a && source .env && set +a
bash docker/scripts/audit-log-lockdown.sh
```

Detalhes (critério OK / NOK / smoke negativo) em
[`docker/scripts/README.md`](../../docker/scripts/README.md).

### Política de retenção

- **Mínimo 24 meses** ativos na tabela.
- `TogareAuditLogArchiver` (Domingo 04:00 BRT) **alerta** quando há rows
  >24m via `audit.archive.pending` no log estruturado — **não deleta**
  (NFR10 exige imutabilidade). Admin arquiva manualmente quando achar
  conveniente:

  ```bash
  docker exec -i nextcloud-crm-mariadb-1 mariadb-dump \
      --single-transaction \
      --where='occurred_at < DATE_SUB(NOW(), INTERVAL 24 MONTH)' \
      "$ESPOCRM_DB_NAME" togare_audit_log \
      > audit-archive-$(date +%Y%m%d).sql
  ```

  Após exportar, **se** quiser liberar espaço, deletar via root (não app
  user — DELETE bloqueado de propósito):

  ```bash
  docker exec -i nextcloud-crm-mariadb-1 \
      mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$ESPOCRM_DB_NAME" \
      -e "DELETE FROM togare_audit_log WHERE occurred_at < DATE_SUB(NOW(), INTERVAL 24 MONTH)"
  ```

### Anti-padrões

- ❌ **NÃO** fazer `INSERT` direto em `togare_audit_log` — sempre via
  `AuditLogContract::log()` (formatação/timestamp/context são
  responsabilidade do service).
- ❌ **NÃO** usar para logging operacional (debug, latência, etc.) — para
  isso existe `TogareLogger::event()` (stdout JSON + Monolog).
- ❌ **NÃO** capturar TODA mudança em `Settings` — allowlist de 10 chaves
  evita poluição (themes, labels, SMTP genérico ficam de fora).

- **Smoke manual dos services** (dentro do container EspoCRM):

  ```bash
  docker compose exec espocrm php -r '
    require "/var/www/html/vendor/autoload.php";
    $app = new \Espo\Core\Application();
    $c = $app->getContainer();
    \Espo\Modules\TogareCore\Services\TogareLogger::init("togare-core", $c);
    var_dump(\Espo\Modules\TogareCore\Validators\BrValidator::isValidCpf("52998224725"));
    // QueueService e RateLimiter recebem EntityManager via DI (Story 1b.1.1.1-followup):
    $q = $c->get("togareCoreQueueService");
    $id = $q->enqueue("smoke", ["ok" => 1], "smoke.".time());
    echo "enqueued: $id\n";
  '
  ```

## Entidade Cliente PF/PJ (Story 3.1)

Implementa **FR6** — Advogado/Assistente cadastram clientes PF ou PJ com
**validation-br dupla** (UX-DR12). Primeira entidade de negócio do MVP — abre
Epic 3.

### Estrutura

- Entity: `Espo\Modules\TogareCore\Entities\Cliente` (usa trait `TenantAwareEntity`).
- Tabela SQL: `cliente` — EspoCRM 9.3 deriva snake_case do entity type.
  ADR-02 explica por que entidades de NEGÓCIO ficam sem prefixo `togare_`
  (precedente: `account`, `contact`, `lead` vanilla EspoCRM também não têm).
- Campos PF: `cpf`, `rg`, `dataNascimento`, `estadoCivil`, `nacionalidade`, `profissao`.
- Campos PJ: `cnpj`, `razaoSocial`, `nomeFantasia`, `inscricaoEstadual`.
- Comuns: `name`, `tipoPessoa` (enum `pf|pj`), `email`, `telefone`, `telefone2`,
  `cep`, `logradouro`, `numeroEndereco`, `complemento`, `bairro`, `cidade`, `uf`,
  `observacoes`, `assignedUser`.
- Storage **só dígitos** em CPF/CNPJ/CEP/telefone — máscara só na UI via helpers
  Handlebars `formatCpf`/`formatCnpj`/`formatPhone`/`formatCep` (registrados
  globalmente por `js/bootstrap-formatters.js`).

### Validação dupla

- **Client-side** (`views/cliente/record/edit.js` + `helpers/brValidators.js`)
  — bloqueia UX no blur do campo, mostra mensagem inline pt-BR.
- **Server-side** (`Hooks/Cliente/ValidateBrFieldsHook`) — chama
  `BrValidator::isValid*` em PHP, fonte da verdade. Lança `BadRequest` em pt-BR
  (HTTP 400 + `X-Status-Reason`) se inválido. Architecture L581 anti-pattern:
  confiar só no client é bypass-able.

### Regras PF↔PJ (server-side)

- PF sem CPF → 400 BadRequest.
- PF com CPF DV inválido → 400.
- PF com CNPJ preenchido → 400 (combinação ilegal).
- PJ sem CNPJ ou DV inválido → 400.
- PJ sem `razaoSocial` → 400.
- PJ com CPF preenchido → 400.
- PJ + name vazio + razaoSocial preenchido → best-effort: copia
  `razaoSocial → name` no beforeSave.

### ACL

Os 8 roles seedados pela Story 2.1 já declaram scope `Cliente`. Story 3.1 patcha
`assistente.json` (togare-rbac 0.6.1) para alinhar com FR6 — read-only →
create+edit (delete continua exclusivo do Sócio/Admin). Em instalação existente,
ajuste manual via Admin → Roles → Assistente/Estagiário (ver README do
togare-rbac, seção v0.6.1).

### Audit log

`Hooks/Cliente/AuditClienteHook` (AfterSave) emite via `AuditLogContract`:

- `audit.cliente.created` quando `isNew()=true`.
- `audit.cliente.modified` quando há mudança em campo sensível (allowlist de 21 campos
  cobrindo PII + endereço + ACL atribuída).

Persiste em `togare_audit_log` (Story 2.4) — append-only, retenção 24m. Stream
nativo do EspoCRM também ativo (`scopes/Cliente.json` `"stream": true`) para
histórico in-app no detail view.

### Smoke real (REST)

Criar PF como Advogado:

```bash
curl -k -s -u "advogado_smoke:senha" \
  -H "Content-Type: application/json" \
  -X POST https://${TOGARE_DOMAIN}/api/v1/Cliente \
  -d '{"name":"João da Silva","tipoPessoa":"pf","cpf":"52998224725","telefone":"11987654321"}'
# → 200 + JSON com id criado
```

Criar PJ como Advogado:

```bash
curl -k -s -u "advogado_smoke:senha" \
  -H "Content-Type: application/json" \
  -X POST https://${TOGARE_DOMAIN}/api/v1/Cliente \
  -d '{"name":"","tipoPessoa":"pj","razaoSocial":"Empresa S.A.","cnpj":"11222333000181"}'
# → 200 + name = "Empresa S.A." (best-effort copy)
```

CPF DV inválido:

```bash
curl -k -i -s -u "advogado_smoke:senha" \
  -H "Content-Type: application/json" \
  -X POST https://${TOGARE_DOMAIN}/api/v1/Cliente \
  -d '{"name":"Teste","tipoPessoa":"pf","cpf":"12345678900"}'
# → HTTP 400 + X-Status-Reason: CPF inválido — confira o número e tente de novo.
```

Listagem:

```bash
curl -k -s -u "advogado_smoke:senha" \
  "https://${TOGARE_DOMAIN}/api/v1/Cliente?orderBy=name&maxSize=20"
# → {"total":N,"list":[...]}
```

Validação no banco (storage só dígitos):

```sql
SELECT cpf, telefone, cep FROM cliente WHERE id='<id>';
-- Esperado: cpf='12345678909', telefone='11987654321', cep='01310100'
```

## Entidade ParteContraria (Story 3.2)

Contraparte processual do escritório (FR6/FR7). Diferente de Cliente, pode ser
identificada apenas pelo nome:

- `tipoPessoa` enum: `pf` | `pj` | `desconhecida`.
- CPF e CNPJ **opcionais** em todos os tipos (sem UNIQUE — homônimos podem coexistir).
- Sem endereço, RG, estado civil, nascimento, razão social — entidade enxuta;
  apenas `name`, `tipoPessoa`, `cpf?`, `cnpj?`, `email?`, `telefone?`, `observacoes?`.
- Link N:N → `Processo` declarado (`relationshipName: "ParteContrariaProcesso"`).
  Join table criada pelo ORM no rebuild com o lado reverso de `Processo`.

### Estrutura

- `entityDefs/ParteContraria.json` — schema completo.
- `scopes/ParteContraria.json` — `acl: true`, `stream: true`, `tab: true`.
- `clientDefs/ParteContraria.json` — dynamic logic visibilidade cpf↔cnpj↔desconhecida.
- `layouts/ParteContraria/{list,detail,filters}.json` — UI nativa.
- `i18n/pt_BR/ParteContraria.json` + atualização em `Global.json` — pt-BR completo.
- `Entities/ParteContraria.php` — extends `Entity` + trait `TenantAwareEntity`.
- `Controllers/ParteContraria.php` — vazio (`extends Record`); obrigatório para REST.

### Validação dupla

- **Client:** `views/parte-contraria/record/edit.js` valida cpf/cnpj/telefone no
  blur via `togare-core:helpers/brValidators` + `_clearOpposingFields()` ao mudar
  `tipoPessoa` (limpa cpf/cnpj inadequados).
- **Server:** `Hooks/ParteContraria/ValidateBrFieldsHook` (order 20) re-valida
  e lança `BadRequest` em pt-BR. Antes dele, `NormalizeBrFieldsHook` (order 10)
  garante storage só dígitos.

### Regras tipoPessoa↔documento (server-side)

| tipo            | cpf       | cnpj      | regra                                |
| --------------- | --------- | --------- | ------------------------------------ |
| `pf`            | opcional  | proibido  | CPF se informado deve ter DV válido  |
| `pj`            | proibido  | opcional  | CNPJ se informado deve ter DV válido |
| `desconhecida`  | proibido  | proibido  | só `name` + opcionalmente contato    |

### ACL

Os 8 roles seedados pelo togare-rbac já incluem `ParteContraria` no scopeList.
**Patch v0.6.2:** Assistente passa de read-only para
`{read: team, edit: team, create: team, delete: no}` — alinha com FR7
(Sócio/Admin, Advogado e Assistente cadastram partes; Secretária só consulta).

### Audit log

`Hooks/ParteContraria/AuditParteContrariaHook` (order 50) emite
`audit.parte_contraria.created` e `audit.parte_contraria.modified` em
`togare_audit_log` via `AuditLogContract` (binding em `Binding.php`,
herdado da Story 3.1). Allowlist de campos sensíveis evita ruído.

### Smoke real (REST)

```bash
# Criar PF sem CPF (permitido — diferença vs Cliente)
curl -k -s -u "advogado_smoke:senha" -X POST \
  "https://${TOGARE_DOMAIN}/api/v1/ParteContraria" \
  -H 'Content-Type: application/json' \
  -d '{"tipoPessoa":"pf","name":"Réu sem CPF"}'
# → HTTP 200

# Criar desconhecida (anônima)
curl -k -s -u "advogado_smoke:senha" -X POST \
  "https://${TOGARE_DOMAIN}/api/v1/ParteContraria" \
  -H 'Content-Type: application/json' \
  -d '{"tipoPessoa":"desconhecida","name":"Parte Anônima"}'
# → HTTP 200

# CPF DV inválido → 400 com X-Status-Reason pt-BR
curl -k -s -u "advogado_smoke:senha" -X POST \
  "https://${TOGARE_DOMAIN}/api/v1/ParteContraria" \
  -H 'Content-Type: application/json' \
  -d '{"tipoPessoa":"pf","name":"DV errado","cpf":"12345678900"}'
# → HTTP 400, message "CPF inválido — confira o número e tente de novo."

# Storage só dígitos
SELECT cpf, cnpj, telefone FROM parte_contraria WHERE id='<id>';
-- Esperado: só dígitos, sem máscara.
```

## Entidade Audiencia (Story 3.6-magro, FR16, v0.12.0)

Versão **MAGRO** — CRUD simples sem detecção automática de conflito de
horário (cortado em D2 do Party Mode). Calendar nativo do EspoCRM cobre
agenda consolidada (Story 3.7 cortada). Identifier técnico sem cedilha
(`Audiencia`); label pt-BR é `"Audiência"` em `Global.json` /
`Audiencia.json`.

### Esquema

| Campo            | Tipo      | Required | Notas                                                  |
|------------------|-----------|----------|--------------------------------------------------------|
| `dataHora`       | datetime  | sim      | quando ocorre — fonte de verdade do Calendar           |
| `duracaoMinutos` | int       | não      | default 60; entre 15 e 480 (validado pelo hook)        |
| `tribunal`       | varchar   | não      | free text — catálogo controlado é Growth               |
| `vara`           | varchar   | não      | free text                                              |
| `enderecoLink`   | text      | não      | endereço físico OU link sala virtual (depende modalidade)|
| `tipo`           | enum      | sim      | conciliacao / instrucao_julgamento / julgamento / una / conciliacao_mediacao / outras |
| `modalidade`     | enum      | sim      | presencial / virtual / hibrida                         |
| `status`         | enum      | sim      | agendada / realizada / cancelada / adiada              |
| `participantes`  | text      | não      | texto livre (Decisão #4 — relacional vira Growth se piloto pedir) |
| `observacoes`    | text      | não      | anotações pós-audiência (resultado, próximos passos)   |
| `processo`       | belongsTo | sim      | foreign `audiencias` em Processo.json                  |
| `assignedUser`   | belongsTo | não      | advogado responsável (pode ≠ assignedUser do Processo) |

### Calendar nativo EspoCRM (Decisão #3)

`scopes.Audiencia.calendar=true` +
`clientDefs.Audiencia.calendar.dateField=dataHora` + `nameField=tipo` fazem
`#Calendar` agregar Audiência lado a lado com Meeting/Call/Task — zero código
de UI custom. Story 3.7 (agenda consolidada custom) cortada por isso.

### ACL by-assignment LIGHT (Decisão #5)

| Role            | Audiencia                                                       |
|-----------------|-----------------------------------------------------------------|
| Sócio/Admin     | `all`                                                           |
| Advogado        | `own` (vê apenas onde é `assignedUser`)                          |
| Assistente      | `own` (apoia advogado responsável)                              |
| Secretária      | `{read: team, edit: no, create: no, delete: no}` (agenda consolidada) |
| Financeiro      | `no`                                                            |
| Marketing       | `no`                                                            |
| RH-lite         | `no`                                                            |
| Cliente-portal  | `no`                                                            |

`aclDefs/Audiencia.json` declara apenas `assignedUser=true` (sem
`collaborators` — diferente de Processo da Story 3.5). Audiencia é menos
sensível: cada audiência é evento pontual, não o caso jurídico todo.

### Hooks

- `EnforceAudienciaAssignmentHook` (BeforeSave, order 5) — versão LIGHT
  do EnforceAssignmentPolicyHook. Auto-titulariza Advogado criador em
  create. **NÃO bloqueia** mudança de assignment em update — admins
  delegando audiência específica é fluxo legítimo.
- `ValidateAudienciaFieldsHook` (BeforeSave, order 10) — enums válidos,
  duracaoMinutos entre 15-480, processoId/dataHora presentes. Mensagens
  pt-BR via `BadRequest::createWithBody` com `X-Status-Reason: invalid`.
- `AuditAudienciaHook` (AfterSave, order 50) — emite
  `audit.audiencia.created` ou `audit.audiencia.modified`. Quando
  `status` muda emite ALÉM eventos derivados:
  - `→ cancelada` → `audit.audiencia.cancelled` (com `previousStatus`)
  - `→ realizada` → `audit.audiencia.realized` (com `durationMinutes`)

### Reverse link em Processo

`entityDefs/Processo.json` ganha `links.audiencias` (`hasMany Audiencia`,
`foreign processo`). Após `rebuild.php` o painel relacional "Audiências"
aparece automaticamente no detail do processo, listando as audiências
vinculadas.

### Smoke real (REST)

```bash
# Criar audiência como Advogado (auto-titular)
curl -k -s -u "advogado_smoke:senha" -X POST \
  "https://${TOGARE_DOMAIN}/api/v1/Audiencia" \
  -H 'Content-Type: application/json' \
  -d '{
    "dataHora":"2026-05-15 14:00:00",
    "tipo":"conciliacao",
    "modalidade":"presencial",
    "status":"agendada",
    "processoId":"<UUID-do-processo>",
    "tribunal":"TJSP",
    "vara":"3ª Vara Cível",
    "participantes":"Dr. Ricardo (escritório), Sr. João (cliente)"
  }'
# → HTTP 200, assignedUserId = id do advogado_smoke

# Tipo inválido → 400 com X-Status-Reason invalid
curl -k -s -u "advogado_smoke:senha" -X POST \
  "https://${TOGARE_DOMAIN}/api/v1/Audiencia" \
  -H 'Content-Type: application/json' \
  -d '{"dataHora":"2026-05-15 14:00:00","tipo":"invalido","processoId":"..."}'
# → HTTP 400, message "Tipo inválido — escolha uma das opções."

# Cancelar audiência → emite 2 eventos audit (modified + cancelled)
curl -k -s -u "advogado_smoke:senha" -X PUT \
  "https://${TOGARE_DOMAIN}/api/v1/Audiencia/<id>" \
  -H 'Content-Type: application/json' \
  -d '{"status":"cancelada"}'
# → HTTP 200; togare_audit_log ganha 2 entries
```

## Pipeline de Leads vanilla — Lead + Opportunity (Story 3.8, FR31, v0.13.0)

**Resumo executivo.** A Story 3.8 entrega o pipeline de captação de leads do
papel **Marketing** (jornada Rafael, PRD §342-352) usando as entidades
**vanilla EspoCRM** `Lead` e `Opportunity` — sem nova entity, sem hook PHP,
sem JS custom. Tudo é **override seletivo de metadata** via merge do EspoCRM
9.x (`entityDefs` + `clientDefs` + `i18n`).

**Decisão arquitetural-chave:** a customização vive em `togare-core/Resources/metadata`
em vez de virar módulo `togare-marketing` separado. Custo de novo módulo
bundled (extension.json + module.json + composer + npm) é desproporcional para
6 arquivos JSON. Se Growth pedir webhook de captação ou hook auto-Cliente,
aí sim spawn-off.

### Lead.status — 4 estágios (3 visíveis + Converted vanilla)

Override em `Resources/metadata/entityDefs/Lead.json`. Substitui completamente
o array vanilla `["New", "Assigned", "In Process", "Converted", "Recycled", "Dead"]`.

| Valor (storage) | Display pt-BR | Style | Default? |
|---|---|---|---|
| `Novo Lead` | Novo Lead | `primary` | ✓ |
| `Qualificado` | Qualificado | `success` | — |
| `Descartado` | Descartado | `danger` | — |
| `Converted` | Convertido | `success` | sistema-only |

> O valor `Converted` é mantido no enum porque o flow vanilla (`Espo\Modules\Crm\Tools\Lead\Convert::process`)
> seta esse literal automaticamente ao final do botão "Convert". Removê-lo
> quebraria o save com erro "value not in options".

### Opportunity.stage — 4 estágios pt-BR + probabilityMap

Override em `Resources/metadata/entityDefs/Opportunity.json`. Substitui o
array vanilla `["Prospecting", "Qualification", ..., "Closed Won", "Closed Lost"]`
e o respectivo `fields.stage.probabilityMap` vanilla.

| Stage | Display | Style | probability | Closed |
|---|---|---|---|---|
| `Proposta Enviada` | Proposta Enviada | `primary` | 30 | — |
| `Oportunidade Aceita` | Oportunidade Aceita | `info` | 70 | — |
| `Cliente Convertido` | Cliente Convertido | `success` | 100 | ✓ won |
| `Perdido` | Perdido | `danger` | 0 | ✓ lost |

### Convert restringido a `Opportunity` (sem Account/Contact)

Override em `Resources/metadata/entityDefs/Lead.json`:

```json
{ "convertEntityList": ["Opportunity"] }
```

**Por quê:** o role Marketing tem `Account=no` e `Contact=no` (Story 2.1).
O Convert default vanilla criaria também `Account` + `Contact` — Marketing
não teria permissão para esses, gerando 403 silencioso. Restringimos a UI
para oferecer apenas `Opportunity`, alinhado com o domínio jurídico (nosso
"cliente" é a entity `Cliente` do togare-core, criada manualmente pelo
Sócio/Admin/Advogado quando o contrato é assinado).

### Kanban habilitado para Opportunity

Override em `Resources/metadata/clientDefs/Opportunity.json`:

```json
{ "kanbanViewMode": true, "kanbanStatusField": "stage" }
```

Acessível via `#Opportunity/list/kanban` — 4 colunas (uma por stage), drag-and-drop
move o card e dispara `PUT /api/v1/Opportunity/<id>` com novo `stage`.

### i18n pt_BR

- `Resources/i18n/pt_BR/Lead.json` — labels e options.status (display "Convertido"
  para o valor literal `Converted`).
- `Resources/i18n/pt_BR/Opportunity.json` — labels (stage, probability, amount,
  closeDate, leadSource) e options.stage.
- `Resources/i18n/pt_BR/Global.json` ganha entries `Lead` + `Opportunity` em
  `scopeNames`, `scopeNamesPlural`, `labels.Global.tabs` (preserva
  Cliente/ParteContraria/Processo/Audiencia anteriores).

### Smoke real exemplos (curl)

```bash
# Login Marketing
AUTH=$(echo -n 'marketing:senha-temp' | base64)
HOST="https://${TOGARE_DOMAIN}"

# AC3 — criar Lead
curl -k -s -X POST -H "Authorization: Basic $AUTH" -H 'Content-Type: application/json' \
  -d '{"firstName":"João","lastName":"Silva","emailAddress":"joao@empresa.com","status":"Novo Lead"}' \
  "$HOST/api/v1/Lead"
# → 201 Created, body com id e status="Novo Lead"

# AC6 — Convert Lead em Opportunity (sem Account/Contact)
curl -k -s -X POST -H "Authorization: Basic $AUTH" -H 'Content-Type: application/json' \
  -d '{"records":{"Opportunity":{"name":"Caso Trabalhista — João Silva","amount":5000,"closeDate":"2026-06-30"}}}' \
  "$HOST/api/v1/Lead/<LEAD_ID>/action/convert"
# → 200; GET /api/v1/Lead/<LEAD_ID>.status === "Converted";
#   GET /api/v1/Opportunity?... — 1 registro stage="Proposta Enviada"

# AC8 — mover stage da Opportunity (drag-and-drop ⇒ PUT)
curl -k -s -X PUT -H "Authorization: Basic $AUTH" -H 'Content-Type: application/json' \
  -d '{"stage":"Oportunidade Aceita"}' \
  "$HOST/api/v1/Opportunity/<OPP_ID>"
# → 200; GET → probability=70

# AC10/11/12 — blindagem ACL Marketing (Story 2.1)
for ent in Processo Cliente LancamentoFinanceiro ContratoHonorarios Audiencia Funcionario; do
  curl -k -s -o /dev/null -w "$ent → %{http_code}\n" -H "Authorization: Basic $AUTH" "$HOST/api/v1/$ent"
done
# → todos 403
```

> **Pós-instalação obrigatória:** `php rebuild.php` no container EspoCRM
> para refrescar o cache de metadata. Sem rebuild, os enums em pt-BR não
> aparecem (vem em inglês vanilla). Confirme que togare-core foi instalado
> via Admin → Extensions e que `loadOrder.php` lista o módulo.

## Field views customizadas (Stories 3.4 e 3-A, v0.14.0)

Field views ES6 que herdam de `views/fields/varchar` e aplicam máscara BR
canônica em **display** (detail/list via `getValueForDisplay()`) e
**auto-format no edit** (listener `input` aplica máscara visual e seta
SÓ DÍGITOS no model — single source of truth, em sintonia com
`NormalizeBrFieldsHook` server-side e architecture L457 — storage só
dígitos).

| Field view | Tamanho dígitos | Máscara display | Storage | Auto-format edit |
|---|---|---|---|---|
| `togare-core:views/fields/cnj` | 20 | `NNNNNNN-DD.AAAA.J.TR.OOOO` | só dígitos | ❌ (Growth) |
| `togare-core:views/fields/cpf-br` | 11 | `XXX.XXX.XXX-XX` | só dígitos | ✅ |
| `togare-core:views/fields/cnpj-br` | 14 | `XX.XXX.XXX/XXXX-XX` | só dígitos | ✅ |
| `togare-core:views/fields/cep-br` | 8 | `XXXXX-XXX` | só dígitos | ✅ |
| `togare-core:views/fields/telefone-br` | 10 ou 11 | `(DD) XXXX-XXXX` / `(DD) XXXXX-XXXX` | só dígitos | ✅ |

**Uso em entityDef:**

```json
"cpf": {
    "type": "varchar",
    "view": "togare-core:views/fields/cpf-br",
    "maxLength": 14,
    "trim": true
}
```

`maxLength` aceita máscara — storage final é só dígitos via
`NormalizeBrFieldsHook` server-side defensivo + `model.set(name, digits)`
no listener `input` da field view (defesa em profundidade).

**Input inválido passa-through:** se `digitsOnly(value).length` ≠ tamanho
esperado da máscara, `getValueForDisplay()` retorna o valor original sem
mascarar. Mantém investigação visual de dados meio-cadastrados (Decisão #3
Story 3-A).

**`telefone-br` auto-detecta 10 vs 11 dígitos:** sem param `tipo` —
formato fixo (10) vs celular com nono (11) escolhido pelo `formatPhone`
(Decisão #4 Story 3-A).

**Divergência intencional `cnj` (sem `-br`):** Story 3.4 fixou o nome antes
da convenção `*-br` da Story 3-A. Renomear quebraria
`Processo.numeroCnj` (entityDef já referencia `togare-core:views/fields/cnj`).
Mantido como precedente histórico — documentado aqui para evitar confusão.

**Aplicação em Cliente (Story 3-A):** patch em `entityDefs/Cliente.json`
nos campos `cpf`, `cnpj`, `cep`, `telefone`, `telefone2`.

**Aplicação em ParteContraria (Story 3-A):** patch em
`entityDefs/ParteContraria.json` nos campos `cpf`, `cnpj`, `telefone`
(entity reduzida — sem `cep`/`telefone2`).

**`inputmode` em mobile** (Open Question #3 confirmada por Felipe pós-dev):
afterRender() em MODE_EDIT seta `inputmode="numeric"` em `cpf-br`/`cnpj-br`/
`cep-br` (teclado numérico simples) e `inputmode="tel"` em `telefone-br`
(teclado de telefone — convenção semântica). Em desktop sem efeito;
em iOS/Android troca o teclado virtual pela versão otimizada.

**Suite vitest** (`tests/js/`): 5 specs novas (`hb-formatters.spec.js` cobre
helpers puros + 4 `<name>-br-field-view.spec.js` cobrem cada field view —
incluindo asserção do `inputmode`, `fetch()` canônico e sync do model com
opções UI/fromField do EspoCRM) — **66 cenários novos**, suite total
**78 verdes** (subiu de 12).

## QueueService::markFailed customDelaySeconds (Story 4a.1, v0.15.0)

Assinatura ampliada com parâmetro opcional:

```php
public function markFailed(
    string $itemId,
    string $reason,
    bool $permanent = false,
    ?int $customDelaySeconds = null,  // novo em 0.15.0
): void
```

**Comportamento:**

- `$customDelaySeconds === null` (default) → mantém backoff exponencial
  histórico `60 * 2^retryCount` segundos com jitter ±10% (chamadas que
  já existem desde 1a.4c não regridem).
- `$customDelaySeconds > 0` → `next_retry_at = NOW() + $customDelaySeconds`
  literal, sem jitter. Call-site controla a precisão.

**Uso na Story 4a.1:** `DjenWorkerService` chama
`markFailed($itemId, $reason, false, 3600)` em falhas do `DjenAdapter`
(timeout/5xx/circuit breaker aberto) — atende AC2/AC3 da story que
exigem `next_retry_at = now+1h` literal.

**Por que parâmetro opcional vs método novo:** call-sites históricos
(`internal`, futuras 5.x/6.x/7a.x) preservam contrato — apenas filas com
SLA específico passam o parâmetro. Não-breaking change.

## BrazilianBusinessCalendar (Story 4a.2, v0.16.0)

Calendário forense brasileiro **cross-cutting** — feriados nacionais BR
+ aritmética de dias úteis e dias corridos. Pensado como utilitário puro
reutilizado por todos os módulos que toquem prazos (DJEN parser,
Audiência, Lançamento Financeiro futuros).

**Caminho:** `Espo\Modules\TogareCore\Services\Calendar\BrazilianBusinessCalendar`
(registrado em `containerServices.json` como `togareCoreBrCalendar`).

**Métodos públicos:**

| Método | Retorna | Uso |
|---|---|---|
| `isHolidayBR(DateTimeImmutable)` | `bool` | Feriado nacional BR? |
| `isBusinessDay(DateTimeImmutable)` | `bool` | Dia útil (não-fim-de-semana E não-feriado)? |
| `nextBusinessDay(DateTimeImmutable)` | `DateTimeImmutable` | Próximo dia útil **estritamente depois** (art. 5º CNJ 455). |
| `addBusinessDays(start, int $days)` | `DateTimeImmutable` | $days-ésimo dia útil **depois** de $start. Pula sábados, domingos, feriados. |
| `addCalendarDays(start, int $days)` | `DateTimeImmutable` | $days dias corridos (não pula nada). |
| `listHolidaysFor(int $year)` | `list<DateTimeImmutable>` | Lista cronológica dos feriados do ano. |
| `easterSundayFor(int $year)` | `DateTimeImmutable` | Domingo de Páscoa (algoritmo Gauss/Meeus). |

**Feriados cobertos:**

- **Fixos** (Lei 662/1949 + Lei 6802/1980 + Lei 14.759/2023): 1/1, 21/4,
  1/5, 7/9, 12/10, 2/11, 15/11, **20/11 (Consciência Negra, desde 2024)**,
  25/12.
- **Móveis** (CPC art. 216 + tradição forense unânime): Carnaval seg+ter
  (Páscoa - 48/-47), Sexta-Feira Santa (Páscoa - 2), Corpus Christi
  (Páscoa + 60).

**Validação Páscoa (algoritmo Anonymous Gregorian):**

| Ano | Páscoa |
|---|---|
| 2024 | 31/03 |
| 2025 | 20/04 |
| 2026 | 05/04 |
| 2027 | 28/03 |
| 2028 | 16/04 |
| 2029 | 01/04 |
| 2030 | 21/04 |

**Convenção `addBusinessDays` — atenção:**

- `addBusinessDays(seg, 1) = ter` (1º dia útil **depois** de seg).
- `addBusinessDays(sex, 1) = seg` (sex+1d útil pula fim de semana).
- `addBusinessDays(qui 17/04/2026, 5) = qua 27/04/2026` (pula Tiradentes 21/04).

Para o art. 5º Res. CNJ 455 + CPC art. 219 (regra inclusiva confirmada
por Felipe 2026-05-03 — disponibilização não conta, mas D+1 útil é o
**1º dia da contagem inclusive**):

```php
// Úteis (CPC art. 219) — fórmula direta sem passo intermediário:
$dataFatal = $cal->addBusinessDays($dataDisp, 15);
// A semântica exclusiva do método (n-ésimo útil DEPOIS de start) já é
// equivalente a "1º útil seguinte como dia 1 + (N-1) úteis adicionais".

// Corridos (CPC art. 523, cumprimento de sentença) — precisa do passo
// intermediário porque calendar puro não pula nada:
$dataInicio = $cal->nextBusinessDay($dataDisp);              // 1º dia inclusive
$dataFatal = $cal->addCalendarDays($dataInicio, 15 - 1);     // 14 corridos a partir do dia 1
```

**Exemplo AC6 (contestação)**: `addBusinessDays(sex 15/05/2026, 15) =
seg 08/06/2026` (Corpus Christi 04/06 pulado dentro da contagem).

**Exemplo AC7 (cumprimento sentença)**: `nextBusinessDay(15/05) = 18/05`,
`addCalendarDays(18/05, 14) = 01/06` (15 dias inclusive contando 18/05
como dia 1).

**Limitações conhecidas (Open Questions Story 4a.2):**

- **Recesso forense fim de ano** (CPC art. 220, 20/12-6/1) — é SUSPENSÃO
  de prazo, não feriado. MVP trata como dia útil normal. Story 4b.x ou
  Epic 10 trata categoria semântica separada.
- **Feriados estaduais/municipais** (ex.: Revolução SP 09/07) variam por
  OAB.uf. MVP usa **só nacionais** (parser conservador). Growth.
- **Feriados retroativos / antecipados** (decreto municipal de calamidade,
  ponte) — não suporta. Growth.

**Reuso futuro (cross-cutting):**

- `togare-djen` 0.2.0 (Story 4a.2) → `DjenParserService` calcula
  `dataFatal` Res. CNJ 455.
- `togare-core` futuras → validar `Audiencia.dataHora` (warning se cair
  em feriado nacional).
- `togare-financeiro` futuro → `dataVencimentoFatura` shift D+1 se cair
  em feriado bancário.

## Story 4a.5.1 — `dataCumprimento` (BriefingDoDia filtra hoje) (v0.22.0)

**Discovery #2 da retrospectiva do Epic 4a (2026-05-06).** Felipe percebeu no smoke F1 da 4a.5 que o BriefingDoDia mostrava 9 prazos pendentes — incluindo prazos que vencem em 25 dias. Isso fura o conceito do dashlet ("prazos do **dia**"). Solução: separar `dataFatal` (deadline LEGAL do tribunal — sancionável) de `dataCumprimento` (data INTERNA do escritório — quando o advogado planeja fazer). Ordem confirmada na retro: **4b.0 → 4a.5.1 → 4b.1**.

**Sem regressão de superfície grande.** 1 migration trivial + 1 hook BeforeSave order=15 + 1 método novo no Calendar service + 1 boolFilter PHP + 1 patch dashlet (1 linha) + 1 patch entityDefs (1 field + 2 indexes) + i18n + layouts (detail+filters; sem mudança em list — viewport apertado).

**Principais entregas:**

- **Migration V013** (Decisão #1) — `ALTER TABLE prazo ADD COLUMN data_cumprimento DATE NULL` + `idx_prazo_data_cumprimento` simples + `idx_prazo_data_cumprimento_status` composto (cobre `WHERE status IN (...) AND (data_cumprimento IS NULL OR data_cumprimento <= today)` sem filesort — espelha pattern V012 `idx_prazo_data_fatal_prioridade_weight`). **Sem backfill** — coluna nasce `NULL` para todas as linhas pré-existentes; default só dispara em CRIAÇÃO. Audit log entry `prazo.schema_migrated_v013` defensiva (try/catch \\Throwable). Idempotente via try/catch DUPLICATE COLUMN/KEY/`already exists`.
- **Hook `DefaultDataCumprimentoHook`** (Decisão #2) — BeforeSave order=15 (entre `Validate=10`/`PrioridadeWeight=10` e `AutoLink=20` e `Audit=50`). Default `dataCumprimento = dataFatal − 2 dias úteis` em `isNew()`. Respeita override manual (não sobrescreve se já setado). Bail silencioso se `dataFatal` vazio (Validate=10 já bloqueia; defesa em profundidade). Try/catch `\\Throwable` com warning log `prazo.dataCumprimento.default_failed` — nunca bloqueia save (mesmo princípio defensivo do `AutoLinkClientHook`).
- **`BrazilianBusinessCalendar::subtractBusinessDays`** (Decisão #3) — método novo cross-cutting espelho de `addBusinessDays`. Convenção: `subtractBusinessDays(seg, 1) = sex anterior`, `subtractBusinessDays(qui, 1) = qua`. Pula sábados, domingos, feriados nacionais BR. Cap defensivo 365 iterações. **Story 4b.2** (alertas D-7/D-3/D-1) reusa.
- **boolFilter `PendentesParaHoje`** (Decisão #4) — implementa `Filter` (não estende `MeusPendentes` — pattern do projeto). WHERE clause: `status IN (4 status pendentes) AND assignedUserId = currentUser AND (dataCumprimento IS NULL OR dataCumprimento <= today)`. Cutoff calculado em PHP via `DateTimeImmutable('today', 'America/Sao_Paulo')` (pattern `ProtocoladosUltimos30d` — portável + testável + TZ-aware). **Default seguro (Felipe):** prazos sem `dataCumprimento` setado caem no painel hoje — advogado não esquece.
- **Dashlet `Prazos.json` patch 1 linha** (Decisão #5) — `defaults.searchData.bool: meusPendentes → pendentesParaHoje`. Orderer custom `DataFatalPriorizado` em `selectDefs::ordererClassNameMap.dataFatal` continua inalterado (cuida do desempate `prioridadeWeight DESC`). CTA "Confira hoje" continua apontando para `#Prazo?bool=meusPendentes` (universo maior — advogado clica para ver list completa).
- **`AuditPrazoHook`** (Decisão #9) — `SENSITIVE_FIELDS += 'dataCumprimento'` + adiciona `dataCumprimento` em `buildCreatedContext` (registra valor inicial após default ou override manual). Compliance NFR10 + debug do piloto.
- **UX edit form / detail** (Decisão #6) — `dataCumprimento` no painel "Detalhamento" linha própria (entre prioridade/tipoPrazo e motivoReagendamento). Tooltip via i18n `tooltips.dataCumprimento`: "Data em que VOCÊ planeja fazer este prazo (controle interno do escritório)...". `filters.json` inclui (search bar avançada). `list.json` **NÃO** inclui (viewport apertado MVP).
- **i18n** — `fields.dataCumprimento`, `tooltips.dataCumprimento`, `boolFilters.pendentesParaHoje` ("Para hoje").

**Decisões vinculantes:** 9 decisões registradas em [story 4a-5-1-data-cumprimento-prazo-interno.md](../../_bmad-output/implementation-artifacts/4a-5-1-data-cumprimento-prazo-interno.md):

1. `dataCumprimento` é coluna stored DATE NULL (não virtual nem extensão de dataFatal).
2. Default via Hook BeforeSave order=15 (não SQL CURDATE nem JS edit form).
3. `subtractBusinessDays` cross-cutting (4b.2 reusa).
4. boolFilter PendentesParaHoje — pendentes COM `dataCumprimento <= today OR NULL` (default seguro).
5. Dashlet só patch `searchData` — orderBy/Orderer/CTA inalterados.
6. UX: linha própria no painel Detalhamento + tooltip + filters.json (sim) + list.json (não).
7. Sem mudança em RBAC fieldLevel (default herda).
8. Sem validação adicional em `ValidatePrazoFieldsHook` (qualquer date válido aceito).
9. `AuditPrazoHook.SENSITIVE_FIELDS` += dataCumprimento.

**Cobertura de testes (≥18 PHPUnit novos):**

- 5 V013MigrationTest (column + 2 indexes + idempotência + down no-op + linhas pré-V013 ficam NULL).
- 6 BrazilianBusinessCalendarTest (subtractBusinessDays: seg-1=sex, qui-1=qua, zero=mesma data, atravessa Carnaval 2026, atravessa Sexta Santa 2026, negative→InvalidArgument).
- 10 DefaultDataCumprimentoHookTest (default seg→qui, ter→sex, atravessa Corpus Christi, atravessa Carnaval, override manual respeitado, edit é no-op, dataFatal vazio é no-op, entity não-Prazo é no-op, dataFatal inválida não bloqueia save, order=15).
- 3 AuditPrazoHookTest (1 update do testNovoPrazoEmiteEventoCreated + 2 novos: dataCumprimento context em created, mudança em dataCumprimento dispara modified).
- 6 PrazoMetadataTest (field declarado + 2 indexes + boolFilter PendentesParaHoje registrado + layout detail + layout filters + NÃO está em list + i18n completo).

**Cross-cutting com Story 4b.2:** `subtractBusinessDays` será reusado pelos alertas D-7/D-3/D-1 para calcular `dataDisparoAlerta = dataFatal − N dias úteis`. Adicionar agora amortiza implementação.

**Sem mudança em** togare-djen, togare-rbac, togare-tpu, togare-licensing, monorepo root.

## Story 4a.5 — Dashboard Advogado / Briefing do Dia (v0.20.1)

**Fix-pass v0.20.0 → v0.20.1 (2026-05-06):** smoke F1 round 1 (Claude CLI) descobriu que a query SQL do dashlet ficava `ORDER BY data_fatal ASC, id ASC, prioridade_weight DESC` — `prioridade_weight DESC` virava terciário inútil porque o `Espo\Core\Select\Order\Applier::applyOrder` injeta automaticamente `[Attribute::ID, $order]` como tiebreaker secundário ANTES dos orders adicionados por boolFilters via `$queryBuilder->order()`. Fix vinculante: criada classe `Espo\Modules\TogareCore\Classes\Select\Order\Prazo\DataFatalPriorizado` implementando `Espo\Core\Select\Order\Orderer` que aplica AMBAS as ordens (`dataFatal $direction` + `prioridadeWeight DESC`); registrada em `selectDefs/Prazo.json::ordererClassNameMap.dataFatal`. Resultado: query final é `ORDER BY data_fatal ASC, prioridade_weight DESC, id ASC` — `id ASC` agora vem POR ÚLTIMO (correto). Simplificação concomitante: removido `MeusPendentesPriorizados` boolFilter (Plano B obsoleto); dashlet metadata volta a usar `meusPendentes` original; CTA href em `briefing-headline-renderer.js` volta para `#Prazo?bool=meusPendentes`. **Comportamento global aceito**: registrar Orderer em `selectDefs` aplica a QUALQUER query que ordene Prazo por `dataFatal` (não só dashlet) — desempate por urgência fica coerente em listas/exports/calendar.

**Lição vinculante (documentada em `feedback_extension_bundled_pattern.md`):** ordenação composta em EspoCRM 9.x é responsabilidade de `Orderer` custom em `selectDefs.ordererClassNameMap`, NÃO de `$queryBuilder->order()` em boolFilter (ineficaz após `id ASC` automático do Order Applier).



Story que materializa o "primeiro reflexo da manhã" do advogado — abrir o
Togare e ver imediatamente quantos prazos pendentes tem hoje, ordenados por
urgência (`dataFatal ASC + prioridadeWeight DESC`). Consome contratos da
4a.3.1 (entity Prazo + 6 boolFilters + 9 status enum) e 4a.4 (StatusBadge
expandido + i18n Prazo + helpers existentes).

**Sem regressão de superfície grande.** 1 dashlet record-list custom + 1
helper renderer puro + 1 service class para layout default + 1 migration
trivial + 1 hook trivial + 1 boolFilter SQL + i18n + CSS.

**Principais entregas:**

- **Dashlet `togare-prazos-do-dia`** (Decisão #1) — view custom em
  `views/dashlets/togare-prazos-do-dia.js` que estende
  `views/dashlets/abstract/record-list` (validado contra `espo-main.js`
  conforme regra v0.19.1: 1 hit). Override de `afterRender()` injeta a
  **headline counter** ("X prazos pendentes — Confira hoje ↗") via helper
  puro `composeHeadlineHtml`. Listener `sync` no collection re-renderiza
  headline em cada fetch (auto-refresh 30min). Wire-up defensivo em
  microtask (`setTimeout(0)`) com retry curto (5×50ms) para colapsar com
  o ciclo async do abstract (`getCollectionFactory().create(scope, callback)`).
- **Metadata `dashlets/Prazos.json`** — registra o dashlet no picker "Add
  Dashlet"; defaults: `entityType=Prazo`, `aclScope=Prazo`,
  `searchData.bool.meusPendentesPriorizados=true`, `orderBy=dataFatal`,
  `order=asc`, `displayRecords=8`, `autorefreshInterval=0.5h`,
  `expandedLayout` 3 rows (status / dataFatal / descricao / processoName /
  prioridade), `inPortalDisabled=true` (CRM-only).
- **Helper puro `briefing-headline-renderer.js`** — função
  `composeHeadlineHtml(count, i18n)` retorna HTML de 3 estados (count=0
  estado calmo sem CTA / count=1 singular + CTA / count>=2 plural com
  substituição `{N}` + CTA). XSS defense via escapeHtml em todos os outputs.
  Fallback hardcoded pt-BR + try/catch defensivo se i18n lançar (graceful
  degradation). CTA href literal `#Prazo?bool=meusPendentesPriorizados`
  (Decisão #6 — hash router padrão Backbone).
- **Coluna `prioridade_weight` + Hook + Migration V012** (Decisão #2 Plano C) —
  ordenação por enum stored produz alfabética errada (alta < baixa < normal
  < urgente). Solução: coluna stored TINYINT mapeada via
  `PrioridadeWeightHook` (BeforeSave order=10): urgente=4, alta=3, normal=2,
  baixa=1. Migration V012: `ALTER TABLE prazo ADD COLUMN prioridade_weight
  TINYINT NOT NULL DEFAULT 2` + `CREATE INDEX idx_prazo_prioridade_weight` +
  backfill destrutivo idempotente via `UPDATE ... CASE WHEN ...` + audit log
  entry `prazo.schema_migrated_v012`. Field oculto da UI nativa
  (`layoutListDisabled`/`layoutDetailDisabled`/etc).
- **boolFilter `MeusPendentesPriorizados`** (Plano B Decisão #2) — class
  isolada em `Classes/Select/Bool/Prazo/MeusPendentesPriorizados.php` que
  implementa `Filter`. Mesma WHERE clause do `MeusPendentes` (4 status
  pendentes + assignedUserId = current user) + adiciona `ORDER BY
  prioridade_weight DESC`. Não muda contrato do `meusPendentes` original
  (zero regressão em outros consumers).
- **Default dashboard layout** (Decisão #4) — `AfterInstall.php` ganha
  método `ensureBriefingDoDiaInDashboardLayout` que via `ConfigWriter`
  popula `Settings.dashboardLayout` com 1 tab "Briefing" contendo 1 item
  `togare-prazos-do-dia` (4×4 grid). Idempotente: scaneia todas as tabs
  antes de adicionar — re-installs / upgrades não duplicam. Lógica
  delegada para `Services\DashboardLayoutSeeder` (2 métodos estáticos
  puros: `hasDashlet` + `appendBriefingTab`) que isola a mutação do array
  do I/O com Container/Config/ConfigWriter.
- **i18n `Dashlets.json` pt-BR** — label do dashlet ("Meus prazos do dia"),
  4 messages para o helper headline (briefingHeadlineZero/One/Many +
  briefingCtaConfiraHoje), 3 labels de campos do options form
  (displayRecords / autorefreshInterval / expandedLayout).
- **CSS `.togare-briefing-headline`** — flex layout + padding + background
  + border-bottom; `--zero` modifier (verdoso), `.togare-briefing-cta`
  (margin-left auto + nowrap); media query mobile (≤768px) stack vertical
  com CTA full-width.

**Decisões D5 e D7 vinculantes:** Dashlet **NÃO** ressuscita o CardDePrazo
helper renderer da 4a.4 (revertido em 0.19.1; ainda em deferred-work até o
spike do pattern correto de customização de row em EspoCRM 9.x). Lista
nativa stock + StatusBadge nas colunas. Empty state count=0 sem CTA +
headline calma; sem banner DJEN offline (escopo da Story 4b.4).

**Tests:** 38 testes vitest novos (briefing-headline-renderer 23 +
togare-prazos-do-dia-dashlet 15) + 31 testes PHPUnit novos
(PrioridadeWeightHook 10 + V012Migration 6 + DashboardLayoutSeeder 15) +
3 testes adicionais em PrazoMetadataTest (testPrioridadeWeightOcultoDaUI,
testIndexPrioridadeWeightExiste, testDashletPrazosMetadataExisteEContemDefaults
+ testClientDefsBoolFilterListIncluiMeusPendentesPriorizados). Suite vitest
total: 261 → 299 verdes.

**Bumps:** togare-core 0.19.10 → **0.20.0**. togare-djen e togare-rbac sem bump.

## Story 4a.4 — StatusSelector + UX polish smoke F1 (v0.19.1)

**Fix-pass v0.19.0 → v0.19.1 (2026-05-05):** removidos `views/prazo/record/list.js` e `views/prazo/record/row.js` que continham `import RowRecordView from "views/record/row"` — esse módulo **não existe como classe ES6** em EspoCRM 9.3 (só existe `views/record/row-actions/*`). O bundler `espo-extension-tools` aceitou em build-time mas, em runtime, EspoCRM tentava carregar a dep declarada e falhava, quebrando o módulo `togare-core` inteiro. Como Cliente/ParteContraria/Processo têm `clientDefs.views.{edit,detail}` apontando para `togare-core:views/...`, suas listas ficavam vazias (Audiencia escapava por não usar view custom). **Lista de Prazos volta ao padrão tabela nativo da Story 4a.3.1**; AC1 (CardDePrazo rowView) reescopado para deferred-work — `card-de-prazo-renderer.js` permanece testado (21 testes vitest verdes) como helper puro, esperando um spike do pattern correto de customização de list view em EspoCRM 9.3 (override de template + `buildRow`, NÃO class extension).



Story que materializa o redesign de UI da entity Prazo expandida na 4a.3.1. Absorve 11 itens UX do feedback Felipe ao smoke F1 da 4a.3 (F1.1, F1.2, F1.3, F1.4, F1.7, F1.9, F1.10, F1.11, F1.12). Sprint Change Proposal 2026-05-04 §4.2.

**Sem backend, sem migration, sem mudança em togare-djen/togare-rbac.** 100% frontend (views + helpers + i18n + layouts + CSS).

**Principais entregas:**

- **CardDePrazo** (rowView custom) — cada linha da lista de Prazos vira um card com StatusBadge cor+ícone+label / chip `tipoPrazo` / chip `prioridade` / CNJ formatado (helper `formatCnj`) / descricao livre / trecho da publicação truncado 200 chars + "Ler mais" inline / data fatal BR + dias restantes + tipo contagem / link Ver no DJEN ↗ + botão Revisar / HedgeBanner inline. **Decisão #1:** rowView custom NÃO substitui `views/record/list` — preserva paginação/busca/filtros/boolFilters/ACL nativos do EspoCRM 9.x. Aplicado via `clientDefs/Prazo.json::views.list`.
- **StatusSelector** (field view custom de `views/fields/enum`) — substitui dropdown nativo do `status` por menu "Mudar status" com **transições válidas para o status atual** (tabela `PRAZO_TRANSITIONS` em `helpers/prazo-transitions.js` — single source of truth). Comportamento por destino: `atrasado_reagendado` abre dialog modal com textarea `motivoReagendamento` ≥10 chars + counter + Confirmar/Cancelar; `protocolado`/`ciencia_renuncia`/`descartado` abrem confirmation dialog leve; demais salvam direto. Após save: `ToastTogare variant=undo` 10s com mensagem específica. Erro backend reverte model + `Espo.Ui.error`. Aplicado via `entityDefs/Prazo.json::status.view`. **Decisão #7:** dialog próprio NÃO duplica `dynamicLogic.required` da 4a.3.1 — paths independentes; backend `ValidatePrazoFieldsHook::validateMotivoReagendamento` é segunda camada.
- **PayloadAccordion** (`views/fields/payload-json` field view) — `publicacaoOrigemRaw` renderiza como `<details>` colapsado por padrão. JSON válido: extrai campos chave (`tribunal`, `siglaTribunal`, `linkOrigem`, `textoPublicacao`) em `<dl>` + JSON pretty-printed em `<pre>` (HTML-escape — defesa XSS). Texto não-JSON: warning + raw escapado. Null: campo omitido. **Decisão #4:** parsing tolerante a falha (JSON cru pode existir em paths legados; nunca quebra renderização).
- **AutoLinkBanner** — variant `auto-link` 5ª no ToastTogare (decisão #3: NÃO componente novo). Trigger client-side no PrazoEditView/PrazoDetailView: comparação `model.previousAttributes()` vs `model.attributes` + Set `_userTouchedFields` (populado em listener `change:clienteId/parteContrariaId` quando `options.ui===true`). Detector pure em `helpers/auto-link-detector.js` cobre 4 cenários: ambos auto-vinculados (variant pair) / só cliente (variant cliente_only) / só parte ou ambos múltiplos / user editou — não dispara banner. Aplicado via `clientDefs/Prazo.json::views.{edit,detail}`.
- **CNJ formatado** em `numeroProcessoOriginal` — aplicado via `entityDefs/Prazo.json::numeroProcessoOriginal.view = togare-core:views/fields/cnj` (field view existente da Story 3.4 — sem código novo). 20 dígitos puros viram `0000000-00.0000.0.00.0000`; ≠20 dígitos passa-through.
- **Label pt-BR para `atoCodigo`** — field view custom `views/prazo/fields/ato-codigo` + dictionary 11 entries em `helpers/atoCodigo-formatter.js` espelhado em `i18n/pt_BR/Prazo.json::options.atoCodigo` E em helper Handlebars global `formatAtoCodigo` em `bootstrap-formatters.js`. **Decisão #6:** double-pattern (i18n cobre list nativo, helper cobre Handlebars templates).
- **Esconder campos técnicos** — `layouts/Prazo/detail.json` reorganizado: painel "Identificação" só tem `status` + `source` + `numeroProcessoOriginal` (CNJ formatado); `parserRegraVersao` removido de "Cálculo"; `publicacaoOrigemRaw` removido de "Auditoria"; **painel novo "Avançado (DJEN)" com `style: "collapsed"`** contém os 3 campos técnicos. **Decisão #5:** ACL field-level forbidden DEFERRED (cobertura layout cobre 95% dos cenários no MVP).
- **StatusBadge expandido** 5→14 estados (5 legados + 9 do Prazo enum, via 8 entries i18n novas + 8 cores CSS). `pendente` é compartilhado entre legacy/Prazo (mesma cor + ícone). `criticalDays` AAA também aplicado a `atrasado_reagendado` (rota crítica). Decisão UX-1 (cor + ícone único + label visível em toda ocorrência).
- **ToastTogare** ganha variant `auto-link` (5ª variant): icon 🔗, role status, defaultActionLabel "Editar", cor neutra cinza-azulado.
- **3 helpers Handlebars globais novos** em `bootstrap-formatters.js`: `formatAtoCodigo`, `daysUntil` (diff calendário simples — Decisão #10: NÃO úteis, frontend não importa BrazilianBusinessCalendar PHP-only), `prioridadeIcon`, `truncate`.
- **3 helpers ES6 novos**: `prazo-transitions.js` (PRAZO_TRANSITIONS map 9 status + STATUSES_REQUIRING_MOTIVO/CONFIRMATION + MOTIVO_REAGENDAMENTO_MIN_LEN espelha PHP), `atoCodigo-formatter.js` (dictionary 11 entries + formatAtoCodigo função pura), `auto-link-detector.js` (lógica AC13 isolada do framework).
- **i18n bumps**: `Prazo.json` ganha `options.atoCodigo` 11 entries + 8 labels novas (panelAvancadoDjen, statusSelectorTrigger, lerMais, etc) + 11 messages novas (status_selector_motivo_*, confirm_status_*, toast_auto_link_*, toast_undo_*); `StatusBadge.json` ganha 8 estados Prazo; `ToastTogare.json` ganha variant `auto-link`.

**163 testes vitest novos** (suite total 78 → 241 verdes): prazo-transitions 17 + atoCodigo-formatter 15 + bootstrap-formatters 26 + payload-json 15 + ato-codigo 3 + status-selector 24 + toast-togare +1 (auto-link) + auto-link-detector 15 + prazo-edit-view 6 + status-badge 20 + card-de-prazo-renderer 21.

**10 decisões D1-D10 vinculantes** (ver story `4a-4-card-de-prazo-confirmar-1-clique-toast-undo.md` Dev Notes §7).

**Pendência Felipe (smoke F1 ~30 min, 14 sub-passos)**: build togare-core-0.19.0.zip + install + rebuild + Ctrl+F5; validar AC1-AC17 via browser (lista renderiza CardDePrazo / StatusSelector menu por status atual / dialog motivoReagendamento / confirmation / undo / PayloadAccordion / CNJ formatado / atoCodigo pt-BR / painel Avançado colapsado / AutoLinkBanner em criação manual / chips descricao+prioridade+tipoPrazo / não-regressão boolFilters/search/edit/dynamicLogic).

## Story 4a.3.1 — redesign de Prazo (status enum 6→9 + 5 campos novos + AutoLinkClientHook + Migrations V009/V010, v0.18.0)

Story de fundação que destrava 4a.4/4a.5/4b.2 reescopadas pelo sprint change proposal 2026-05-04. Materializa as decisões finais do Felipe sobre o vocabulário jurídico real do escritório.

**Mudanças principais:**

- **Status enum 6→9 valores** (8 visíveis + `descartado` técnico oculto — D7 (b)): `rascunho`, `pendente`, `atrasado_reagendado`, `aguardando_cliente`, `aguardando_correcao`, `protocolado`, `ciencia_renuncia`, `acompanhamento`, `descartado` (oculto via `clientDefs.dynamicLogic.options.status`).
- **5 campos novos**: `descricao` (text), `prioridade` (enum 4 — baixa/normal/alta/urgente, default normal), `tipoPrazo` (enum 17 — Apêndice A do PRD v1.3, **nullable** Decisão #3), `motivoReagendamento` (varchar 500, **obrigatório ≥10 chars** quando `status=atrasado_reagendado` — Decisão #2), `cliente` + `parteContraria` (links belongsTo).
- **AutoLinkClientHook** (BeforeSave order=20, em togare-core): quando `processoId` é setado, lê `processo.clientes` / `processo.partesContrarias`. Comportamento N:N defensivo: 1→set, 0 ou 2+→deixa NULL + log info. NÃO infere preferência. Idempotente: NÃO sobrepõe `clienteId` já SETADO.
- **3 boolFilter classes novas**: `AguardandoCliente`, `ProtocoladosUltimos30d` (cutoff PHP `-30 days`), `Acompanhamento`. Os 3 antigos atualizados (`MeusPendentes` agora cobre 4 status que precisam ação; `MeusRascunhos`/`NaoVinculadas` usam `STATUS_RASCUNHO`).
- **Migration V009** destrutiva: `rascunho_nao_vinculado→rascunho` / `confirmado→pendente` (D2) / `cumprido→protocolado` / `revertido→pendente`. Backup obrigatório via audit `prazo.schema_migrated_v009` com counts antes/depois.
- **Migration V010** idempotente: 6 ALTER TABLE ADD COLUMN + 4 indexes auxiliares (try/catch DUPLICATE COLUMN/KEY).
- **AuditPrazoHook.derivedEventMap** 4→7 eventos (alinhado a ADR-03 v1.1 §1): `pendente→bound` (substitui confirmed), `descartado` preservado, `protocolado` (substitui cumprido), `reagendado` (com motivoReagendamento no context — FR37), `aguardando_cliente`, `acompanhamento` + transição `protocolado→pendente` emite `audit.prazo.revertido` puro. SENSITIVE_FIELDS +3.
- **Constants `STATUS_*` legadas REMOVIDAS** (sem alias BC — Decisão #7). Único callsite externo (PrazoCreatorService da togare-djen) atualizado em 1 linha (bump 0.4.0 não-breaking).
- **Reverse links em Cliente.json + ParteContraria.json**: `prazos hasMany Prazo` — painéis relacionais aparecem no detail dessas entidades.

## Entidade Prazo (Story 4a.3, FR12+FR13+FR14, v0.17.0)

Materializa o pipeline `publicação DJEN → Prazo persistido` — fecha o
"aha moment" da jornada Ricardo do PRD. Predecessoras imediatas:

- **Story 4a.1** (togare-djen 0.1.0) — adapter Comunica + worker dedicado.
- **Story 4a.2** (togare-djen 0.2.0) — DjenParserService puro + DTO `PrazoCalculado` com `dataFatal` calculada.

Esta story entrega a **entity `Prazo` cross-cutting em togare-core** que:

- É consumida pelo `PrazoCreatorService` (togare-djen 0.3.0) que materializa o output do parser em DB.
- Será exibida pelo `CardDePrazo` UI (Story 4a.4) e contada pelo `BriefingDoDia` (Story 4a.5).
- Será alvo dos alertas D-7/D-3/D-1 (Story 4b.2).
- Será exposta no Portal do Cliente (Epic 7a).

### Schema (22 fields)

| Categoria | Fields |
|---|---|
| Cálculo (art. 5º) | `dataDisponibilizacao`, `dataInicioPrazo`, `dataFatal`, `prazoDias`, `contagem` (uteis/corridos) |
| Classificação | `atoCodigo`, `referenciaLegal` (CPC art. X), `confidence` (high/medium/low), `parserRegraVersao`, `fonteExcerpt` |
| Origem | `source` (djen/manual/manual_ambiguo), `sourcePubId`, `numeroProcessoOriginal`, `publicacaoOrigemRaw` (JSON) |
| Workflow | `status` (6 valores enum), `processo` (belongsTo opcional), `assignedUser` (belongsTo opcional) |
| Auditoria | `tenantId`, `createdAt/By`, `modifiedAt/By` |

### Status enum (6 valores declarados, 2 emitidos nesta story)

| Status | Quando | Story |
|---|---|---|
| `pendente` | Match CNJ → Prazo vinculado ao Processo | 4a.3 (esta) |
| `rascunho_nao_vinculado` | Sem match → Prazo em rascunho com payload preservado | 4a.3 (esta) |
| `confirmado` | Advogado confirma 1-clique no CardDePrazo | 4a.4 (futuro) |
| `descartado` | Advogado descarta no CardDePrazo ou ComparadorCandidatos | 4a.4 / 4b.1 |
| `cumprido` | Pós-vencimento, advogado marca como cumprido | Epic 10 |
| `revertido` | Pattern correção retroativa UX Step 12 | UX Step 12 |

### Hooks (ordem)

- `Hooks/Prazo/ValidatePrazoFieldsHook` (BeforeSave order=10) — enums + datas + integridade status×campos.
- `Hooks/Prazo/AuditPrazoHook` (AfterSave order=50) — `audit.prazo.created`/`modified` + 4 eventos derivados de status (`confirmed`/`descartado`/`cumprido`/`revertido`).

### Migrations

- **V007**: indexes auxiliares (`data_fatal`, `status_data_fatal`, `processo_id`, `assigned_user_id`, `numero_processo_original`).
- **V008**: `UNIQUE INDEX prazo_source_pub_id_unique` — idempotência do creator (3 níveis: app-level findOne + DB UNIQUE + race-handler PDOException 23000).

### Listagem boolFilter classes

Em `Classes/Select/Bool/Prazo/`:

- **NaoVinculadas** — `status='rascunho_nao_vinculado'` (visão Sócio/Admin: triagem global).
- **MeusPendentes** — `status='pendente' AND assignedUserId=self` (visão Advogado).
- **MeusRascunhos** — `status='rascunho_nao_vinculado' AND assignedUserId=self` (visão Advogado).

### Reverse link em Processo

`entityDefs/Processo.json` ganha link `prazos hasMany Prazo, foreign=processo` —
painel relacional "Prazos" aparece no detail de cada Processo.

### ACL

- `aclDefs/Prazo.json`: `assignedUser=true` (sem collaborators — pattern Audiencia 3.6-magro).
- `scopes/Prazo.json`: `entity, tab, acl, stream, calendar=true` (Calendar nativo EspoCRM exibe Prazos via `dataFatal`).

### RBAC (togare-rbac 0.8.0)

| Role | Política |
|---|---|
| Sócio/Admin | all |
| Advogado | `{read:own, edit:own, create:team, delete:no}` |
| Assistente | `{read:team, edit:team, create:team, delete:no}` |
| Secretária | `{read:team, edit:no, create:no, delete:no}` |
| Financeiro / Marketing / RH-lite / Cliente-portal | no |

Migration **V004 togare-rbac** patcheia as 8 roles idempotentemente
(espelha pattern V003 da Story 3.5).

Ver `espocrm/togare-djen/docs/ADR-03-pipeline-djen-prazo.md` para decisões
arquiteturais completas do pipeline DJEN → Prazo.

---

## Story 4b.1b (v0.24.0) — PublicacaoAmbigua permanece fundacional

Filha 2/3 do split 4b.1. O `togare-core` segue dono da entity
`PublicacaoAmbigua`, metadata, layouts, i18n e Controller Record stock. A ponte
REST de resolve/ignore/bulk-ignore vive no `togare-djen` 0.6.0, no Controller
`TogareDjenPublicacaoAmbigua`, para preservar a direção de dependência:
`togare-djen` depende de `togare-core`, nunca o contrário.

### Endpoints DJEN

```
POST /api/v1/TogareDjenPublicacaoAmbigua/action/resolve
  body: {"publicacaoAmbiguaId": "<pubId>", "chosenProcessoId": "<24-char>"}

POST /api/v1/TogareDjenPublicacaoAmbigua/action/ignore
  body: {"publicacaoAmbiguaId": "<pubId>"}

POST /api/v1/TogareDjenPublicacaoAmbigua/action/bulkIgnoreProcesso
  body: {"processoId": "<24-char>"}
```

O Controller DJEN aplica ACL record-level (`edit`) antes de resolver/ignorar e
filtra cada row no bulk-ignore. Dependência declarada no DJEN: **togare-core >=
0.24.0**.

---

## Fix-pass V015 (v0.24.1) — UNIQUE em prazo.source_pub_id (bug B21 da 4b.1b)

**Bug B21 descoberto no smoke F1 da Story 4b.1b**: a Migration V008 da Story
4a.3 deveria ter criado `UNIQUE INDEX prazo_source_pub_id_unique` em
`prazo.source_pub_id` (idempotência cross-table + race protection do worker
DJEN concorrente). Em campo, o índice existente é `IDX_SOURCE_PUB_ID`
NÃO-UNIQUE — versão antiga do entityDefs criou o índice como non-unique
antes da V008 tentar adicionar UNIQUE; a V008 com try/catch DUPLICATE
silenciou o erro e nunca aplicou UNIQUE.

**Por que este fix-pass**: sem UNIQUE, a
`AmbiguityResolverService::resolve` da Story 4b.1b perdia a defesa de race
condition em produção (PHPUnit cobria a lógica via
`isDuplicateKeyThrowable`, mas o banco real não bloqueava 2 INSERTs
concorrentes com mesmo source_pub_id).

### Estratégia de dedup

- O bloqueio histórico para criar UNIQUE são source_pub_id duplicados em
  `prazo` no banco do dev (cada um com 1 row deleted=0 + 1 row deleted=1,
  lixo de re-execução do sync DJEN no smoke da 4a.3).
- **Decisão #1**: hard-delete TODOS os prazos soft-deleted (`deleted=1`)
  com `source_pub_id NOT NULL`. Rows soft-deleted são lixeira EspoCRM sem
  uso operacional.
- **Decisão #2**: se ainda restarem duplicatas ATIVAS (deleted=0) após o
  hard-delete dos soft, a Migration ABORTA com mensagem explícita.

### Ordem de operações

1. Audit pré-fix: contar quantos rows serão hard-deletados.
2. Hard-delete soft-deleted com source_pub_id NOT NULL.
3. Verificar duplicatas ativas restantes — abortar se houver.
4. DROP INDEX IDX_SOURCE_PUB_ID + ADD UNIQUE prazo_source_pub_id_unique
   (sintaxe driver-aware MariaDB/SQLite).
5. Audit log entry `prazo.schema_migrated_v015`.

6 testes PHPUnit `V015MigrationTest` cobrem dedup + abort + UNIQUE em vigor + idempotência + down no-op. Sem mudança em entityDefs/Hook/Controller/UX.

## Story 4b.1c (v0.25.0) — UX flow F3 frontend (jornada Beatriz)

Filha 3/3 do split 4b.1. Frontend-only sobre a fundação backend (4b.1a +
4b.1b). UX C9 QueueNavegavel + UX C10 ComparadorCandidatos materializados
em 4 views JS custom + CSS + clientDefs patch + i18n + 24 testes vitest
novos. Sem PHP, sem migration, sem RBAC, sem entityDefs change.

### Views novas

- `togare-core:views/fields/link-autocomplete` — subclasse de
  `views/fields/link` com `selectAction = null` + `createDisabled = true`
  + `getAutocompleteMaxCount() = 10`. Materializa a regra A6
  (autocomplete inline) como side-product reutilizável **sem call site
  nesta story** (aplicação transversal a Cliente / ParteContraria /
  Processo / Audiencia / Prazo edit forms = Story 4b.1-followup ou Epic
  10 housekeeping).
- `togare-core:views/publicacao-ambigua/record/list` — extends
  `views/record/list`. Mass-action `bulkIgnoreProcesso` derivando o
  processoId-alvo via interseção de `candidatos[]` snapshotted nas rows
  selecionadas (3 cenários: 1 processoId comum → confirmação direta;
  2+ → escolha radio; 0 → toast warning).
- `togare-core:views/publicacao-ambigua/record/detail` — extends
  `views/record/detail`. afterRender injeta header **QueueNavegavel**
  (contador `Item N de M` + ←/→ + dropdown bulk ≡) via DOM insert
  antes do `.middle` (pattern B2 da Story 4a.4) e substitui middle
  panel por sub-view ComparadorCandidatos. Keyboard shortcuts globais
  ←/→/b registrados em `document` com filtro de inputs / textarea /
  contentEditable. `aria-live="polite"` no contador. Queue cached em
  `_queueIds` por mount via 1 `Espo.Ajax.getRequest('PublicacaoAmbigua',
  { boolFilterList: ['precisaSuaLeitura'], maxSize: 100 })`.
- `togare-core:views/publicacao-ambigua/comparador-candidatos` — UX C10
  standalone. Stack vertical, 5 cores distintivas (azul / laranja /
  verde / roxo / vermelho), heading `<h3>` semântico colorblind-safe,
  trecho com nomes em `<mark>` amarelo XSS-safe (`Espo.Utils.escapeHtml`
  ANTES do `<mark>`; regex sobre string já escapada), 1-clique CTAs
  "Confirmar prazo neste processo" + "Ignorar todos com mesmo Candidato
  X" + "Nenhum dos N — ignorar publicação" + HedgeBanner inline.
  Detect read-only (Assistente) via `getAcl().check(model, 'edit')` →
  3 botões `disabled` + `aria-disabled` + tooltip.

### Endpoints REST consumidos (controller mora em togare-djen)

- `POST TogareDjenPublicacaoAmbigua/action/resolve` body
  `{ publicacaoAmbiguaId, chosenProcessoId }` → 200 `{ prazoId }`
- `POST TogareDjenPublicacaoAmbigua/action/ignore` body
  `{ publicacaoAmbiguaId }` → 200 `{ success: true }`
- `POST TogareDjenPublicacaoAmbigua/action/bulkIgnoreProcesso` body
  `{ processoId }` → 200 `{ count }`

UI mapeia `xhr.status` para variants do ToastTogareView:

| status | toast variant | ação |
|---|---|---|
| 200 | `success` (4s) + redirect próximo item da queue | OK |
| 409 | `warning` (6s) — `messages.alreadyResolved` | redirect 2s |
| 400 | `error` — `messages.invalidCandidate[Empty]` | reabilita CTAs |
| 403 | `error` — `messages.forbidden` | reabilita CTAs |
| 5xx | `error` — `messages.serverError` | reabilita CTAs |

### CSS (em `components.css`, `@layer togare-components`)

- 5 bandas coloridas tokens reusados de StatusBadge (contraste AA com
  texto branco): `#1f6feb` / `#d97706` / `#16a34a` / `#7c3aed` /
  `#dc2626`.
- `<mark>` amarelo `#fef3c7`.
- Dropdown bulk-action com CSS próprio (Bootstrap não estiliza
  `<ul role="menu">` standalone — defesa B5 da Story 4a.4).
- `prefers-reduced-motion: reduce` desliga animações de transição.
- Mobile (<768px): header colapsa, bulk vira full-width.

### Defesas explícitas (B0–B17 + Df do 4b.0)

- B0: `tools/validate-bundle-imports.mjs` zero violações — imports só
  do whitelist (`view`, `views/record/list`, `views/record/detail`,
  `views/fields/link`).
- B6: TODOS os dialogs via `new window.Espo.Ui.Dialog` (modal Bootstrap
  real com backdrop + focus-trap + ESC handler).
- B7: `ToastTogareView` importada via ES6 direto. Nenhum
  `window.TogareCore.X`.
- B11: enum labels (`area` / `fase`) via
  `getLanguage().translateOption(value, fieldName, scope)`.
- B14b: minifier-safe — `Object.prototype.hasOwnProperty.call(opts,
  'key')` em vez de `=== undefined`.
- B17: `ToastTogareView.show()` é static DOM-puro — usar a forma
  estática.
- Df12: helper compartilhado `togare-core:helpers/translate-or-fallback`
  (i18n com fallback hardcoded pt-BR — graceful degradation).

### clientDefs/PublicacaoAmbigua.json patch

```json
{
    "controller": "controllers/record",
    "views": {
        "list": "togare-core:views/publicacao-ambigua/record/list",
        "detail": "togare-core:views/publicacao-ambigua/record/detail"
    },
    "boolFilterList": ["onlyMy", "precisaSuaLeitura"],
    "defaultFilterData": {
        "boolFilterList": ["precisaSuaLeitura"]
    },
    "massActionList": ["__APPEND__", "bulkIgnoreProcesso"],
    "kanbanViewMode": false,
    "accessDataList": [{ "inPortalDisabled": true }]
}
```

`defaultFilterData.boolFilterList` faz com que `precisaSuaLeitura` apareça
selecionado por default ao abrir `#PublicacaoAmbigua` (UX flow F3 —
advogado vê só sua fila).

Sem mudança em togare-djen, togare-rbac, togare-tpu ou togare-licensing.

**Próxima após 4b.1c done:** spec-mãe `4b.1` marcada `done` implícito
(3/3 filhas done). Story `4b.2` (Alertas D-7/D-3/D-1) só após ADR-0009
escrito (regra A5 da retro Epic 4a — alinhamento retry × Circuit
Breaker).

## Story 4b.2 (v0.26.0) — Subsistema Notifications & Reminders (alertas D-7/D-3/D-1 + status dirigidos via PrazoReminderJob)

Materializa o subsistema togare-core/Notifications & Reminders previsto em
ADR-04 (PRD v1.3.1 FR15 + NFR37). Alertas escalonados de prazo via canal
duplo (pop-up in-app + e-mail) com SLA medido (≤10min p95) + configuração
por usuário em `Preferences → Meus lembretes`.

> **Nota OQ#1:** Esta story foi entregue antes do ADR-0009 (regra A5 da
> retro Epic 4a). Confirmação retroativa: ADR-0009 trata `togare_queue_items`
> da fila DJEN — pré-requisito da Story 4b.4 (banner DJEN), não desta.
> 4b.2 opera em `togare_prazo_lembrete` próprio.

### Componentes entregues

- **Migration V016** — cria `togare_prazo_lembrete` (tabela auxiliar, sem
  entity wrap; pattern `togare_audit_log` + `togare_ambiguity_log`).
  UNIQUE INDEX `(prazo_id, user_id, marco)` é defesa de idempotência;
  índices auxiliares em `(status, scheduled_for)` (query do Job) +
  `(user_id)` (listagem futura).

- **`Hooks/Prazo/EnqueuePrazoLembretesHook`** AfterSave order=40.
  5 cenários de enqueue + 3 de cancel (DELETE — Decisão D1.1):
  - Criação `pendente` ou transição para `pendente` → enfileira D-7/D-3/D-1.
  - Transição para `atrasado_reagendado` / `aguardando_cliente` /
    `aguardando_correcao` → 1 marco imediato (`status_dirigido`).
  - Transição para status final (`protocolado`/`descartado`/
    `ciencia_renuncia`/`acompanhamento`) → cancel pending.
  - `dataFatal` ou `assignedUserId` mudou → cancel + re-enqueue.
  - Defensivo `\Throwable`: nunca bloqueia save do Prazo.

- **`Jobs/PrazoReminderJob`** scheduled cron `*/5 * * * *` (registrado em
  `Resources/metadata/app/scheduledJobs.json`). Varre batch 100 entries
  pending vencidas (FIFO `scheduled_for ASC, id ASC`); resolve preferences
  do user; despacha canal duplo paralelo; atualiza status conforme outcome
  (sent / pending+attempt++ / failed após 3 retries SMTP). Decisão D2.1:
  popup OK + email FAIL marca status=sent (SLA cumprido pelo popup;
  email_partial_failure registrado em audit log para diagnóstico).

- **`Services/Notification/StreamNotificationService`** (canal pop-up) —
  cria `Notification` entity nativa do EspoCRM (`type=Message`); badge
  refresh-poll do front-end stock cumpre SLA <1min.

- **`Services/Notification/EmailNotificationService`** (canal e-mail) —
  usa `Espo\Core\Mail\EmailSender` nativo; renderiza
  `Resources/templates/prazo-reminder.tpl.html` + `.tpl.txt` (XSS-safe via
  `htmlspecialchars` em todas as variáveis de usuário). Hedge jurídico
  FR39 LITERAL no rodapé (auditável para tribunal).

- **`Services/Notification/PrazoLembreteConstants`** — value object com
  marcos, canais, status, defaults, hedge jurídico, retry backoff
  `[1, 5, 30]` minutos, e helpers `resolveCanal()` /
  `mergeWithDefaults()` / `labelsForMarco()`.

- **`Resources/templates/prazo-reminder.tpl.{html,txt}`** — pt-BR,
  responsivo, max-width 600px, inline CSS único.

- **`LembreteConfigPanel`** view custom em `Preferences → Meus lembretes`
  (`client/.../views/preferences/lembrete-config.js`) — 6 checkboxes
  (2 canais + 4 marcos) com WCAG AA: `<label for=id>` em cada input,
  `aria-describedby` apontando para hint, `<fieldset role=group>` +
  `<legend>` para grupos, contraste herdado do tema EspoCRM.
  `entityDefs/Preferences.json` patch adiciona `togareLembreteConfig`
  (jsonObject) e `layouts/Preferences/detail.json` adiciona tab "Meus
  lembretes (Togare)" (cópia stock + nova aba — Decisão D5.1).

- **`Resources/i18n/pt_BR/PrazoReminder.json`** (novo) — labels de
  subjects/bodies por marco + hedge + footer.
- **`Resources/i18n/pt_BR/Preferences.json`** (novo) — chaves
  `lembreteConfig.*` (6 labels + 2 títulos + hint + tabLabel).

### Decisões de implementação documentadas

- **D1.1** — `cancelPendingForPrazo` faz HARD DELETE (não UPDATE
  status='cancelled'). Motivo: UNIQUE INDEX `(prazo_id, user_id, marco)`
  bloquearia re-INSERT subsequente; tabela é fila de trabalho, history
  vai pra `togare_audit_log` (`audit.notification.cancelled` com
  snapshot completo).
- **D2.1** — Pop-up OK + email FAIL marca status=sent (SLA cumprido).
  Email retry isolado é Growth; gravamos
  `audit.notification.email_partial_failure` para diagnóstico SRE.
- **D5.1** — `layouts/Preferences/detail.json` é cópia do stock + nova
  tab "Meus lembretes". Frágil em update do EspoCRM core; mitigação:
  smoke F1 do Felipe valida; documentado em pendência operacional.
- **OQ#3 resolvida** — `audit.notification.scheduled` ligado por default
  (auditabilidade > volume; volume estimado 4000 rows/dia × 24m = 2.9M).

### Não toca

- togare-djen / togare-tpu / togare-rbac / togare-licensing — sem mudança.
- Entity Prazo — schema intacto; só consumimos `dataFatal`,
  `assignedUserId`, `status`, `numeroProcessoOriginal`, `descricao`,
  `dataCumprimento`.
- Hooks existentes (Validate/Default/AutoLink/Prioridade/Audit) —
  intactos; somente adicionamos novo Hook irmão `EnqueuePrazoLembretes`.
- `BrazilianBusinessCalendar` — reusa `subtractBusinessDays` da Story
  4a.5.1 sem modificação.

### Cobertura de testes

- **PHPUnit togare-core**: +45 novos (V016 7 + EnqueuePrazoLembretesHook
  14 + StreamNotificationService 5 + EmailNotificationService 8 +
  PrazoReminderJob 11). Total 465 verdes (2 falhas pré-existentes
  TenantAwareEntityTest desde 2.4 — não-regressão).
- **vitest togare-core**: +11 novos (LembreteConfigView). Total
  364/364 verdes.
- **validate-bundle-imports.mjs**: zero violações.
- **JSON lint**: OK em todos os arquivos novos/modificados.

## Story 4b.3 (v0.27.0) — Redundância semântica D-0 (UX-DR10)

PATCH incremental do subsistema Notifications & Reminders da 4b.2 — adiciona o
4º marco de proximidade (`D-0`, vence hoje) com **redundância semântica em 4
canais simultâneos** (UX-DR10): badge vermelho sólido + ícone sino + texto
"VENCE HOJE" + toast estático persistente in-page + email backup às 00:05 BRT
do dia. **Não depende de pulsação animada** — pulsação é reforço opcional
respeitando `prefers-reduced-motion` (AR-7).

### Componentes entregues

#### Backend (incremental ao subsistema 4b.2)

- **`PrazoLembreteConstants`** ganha:
  - `MARCO_D0 = 'D-0'` + entry em `DEADLINE_OFFSETS` com offset=0.
  - `HORA_DISPARO_BY_MARCO` map (D-0=0, demais=9) + `MINUTO_DISPARO_BY_MARCO`
    map (D-0=5, demais=0). Const escalar legacy `HORA_DISPARO=9` mantida
    para retro-compat.
  - `defaultConfig().marcos['D-0'] = true` (fail-safe — alerta crítico).
  - `mergeWithDefaults` ganha branch `D-0`.
  - `labelsForMarco('D-0', $cnj)` retorna `subject="[Togare] VENCE HOJE — {cnj}"`
    e `title="VENCE HOJE"` (sem prefixo "Prazo " — urgência > formalidade).
- **`EnqueuePrazoLembretesHook.enqueueDeadlineMarcos`** lê `HORA_DISPARO_BY_MARCO`
  + `MINUTO_DISPARO_BY_MARCO` em vez do escalar `HORA_DISPARO`. Laço já
  tolerante a 4 marcos: Prazo pendente cria 8 entries (4 marcos × 2
  destinatários: `assignedUser` + Sócio/Admin).
- **2 templates email novos** dedicados a D-0:
  - `Resources/templates/prazo-reminder-d0.tpl.html` — header destacado
    `background:#c62828` + overline `🔔 Togare — VENCE HOJE` + `<h1>VENCE
    HOJE — {cnj}</h1>` 24px font-weight:700 + CTA button vermelho + linha
    extra "Confirme ou adie este prazo hoje." + hedge jurídico FR39 LITERAL
    no rodapé.
  - `Resources/templates/prazo-reminder-d0.tpl.txt` — fallback texto
    `🔔 TOGARE — VENCE HOJE` no topo.
- **`EmailNotificationService`** ganha 2 const novas + branch em
  `renderHtml`/`renderText`: se `$vars['marcoLabel'] === MARCO_D0`, carrega
  template D-0; senão, default. Decisão de design **D1.1** durante dev:
  engine de templates real do EspoCRM 9.x é `strtr` com placeholders
  `{key}` (não Mustache); 2 templates físicos é mais limpo que sections
  inline.

#### Frontend

- **`d-zero-detector.js`** novo (helper puro):
  ```js
  import { isVenceHoje, toBrtYmd } from "togare-core:helpers/d-zero-detector";
  isVenceHoje(prazo.dataFatal);  // true se dataFatal=hoje BRT.
  ```
  Comparação YYYY-MM-DD em fuso `America/Sao_Paulo` via
  `Intl.DateTimeFormat('en-CA', {timeZone:'America/Sao_Paulo'})`. Cobre
  cenários de transição UTC ↔ BRT (ex.: `2026-01-01T01:00:00Z` é
  `2025-12-31` em BRT). Fallback offset fixo UTC-3 se `Intl` não suporta
  timeZone (BRT removeu DST em 2019).
- **`StatusBadge`** ganha state novo `vence_hoje`:
  - Adicionado a `VALID_STATES` (15 estados total: 5 legacy + 9 Prazo + 1
    novo).
  - i18n `Resources/i18n/pt_BR/StatusBadge.json::states.vence_hoje`:
    `label="VENCE HOJE"`, `icon="🔔"`, `ariaLabel="VENCE HOJE — confirme
    ou adie"` (literal — rota crítica AAA exige texto explícito).
- **`card-de-prazo-renderer`** detecta D-0 + status ∈ família "ainda em
  jogo" (Decisão #4 — D-0 é **camada visual cumulativa**, NÃO valor de
  status; preserva enum 9 imutável da Decisão UX-1):
  - Adiciona modifier `togare-card-de-prazo--d-zero` no wrapper.
  - Insere chip combinado `[🔔 VENCE HOJE]` ANTES do StatusBadge real
    (e.g. ao lado de `[🟡 Pendente]`) — colorblind-safe + WCAG AAA.
  - `aria-label="VENCE HOJE — confirme ou adie"` literal no chip.
- **`togare-prazos-do-dia.js`** dashlet ganha `_renderD0Toast()`:
  - Conta prazos D-0 via `_countD0PrazosInCollection()` (filtrando
    `STATUS_PENDENTE_FAMILIA_JS`).
  - Dispara **um único** toast persistente:
    `ToastTogare.show({ variant: 'warning', message: 'VENCE HOJE: {N} prazo(s)
    — confirme ou adie', duration: null, actionLabel: null })`.
  - **Idempotente** entre auto-refreshes (autorefresh 30min) via
    `_d0ToastLastCount` — só recria se contagem mudou.
  - **Gestão de fadiga**: `_d0ToastDismissedManually` flag (set quando
    user fecha manualmente via ESC ou X) — toast NÃO re-aparece nesta
    sessão de view; some no reload da página. Sem persistência server-side
    (alerta diário em escopo de sessão).

#### CSS (em `components.css`)

- `.togare-status-badge--vence-hoje` (e variante snake `--vence_hoje`):
  `background:#c62828; color:#fff; font-weight:700`.
- `.togare-status-badge--vence-hoje.togare-status-badge--pulse` (OPCIONAL,
  OFF default): pulsação `togareDZeroPulse` 1.6s — desabilitada por
  `@media (prefers-reduced-motion: reduce)` (defesa em profundidade).
- `.togare-card-de-prazo--d-zero`: `border-left: 4px solid #c62828`.
- `.togare-card-de-prazo__d-zero-badge`: chip vermelho redondo com 0.5rem
  margin-right antes do StatusBadge real.

### Decisões arquiteturais

- **#1 D-0 entra como 4º marco em DEADLINE_OFFSETS** offset=0. Pipeline 4b.2
  cego ao marco — laço já tolerante.
- **#2 Hora 00:05 BRT (não 09:00)** via 2 maps novos. Alinha NFR5 ("alerta
  D-1, D-0 ≤5min após entrar na janela crítica") + AC original epic linha
  1144 ("email de backup já foi enviado às 00:05 do dia").
- **#3 Detecção frontend** via comparação `dataFatal_BRT === today_BRT`
  string YYYY-MM-DD.
- **#4 D-0 é camada visual cumulativa** — NÃO valor de status (preserva
  enum 9 imutável da Decisão UX-1; evita explosão combinatória em
  StatusSelector dropdown).
- **#5 Toast persistente disparado pelo dashlet `BriefingDoDia`** (não
  hook global). Aproveita o momento de máxima atenção do user (primeira
  tela do advogado).
- **#6 Redundância visual 4 sinais simultâneos sem dependência de
  pulsação**. Pulsação opcional (CSS class `--pulse` não acionada por
  default). AC10: `prefers-reduced-motion: reduce` desabilita SÓ animação;
  badge cor + ícone + texto + borda + toast PERMANECEM.
- **#7 Email com header destacado vermelho via 2 templates físicos
  separados** (D1.1 — engine real EspoCRM 9.x é `strtr` placeholders,
  não Mustache). Mais limpo + testável.
- **#8 LembreteConfigPanel posição 4** (D-7→D-3→D-1→D-0→status_dirigido).
  D-0 default true (fail-safe alerta crítico).

### Não toca

- Migration V016 (schema já tolerante `marco VARCHAR(32)` cobre `'D-0'`).
- `PrazoReminderJob.php` (job é cego ao marco — pipeline 4b.2 reusada).
- `StreamNotificationService` (canal pop-up genérico — D-0 idêntico).
- Hooks `Prazo/{Validate,Default,AutoLink,Prioridade,Audit}*.php` (intactos).
- `entityDefs/Prazo.json` / `clientDefs/Prazo.json` / `selectDefs/Prazo.json`
  (Decisão #4 — D-0 não é status).
- `BrazilianBusinessCalendar.php` (offset=0 já cobre por contrato).
- `Binding.php` (sem novo serviço bindado).
- togare-djen / togare-tpu / togare-licensing / togare-rbac (sem mudança).

### Cobertura de testes

- **PHPUnit togare-core**: +13 novos (PrazoLembreteConstantsTest 14 ao
  todo — sem teste prévio dedicado; EnqueuePrazoLembretesHookTest +4
  novos D-0 + 8 atualizados de 6→8 entries; EmailNotificationServiceTest
  +3 novos D-0). Total **498 verdes** (2 falhas pré-existentes
  `TenantAwareEntityTest` desde 2.4 — não-regressão).
- **vitest togare-core**: +31 novos (d-zero-detector 11 + status-badge
  2 + lembrete-config-view 4 + card-de-prazo-renderer 6 + togare-prazos-
  do-dia-dashlet 8). Total **395/395 verdes**.
- **validate-bundle-imports.mjs**: zero violações (helper novo
  `togare-core:helpers/d-zero-detector` usa namespace `togare-X:` —
  whitelist automática).
- **JSON lint**: OK em todos os JSONs i18n / templates touched.

## Story 4b.3 fix-pass v0.27.1 — UX-DR10 redundância visual D-0 no dashlet (B26)

**Bug B26 descoberto no smoke F1 browser do Felipe**: a redundância
visual D-0 (modifier `togare-card-de-prazo--d-zero` + chip
`[🔔 VENCE HOJE]` + borda esquerda vermelha) **não aparecia** em runtime
real no dashboard "Meus prazos do dia", na list-view `#Prazo` nem no
record-view `#Prazo/view/<id>`. Inspeção DOM confirmou: dashlet usa
template stock do EspoCRM (`abstract/record-list` → `<div class="list-row"
data-id="...">`) — `card-de-prazo-renderer.js` é helper puro testado em
vitest mas **não consumido** em produção desde a Story 4a.4 fix-pass
0.19.1 (que removeu `PrazoListView/PrazoRowView` por bug do
`views/record/row` ES6 module fantasma).

### Solução adotada (não-invasiva)

`TogarePrazosDoDiaDashletView` ganha 3 métodos novos:

- **`_scheduleDecorateD0Cards(retry=0)`**: agenda decoração em microtask
  `setTimeout(0)` + retry curto (até 5×50ms) para tolerar o ciclo async
  do abstract record-list (collection populada antes das `.list-row`
  aparecerem no DOM).
- **`_decorateD0Cards()`**: percorre `root.querySelectorAll('.list-row[data-id]')`,
  cruza com `this.collection.models` por `id`, e:
  - Se `isVenceHoje(model.get('dataFatal'))` E `status ∈ STATUS_PENDENTE_FAMILIA_JS`
    → aplica modifier `togare-row--d-zero` + injeta chip via
    `_decorateD0Row(row)`.
  - Senão → desfaz decoração legada via `_undecorateD0Row(row)` (status
    pode ter mudado em re-render).
- **`_decorateD0Row(row)` / `_undecorateD0Row(row)`**: idempotentes — chip
  não duplica em re-renders; remove classe + chip quando undecora.

Disparo: encadeado após `_renderHeadline` + `_renderD0Toast` no
`_wireUpHeadline` E no listener `sync` da collection (mesmo timing).

### CSS novo (`components.css`)

```css
.list-row.togare-row--d-zero {
  border-left: 4px solid #c62828;
}
.togare-row__d-zero-badge {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  background: #c62828;
  color: #ffffff;
  font-weight: 700;
  letter-spacing: 0.02em;
  padding: 0.125rem 0.5rem;
  border-radius: 999px;
  margin-right: 0.5rem;
  font-size: 0.75rem;
  line-height: 1.2;
}
```

### Decoração no record-view e list-view full

Fica como **deferred-work / Growth** — escopo MVP cobre o `BriefingDoDia`
que é a primeira tela do advogado (Pattern 1 jornada Ricardo). Expansão
demanda spike do pattern correto de customização de list-view em
EspoCRM 9.x (sem ressuscitar bug do `views/record/row`).

### Cobertura testes (fix-pass)

- **vitest togare-core**: +9 novos cenários no dashlet spec (B26 — decora
  pendente / não-decora protocolado / não-decora futuro / múltiplos rows
  mistos / idempotência / undecora ao mudar status / row sem model /
  sem element / sem .list-row). Total **410/410 verdes**.

### Achados Felipe não acionados

- **Toast amarelo (`variant: 'warning'`) vs vermelho crítico**: spec
  Decisão #5 da 4b.3 pediu literal `variant: 'warning'`. Felipe sugere
  vermelho — alinha com o resto da redundância (badge + email + borda
  todos vermelhos). **Deferred** — cria 6ª variant (`critical`) ou
  modifier especial; abre escopo. Anotado para Growth.
- **Hint Preferences sem "fadiga"**: hint vem da 4b.2 e fala de canais
  desativados + lembretes pendentes. "Fadiga" é conceito interno do
  toast (não re-aparece após dismiss); misturar confunde o user. Sem
  mudança.
- **`prefers-reduced-motion` "ausente"**: falso positivo —
  `components.css` em produção tem 4 regras `@media (prefers-reduced-motion: reduce)`
  (linhas 174, 207, 612, 884). Provavelmente a inspeção do Felipe
  consultou um escopo errado.

## Story 4b.4 (v0.28.0) — SystemStatusBannerView + Migration V017 + reschedule API (FR18 / NFR19 / ADR 0009)

Implementa **alinhamento retry × circuit breaker** + **banner UI** quando integração DJEN está pausada há ≥30 min.

### Backend
- **Migration V017** (`Migration/V017__add_queue_items_failure_category.php`): adiciona coluna `failure_category VARCHAR(40) NULL` em `togare_queue_items` + índice composto `idx_togare_queue_failure_category (queue_name, status, failure_category)`. Idempotente (try-catch SQLSTATE) + audit log em `togare_audit_log` com event `togare_queue_items.failure_category_added_v017`. **Down: no-op intencional** (preserva dados — pattern V010/V012/V013/V015).
- **`QueueService::markFailed`** ganha 6º param `?string $failureCategory = null`. Quando informado, gravado na coluna em ambos os caminhos (`failed_retry` e `dead_letter`). NULL preserva valor pre-existente — **não-breaking** para call-sites pré-4b.4.
- **`QueueService::rescheduleAfterCircuitBreakerClose($queueName, $failureCategory): int`** método novo: UPDATE único transacional `WHERE queue_name=:q AND status='failed_retry' AND failure_category=:cat AND next_retry_at>:now`. Idempotente por construção (segundo worker → no-op). Log `info` `queue.items.rescheduled_after_cb_close` quando count > 0; silêncio quando count=0.

### Frontend
- **`views/common/system-status-banner.js`** (NOVO): view com polling de 60s do endpoint `GET /api/v1/TogareDjenStatus/action/snapshot` (do togare-djen). Pause automático quando aba está hidden (`document.visibilityState`); resume + fetch imediato ao voltar para visible. `onRemove()` cancela timer + remove listener `visibilitychange` (sem leak). Resolver `Espo.Ajax.getRequest` consulta `globalThis.__togareAjaxStub` em testes vitest.
- **`res/templates/common/system-status-banner.tpl`** (NOVO): `<div role="status" aria-live="polite" data-visible="...">` warning amarelo (`#fff3cd` bg / `#664d03` texto — contraste WCAG AA).
- **`Resources/i18n/pt_BR/SystemStatusBanner.json`** (NOVO): `messages.djenUnavailable = "Sync DJEN pausada há {N}min. Próxima tentativa às {HH:MM}."` — texto literal SEM `[Ver status]` (Discovery #1 da retro Epic 4a; HealthPanel é Epic 10).
- **CSS components.css**: 4 classes `.togare-system-status-banner-*` + `data-visible="false"` ⇒ `display:none` (preserva DOM, evita layout shift) + `@media (prefers-reduced-motion: reduce)` desliga transitions.

### Mount em 3 surfaces (Decisão #8 — detail wrapper deferido para followup)
1. **`views/prazo/record/list.js`** (NOVO) — extends `views/record/list`, mount banner via `createView` no setup; `clientDefs/Prazo.json` patch com `views.list = "togare-core:views/prazo/record/list"`.
2. **`views/dashlets/togare-prazos-do-dia.js`** — `_mountSystemStatusBanner()` chamado no `afterRender`, idempotente via flag `_systemStatusBannerMounted`.
3. **`views/publicacao-ambigua/record/list.js`** — mount inline no setup após mass-action registration.

### Cobertura
- **+18 vitest novos**: `system-status-banner-view.spec.js` 11 cenários + `system-status-banner-mounts.spec.js` 5 cenários.
- **+12 PHPUnit novos**: `V017MigrationTest` 6 + `QueueServiceTest` +6 (markFailed com/sem failureCategory + reschedule rowCount + idempotência + categoria/status mismatch).

### Sem mudança em
- `togare-djen` (escopo isolado em togare-djen 0.7.0).
- `togare-rbac`, `togare-tpu`, `togare-licensing`, `togare-backup`.

## Story 5.2 + 5.6 — Entity Documento: upload Nextcloud + XOR triplo (v0.29.0 → v0.30.0)

**Story 5.2 (v0.29.0):** Entity `Documento` com upload de binários ao Nextcloud vinculado a Processo OU Cliente (XOR rígido binário). 6 Hooks (Validate=10, DefaultUploadedBy=15, MoveToNextcloud=30, Cleanup AfterSave=10, SoftPurge BeforeRemove=20, Audit=50). Migration V018 (tabela auxiliar `togare_documento_log` para audit de soft-purge). Controller stub 501 (download — Story 5.3 substitui). 3 views frontend (`upload-modal`, `documento-list-item`, `panel-action-handler`). RBAC 8 roles patcheadas via V007 com team-scope. FileStorageContract bound a `NextcloudFileStorage` (Story 5.1).

**Story 5.6 (v0.30.0):** Extensão do XOR binário para **XOR triplo** — Documento pode ser vinculado a Processo, Cliente **ou Prazo**. Bucket novo `prazos` no `DocumentoLogicalPathBuilder` (path = `prazos/<prazoId>/<documentoId>-<filename>`). Const `BUCKET_PRAZOS = 'prazos'` em `Documento`. Painel "Documentos" no Prazo detail page via `clientDefs/Prazo.json + layouts/Prazo/relationships.json`. `DefaultUploadedByHook` estendido para cadeia 3-elos (Processo → Cliente → Prazo → fallback uploadedBy). Migration V019 defensiva idempotente para `IDX_DOCUMENTO_PRAZO_ID`. togare-rbac sem bump (V007 já cobre Documento via team-scope; ACL by-assignment herda transitivamente via Prazo.assignedUser).
