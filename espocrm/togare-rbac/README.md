# Togare RBAC

Módulo EspoCRM que **pré-configura os 8 roles do MVP Togare** via seed idempotente no `AfterInstall` **+ habilita convite de usuários com política de senha forte** reusando o fluxo nativo do EspoCRM (`accessInfo` + `PasswordChangeRequest`). Cada role é uma linha na tabela ORM core `role` do EspoCRM, com `data` JSON descrevendo `scopeList` + `scopeLevel`. Roles ficam editáveis pelo Sócio/Admin via `Admin → Roles` depois — o seed só popula no install limpo, **nunca sobrescreve customizações posteriores**.

**Stories:** [2.1](../../_bmad-output/implementation-artifacts/2-1-roles-pre-configurados-espocrm-acl.md) (8 roles seedados) + [2.2](../../_bmad-output/implementation-artifacts/2-2-convite-usuario-com-atribuicao-role.md) (convite usuário com política de senha forte).
**Cobre:** FR1 (cadastro de usuário com papéis pré-configurados), FR3 (separação de acessos por papel), FR4 (alteração/desativação de usuários), NFR8 (bcrypt cost ≥12). RBAC Matrix do PRD §L684-695.
**Pré-requisito de:** Stories 2.3 (MFA), 2.4 (audit log), 2.5 (rate limit auth), 2.6 (wizard pós-login).

## O que faz

Instala 8 roles na tabela `role` do EspoCRM:

| Role | Escopo característico | Bloqueio característico |
|---|---|---|
| Sócio/Admin | Tudo: clientes, processos, financeiro, RH, marketing, pipeline, configuração | MFA obrigatório (Story 2.3) |
| Advogado | Clientes atribuídos, processos, prazos, audiências, minutas, agenda própria | Não vê honorários de outros advogados nem RH |
| Assistente/Estagiário | Clientes/processos atribuídos (visibilidade reduzida), agenda, prazos | Não vê honorários nem minutas finais |
| Secretária | Agenda geral, contatos, prazos (lembrete sem conteúdo sensível), recepção | Não vê honorários nem conteúdo processual |
| Financeiro | Contratos honorários, faturas, pagamentos, relatórios financeiros | Não vê minutas nem conteúdo processual |
| Marketing | Pipeline de leads, campanhas, comunicações comerciais | Não vê clientes efetivos nem conteúdo processual |
| RH-lite | Cadastro funcionários (nome, CPF, cargo, salário) | Não vê processos, clientes, honorários |
| Cliente-portal | Somente seus próprios processos, documentos, mensagens | Não vê outros clientes nem honorários alheios |

Os 8 arquivos JSON ficam em `src/files/custom/Espo/Modules/TogareRbac/Resources/seed/roles/`. Cada arquivo espelha as colunas da tabela `role` (`name`, `assignmentPermission`, `userPermission`, `messagePermission`, `portalPermission` etc.) + nó `data` com `scopeList`/`scopeLevel`/`fieldLevel`.

## Como instalar

```bash
node build --extension                                            # gera build/togare-rbac-0.1.0.zip
docker compose exec -u www-data espocrm \
  php command.php extension --file=/path/to/togare-rbac-0.1.0.zip
docker compose exec -u www-data espocrm php command.php rebuild
```

`AfterInstall.php` itera os 8 JSONs e faz seed idempotente:

- Se role com mesmo `name` já existe → log `rbac.role.skipped` (motivo `already_exists`), sem UPDATE.
- Se não existe → log `rbac.role.seeded` + INSERT na tabela `role`.

Logs são emitidos pelo `TogareLogger` (do togare-core) em JSON estruturado.

## Entidades expostas

- **`Role`** (entidade ORM core EspoCRM, tabela `role`) — 8 linhas inseridas via seed.
- **`User`** (entidade ORM core) — nenhum dado modificado; permissões a `User` são definidas dentro do `data` JSON de cada role.
- **`TogareMfaBackupCode`** (entidade própria, tabela `togare_mfa_backup_code`) — criada pela Migration V001 da Story 2.3. Guarda backup codes one-time-use para fallback do TOTP. Não exposta em UI (scope `acl:false`).

## Hooks disparados

