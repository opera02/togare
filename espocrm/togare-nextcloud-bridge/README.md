# TogareNextcloudBridge

## 1. O que faz

Camada de integração unificada **Togare ↔ Nextcloud** via WebDAV/OCS, com degradação graciosa: timeout 30s, retry 3x backoff exponencial, circuit breaker file-based + evento `nextcloud.unavailable` para HealthPanel.

Story **5.1** entrega:

- `NextcloudClientContract` — interface pluggable de 7 métodos WebDAV/OCS (`putWebDav`, `getWebDav`, `existsWebDav`, `deleteWebDav`, `moveWebDav`, `propfindList`, `resolveWebDavUrl`).
- `OcsApiClient` — implementação default contra `nextcloud:80` (cURL + Basic auth + retry 3x backoff [1s, 4s, 16s] + circuit breaker file-based 5/300s/300s + `httpExecutor` injetável + suporte `file://` para fixtures).
- `NextcloudFileStorage implements FileStorageContract` — implementação concreta dos 4 métodos `put/get/exists/delete` (contrato vive em togare-core 0.28.4 desde Story 1a.4a).
- `NextcloudPurgeableStorage extends NextcloudFileStorage implements PurgeableStorageContract` — adiciona `softPurge` (WebDAV MOVE para `/togare/.purged/<tombstoneId-32-hex>/<logicalPath>`) e `restoreFromTombstone` (PROPFIND depth=1 para descobrir original-path + MOVE inverso). `tombstoneId = bin2hex(random_bytes(16))`.
- `Binding.php` — registra os 3 contratos via DI (`bindImplementation` cross-module).

**FRs/NFRs:** FR19 (anexar documentos), FR20 (referência cruzada metadata ↔ binário — bridge é a interface), FR21 fundação (download/visualizar — Story 5.3 entrega o proxy), NFR26 (Nextcloud = fonte única de binários, acessível **só** via bridge).

**O que NÃO faz** (próximas stories do Epic 5/8/10):

- Entidade `Documento` (`TogareDocumento`) com `nextcloudUri`, `filename`, `size`, `uploadedBy` etc. → **Story 5.2**.
- UI "Anexar documento" no detail de Processo/Cliente → **Story 5.2**.
- Download proxy `/api/v1/togare/download/{id}` com ACL + `X-Accel-Redirect` → **Story 5.3** (depende da receita Caddy v2 da Spike 1b.S1, validada em sanity local 2026-04-24).
- Histórico de versões de documento → **Story 5.4** (versioning nativo Nextcloud + metadata EspoCRM).
- `TogareBridgeHardDeleteJob` (scheduled job que promove tombstones expirados em hard-delete) → **Story 5.5**. Tabela `togare_bridge_tombstones` também vem na 5.5.
- HealthPanel UI consumindo `IntegrationFailedEvent` → **Story 10.2**.

## 2. Escopo Story 5.1

| Categoria | Entregue |
|---|---|
| Contract pluggable | `Contracts/NextcloudClientContract` |
| Adapter HTTP | `Services/OcsApiClient` (cURL + Basic auth + retry + CB file-based + `httpExecutor` injetável) |
| FileStorage | `Services/NextcloudFileStorage` implements `FileStorageContract` |
| PurgeableStorage | `Services/NextcloudPurgeableStorage` extends `NextcloudFileStorage` implements `PurgeableStorageContract` |
| DI bindings | `Binding.php` registra 3 contratos |
| Exceções tipadas | `Exception/NextcloudUnavailableException` (pt-BR), `Exception/NextcloudFileNotFoundException` (pt-BR) |
| i18n | `Resources/i18n/pt_BR/Global.json` |
| containerServices | `togareNextcloudClient`, `togareNextcloudFileStorage`, `togareNextcloudPurgeableStorage` |
| Configuração | env vars `TOGARE_NEXTCLOUD_BASE_URL` / `_USER` / `_PASSWORD` |
| Tests PHPUnit | ≥18 verdes (OcsApiClient ≥7 + FileStorage ≥4 + PurgeableStorage ≥4 + Binding ≥1 + Event ≥2) |

## 3. Dependências de versão

| Pacote | Versão mínima | Por quê |
|---|---|---|
| EspoCRM | 9.3.0 | Hooks, Binding, ContextualBinder API |
| PHP | 8.3 | `readonly` props, enums, named args, `random_bytes` |
| `togare-core` | **0.28.4** | `FileStorageContract`, `PurgeableStorageContract`, `EventDispatcher`, `IntegrationFailedEvent`, `TogareLogger` |
| Nextcloud | 31-apache | suportado pela imagem oficial (ADR 0002); WebDAV `/remote.php/dav/files/<user>/`; PROPFIND/MOVE/MKCOL stable |

## 4. Arquitetura interna

