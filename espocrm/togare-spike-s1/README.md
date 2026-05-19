# TogareSpikeS1

> **⚠️ THROWAWAY — uso restrito ao Spike 1b.S1 (sanity + Fase 2 bench em VPS, Epic 10).**
> **NÃO subir esses recursos no compose principal.** Será deletado completamente após
> a Fase 2 fechar. Módulo montado APENAS via volume do compose isolado
> `docker/spike-1b-S1/docker-compose.spike.yml`.

## O que faz

Controller throwaway `TogareSpike` (URL pública `/api/v1/Spike/action/download`) usado
para validar a receita X-Accel-Redirect equivalente no Caddy v2 ([ADR 0004](../../docs/decisoes/0004-caddy-reverse-proxy.md))
e seu plano B PHP-proxy ([ADR 0004b draft](../../docs/decisoes/drafts/0004b-php-proxy-fallback.md))
antes da Story 5.3 (togare-nextcloud-bridge) consumir o contrato validado.

Comportamento:

- `GET /api/v1/Spike/action/download?path=test-200mb.pdf` → mock ACL + retorna
  `X-Accel-Redirect: /internal-files/data/test-200mb.pdf` com body vazio. O Caddy
  (via `handle_response`) serve bytes direto do volume `nextcloud_data_spike`
  montado read-only.
- `GET /api/v1/Spike/action/download?path=test-200mb.pdf&use_proxy=php` → mesmo
  endpoint, mas streama via PHP `fread()` em chunks de 1 MB (plano B). Worker
  FPM fica ocupado durante todo o download — esperado.

## Como instalar

Não se instala. Apenas montado como volume via
[docker/spike-1b-S1/docker-compose.spike.yml](../../docker/spike-1b-S1/docker-compose.spike.yml):

```yaml
volumes:
  - ../../espocrm/togare-spike-s1/src/files/custom/Espo/Modules/TogareSpikeS1:/var/www/html/custom/Espo/Modules/TogareSpikeS1
```

Se o EspoCRM não detectar a rota do módulo automaticamente ao subir pela primeira
vez, executar limpeza de cache dentro do container:

```bash
docker compose -f docker/spike-1b-S1/docker-compose.spike.yml exec espocrm-spike \
  php clear_cache.php
docker compose -f docker/spike-1b-S1/docker-compose.spike.yml exec espocrm-spike \
  php rebuild.php
```

## Entidades expostas

Nenhuma. Controller throwaway sem schema, sem migration, sem job.

## Hooks disparados / consumidos

Nenhum. Não é lifecycle entity; é apenas um endpoint HTTP de validação técnica.

## Como testar

Ver [docker/spike-1b-S1/README.md](../../docker/spike-1b-S1/README.md) — seções
"Sanity X-Accel" e "Sanity PHP-proxy". Testes unitários/integration **não se
aplicam** (spike valida config real Caddy + mount filesystem; mock perderia o
ponto). Resultado do sanity registrado em
[_bmad-output/implementation-artifacts/1b-S1-spike-x-accel-redirect-relatorio.md](../../_bmad-output/implementation-artifacts/1b-S1-spike-x-accel-redirect-relatorio.md).

## Observação sobre convenções

- R1 (validate-togare-naming) passa: namespace `Espo\Modules\TogareSpikeS1\...` e
  classe `TogareSpike` cumprem o prefixo Togare.
- A URL `/Spike/action/download` é roteada via [Resources/routes.json](./src/files/custom/Espo/Modules/TogareSpikeS1/Resources/routes.json)
  com `controller: TogareSpike` + `noAuth: true` (aceitável apenas porque este
  módulo NÃO entra no compose principal — confirmado em Task 10.4 do Spike 1b.S1).