- **`Hooks\User\UserRoleRequired`** (BeforeSave em `User`) — bloqueia criação de usuário regular sem role (Story 2.2).
- **`Hooks\PasswordChangeRequest\UserInvitedAuditHook`** (AfterSave em `PasswordChangeRequest`) — emite log `user.invited` quando novo usuário é convidado (Story 2.2).
- **`Hooks\UserData\PreventMfaDisableForRequiredUsers`** (BeforeSave em `UserData`) — bloqueia desativação de MFA para Sócio/Admin (Story 2.3, NFR9).
- **`Authentication\Hook\AuthLockoutEnforcer`** (BeforeLogin) — rejeita login quando username atingiu 5 falhas/15min; grava `auth.lockout` no audit log (Story 2.5, NFR11).
- **`Authentication\Hook\AuthFailedAttemptCounter`** (OnFail) — incrementa contador de falhas via `RateLimiter::check()` a cada credencial errada (Story 2.5, NFR11).
- **`Authentication\Hook\AuthSuccessRateLimitReset`** (OnSuccess) — zera contador via `RateLimiter::reset()` em login bem-sucedido (Story 2.5, NFR11 UX).

> **Contrato pra entidades futuras:** toda nova entidade Togare custom (`Cliente`, `Processo`, `Deadline`, `Hearing`, `Invoice`, `LancamentoFinanceiro`, `Funcionario`, etc.) deve declarar seu scope no seed dos 8 roles via patch incremental deste módulo OU via `aclDefs` do próprio módulo premium. Roles em si **não** devem ser editados aqui após primeira instalação — admin customiza pelo UI nativo.

## Como testar

```bash
vendor/bin/phpunit                         # suite unitária do togare-rbac
```

Smoke real no container (após install):

```bash
docker compose exec -u www-data espocrm php -r '
  $cfg = require "data/config-internal.php";
  $db = $cfg["database"];
  $pdo = new PDO("mysql:host={$db["host"]};dbname={$db["dbname"]};charset=utf8mb4", $db["user"], $db["password"]);
  $count = $pdo->query("SELECT COUNT(*) FROM role WHERE deleted=0")->fetchColumn();
  echo "Roles seedados: {$count}\n";
'
# Esperado: Roles seedados: 8
```

Validação via REST:

```bash
curl -4 --http1.1 -k -s -u "admin:<senha>" -H "X-Requested-With: XMLHttpRequest" \
  "https://127.0.0.1/api/v1/Role?orderBy=name&maxSize=50"
# Esperado: {"total":8,"list":[{"id":"...","name":"Advogado",...}, ...]}
```

## Convite + política de senha (Story 2.2)

Reusa o fluxo nativo do EspoCRM (`Espo\Tools\UserSecurity\Password\Service::sendAccessInfoForNewUser` + `PasswordChangeRequest` + `Sender::sendAccessInfo` + `Checker::checkStrength`). Em cima dele, este módulo aplica:

| Componente | O que faz | Cobre |
|---|---|---|
| `Service\SecurityConfigInstaller` | No `AfterInstall`, aplica via `Espo\Core\Utils\Config\ConfigWriter` 13 chaves de segurança: 6 de política de senha (`passwordStrengthLength=10`, `passwordStrengthLetterCount=1`, etc.) + 3 de MFA (`auth2FA`, `auth2FAMethodList`, `auth2FAInPortal`) + 4 de sessão/throttle (`authTokenMaxIdleTime=0.5h`, `authTokenLifetime=0`, `authMaxFailedAttemptNumber=10`, `authFailedAttemptsPeriod='60 seconds'`). Idempotente: nunca baixa um valor que o admin tornou mais restritivo. | FR1, NFR8, NFR11, NFR13 |
| `Service\Password\TogarePasswordHash` | Override do serviço nativo `passwordHash` via `Resources/metadata/app/containerServices.json`. Hash com `PASSWORD_BCRYPT, ['cost' => 12]`. `verify()` herda comportamento do core (cost-agnostic — aceita hashes legacy cost 10). | NFR8 |
| `Hooks\User\UserRoleRequired` (BeforeSave) | Bloqueia criação de User regular sem ao menos 1 role atribuído. Lança `BadRequest` em pt-BR. Skip para `isAdmin`/`isApi`/`isPortal`. | FR3 |
| `Hooks\User\UserInvitedAuditHook` (AfterSave) | Quando User novo é salvo, emite log estruturado `event=user.invited` via TogareLogger com `{userId, email, rolesNames, invitedBy, expiresAt}`. Substitui temporariamente o audit log da Story 2.4 (ainda backlog). | FR1 |
| `Service\Invitation\InvitationService` | Facade testável: `invite(email, name, roleIds): User`. Valida role IDs ⊂ 8 seedados, cria User, dispara `sendAccessInfoForNewUser` nativo. | FR1 |
| `Resources/templates/accessInfo/pt_BR/{subject,body}.tpl` | Override do template nativo: subject "Convite para o Togare" + body com saudação personalizada, role atribuído, CTA "Criar minha senha", validade 7d. Linguagem acolhedora (UX-DR1). | UX |

