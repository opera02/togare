# Ambiente de Desenvolvimento

Stack local do Togare via Docker Compose: EspoCRM + Nextcloud + bancos + Redis, com Caddy como reverse proxy único servindo TLS 1.3 exclusivo.

Fundamentos:

- [ADR 0002 — docker-compose como ambiente de dev](../docs/decisoes/0002-docker-compose-dev.md)
- [ADR 0004 — Caddy como reverse proxy](../docs/decisoes/0004-caddy-reverse-proxy.md)
- [ADR 0005 — fila outbox em MariaDB ≥10.6](../docs/decisoes/0005-outbox-queue-mariadb.md)

## Pré-requisitos

- Docker 24+ e Docker Compose v2 (Docker Desktop com WSL2 no Windows; Docker Engine em Linux/macOS).
- **Portas `80` e `443` livres no host.** Caddy ocupa essas portas para servir HTTPS com redirect automático de HTTP. Em Windows, verificar que não há IIS/Apache local rodando. Se essas portas estiverem em uso e não puder libertá-las, editar temporariamente o mapeamento no `docker-compose.yml` para `8080:80` e `8443:443` e documentar.
- A porta `443/udp` é usada por HTTP/3 (QUIC). Não é crítica — sem ela, o Caddy cai para HTTP/2 normalmente.

## Primeira subida

> **Servidor de produção (escritório):** NÃO faça os passos manuais abaixo —
> use o instalador one-shot, que faz isto + Docker + senhas + módulos +
> validação automaticamente: a partir da raiz do projeto, `bash instalar.sh`
> (ver [scripts/README.md](scripts/README.md) → `install.sh`). O fluxo manual
> a seguir é para **desenvolvimento local**.

```bash
cd docker
cp .env.example .env       # ajustar senhas ANTES de subir
docker compose up -d
docker compose ps          # conferir serviços saudáveis
```

> **Senhas:** seguir o cabeçalho de [.env.example](.env.example). Mínimo 12 caracteres alfanuméricos; **evitar** `$ " ' \` `` ` `` (quebram parsing do Docker Compose). Senhas do admin do EspoCRM e Nextcloud só são gravadas na **primeira subida** — para trocar depois, use `docker compose down -v` (apaga dados) e suba de novo.

A primeira subida baixa imagens e provisiona bancos; pode levar alguns minutos. Acompanhe logs:

```bash
docker compose logs -f espocrm
docker compose logs -f nextcloud
docker compose logs -f caddy
```

## Aceitar o certificado TLS em dev local

Em `TOGARE_DOMAIN=localhost`, Caddy usa sua CA interna (cert auto-assinado). O navegador exibirá aviso de "conexão não é privada". Três opções:

1. **Chrome/Edge:** digitar `thisisunsafe` com a tela de aviso em foco (bypass temporário).
2. **Firefox:** botão "Avançado" → "Aceitar o risco e continuar".
3. **Permanente:** importar o root cert do Caddy para o trust store do SO. O arquivo fica em `caddy_data` (volume nomeado). Copiar com:
   ```bash
   docker compose cp caddy:/data/caddy/pki/authorities/local/root.crt ./caddy-root.crt
   ```
   Depois instalar `caddy-root.crt` como autoridade confiável no Windows (`certmgr.msc` → Autoridades de Certificação Raiz Confiáveis).

## URLs padrão (após Caddy)

| Serviço   | URL                     | Credenciais                                                   |
| --------- | ----------------------- | ------------------------------------------------------------- |
| EspoCRM   | https://localhost       | `ESPOCRM_ADMIN_USERNAME` / `ESPOCRM_ADMIN_PASSWORD` do `.env` |
| Nextcloud | https://files.localhost | `NEXTCLOUD_ADMIN_USER` / `NEXTCLOUD_ADMIN_PASSWORD` do `.env` |

As portas antigas `18080` (EspoCRM) e `18081` (Nextcloud) **não são mais expostas no host** — todo acesso externo passa pelo Caddy com TLS 1.3.