```
        ┌────────────────────────────────────────┐
        │  Caller (Story 5.2 Documento upload,   │
        │  Story 5.3 download proxy, Epic 8 LGPD,│
        │  ContratoHonorarios da 6.1, etc.)      │
        └─────────────────┬──────────────────────┘
                          │ injetado via DI
                          ▼
            ┌──────────────────────────────┐
            │  FileStorageContract         │  togare-core
            │  PurgeableStorageContract    │  (interfaces)
            └─────────────────┬────────────┘
                              │ Binding.php registra
                              ▼
        ┌──────────────────────────────────────┐
        │  NextcloudFileStorage                │  togare-nextcloud-bridge
        │  NextcloudPurgeableStorage           │
        │  (implementação WebDAV)              │
        └─────────────────┬────────────────────┘
                          │ delega operações HTTP
                          ▼
            ┌──────────────────────────────┐
            │  NextcloudClientContract     │  (interface)
            │  ─→ OcsApiClient             │  (impl default)
            │  • cURL puro                 │
            │  • Basic auth                │
            │  • retry 3x [1s,4s,16s]      │
            │  • CB file-based 5/300s/300s │
            │  • httpExecutor injetável    │
            └─────────────────┬────────────┘
                              │ HTTP (interno docker network)
                              ▼
        ┌──────────────────────────────────────┐
        │  Nextcloud apache  (nextcloud:80)    │
        │  WebDAV /remote.php/dav/files/<user>/│
        └──────────────────────────────────────┘

       Em paralelo:
            │
            ├── TogareLogger.event('nextcloud.*', …)         JSON estruturado pt-BR stdout
            └── EventDispatcher::dispatch(IntegrationFailedEvent(…))   consumido em Story 10.2
```

URI lógica = `nextcloud://<logicalPath>` (sem host, sem user). Bridge prefixa `togare/` ao resolver via WebDAV.

## 5. Operação

### Variáveis de ambiente

| Var | Default | Descrição |
|---|---|---|
| `TOGARE_NEXTCLOUD_BASE_URL` | `http://nextcloud:80` | DNS interno Docker. Override `file:///app/fixtures/` em CI/dev. |
| `TOGARE_NEXTCLOUD_USER` | `NEXTCLOUD_ADMIN_USER` | User Nextcloud para WebDAV. MVP usa admin; produção pode setar `togare-bridge` dedicado. |
| `TOGARE_NEXTCLOUD_PASSWORD` | `NEXTCLOUD_ADMIN_PASSWORD` | Senha (admin password ou app password). |

### Smoke commands

```bash
# Build + install (host PowerShell ou WSL)
cd espocrm/togare-nextcloud-bridge
npm install
npm run extension     # gera build/togare-nextcloud-bridge-0.1.0.zip

# Validar resolução DI
docker compose exec espocrm php -r "
  require '/var/www/html/bootstrap.php';
  \$c = \$container->getByClass(\\Espo\\Modules\\TogareCore\\Contracts\\FileStorageContract::class);
  var_dump(get_class(\$c)); // espera 'Espo\\\\Modules\\\\TogareNextcloudBridge\\\\Services\\\\NextcloudFileStorage'
"

# PUT real
docker compose exec espocrm php -r "
  require '/var/www/html/bootstrap.php';
  \$c = \$container->getByClass(\\Espo\\Modules\\TogareCore\\Contracts\\FileStorageContract::class);
  \$c->put('clientes/abc/contratos/2026-001.pdf', file_get_contents('/tmp/dummy.pdf'));
  echo 'OK\n';
"

# Verificar via WebDAV (de dentro do container nextcloud)
docker compose exec nextcloud curl -u admin:senha \
  http://localhost:80/remote.php/dav/files/admin/togare/clientes/abc/contratos/2026-001.pdf -o /tmp/back.pdf
```

### Troubleshooting comum

- **CB ficou aberto e não recupera** → deletar state file: `docker compose exec espocrm rm /tmp/togare-nextcloud-bridge-circuit-breaker.json`. Próxima request reabre o circuito.
- **PROPFIND retorna XML mal-formado** → validar via `curl -X PROPFIND -H "Depth: 0" -d "..."` direto no `nextcloud:80`. Bridge espera `<d:multistatus>` lowercase (Nextcloud 31).
- **`NextcloudUnavailableException` constante** → checar healthcheck do container nextcloud (`docker compose ps`). Se `unhealthy`, ver `docker compose logs nextcloud`.
- **MOVE retorna 409 Conflict** → diretório destino pai não existe. Bridge faz MKCOL recursivo automático em `moveWebDav`/`putWebDav` — se ainda assim falhar, validar permissões do user no Nextcloud admin UI.
- **Tests PHPUnit "Class TogareLogger not found"** → bootstrap carrega `Stubs/CoreStubs.php` automaticamente. Se rodar via `composer install --no-dev`, autoload-dev fica fora; usar bootstrap completo.

## Histórico de versões

- **0.1.0** (2026-05-10) — Story 5.1 — primeiro release. Skeleton clone literal de togare-djen 0.7.0 com domínio djen removido. NextcloudClientContract + OcsApiClient + NextcloudFileStorage + NextcloudPurgeableStorage + Binding + EventBus emission. ≥18 PHPUnit. Sem UI nesta story (5.2 traz entity Documento).