### Senhas aceitas vs rejeitadas

| Senha | Resultado | Razão |
|---|---|---|
| `Curt@1` | rejeita | <10 chars |
| `lowercase!9` | rejeita | sem maiúscula |
| `UPPERCASE!9` | rejeita | sem minúscula |
| `Sem5imbolos1` | rejeita | sem símbolo |
| `SemNumeros!` | rejeita | sem número |
| `SenhaForte!9` | aceita | 12 chars, M+m+9+! ✓ |

### Mailpit dev (smoke email)

`docker-compose.yml` perfil `dev`: `axllent/mailpit:v1.21` em portas `8025` (UI) + `1025` (SMTP). Subir com `docker compose --profile dev up -d`. Configurar SMTP do EspoCRM apontando para `mailpit:1025` no Admin Panel.

**Produção:** trocar para SMTP real do escritório (Gmail App Password ou provedor) — passos detalhados na pendência manual da Story 2.2.

## MFA TOTP obrigatório (Story 2.3)

**Versão:** togare-rbac 0.5.0. Cobre FR2, FR5, NFR9 do PRD.

Sócio/Admin tem MFA obrigatório e não-desativável. Demais 7 roles têm MFA opcional.

### Pré-condições

- togare-rbac 0.3.0 instalado (`AfterInstall` aplicou `auth2FA=true`, `auth2FAMethodList=["Totp"]`, `auth2FAInPortal=false`).
- Usuário com role `Sócio/Admin` criado via convite (Story 2.2).
- App authenticator no telefone (Google Authenticator / Authy / Bitwarden).

### Setup REST do MFA (passos)

```bash
# 1. Obter dados do setup (gera secret base32 e o persiste no UserData)
curl -k -u "socio_smoke:SenhaForte!9" -X POST \
  -H "Content-Type: application/json" \
  -d '{"id":"<userId>","password":"SenhaForte!9","auth2FAMethod":"Totp"}' \
  https://localhost/api/v1/UserSecurity/action/getTwoFactorUserSetupData
# → {"auth2FATotpSecret":"<base32>","label":"Togare:socio_smoke"}

# 2. Calcular código TOTP atual (no container)
docker exec -u www-data <container> php -r '
  require "/var/www/html/vendor/autoload.php";
  echo (new RobThree\Auth\TwoFactorAuth())->getCode("<secret>");
'

# 3. Ativar MFA
curl -k -u "socio_smoke:SenhaForte!9" -X PUT \
  -H "Content-Type: application/json" \
  -d '{"password":"SenhaForte!9","auth2FA":true,"auth2FAMethod":"Totp","code":"<6-digits>"}' \
  https://localhost/api/v1/UserSecurity/<userId>
# → {"auth2FA":true,"auth2FAMethod":"Totp"}
```

### Backup codes — gerar + usar

```bash
# Regenerar 8 backup codes (exibidos uma única vez — anote!)
curl -k -u "socio_smoke:SenhaForte!9" -X POST \
  -H "Content-Type: application/json" \
  -d '{"password":"SenhaForte!9"}' \
  https://localhost/api/v1/TogareRbac/action/regenerateMfaBackupCodes
# → {"codes":["abcd-ef12",...],"warning":"Estes códigos serão exibidos apenas uma vez. Anote em local seguro."}

# Verificar status dos backup codes
curl -k -u "socio_smoke:SenhaForte!9" \
  https://localhost/api/v1/TogareRbac/action/mfaBackupCodesStatus
# → {"total":8,"used":0,"remaining":8}

# Login com backup code (no campo X-Authorization-Code)
curl -k -u "socio_smoke:SenhaForte!9" \
  -H "X-Authorization-Code: abcd-ef12" \
  https://localhost/api/v1/App/user
```

### Fluxo "perdi o telefone E os backup codes"

