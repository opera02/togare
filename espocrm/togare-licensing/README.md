# Togare Licensing

Módulo EspoCRM que valida chaves de licença JWT **offline** (assinatura RSA contra chave pública embutida) e impõe **ReadOnlyGate** sobre entidades de módulos premium quando a licença expira — sem corromper dados.

**Story:** [1b.1](../../_bmad-output/implementation-artifacts/1b-1-togare-licensing-jwt-readonlygate.md)
**Cobre:** FR35 (parcial — ativação + monitoramento), FR36, NFR12, NFR20.
**Pré-requisito de:** todos os módulos premium futuros (`togare-djen`, `togare-tpu`, `togare-lgpd`, `togare-portal-ui`, etc.).

## O que entrega

- **`JwtValidator`** — verifica assinatura RSA + claims (`iss`, `exp`, `iat`, `sub`, `jti`, `mod`) **stateless**, sem tocar banco e sem chamada externa.
- **`LicenseKeyService::activate(key)`** — valida + persiste linha por módulo em `togare_module_status`.
- **Entidade `ModuleStatus`** — 1 linha por módulo premium, estados `never_activated | active | read_only`.
- **`ReadOnlyGate` hook** — intercepta `beforeSave`/`beforeRemove` em entidades marcadas com metadata `togarePremium.module: '<nome>'`. Lança `Forbidden` quando módulo está em read-only.
- **`RevalidateLicensesJob`** — scheduled job EspoCRM, cron `0 4 * * *` (04:00 BRT diariamente). Transita módulos com `expires_at < now` para `read_only` sem destruir dados.
- **Endpoint REST** `POST /api/v1/TogareLicensing/action/activateKey` (admin-only) + admin tool mínimo no painel EspoCRM.
- **Event** `LicenseStatusChangedEvent` despachado via `EventBusContract` do togare-core.

## Como funciona o JWT

**Schema esperado (claims):**

```json
{
  "iss": "togare-empresa",
  "sub": "<installation_id arbitrário>",
  "iat": 1745424000,
  "exp": 1761148800,
  "jti": "lic-abc123def456",
  "mod": ["togare-djen", "togare-portal-ui"]
}
```

Algoritmo: **RS256** (RSA SHA-256, chave 4096-bit). Chave pública em `src/files/custom/Espo/Modules/TogareLicensing/Resources/keys/togare-public.pem` (commitada — pública por definição). Chave privada fica no servidor Togare empresa, **NUNCA** no repositório.

## Como ativar uma chave

Via admin tool: Admin Panel → Togare → Ativar Licença → cola o JWT → "Ativar".

Via API:

```bash
curl -X POST http://localhost/api/v1/TogareLicensing/action/activateKey \
  -H 'Content-Type: application/json' \
  -H 'X-Auth-Token: <token-admin>' \
  -d '{"key":"<jwt>"}'
```

Resposta sucesso:

```json
{
  "success": true,
  "modulesActivated": ["togare-djen", "togare-portal-ui"],
  "expiresAt": "2026-10-21T00:00:00-03:00"
}
```

Resposta erro:

```json
{
  "success": false,
  "reason": "expired",
  "message": "A chave JWT expirou. Solicite renovação ao Togare empresa."
}
```

## Como o ReadOnlyGate é descoberto

Módulos premium marcam suas entidades em `Resources/metadata/entityDefs/<Entity>.json`:

```json
{
  "fields": { "...": "..." },
  "togarePremium": { "module": "togare-djen" }
}
```

O hook `Hooks/Common/ReadOnlyGate.php` roda em todas as entidades EspoCRM, mas retorna em <1ms se a entidade não tem metadata `togarePremium`. Entidades premium consultam `togare_module_status` e bloqueiam UPDATE/DELETE quando `status='read_only'`.

**SELECT continua funcionando** quando módulo está read-only — o usuário vê dados históricos, só não pode alterar (preserva integridade — NFR20).

## Troca de chave de produção

A chave em `Resources/keys/togare-public.pem` é placeholder de teste. Para gerar a definitiva:

```bash
# No servidor Togare empresa (chave privada NUNCA sai daí):
openssl genrsa -out togare-private-prod.pem 4096
openssl rsa -in togare-private-prod.pem -pubout -out togare-public-prod.pem

# Substituir Resources/keys/togare-public.pem pelo conteúdo de togare-public-prod.pem
# Rebuild: node build --extension
# Publicar togare-licensing-X.Y.Z.zip
```

Chaves antigas (assinadas pela chave de teste) passam a falhar `invalid_signature` — esperado.

## Build

```bash
node build --extension              # gera togare-licensing-0.1.0.zip
docker compose exec espocrm php command.php extension --file=/path/to/togare-licensing-0.1.0.zip
```

## Testes

```bash
vendor/bin/phpunit                  # 15+ testes unit + integration
```

## Dependências

- **togare-core ≥0.4.0** (Migration runner + EventDispatcher + TogareLogger).
- **lcobucci/jwt ^5.3** (composer; padrão PHP pra JWT, BSD-3 license — compatível AGPLv3).
- **PHP ≥8.3** + **EspoCRM ≥9.3.0**.

## Convenções honradas (validator do togare-core)

- R1: namespace `Espo\Modules\TogareLicensing\...` (OR `Togare\Licensing\...` em scripts standalone).
- R2: README presente.
- R3: tabela `togare_module_status` com prefixo `togare_`.
- R4: labels em `Resources/i18n/pt_BR/`.
- R5: `TogareLogger` (do togare-core), nunca `error_log()` ou `$GLOBALS['log']`.
- R6: não aplica (não toca em `togare_queue_items`).
