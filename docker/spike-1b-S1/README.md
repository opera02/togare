# Spike 1b.S1 — sanity local X-Accel-Redirect (Caddy v2)

> **⚠️ THROWAWAY — uso restrito ao Spike 1b.S1 Fase 2 (bench VPS, Epic 10).**
> **NÃO subir esses recursos no compose principal.** Será deletado após Fase 2 fechar.
> Compose isolado em `docker-compose.spike.yml` (não `docker-compose.yml` principal).
> Módulo `togare-spike-s1` é montado APENAS via volume deste compose.

## O que este ambiente valida

Fase 1 (sanity local, esta story): receita X-Accel-Redirect equivalente no
Caddy v2 funciona end-to-end — PDF de 200 MB completa via
`reverse_proxy` + `handle_response` + `file_server` sem erro.
Valida também o plano B (PHP-proxy chunked) fim-a-fim para ter smoke do
fallback antes da Fase 2.

Fase 2 (DIFERIDO — Epic 10, story 10.X-bench-nfr a criar): bench quantitativo
em VPS baseline (4vCPU/8GB/SSD NVMe Ubuntu 22.04 LTS) — 10 VUs × 5min ×
PDF 200 MB, p95 TTFB ≤ 2s. Script k6 já preparado em `k6-script.js` para
ser reutilizado.

**Esta Fase 1 NÃO mede NFR1 quantitativo.** Laptop Windows + Docker Desktop +
WSL2 ≠ hardware baseline; números servem apenas como referência informativa.

## Pré-requisitos

- Docker Desktop ≥4.x rodando (Windows 11 + WSL2 backend).
- Portas 9080/tcp livres no host (HTTP plain, usada pelo sanity padrão).
  Opcionalmente também 8443/tcp+udp se quiser testar via HTTPS.
- `curl` disponível no PATH (Windows: Git Bash já traz).
- ~2 GB de RAM livres e ~1 GB de espaço em disco no volume Docker.

> **Por que HTTP e não HTTPS no sanity:** o `curl` do Windows (schannel) rejeita
> o cert auto-assinado do Caddy (`tls internal`) mesmo com `-k`. Em produção
> (VPS), o Caddy gera certificado Let's Encrypt real e esse problema não existe.
> Para o spike local, HTTP plain em porta não-443 é o caminho sem fricção.

## Passo 1 — Subir o ambiente

Do repo root:

```bash
docker compose -f docker/spike-1b-S1/docker-compose.spike.yml up -d
```

Aguardar ~60s para os healthchecks ficarem verdes. Verificar:

```bash
docker compose -f docker/spike-1b-S1/docker-compose.spike.yml ps
```

Esperado: `mariadb-spike`, `espocrm-spike`, `caddy-spike` como `Up (healthy)`
ou `Up`; `nextcloud-spike` pode demorar a ficar healthy mas não bloqueia.

## Passo 2 — Gerar PDF de teste 200 MB no volume Nextcloud

```bash
docker compose -f docker/spike-1b-S1/docker-compose.spike.yml exec nextcloud-spike \
  sh -c 'dd if=/dev/urandom of=/var/www/html/data/test-200mb.pdf bs=1M count=200'
```

Verificar:

```bash
docker compose -f docker/spike-1b-S1/docker-compose.spike.yml exec nextcloud-spike \
  ls -lh /var/www/html/data/test-200mb.pdf
```

Esperado: aprox. `200M`.

> **Não precisa ser um PDF válido.** Caddy serve bytes opacos; o teste é throughput
> / integridade de transferência, não parsing. Bytes aleatórios cobrem o caso.

## Passo 3 — Garantir que o EspoCRM reconhece o módulo spike

Limpar cache + rebuild (o EspoCRM precisa varrer `custom/Espo/Modules/` para
descobrir `TogareSpikeS1` e sua rota):

```bash
docker compose -f docker/spike-1b-S1/docker-compose.spike.yml exec espocrm-spike \
  php clear_cache.php
docker compose -f docker/spike-1b-S1/docker-compose.spike.yml exec espocrm-spike \
  php rebuild.php
```

Smoke rápido (sem download — só checar que a rota responde):

```bash
curl -k -i "http://localhost:9080/api/v1/Spike/action/download?path=nao-existe"
```

Esperado: HTTP 403 com JSON `{"message":"Path negado..."}` — confirma que a rota
chegou ao controller e o mock ACL funcionou.

## Passo 4 — Sanity AC2 (X-Accel-Redirect via Caddy)

```bash
curl --no-progress-meter \
     "http://localhost:9080/api/v1/Spike/action/download?path=test-200mb.pdf" \
     -o /tmp/out-xaccel.pdf \
     -w "TTFB: %{time_starttransfer}s\nTotal: %{time_total}s\nSize: %{size_download} bytes\nSpeed: %{speed_download}/s\n" \
     2>&1 | tee sanity-results/sanity-xaccel.txt
```

Validações obrigatórias:

