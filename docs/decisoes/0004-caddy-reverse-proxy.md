# ADR 0004 — Caddy como reverse proxy único com TLS 1.3 automático e X-Accel-Redirect

**Data:** 2026-04-22
**Status:** Aceito condicionalmente — receita funcional validada 2026-04-24 via sanity local (Spike 1b.S1 Fase 1); NFR1 quantitativo (p95 ≤ 2s) pendente bench VPS na Fase 2 (Epic 10). HSTS condicional adicionado na Story 2.5 (2026-04-26)

## Contexto

O Togare precisa de um reverse proxy único para:

- Terminar TLS em todas as conexões externas (NFR7 exige TLS 1.3 exclusivo).
- Rotear tráfego entre EspoCRM (CRM principal + Portal nativo + endpoints custom), Nextcloud e, futuramente, o painel TogareHealth.
- Rate-limit de autenticação (NFR11: 5 tentativas em 15min).
- Servir download de arquivos grandes sem bloquear PHP — PDFs processuais de 200 MB são plausíveis, e streamar via `readfile()` explode `memory_limit` + trava workers PHP-FPM.

O ICP é escritório pequeno sem TI dedicada. Configuração precisa ser mínima — admin TI freelancer (jornada 4, Pedro) monta o ambiente uma vez.

## Decisão

1. **Caddy v2** como reverse proxy único da stack docker-compose.
2. **TLS 1.3 automático** via Let's Encrypt — Caddy gerencia certificados sem boilerplate. TLS 1.2 e inferiores rejeitados no Caddyfile. Em ambientes internos (dev, homologação), Caddy usa CA interna.
3. **HTTP/3** habilitado por default (ganho de performance em redes móveis sem custo).
4. **Rate limit por IP via Caddy: deferido para Growth** — requer build custom (xcaddy + plugin `mholt/caddy-ratelimit`). NFR11 atendido pela camada por usuário em togare-rbac (Story 2.5). Reativar quando custo operacional do build custom for justificado por bench/incidente. A imagem oficial `caddy:${CADDY_VERSION}-alpine` não inclui esse plugin.
5. **Download de binários via `X-Accel-Redirect`:** ACL valida no PHP, resposta retorna header indicando caminho interno → Caddy serve bytes diretamente do filesystem montado do Nextcloud. Preserva controle de acesso no EspoCRM sem sobrecarregar PHP.
6. **Header `X-Togare-Correlation-Id`** gerado pelo Caddy como UUID v4 se ausente no request e propagado para response (ver ADR 0007).

**Plano B documentado (caso Spike 1b.S1 falhe):** PHP-proxy temporário com `readfile()` + `ob_end_clean()` + chunks de 1 MB. Performance pior, mas destrava funcionalidade. Acionado via config flag em `togare-nextcloud-bridge`; revisão em nova iteração.

## Consequências

- ✅ Configuração mínima para escritório sem TI — Caddy "just works" para TLS e HTTPS.
- ✅ NFR7 (TLS 1.3 exclusivo) atendido pela config padrão do Caddy.
- ✅ HTTP/3 disponível sem esforço adicional.
- ⚠️ Rate limit IP via Caddy **deferido para Growth** — Story 2.5 entregou camada por usuário (NFR11 PRD-compliant). Camada IP é upgrade-path quando volume de ataques justificar custo do build custom (xcaddy + caddy-ratelimit).
- ✅ Download de PDF grande não trava PHP-FPM — bytes servidos pelo Caddy, ACL preservada.
- ⚠️ X-Accel-Redirect requer setup cuidadoso de `internal` directives + mount read-only do volume Nextcloud no container Caddy. Documentar no README do `togare-nextcloud-bridge`.
- ⚠️ Decisão depende de **Spike 1b.S1** passar (p95 ≤ 2s PDF 200MB, 10 downloads concorrentes, baseline 4vCPU/8GB/SSD NVMe). Se falhar, fallback PHP-proxy entra; ADR é revisado com nota de revisão.
- ⚠️ Alternativas avaliadas: Traefik (config via labels Docker; mais verboso), NGINX (config extensa + certbot manual). Caddy vence por simplicidade operacional no ICP.

## Validação funcional (Fase 1 — sanity local 2026-04-24)

Sanity local rodou no laptop do Felipe (Windows 11 + Docker Desktop + WSL2) e confirmou que a receita X-Accel equivalente **funciona end-to-end** no Caddy 2.8 com EspoCRM 9.3 + Nextcloud 31:

- **AC2 (X-Accel via Caddy):** 1 download de 200 MiB completou em 2.0s total / TTFB 30 ms / bytes íntegros (SHA-256 idêntico ao original).
- **AC3 (PHP-proxy fallback):** mesmo arquivo via rota `&use_proxy=php` completou em 1.2s total / TTFB 51 ms / bytes íntegros. Plano B comprovadamente viável.

**Receita adotada** (derivada da Alternativa A das Dev Notes da Story 1b.S1 — a receita primária do spike não funcionou no Caddy 2.8):

```caddy
reverse_proxy upstream:80 {
    # Matcher restrito a /internal-files/* — impede que headers malformados
    # ou inesperados do upstream disparem o file_server em paths arbitrários.
    @x header X-Accel-Redirect /internal-files/*
    handle_response @x {
        root * /
        rewrite * {rp.header.X-Accel-Redirect}
        file_server
    }
}
```

O `file_server` tem que ficar DENTRO do `handle_response`. A variante com `vars sub_request true` + `handle_path /prefix/*` externo consumiu o header mas não re-entrou no pipeline — response chegou com `Content-Length: 0`. Essa receita vira referência canônica da Story 5.3 (togare-nextcloud-bridge).

Bytes reais não foram medidos para p95 ≤ 2s sob 10 conexões concorrentes — isso exige hardware baseline (VPS Linux) e fica para Fase 2 (Epic 10, story 10.X-bench-nfr-spikes a criar). Se Fase 2 reprovar, plano B ([ADR 0004b](./drafts/0004b-php-proxy-fallback.md)) vira definitivo — controller do spike já provou que a troca é só uma query param (`use_proxy=php`), sem mudança de contrato.

Relatório completo: [1b-S1-spike-x-accel-redirect-relatorio.md](../../_bmad-output/implementation-artifacts/1b-S1-spike-x-accel-redirect-relatorio.md).