## Serviços

| Serviço          | Imagem                               | Papel                                           |
| ---------------- | ------------------------------------ | ----------------------------------------------- |
| `caddy`          | `caddy:${CADDY_VERSION}-alpine`      | Reverse proxy + TLS 1.3                         |
| `espocrm`        | `espocrm/espocrm:${ESPOCRM_VERSION}` | App web do CRM                                  |
| `espocrm-daemon` | `espocrm/espocrm:${ESPOCRM_VERSION}` | Cron/worker do EspoCRM                          |
| `mariadb`        | `mariadb:${MARIADB_VERSION}`         | Banco do EspoCRM (≥10.6 exigido por ADR 0005)   |
| `nextcloud`      | `nextcloud:${NEXTCLOUD_VERSION}`     | App web do Nextcloud                            |
| `postgres`       | `postgres:${POSTGRES_VERSION}`       | Banco do Nextcloud                              |
| `redis`          | `redis:${REDIS_VERSION}`             | Cache/sessões (Nextcloud DB 0, togare-tpu DB 1) |

Todas as versões são pinadas via `.env` para reprodutibilidade. Alterar versão em um único lugar.

### Redis — segregação por DB

A partir da Story 3.3 (módulo `togare-tpu`), o servidor Redis tem **dois consumidores**:

| Consumidor | DB index | Origem                                                                                    |
| ---------- | -------- | ----------------------------------------------------------------------------------------- |
| Nextcloud  | 0        | `REDIS_HOST` + `REDIS_HOST_PASSWORD` (oficial, default DB)                                |
| togare-tpu | 1        | `TOGARE_REDIS_HOST` + `TOGARE_REDIS_PORT` + `TOGARE_REDIS_PASSWORD` + `TOGARE_REDIS_DB=1` |

**Por quê DB 1 para o Togare?** Isolamento: um `FLUSHDB` durante reset do Nextcloud não purga o cache TPU. Senha (`REDIS_PASSWORD`) é compartilhada — ambos consumidores autenticam contra o mesmo servidor.

**TZ do espocrm-daemon**: `America/Sao_Paulo` (Story 3.3 / Dev Notes §11) — alinha o cron mensal `0 3 1 * *` do `TogareTpuSync` ao horário BRT, evitando pegadinha de DST e ficando consistente com `togare-backup`.

## Volumes

Volumes nomeados persistem dados entre `docker compose down` e `up`. Para zerar o ambiente:

```bash
docker compose down -v     # ATENÇÃO: apaga bancos, arquivos e certs do Caddy
```

### `togare_djen_cb_state` (Story 4b.4 / ADR 0009)

Volume novo introduzido pela Story 4b.4 para hospedar o **state-file do circuit breaker do `DjenAdapter`**. Montado em `/var/togare-djen/` em **2 containers simultaneamente**: `espocrm` (snapshot endpoint do banner UI lê) e `togare-djen-worker` (escreve quando CB abre / fecha).

**Por quê um volume compartilhado:** o adapter persiste o CB state em `circuit-breaker.json` para sobreviver a restart do worker. Antes da 4b.4, o file vivia em `sys_get_temp_dir() = /tmp/` — mas `/tmp` **não é compartilhado** entre containers Docker. Sem o volume, o snapshot endpoint (rodando no container `espocrm`) sempre veria `cbOpen=false` mesmo com CB aberto no worker — o banner amarelo nunca apareceria em produção.

**Concorrência:** o `DjenAdapter` usa `flock(LOCK_EX/LOCK_SH)` em todas as leituras/escritas. Volumes Docker `local` driver (default) garantem semântica `flock` consistente entre containers no mesmo host. Em deploy não-Docker (futuro enterprise self-host com NFS/CIFS), validar que `flock` funciona — alguns mounts NFS antigos retornam no-op silencioso.