1. Admin EspoCRM faz reset: `POST /api/v1/UserSecurity/action/getTwoFactorUserSetupData` com `reset:true` (autenticando como admin).
2. Sócio/Admin loga sem MFA temporariamente (`appParams.togareMfaRequired=true`).
3. Refaz setup (passos acima) + regenera novos backup codes.
4. Story 2.6 (wizard) guiará o Sócio/Admin neste fluxo visualmente.

### Bloqueio de desativação para Sócio/Admin

```bash
# Sócio/Admin tentando desativar → 403
curl -k -u "socio_smoke:SenhaForte!9" -X PUT \
  -H "Content-Type: application/json" \
  -d '{"password":"SenhaForte!9","auth2FA":false}' \
  https://localhost/api/v1/UserSecurity/<userId>
# → 403: "MFA não pode ser desativado para a role Sócio/Admin (NFR9 do PRD Togare)."
```

### Pendência — wizard pós primeiro login (Story 2.6)

Story 2.6 entregará UI guiada que:
- Detecta `appParams.togareMfaRequired=true` ao entrar.
- Exibe QR code em tela cheia com instrução passo a passo.
- Exibe 8 backup codes após confirmação (exibição única).
- Impede Sócio/Admin de usar o sistema sem MFA configurado.

## Auth lockout + sessão 30min (Story 2.5)

Implementa NFR11 (rate limit auth 5 falhas/15min por usuário) e NFR13 CRM (sessão idle 30min).

### Controles entregues

| Layer | Mecanismo | NFR |
|---|---|---|
| L2 — Throttle IP nativo | `authMaxFailedAttemptNumber=10` + `authFailedAttemptsPeriod='60 seconds'` via `SecurityConfigInstaller` | defense in depth |
| L3 — Throttle por usuário | `AuthLockoutEnforcer` (BeforeLogin) + `AuthFailedAttemptCounter` (OnFail) + `AuthSuccessRateLimitReset` (OnSuccess) | NFR11 |
| L4 — Sessão idle | `authTokenMaxIdleTime=0.5` (0.5h = 30min) via `SecurityConfigInstaller` | NFR13 CRM |

### Flow de lockout

1. Usuário erra senha → `AuthFailedAttemptCounter` incrementa contador em `togare_rate_limits` (key = `auth.failed.user:<username_lowercase>`).
2. Na 6ª tentativa (ou qualquer posterior dentro da janela de 15min): `AuthLockoutEnforcer` faz `RateLimiter::peek()` → counter ≥ 5 → lança `Forbidden` com mensagem pt-BR: _"Conta temporariamente bloqueada. Tente novamente em 15 minutos."_
3. Evento `auth.lockout` é gravado em `togare_audit_log` via `AuditLogContract` (dual-write com `TogareLogger::event('auth.lockout.blocked')`).
4. Após login bem-sucedido: `AuthSuccessRateLimitReset` deleta a linha do contador — usuário começa com orçamento novo.
5. Lockout expira automaticamente após 15min (janela sliding window do `RateLimiter`).

### Critério OK/NOK do smoke (AC3)

```bash
# 5 falhas consecutivas → returns 401 (credencial errada)
for i in 1 2 3 4 5; do
  curl -k -s -o /dev/null -w "Tentativa $i: %{http_code}\n" \
    -H "Authorization: Basic $(echo -n 'socio_smoke:senha_errada' | base64)" \
    https://${TOGARE_DOMAIN}/api/v1/App/user
done

# 6ª tentativa → deve retornar 403 com mensagem de lockout
curl -k -s -w "%{http_code}\n" \
  -H "Authorization: Basic $(echo -n 'socio_smoke:qualquer_senha' | base64)" \
  https://${TOGARE_DOMAIN}/api/v1/App/user
```

**OK:** tentativas 1-5 retornam `401`; 6ª retorna `403` com body `"Conta temporariamente bloqueada..."`.

**NOK:** 6ª retorna `401` — hook `BeforeLogin` não disparou; verificar `authentication.json` em `togare-rbac/Resources/metadata/app/`.

### Debt conhecida

- `authTokenMaxIdleTime=0.5` é global (CRM + Portal). Portal terá 30min de idle até Story 7a.6 entregar mecanismo de override para 45min (NFR13 v1.1).
- Rate limit por IP no Caddy (camada L5) deferido para Growth — requer build custom com `xcaddy + caddy-ratelimit` (ADR 0004).

## Wizard pós primeiro login (Story 2.6)