1. `wc -c /tmp/out-xaccel.pdf` retorna ~209715200 bytes (±1 MB).
2. `cmp` contra o arquivo no container confirma bytes idênticos:
   ```bash
   docker compose -f docker/spike-1b-S1/docker-compose.spike.yml exec nextcloud-spike \
     sha256sum /var/www/html/data/test-200mb.pdf
   sha256sum /tmp/out-xaccel.pdf
   ```
   Os dois hashes devem ser iguais.
3. `docker compose -f docker/spike-1b-S1/docker-compose.spike.yml logs caddy-spike | grep -i "handle_response\|internal-files"`
   mostra o Caddy servindo do mount interno.
4. `docker compose -f docker/spike-1b-S1/docker-compose.spike.yml logs espocrm-spike`
   mostra request retornando rápido (<50 ms) — confirma que o PHP não leu bytes.

**Se AC2 falhar** (curl retorna erro, body vazio, ou hashes não batem): tentar
as receitas alternativas A e B descritas em `Caddyfile.spike` (comentários no
fim do arquivo) e nas Dev Notes da story. Time-box: 1 dia útil para fazer
alguma variante funcionar. Se nenhuma funcionar, registrar no relatório e
promover o Plano B (ADR 0004b).

## Passo 5 — Sanity AC3 (PHP-proxy fallback)

```bash
curl --no-progress-meter \
     "http://localhost:9080/api/v1/Spike/action/download?path=test-200mb.pdf&use_proxy=php" \
     -o /tmp/out-proxy.pdf \
     -w "TTFB: %{time_starttransfer}s\nTotal: %{time_total}s\nSize: %{size_download} bytes\nSpeed: %{speed_download}/s\n" \
     2>&1 | tee sanity-results/sanity-php-proxy.txt
```

Validações obrigatórias:

1. Tamanho ~209715200 bytes e hash idêntico ao original (mesmo comando do Passo 4).
2. Log do `espocrm-spike` mostra a request ocupando um worker durante vários
   segundos — esperado para PHP-proxy.
3. TTFB provavelmente maior que o da AC2 — esperado; registrar número no
   relatório.

## Passo 6 — Registrar resultados no relatório

Salvar os dois arquivos de sanity (`sanity-xaccel.txt` e `sanity-php-proxy.txt`)
em `sanity-results/` e copiar o conteúdo integral para o relatório:

`_bmad-output/implementation-artifacts/1b-S1-spike-x-accel-redirect-relatorio.md`

Preencher seções:

- "Hardware Fase 1" (seu laptop: modelo, CPU, RAM, tipo de disco).
- "Versões": `curl --version` + versões do compose já pinadas (Caddy 2.8, EspoCRM 9.3, Nextcloud 31).
- "Receita Caddy v2 que funcionou": copiar o bloco de config que passou.
- "Sanity X-Accel" e "Sanity PHP-proxy": colar resultados dos `.txt`.
- "Comparação informativa": tabela com TTFB / Total / Worker FPM dos dois cenários.

## Passo 7 — Derrubar o ambiente (limpa tudo)

```bash
docker compose -f docker/spike-1b-S1/docker-compose.spike.yml down -v
```

`-v` remove volumes (`nextcloud_data_spike`, `caddy_data_spike`, `caddy_config_spike`).
O `mariadb-spike` já usa `tmpfs`, então não deixa rastro.

**Não faz `git rm`** do diretório `docker/spike-1b-S1/` nem de `espocrm/togare-spike-s1/`
— ambos permanecem no repo até a Fase 2 fechar (Epic 10, story 10.X-bench-nfr).
Ver Task 10 da story 1b.S1 para detalhes do cleanup parcial.

## Troubleshooting

- **HTTPS handshake falha no curl:** `tls internal` usa CA do Caddy auto-gerada;
  `-k` no curl é obrigatório. Em produção nunca usar `-k`.
- **Porta 8443 ocupada:** alguém já tem outro serviço lá. Liberar ou mudar o
  mapeamento de portas no `docker-compose.spike.yml`.
- **EspoCRM retorna 404 na rota `/api/v1/Spike/...`:** cache não foi reconstruído.
  Rodar `php clear_cache.php` + `php rebuild.php` no container (Passo 3).
- **HTTP/3 instável (curl --http3 intermitente):** desabilitar QUIC para isolar
  — no `Caddyfile.spike`, trocar `protocols h1 h2 h3` por `protocols h1 h2`
  e re-subir. Documentar no relatório.
- **Download trava em Docker Desktop (virtio-fs):** é overhead conhecido. Não
  invalida a receita; apenas anotar no relatório como pendência para Fase 2
  validar em Linux nativo.

## Arquivos deste diretório

- `docker-compose.spike.yml` — compose isolado (4 services).
- `Caddyfile.spike` — receita Caddy v2 do X-Accel equivalente + alternativas.
- `k6-script.js` — script de bench pré-preparado para Fase 2 (não executado nesta story).
- `sanity-results/` — saída dos sanity runs (`sanity-xaccel.txt`, `sanity-php-proxy.txt`).
- `README.md` — este arquivo.