**Env var:** `TOGARE_DJEN_CB_STATE_PATH=/var/togare-djen/circuit-breaker.json` em ambos os containers (já hardcoded no `docker-compose.yml`).

## Customizações do EspoCRM

A pasta [../espocrm/custom](../espocrm/custom) é montada dentro do contêiner em `/var/www/html/custom/Espo/Modules/Custom`. Módulos Togare (`togare-core`, `togare-licensing`, etc., a partir da Story 1a.3) ficam aqui e são vistos pelo EspoCRM sem rebuild de imagem.

## Comandos úteis

```bash
docker compose exec espocrm bash                # shell no EspoCRM
docker compose exec mariadb mariadb -uroot -p   # acesso direto ao banco do CRM
docker compose exec caddy caddy validate --config /etc/caddy/Caddyfile  # lint do Caddyfile
docker compose restart espocrm                  # reinício rápido após alterar .env
docker compose restart caddy                    # recarregar Caddyfile alterado
```

## Smoke test pós-instalação

Após `docker compose up -d`, aguardar até `docker compose ps` mostrar todos os serviços com `running (healthy)` ou `running` (máx 2 min). Então rodar os 4 comandos abaixo — todos devem passar.

```bash
# 1) Todos os serviços estão healthy?
docker compose ps
# Esperado: mariadb/postgres/redis/espocrm/nextcloud como "running (healthy)";
#           caddy e espocrm-daemon como "running" (não têm healthcheck).

# 2) EspoCRM responde via Caddy com TLS 1.3?
curl -k --tls-max 1.3 https://localhost/ -o /dev/null -w "%{http_code}\n"
# Esperado: 200 ou 302 (EspoCRM redireciona para /#login quando não autenticado).

# 3) TLS 1.2 é rejeitado?
curl -k --tls-max 1.2 --tlsv1.2 https://localhost/ -o /dev/null -w "%{http_code}\n" 2>&1 | grep -Ei "handshake|alert|error"
# Esperado: mensagem de erro de handshake. Sem mensagem = TLS 1.2 passou (falha do teste).

# 4) Correlation id é injetado pelo Caddy?
curl -k -sI https://localhost/ | grep -i "x-togare-correlation-id"
# Esperado: linha "x-togare-correlation-id: <uuid>" na resposta.
```

Se algum passo falhar, consultar a seção abaixo.

## Troubleshooting

**Caddy não sobe / erro de bind na porta 80 ou 443.**
Outra aplicação está ocupando a porta. Em Windows: `netstat -ano | findstr ":80"` e `netstat -ano | findstr ":443"`. Pode ser IIS, Skype antigo, ou outro container. Parar o processo ou ajustar temporariamente o compose para `8080:80` e `8443:443`.

**Navegador mostra "conexão não é privada" em https://localhost.**
Esperado em dev local — Caddy usa CA interna. Seguir a seção "Aceitar o certificado TLS em dev local" acima.

**`curl -k` retorna erro de conexão recusada.**
Verificar se o Caddy está de pé: `docker compose logs -f caddy`. Comuns: erro de sintaxe no Caddyfile (corrigir + `docker compose restart caddy`) ou healthcheck do EspoCRM ainda pendente (aguardar ~60s no start).

**EspoCRM não liga (healthcheck eternamente starting).**
Ver logs: `docker compose logs -f espocrm`. Normalmente é erro de senha no `.env` com caracteres especiais (`$ " '`), ou MariaDB ainda não terminou de inicializar (Compose aguarda healthy via `depends_on`, deve auto-resolver em <30s).

**Nextcloud retorna 500 ou "trusted domains".**
Ver `NEXTCLOUD_TRUSTED_DOMAINS` no compose — precisa incluir `files.${TOGARE_DOMAIN}`. Já está configurado, mas se `TOGARE_DOMAIN` foi alterado após a primeira subida, rodar `docker compose exec -u www-data nextcloud php occ config:system:set trusted_domains 1 --value="files.<novo-dominio>"`.

**Validar que TLS 1.3 está ativo de verdade.**