Wizard in-app de 4 passos disparado **automaticamente** no primeiro login do
Sócio/Admin **após** o setup de MFA (Story 2.3) ter concluído. Cobre **FR34** +
camada 1 do Pattern 12 (Onboarding em Camadas) do UX Design Spec.

### Flow

1. **Sócio/Admin loga** (senha + TOTP).
2. EspoCRM carrega `appParams` via `GET /api/v1/App/user`.
3. `TogareWizardRequired::get()` retorna `true` se TODAS:
   - `MfaPolicyResolver::isMfaRequired($user)` (Sócio/Admin ou Admin nativo).
   - `User.togareWizardCompleted === false`.
   - `UserData.auth2FA === true` (precedência: 2.3 > 2.6).
4. Frontend `extensions.js` (script global do togare-rbac) detecta a flag e
   monta `WizardShellView` como **modal full-screen**.
5. Usuário avança/recua entre 4 passos; cada `POST` confirma o passo e
   registra audit em `togare_audit_log`.
6. Ao "Concluir wizard" ou "Pular wizard" (com ConfirmacaoTextual digitando
   "pular"), `User.togareWizardCompleted=true` é gravado e o modal fecha.

### 4 passos × endpoint × audit event

| Passo | Endpoint REST | Audit event |
|---|---|---|
| 1 — Identidade (companyName + companyLogoId) | `POST /TogareRbacWizard/action/applyOrgInfo` | `wizard.step_completed` step=1 |
| 2 — Cor primária `#RRGGBB` | `POST .../applyPrimaryColor` | `wizard.step_completed` step=2 |
| 3 — Confirmar/renomear roles | `POST .../confirmRoles` | `wizard.step_completed` step=3 (+ `wizard.role_renamed` por rename) |
| 4 — Convidar usuários (≤20 batch) | `POST .../inviteBatch` | `wizard.step_completed` step=4 |
| Final | `POST .../complete {skipped?}` | `wizard.completed` ou `wizard.skipped` |

### Idempotência

Cada passo pode ser chamado N vezes; estado final é o **último envio**. Audit
log dedup `wizard.started` por janela 1h. Settings (companyName, logo, cor) são
gravados via `ConfigWriter` — última escrita ganha (last-write-wins).

### Skip flow

Clique em "Pular wizard" → modal `togare-core:common/confirmacao-textual`
pedindo digitar literal **"pular"**. Após match exato, `POST /complete
{skipped:true}` marca `togareWizardCompleted=true`. Wizard não dispara mais
em sessões futuras.

### Proteção crítica do role "Sócio/Admin"

[MfaPolicyResolver::isMfaRequired](src/files/custom/Espo/Modules/TogareRbac/Service/Mfa/MfaPolicyResolver.php) detecta
Sócio/Admin pelo **nome literal** (`ROLE_NAME_SOCIO_ADMIN = 'Sócio/Admin'`). O
`WizardService::confirmRoles` **rejeita rename desse role** com `BadRequest`
pt-BR — protege MFA enforcement da Story 2.3 + AppParam `togareMfaRequired`.
Os outros 7 roles são livres para rename.

### Persistência

- `Settings.companyName` e `Settings.companyLogoId` (chaves nativas EspoCRM).
- `Settings.togarePrimaryColor` (custom via `entityDefs/Settings.json`).
- `User.togareWizardCompleted` + `User.togareWizardCompletedAt` (custom via
  `entityDefs/User.json`).

### Critério OK/NOK do smoke (Felipe F1)

```bash
# AC1 — appParam disparado pra Sócio/Admin com MFA OK e wizard pendente:
curl -k -X GET \
  -H "Authorization: Basic $(echo -n 'socio_smoke:senha' | base64)" \
  -H "Espo-Authorization-Code: <TOTP>" \
  https://localhost/api/v1/App/user | jq .appParams.togareWizardRequired
# OK: true

# AC3 — passo 1 persiste:
curl -k -X POST -H "Content-Type: application/json" \
  -H "Authorization: Basic ..." \
  -d '{"companyName": "Escritório Smoke Ltda", "companyLogoFileId": null}' \
  https://localhost/api/v1/TogareRbacWizard/action/applyOrgInfo
# OK: 200 + {"step": 1, "status": "applied"}

# AC7 — complete:
curl -k -X POST -H "Content-Type: application/json" \
  -H "Authorization: Basic ..." \
  -d '{}' \
  https://localhost/api/v1/TogareRbacWizard/action/complete
# OK: 200 + {"wizardCompleted": true, "skipped": false}
```

**NOK:**
- 403 em qualquer endpoint → user não é Sócio/Admin ou é Portal/API.
- AC1 retorna `false` mesmo após MFA → checar `togareWizardCompleted` no DB
  (pode estar `1` por um wizard concluído anterior); reset com
  `UPDATE user SET togare_wizard_completed=0 WHERE user_name='socio_smoke'`.

### Anti-padrões

- **NÃO** acoplar wizard ao `AfterInstall` — flag é per-User, não per-instalação.
- **NÃO** disparar wizard antes do MFA setup — gate `auth2FA === true` no AppParam.
- **NÃO** permitir delete/criação de roles via wizard — apenas rename dos 7.

## v0.6.1 — patch `assistente.json` para alinhar com FR6 (Story 3.1)

A versão 0.6.0 seedou o role **Assistente/Estagiário** com `Cliente:
{read=team, edit=no, create=no, delete=no}` (read-only). FR6 do PRD diz:
"Advogado **e Assistente** podem cadastrar, editar e consultar clientes" —
contradição. **0.6.1** patcha o JSON de seed para
`Cliente: {read=team, edit=team, create=team, delete=no}` (delete continua
exclusivo do Sócio/Admin).

**Em install limpo** (cluster novo): seed instala já com a role corrigida —
sem ação extra.

**Em upgrade sobre instalação existente** (caso típico do ambiente Felipe):
o `RoleSeeder` detecta o role já criado pelo `name` e **skip preservando
customização** (política da Story 2.1 é firme — seed nunca sobrescreve
customização do admin). O Sócio/Admin precisa abrir
**Admin → Roles → Assistente/Estagiário → Cliente** e mudar
`Edit/Create` de `No` para `Team` manualmente. Esta operação é log
em `togare_audit_log` automaticamente (Role audit hook da Story 2.4).

## v0.6.3 — rename `Process` -> `Processo` para Story 3.4

A Story 3.4 renomeou a entidade jurídica de workflow de `Process` para
`Processo`. A versão 0.6.3 atualiza os 8 JSONs de seed de roles Togare para
usar o novo scope (`Processo`) e remove o scope legado (`Process`) dos
payloads de permissão.

**Em install limpo**: o seed já cria os roles com `Processo`.

**Em upgrade sobre instalação existente**: como o `RoleSeeder` preserva roles
já customizadas, a migração `V002__rename_process_scope_to_processo` faz o
patch somente nos roles Togare conhecidos. Ela troca `Process` por `Processo`
em `scopeList`, copia o nível de `Process` para `Processo` quando ainda não
existe nível customizado no novo scope, e remove a chave legada `Process`.

Níveis esperados após o patch: **Sócio/Admin** com acesso total;
**Advogado** com read team/edit own/create team/delete no; **Assistente** com
read/edit/create team/delete no; **Secretaria** e **Financeiro** com read team
e demais ações no; **Marketing**, **RH Lite** e **Cliente Portal** sem acesso.

## v0.7.0 — Advogado.Processo.read team→own (Story 3.5, FR11)

A Story 3.5 implementa **ACL by-assignment** para Processo: um advogado vê
apenas processos onde ele é titular (`assignedUser`) ou colaborador (link
multiple `collaborators`). Isso reduz visibilidade ampla anterior (`team`)
para o nível canônico `own`. O EspoCRM 9.x resolve isso automaticamente
quando:

1. `togare-core 0.11.0+` declara `scopes.Processo.collaborators=true` +
   `aclDefs/Processo.json` com `assignedUser=true, collaborators=true` +
   link `collaborators hasMany User relationshipName ProcessoCollaborator`
   (cria a join table `processo_collaborator` no rebuild).
2. **Esta extensão** (togare-rbac 0.7.0) define `Advogado.Processo.read =
   "own"` no seed `advogado.json`. Os outros 7 roles preservam configuração
   pré-existente: Sócio/Admin permanece `all`; Assistente, Secretária e
   Financeiro permanecem `team` (apoio operacional precisa enxergar carteira
   inteira); Marketing, RH-lite e Cliente-portal permanecem `no`.