```bash
curl -v --tls-max 1.3 https://localhost/ 2>&1 | grep -Ei "tlsv1.3|ssl connection"
# Esperado: linha "SSL connection using TLSv1.3 / ..."
```

**Validar o Caddyfile sem reiniciar.**

```bash
docker compose exec caddy caddy validate --config /etc/caddy/Caddyfile
docker compose exec caddy caddy fmt /etc/caddy/Caddyfile  # formatação canônica
```

**Reiniciar somente a camada web (sem perder bancos).**

```bash
docker compose restart caddy espocrm espocrm-daemon nextcloud
```

## Nextcloud bridge (Story 5.1)

Módulo `togare-nextcloud-bridge` consome o serviço `nextcloud:80` da stack via WebDAV/OCS para upload, download, soft-purge e restore de documentos.

**Configuração via env vars** em `docker/.env`:

| Var                         | Default                    | Descrição                                                                                                         |
| --------------------------- | -------------------------- | ----------------------------------------------------------------------------------------------------------------- |
| `TOGARE_NEXTCLOUD_BASE_URL` | `http://nextcloud:80`      | DNS interno Docker. Caddy NÃO entra no caminho — bridge fala direto com Apache do Nextcloud na rede `togare_net`. |
| `TOGARE_NEXTCLOUD_USER`     | `NEXTCLOUD_ADMIN_USER`     | Vazio = bridge usa o admin do `.env`. Em produção: criar user dedicado.                                           |
| `TOGARE_NEXTCLOUD_PASSWORD` | `NEXTCLOUD_ADMIN_PASSWORD` | Senha do user (admin password ou app password gerada via Settings → Security do Nextcloud).                       |

**MVP usa NEXTCLOUD_ADMIN_USER** — Decisão #6 da Story 5.1 (single-tenant, 1 escritório = 1 admin Nextcloud). Para Growth / produção, criar user dedicado:

```bash
# Dentro do container nextcloud:
docker compose exec --user www-data nextcloud php /var/www/html/occ user:add togare-bridge --display-name="Togare Bridge"
# Definir senha (ou usar --password-from-env=BRIDGE_PWD)
docker compose exec --user www-data nextcloud php /var/www/html/occ user:resetpassword togare-bridge

# Setar no docker/.env:
# TOGARE_NEXTCLOUD_USER=togare-bridge
# TOGARE_NEXTCLOUD_PASSWORD=<senha-gerada>

docker compose up -d espocrm  # recarrega env vars
```

**Path WebDAV canônico**: `http://nextcloud:80/remote.php/dav/files/<user>/togare/<logicalPath>`. URI lógica persistida em `Documento.nextcloudUri` (Story 5.2): `nextcloud://<logicalPath>` (sem host, sem user — bridge resolve).

O compose inclui `nextcloud` em `NEXTCLOUD_TRUSTED_DOMAINS` para fresh installs. Em stacks já inicializadas antes da Story 5.1, aplicar uma vez:

```bash
docker compose exec --user www-data nextcloud php /var/www/html/occ config:system:set trusted_domains 2 --value=nextcloud
```

**Soft-purge**: arquivos vão para `togare/.purged/<tombstoneId-32-hex>/<originalPath>`. Hard-delete só vem na Story 5.5 (`TogareBridgeHardDeleteJob`).

## Download de Documento (Story 5.3)

`GET /api/v1/Documento/action/download?id=<id>` valida ACL no PHP e despacha bytes via dois caminhos, controlados por env var `TOGARE_DOWNLOAD_USE_PHP_PROXY` (default `false`):

| Branch                         | Como funciona                                                                                                                                                                                                                                                                             | Quando usar                                                                                                            |
| ------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| **X-Accel-Redirect** (default) | PHP responde com header `X-Accel-Redirect: /internal-nextcloud/data/<user>/files/togare/<path>` + body vazio. Caddy intercepta via `handle_response @hasAccelRedirect` (Caddyfile) e serve bytes diretos do volume `nextcloud_data:/internal-nextcloud:ro`. **PHP-FPM nunca toca bytes.** | Default — NFR1 p95 ≤ 2s validado em Spike 1b.S1 Fase 1 (2026-04-24, sanity local).                                     |
| **PHP-proxy chunked**          | PHP usa cURL com callback de escrita, confirma HTTP 2xx antes de emitir headers/primeiro byte, lê do Nextcloud via `OcsApiClient::streamWebDav` e despacha em chunks de até 1 MB. **Body nunca em memória** (`memory_limit` irrelevante).                                                 | Fallback — promover apenas se bench VPS Fase 2 da Spike 1b.S1 (Epic 10) revelar p95 TTFB > 2.5s sustentado no X-Accel. |

**Trocar para PHP-proxy (operacional):**

```bash
# .env (na raiz do compose)
TOGARE_DOWNLOAD_USE_PHP_PROXY=true

docker compose up -d espocrm     # PHP-FPM relê env var
# Caddy NÃO precisa reload — handle_response simplesmente nunca dispara
# (header X-Accel-Redirect não é mais enviado pelo PHP).
```

Também ajustar `pm.max_children` ≥ 20 no `espocrm-php-overrides.ini` quando promover (workers ficam ocupados durante o download — sem ajuste, pool satura em pico de downloads concorrentes).

**Smoke de validação:**

```bash
# Login admin EspoCRM e curl com cookie de sessão. Cabeçalho X-Accel-Redirect
# DEVE estar AUSENTE da response (Caddy consome). Bytes íntegros via sha256sum.
curl -k -i -b 'auth-token=...; auth-username=admin' \
  "https://localhost/api/v1/Documento/{id}/action/download" \
  -o /tmp/baixado.pdf
sha256sum /tmp/baixado.pdf
```