Em **upgrade sobre instalação existente**, a migração
`V003__patch_advogado_processo_to_own` rebaixa `Advogado.Processo.read` de
`team` para `own` apenas na row `Advogado` da tabela `role`, preservando
customizações que o admin tenha feito nas demais 7 roles via UI nativo.
Idempotente: rodar duas vezes é no-op.

`down()` é no-op intencional — voltar para `team` reabriria a brecha de
FR11 (Advogado vendo processo de outro Advogado). Reversão exige
intervenção manual em Admin → Roles.

## v0.7.1 — SeedRolesTest cobre Audiencia (Story 3.6-magro, FR16)

Cobertura de teste para a entidade `Audiencia` adicionada na Story
3.6-magro (togare-core v0.12.0). Os 8 JSONs de role já declaravam
`Audiencia` em `scopeList` + `scopeLevel` desde a Story 3.5 — esta versão
**não muda nenhum arquivo de seed**, apenas adiciona 7 testes unit em
`SeedRolesTest`:

- `testSocioAdminPodeAllAudiencia` — Sócio/Admin tem `Audiencia: "all"`.
- `testAdvogadoTemReadOwnEmAudiencia` — Advogado tem `Audiencia: "own"`
  (FR16: visibilidade by-assignment via `assignedUser`).
- `testSecretariaSoLeAudiencia` — Secretária tem `{read: team, edit: no,
  create: no, delete: no}` (apoio agenda consolidada FR17).
- `testRolesNaoOperacionalNaoVeemAudiencia` — DataProvider de 4 roles
  (Financeiro / Marketing / RH-lite / Cliente-portal), todos `"no"`.

Suite `SeedRolesTest` total: **127 testes** (120 da v0.7.0 + 7 novos da
v0.7.1). 1 erro pré-existente em `BackupCodeServiceTest::testConsumeMarcaUsedERetornaTrue`
documentado desde Stories 3.1-3.5 — não-regressão.

## v0.7.2 — SeedRolesTest cobre Marketing/Lead/Opportunity (Story 3.8, FR31)

Cobertura de teste para o pipeline de leads do Marketing entregue pela Story
3.8 (togare-core v0.13.0 — overrides metadata vanilla de `Lead`/`Opportunity`).
Os 8 JSONs de role já declaravam `Lead`/`Opportunity` em `scopeList` +
`scopeLevel` desde a Story 2.1 — esta versão **não muda nenhum arquivo de
seed**, apenas adiciona 5 métodos novos em `SeedRolesTest` cobrindo ≥10
assertions distintas via DataProviders:

- `testMarketingTemAclAllEmLead` — `Marketing.Lead === "all"` (FR31:
  pipeline de captação sem restrição de team).
- `testMarketingTemAclTeamEmOpportunity` — `Marketing.Opportunity === "team"`
  (pipeline compartilhado entre marketing do escritório).
- `testSocioAdminPodeAllLeadEOpportunity` — `socio-admin.Lead === "all"` E
  `socio-admin.Opportunity === "all"` + ambos no `scopeList` (regressão
  invariante "Sócio/Admin = aclLevel.all em tudo").
- `testRolesNaoMarketingNaoVeemLead` — DataProvider de 6 roles (Advogado /
  Assistente / Secretária / Financeiro / RH-lite / Cliente-portal), todos
  `Lead === "no"` (FR31: pipeline de captação é exclusivo do Marketing).
- `testMarketingNaoVeContentoJuridico` — DataProvider de 8 escopos (Cliente,
  Processo, Audiencia, LancamentoFinanceiro, ContratoHonorarios, Funcionario,
  PortalProcess, PortalMessage), todos `Marketing[scope] === "no"` (FR31 +
  Story 2.1: blindagem do role Marketing contra conteúdo
  jurídico/financeiro/RH/Portal).

**Sem mudança em runtime** — zero patch nos 8 JSONs de role, zero migration,
zero novo arquivo de produção. Apenas 1 arquivo de teste editado:
`tests/unit/Espo/Modules/TogareRbac/SeedRolesTest.php`.

Suite `SeedRolesTest` total: **132 + DataProvider cases** (127 da v0.7.1 +
2 testes simples + 1 teste agregado + 6 cases via DataProvider Lead + 8
cases via DataProvider Marketing scopes blindados). 1 erro pré-existente
em `BackupCodeServiceTest::testConsumeMarcaUsedERetornaTrue` documentado
desde Stories 3.1-3.6-magro — não-regressão.