**Audit log**: cada tentativa (autorizada ou negada) grava 1 row em `togare_audit_log` (`documento.downloaded` / `documento.download_denied` / `documento.download_failed`). Granularidade não-deduplicada (Decisão #7 — auditoria prefere redundância à omissão).

**Defesa em profundidade**: matcher Caddy é restritivo (`X-Accel-Redirect /internal-nextcloud/*` — só dispara para esse prefixo). Cliente externo NÃO consegue forjar `X-Accel-Redirect: /internal-nextcloud/etc/passwd` porque request headers do cliente nunca viram response headers do upstream.

## Backup e Restore

Container `togare-backup` (Story 1a.7) roda `restic` + `supercronic`. Faz backup diário às 02:00 (Brasília) de:

- **MariaDB** (banco do EspoCRM) via `mariadb-dump --single-transaction`.
- **Postgres** (banco do Nextcloud) via `pg_dump`.
- **Volumes** `espocrm_data` e `nextcloud_data` via snapshot restic.

Healthcheck do container falha se o último backup tem **mais de 26h** (sentinela `last-success.json` no repositório).

> **⚠️ AVISO CRÍTICO — `RESTIC_PASSWORD`**
>
> A variável `RESTIC_PASSWORD` no `docker/.env` é a **chave de descriptografia** do repositório restic. Sem ela:
>
> - Backups continuam sendo gerados, mas **não podem ser restaurados** — nem por você, nem pela Anthropic, nem pelo upstream do restic. É AES-256.
> - Trocar a senha **invalida todos os backups anteriores**.
>
> **Guarde uma cópia FORA do servidor** (gerenciador de senhas do Sócio, cofre físico, etc.). Use 20+ caracteres alfanuméricos — `$ " ' \` `` ` `` `{ }` quebram parsing do Compose e o entrypoint do container rejeita esses caracteres.

### Verificar saúde do backup

```bash
docker compose ps togare-backup
# Esperado: "running (healthy)" depois do primeiro backup ter rodado.
# Antes do primeiro backup: "running (health: starting)" (start_period 25h).

# Ver último sucesso (timestamp + ids + tamanhos):
cat ./backup-data/last-success.json | jq
```

> **Painel TogareHealth (Story 10.2 / FR41).** O tile **Backup** do painel
> in-app (Sócio/Admin) lê **a mesma** sentinela `last-success.json`. Por isso
> os serviços `espocrm` e `espocrm-daemon` montam `${TOGARE_BACKUP_LOCAL_PATH}`
> em `/var/backups/togare` **read-only** (`:ro` no `docker-compose.yml`) — o
> app só lê, nunca escreve/apaga backup. Limiar idêntico ao healthcheck do
> container (**26h**): verde ≤26h, vermelho >26h, amarelo "ainda não rodou"
> quando a sentinela não existe (instalação nova — o `start_period: 25h` do
> container cobre o primeiro ciclo).

### Disparar backup manual

Pré-requisito: stack completa `up` (mariadb e postgres precisam estar healthy).

```bash
docker compose up -d                                                # se ainda não estiver
docker compose run --rm togare-backup /app/backup.sh
# Backup completa em <2min com dados pequenos. Imprime log JSON event=backup.completed.
```

### Listar snapshots

```bash
docker compose run --rm togare-backup restic snapshots
docker compose run --rm togare-backup restic stats               # tamanho total do repo
```

### Restaurar

```bash
docker compose down                                # PARE a stack antes
./docker/scripts/restore.sh --latest               # restaura último snapshot
./docker/scripts/restore.sh --snapshot abc12345    # restaura snapshot específico
./docker/scripts/restore.sh --dry-run              # mostra o que faria
```

O script pede confirmação textual (digite `RESTAURAR`) e re-sobe a stack. Veja docstring no topo de [scripts/restore.sh](scripts/restore.sh) para o fluxo completo.

### Aviso de ownership (Linux produção)

`restic` roda como `root` por default. Os arquivos em `./backup-data/` ficam com owner `root:root` no host. Em servidor Linux onde o admin não é root, ele não consegue `ls`/`du`/deletar sem `sudo`. Mitigação:

```bash
sudo chown -R 1000:1000 ./backup-data/   # rodar uma vez após primeira subida
```

(Alternativa mais defensiva: rodar o container como `user: "1000:1000"` no compose. Hoje fica como melhoria Growth se houver atrito real.)

### Teste trimestral de restore (NFR21)

A cada 3 meses o Sócio (ou o admin operacional) deve validar restore em ambiente **staging** (nunca em produção). Checklist:

1. **Provisionar staging:** clonar repo + copiar `docker/.env` (com a `RESTIC_PASSWORD` correta).
2. **Copiar `./backup-data/`** de produção para staging (rsync, ou snapshot do disco).
3. **Subir parcialmente:** `docker compose up -d togare-backup` (apenas para acessar restic, sem subir bancos).
4. **Listar snapshots:** `docker compose run --rm togare-backup restic snapshots`. Confirmar que existe pelo menos 1 snapshot recente (<24h).
5. **Restaurar:** `./docker/scripts/restore.sh --latest`. Acompanhar os 7 passos.
6. **Smoke test:** seguir os 4 comandos da seção "Smoke test pós-instalação" acima. Logar em EspoCRM e Nextcloud, conferir 1 cliente / 1 arquivo de teste.
7. **Registrar:** anotar data + resultado em planilha interna do escritório (responsabilidade operacional, fora da automação).

**Critério objetivo de "passou"** (mesmo do gate da Story 10.6): contadores de registros-chave pós-restore batem com o backup (clientes/processos/prazos), audit log termina no timestamp do backup, ≥1 documento Nextcloud baixável, e os 4 comandos do smoke pós-instalação OK. **Owner: Felipe (PO).** Procedimento **manual** no MVP do piloto — sem automação/agendamento (consistente com NFR23: uptime/operação self-hosted é do escritório).

Se algum passo falhar: abrir issue no repo + investigar antes do próximo ciclo trimestral. Não deixar passar.

### Variáveis relevantes do `.env`

| Variável                   | Default         | Efeito                                                    |
| -------------------------- | --------------- | --------------------------------------------------------- |
| `RESTIC_VERSION`           | `0.17.3`        | Pin da imagem base. Upgrade major exige `restic migrate`. |
| `BACKUP_CRON_EXPRESSION`   | `"0 2 * * *"`   | Horário do backup diário (TZ=America/Sao_Paulo).          |
| `PRUNE_CRON_EXPRESSION`    | `"0 3 * * 0"`   | Manutenção semanal (forget cumulativo + check 5%).        |
| `BACKUP_RETENTION_DAYS`    | `30`            | Quantos snapshots diários manter.                         |
| `RESTIC_PASSWORD`          | (vazio)         | OBRIGATÓRIO. Sem isso, o entrypoint falha.                |
| `TOGARE_BACKUP_LOCAL_PATH` | `./backup-data` | Path no host pro repo restic (gitignored).                |

## Operação: atualizar, reverter e restaurar (runbook)

> Para o **Sócio/Admin ou secretária** — não precisa saber Docker/Linux, só
> copiar e colar os comandos no terminal aberto na pasta do projeto. Sempre que
> uma instrução diz "rode", copie a linha inteira e aperte Enter. Os scripts
> guardam um log em `docker/logs/` (você não precisa abrir; é para suporte).

### Atualizar o sistema (`update.sh`)

**Quando rodar:** quando houver uma versão nova do Togare (alguém da equipe
técnica avisou e atualizou as versões no `docker/.env`).

**O que ele faz, em ordem:** (1) confere pré-condições → (2) **faz um backup
automático** (rede de segurança) → (3) baixa as imagens novas → (4) sobe a
stack → (5) reorganiza o EspoCRM (rebuild + limpa cache) → (6) testa se o site
respondeu. **Tempo típico:** 3 a 8 minutos (a maior parte é o backup).

```bash
cd ~/projetos/nextcloud-crm
./docker/scripts/update.sh                 # atualização real
./docker/scripts/update.sh --dry-run       # só MOSTRA o que faria, não muda nada
```

**Sinal de que deu certo (OK):** a última linha é

```
✓ [....] update.sh concluído com sucesso.
```

**Sinal de problema (NOK):** aparece `✗ ... FALHA no passo: ...` seguido de um
bloco **ROLLBACK MANUAL**. Não entre em pânico — o backup do passo 2 já foi
feito. Siga a seção "Reverter" abaixo (os comandos exatos aparecem na tela).

**Aviso de módulo desatualizado:** se o update mostrar
`⚠ DRIFT: módulo togare-... instalado=X fonte=Y`, ele **imprime os 3 comandos
exatos** para aplicar a atualização daquele módulo. O `update.sh` **não** faz
isso sozinho de propósito (atualizar módulo é decisão da equipe técnica). Copie
e rode os comandos indicados, ou peça à equipe técnica.

### Reverter (rollback) após um update que deu errado

O rollback é **manual e documentado** (nunca automático — assim você decide).
O ponto de retorno é o backup que o `update.sh` fez no passo 2. Sequência:

```bash
cd ~/projetos/nextcloud-crm/docker
git checkout -- .env          # volta as versões de imagem para as anteriores
                              # (se não usa git, edite docker/.env à mão)
docker compose down           # derruba a stack
./scripts/restore.sh --latest # restaura o backup mais recente (o do passo 2)
```

Depois, confira: `docker compose ps` (tudo `healthy`) e abra
`https://localhost/` no navegador (deve carregar o login do EspoCRM).
**Tempo típico de rollback:** 5 a 20 min (depende do tamanho dos dados; NFR22
exige ≤ 4h mesmo em bases grandes).

### Restaurar de um backup (`restore.sh`)

Use quando precisa recuperar a stack a partir de um backup (desastre, perda de
dados, ou o rollback acima).

**Pré-checklist (confira ANTES):**

- A stack está **parada** (`docker compose down`). O script recusa rodar com os
  serviços de dados no ar — isso é proposital, protege contra sobrescrever
  dados ao vivo.
- A `RESTIC_PASSWORD` no `docker/.env` é a **mesma** com que os backups foram
  feitos. Sem ela os backups são irrecuperáveis (é a chave de criptografia).
- Espaço em disco livre ≥ ~2× o tamanho da base.

```bash
cd ~/projetos/nextcloud-crm
docker compose down
./docker/scripts/restore.sh --latest            # restaura o último snapshot
./docker/scripts/restore.sh --snapshot abc12345 # restaura um snapshot específico
./docker/scripts/restore.sh --dry-run --latest  # só mostra o que faria
```

O script **pede confirmação**: digite `RESTAURAR` (maiúsculas) e Enter. Ele
roda 7 passos numerados (`→ 1/7 ...` até `→ 7/7 ...`).

**Validação pós-restore (OK):** última linha `✓ Restore concluído.`; rode os 4
comandos do "Smoke test pós-instalação" acima; logue no EspoCRM e no Nextcloud
e confira 1 cliente e 1 arquivo de teste. **Lembrete importante:** os gatilhos
append-only do `togare_audit_log` somem quando o banco é recriado — reaplique:

```bash
bash docker/scripts/audit-log-lockdown.sh        # idempotente, pode rodar 2×
```

Pós-restore o cache TPU (Redis) repovoa sozinho com o uso; se necessário,
force pelo menu admin do EspoCRM → Sync TPU.

### Teste trimestral de restore (NFR21)

É um **gate de confiança**, owner **Felipe**. Procedimento completo + critério
objetivo de "passou" na seção **"Teste trimestral de restore (NFR21)"** acima
(dentro de "Backup e Restore"). Resumo: a cada 3 meses, restaurar um backup em
staging e confirmar que os contadores batem + 1 documento abre. Manual no MVP.

### Troubleshooting (erros mais prováveis)

| Sintoma                                                              | Causa provável                                               | O que fazer                                                                                                      |
| -------------------------------------------------------------------- | ------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------- |
| `update.sh` aborta no passo 1 com `volume *_mariadb_data` não existe | stack nunca subiu                                            | Use `docker compose up -d` (primeira subida), não o update                                                       |
| `update.sh` aborta no passo 2 ("backup não confirmou sucesso")       | togare-backup com problema (ex.: `RESTIC_PASSWORD` vazia)    | Veja `docker compose logs togare-backup`; corrija o `.env`; rode de novo                                         |
| `restore.sh` recusa com "serviços ainda rodando"                     | stack no ar                                                  | `docker compose down` e rode de novo (proteção proposital)                                                       |
| `restore.sh`: "repositório restic está vazio"                        | nenhum backup feito ainda, ou `RESTIC_PASSWORD`/path errados | Confira `docker/.env` e `./backup-data/`; rode um backup manual antes                                            |
| Site não abre após update/restore                                    | algum container não ficou `healthy`                          | `docker compose ps` e `docker compose logs -f <serviço>` do que não estiver `healthy`                            |
| Erros estranhos no EspoCRM após update                               | cache antigo                                                 | `docker compose exec espocrm php command.php rebuild && docker compose exec espocrm php command.php clear-cache` |

## Pendências conhecidas (fora do escopo desta story)

- Pre-commit hooks via lefthook — **Story 1a.2**.
- Tile "Backup" no painel TogareHealth (consome `last-success.json` via `BackupHealthCheckProvider`) — **Story 10.2 (HealthPanel)**.
- Rate limit no Caddy por tenant/IP — **Story 2.5**.
- X-Accel-Redirect para download binário via Nextcloud — **Story 5.3** (depende do Spike 1b.S1).
- Subdomínio `portal.<dominio>` dedicado ao Portal do Cliente — **Story 7a.x** (quando portal ganhar rota própria).