## v0.9.0 — fieldLevel.Prazo nas 4 roles operacionais (Story 4a.3.1, FR12+FR13+FR14+FR37)

A Story 4a.3.1 adiciona 6 campos novos na entity Prazo (descricao, prioridade, tipoPrazo, motivoReagendamento, cliente, parteContraria). Esta v0.9.0 cobre o controle de acesso fino (`data.fieldLevel.Prazo`) das 4 roles operacionais via Migration V005 + 4 seed JSONs patcheados.

| Role | fieldLevel.Prazo (6 campos) |
|---|---|
| Sócio/Admin | `yes` em todos |
| Advogado | `yes` em todos |
| Assistente/Estagiário | `yes` em todos |
| Secretária | `read` em todos (alinhado scope.edit=no) |
| Financeiro / Marketing / RH-lite / Cliente-portal | ausente (scope.no já bloqueia) |

**Migration V005** espelha pattern V003/V004 — idempotente, preserva customizações do admin (se admin alterou um campo via UI Admin → Roles, V005 não sobrepõe). Down=no-op.

**Testes novos:** 4 cenários SeedRolesTest (`testSocioAdminFieldLevelPrazoYesEm6Campos` + 3 análogos para Advogado/Assistente/Secretária) + 1 DataProvider 4-cases (testRolesNaoOperacionaisNaoTemFieldLevelPrazo) + V005MigrationTest dedicado com ≥6 cenários (idempotência, preserva customizações, roles ausentes, down no-op).

Requer togare-core ≥ 0.18.0.

## v0.8.0 — RBAC para entity Prazo (Story 4a.3, FR12+FR13+FR14)

Cobertura RBAC para a entity `Prazo` introduzida em togare-core 0.17.0
(materializa o pipeline DJEN → Prazo persistido). Os 8 JSONs de role são
patcheados (scopeList + scopeLevel) com a política:

| Role | Política Prazo |
|---|---|
| Sócio/Admin | `all` |
| Advogado | `{read:own, edit:own, create:team, delete:no}` |
| Assistente/Estagiário | `{read:team, edit:team, create:team, delete:no}` |
| Secretária | `{read:team, edit:no, create:no, delete:no}` |
| Financeiro / Marketing / RH-lite / Cliente-portal | `no` |

**Migration V004** (`V004__patch_roles_add_prazo.php`) garante o patch em
instalações existentes que já rodaram togare-rbac 0.7.x sem `Prazo` nas
roles. Idempotente — preserva customizações manuais via Admin → Roles.
Espelha o pattern V003 da Story 3.5 (Advogado.Processo.read team→own).

**5 testes novos em SeedRolesTest** (totalizando ≥7 assertions distintas):

- `testSocioAdminPodeAllPrazo` — `Sócio/Admin.Prazo === "all"` + presente em scopeList.
- `testAdvogadoTemReadOwnEmPrazo` — granular `{read:own, edit:own, create:team, delete:no}` (ACL by-assignment).
- `testSecretariaSoLePrazo` — granular read-only (apoio operacional ao advogado).
- `testRolesNaoOperacionalNaoVeemPrazo` — DataProvider de 4 roles (Financeiro / Marketing / RH-lite / Cliente-portal) — todos `Prazo === "no"`.
- `testAssistenteTemTeamEmPrazo` — granular team scoped (apoio operacional ao Advogado titular).

Cliente-portal recebe `no` mesmo sendo o role do Portal — exposição ao
cliente final é via API filtrada (Epic 7a — `togare-portal-bridge`),
não via role direto.

Ver `espocrm/togare-djen/docs/ADR-03-pipeline-djen-prazo.md` para a
arquitetura completa do pipeline DJEN → Prazo.

## Convenções honradas (validator do togare-core)

- **R1**: namespace `Espo\Modules\TogareRbac\...`.
- **R2**: README presente (este arquivo).
- **R3**: tabela própria `togare_mfa_backup_code` criada via Migration V001 (Story 2.3) com prefix `togare_` conforme a convenção. Tabela `role` é core do EspoCRM (exception R3 herdada das Stories 2.1/2.2).
- **R4**: este módulo não declara labels (não há entidade própria).
- **R5**: `TogareLogger`, nunca `error_log()` ou `$GLOBALS['log']`.
- **R6**: não aplica.
- **R7**: logs estruturados nunca devem conter tokens de segurança ou credenciais (`requestId` de invite/recovery, JWT, passwords, backup codes, etc.).
