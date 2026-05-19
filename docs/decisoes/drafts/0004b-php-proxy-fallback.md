# ADR 0004b (draft) — PHP-proxy como fallback para download de binários grandes

**Data:** 2026-04-24 (status final atualizado 2026-04-25)
**Status:** Não promovido — receita primária Caddy `handle_response + file_server` validada em sanity local 2026-04-24 (Spike 1b.S1 Fase 1). Bench VPS Fase 2 (Epic 10) reavalia se houver regressão funcional ou quantitativa.

> **Histórico:** este draft existiu **antes** da Spike 1b.S1 rodar (regra do PM John, epics.md linha 1806).
> O propósito era ter o plano B redigido em momento calmo, caso a Spike falhasse em Fase 1 (sanity local) ou Fase 2 (bench VPS, Epic 10).
> A Fase 1 PASSOU em 2026-04-24 — receita primária do [ADR 0004](../0004-caddy-reverse-proxy.md) confirmada. Este documento permanece arquivado em `drafts/` como contexto histórico do plano B caso a Fase 2 revele regressão de performance.

## Contexto

Complementa o [ADR 0004](../0004-caddy-reverse-proxy.md), que escolheu Caddy v2 como reverse proxy único da stack
com a aposta de servir downloads de binários grandes (PDFs processuais ~200 MB) através de uma receita
equivalente ao `X-Accel-Redirect` do nginx (`reverse_proxy` + `handle_response` + `file_server`).

A aposta do 0004 carrega dois riscos:

1. **Risco funcional:** Caddy v2 não tem `X-Accel-Redirect` nativo; a receita equivalente usa 3 primitivas
   combinadas. Se a combinação não funcionar em produção ou exigir plugins fora do core do Caddy 2.8,
   a receita é inviável.
2. **Risco quantitativo (NFR1):** mesmo funcionando, p95 TTFB pode ficar >2s sob 10 downloads concorrentes
   de PDF 200 MB em hardware baseline (4vCPU/8GB/SSD NVMe Ubuntu 22.04).

Se **qualquer** um desses riscos se materializar, o Togare precisa servir os mesmos downloads via outra rota
sem refazer o contrato funcional do `togare-nextcloud-bridge` (Story 5.3). Este ADR descreve essa rota
alternativa, já desenhada, para ser ativada via feature flag.

Motivação secundária: streamar via `readfile()` no PHP-FPM explode `memory_limit` e trava workers se feito
sem chunking. A solução aqui inclui **chunking de 1 MB + buffers flushed** para manter memória baixa.

## Decisão alternativa

Se acionada, a fallback:

1. **EspoCRM continua validando ACL** no `togare-nextcloud-bridge`. Sem mudança no contrato ACL.
2. **EspoCRM lê o arquivo do filesystem** (volume Nextcloud montado read-only no container EspoCRM)
   e serve bytes via PHP com chunks de 1 MB:

   ```php
   // togare-nextcloud-bridge → Controller::getActionDownload
   \header('Content-Type: application/pdf');
   \header('Content-Length: ' . \filesize($absPath));
   \header('X-Accel-Buffering: no');  // precaução — sem buffering em proxies intermediários
   \ob_end_clean();                    // evita buffer PHP inflando memória
   \set_time_limit(0);                 // download pode levar >60s em PDFs grandes
   $handle = \fopen($absPath, 'rb');
   while (!\feof($handle)) {
       echo \fread($handle, 1024 * 1024);
       \flush();
   }
   \fclose($handle);
   exit;
   ```

3. **Caddy fica como reverse proxy transparente** — mesma config do ADR 0004 para TLS 1.3, HTTP/3,
   correlation id, rate limit. Apenas a receita `handle_response` sai do caminho de download.
4. **Feature flag em `togare-nextcloud-bridge`:** `USE_PHP_PROXY=true` em `.env` (default `false` se
   X-Accel funcionar; `true` se fallback acionado). Troca implementação sem refactor de schema/API/DB.
5. **Pool PHP-FPM ajustado:** `pm.max_children` ≥ 20 (vs. default 5) para absorver downloads simultâneos
   sem bloquear outras rotas do CRM. Documentar no `docker/docker-compose.yml` da stack principal.
6. **Limite superior de tamanho:** downloads via PHP-proxy ficam capados em 500 MB (`Content-Length` check).
   Acima disso, redirect para link compartilhado direto do Nextcloud Web (rota fora do bridge).
7. **`togare-backup` não é afetado** — ele já lê direto do volume montado read-only; nenhuma dependência
   do bridge.

## Consequências

- ✅ **Destrava Story 5.3** sem bloquear o MVP se X-Accel falhar funcional ou quantitativamente.
- ✅ **Contrato funcional preservado** — `togare-nextcloud-bridge` mantém a mesma API; Portal do Cliente,
  EspoCRM backoffice e demais consumidores não precisam ser tocados.
- ✅ **Feature flag reversível** — se uma versão futura do Caddy (2.9+) consertar a receita,
  volta-se para X-Accel virando o flag.
- ✅ **Memória PHP controlada** — chunks de 1 MB + `ob_end_clean()` mantêm `memory_limit` irrelevante.
- ⚠️ **Workers PHP-FPM ocupados durante todo o download** — escalar pool (`pm.max_children` ≥ 20) +
  monitorar em HealthPanel (Story 10.2). Se o escritório tiver pico de downloads concorrentes + uso
  normal do CRM, pool pode saturar — alerta operacional obrigatório.
- ⚠️ **Latência ligeiramente maior** — Caddy → PHP-FPM → filesystem vs. Caddy → filesystem direto.
  Estimativa baseada em `readfile` chunked: TTFB +50-150ms, total +5-10% do tempo de transferência.
  NÃO é ideal para NFR1 (p95 ≤ 2s TTFB) mas é aceitável se ficar próximo (ex: p95 = 2.3s é tolerável
  com mitigação de UX via skeleton + progresso; >3s é inaceitável — nesse caso, reabrir conversa).
- ⚠️ **Risco de timeout em arquivos >500 MB** — mitigado pelo cap de 500 MB + redirect para Nextcloud Web
  acima disso. Para a base de PDFs jurídicos reais, 95%+ cabem em <200 MB (referência arquitetural);
  cap de 500 MB cobre o caso absurdo sem degradar UX nos casos comuns.
- ⚠️ **Sem HTTP/3 nativo no download** — PHP-FPM fala HTTP/1.1 ao Caddy, Caddy continua falando HTTP/3
  ao cliente, mas overhead marginal de proxy adicionado. Aceitável.
- ⚠️ **Observabilidade:** worker PHP-FPM ocupado durante download precisa aparecer no HealthPanel
  (Story 10.2) como métrica dedicada — senão, latência de outras rotas do CRM pode degradar sem causa
  aparente. Adicionar tile "PHP-FPM pool utilization %" obrigatório se este ADR for promovido.

## Alternativas consideradas (e descartadas aqui)

- **Plugin `caddy-server-exec` ou custom build do Caddy com handler X-Accel nativo:** viola o princípio
  de config mínima (ADR 0004 — admin TI freelancer). Custom build também quebra o caminho de update
  via imagem oficial.
- **Trocar Caddy por nginx:** invalida todo o ADR 0004 (TLS automático, HTTP/3 nativo, rate_limit,
  correlation id). Custo de migração > custo de manter PHP-proxy.
- **Servir direto pelo Nextcloud (bypass do bridge):** quebra o princípio "EspoCRM é fonte de verdade da
  ACL" do CLAUDE.md. Cliente com acesso ao Nextcloud teria bypass das regras de visibilidade do CRM.
- **S3/MinIO com URLs pré-assinadas:** adiciona componente operacional (overkill para escritório
  pequeno) e exige duplicação de storage (Nextcloud + MinIO). Fora do escopo do MVP.

## Critério de promoção deste ADR para "Aceito"

Este ADR é promovido se **qualquer uma** das condições abaixo for verdadeira:

1. **Fase 1 falhou:** sanity local da receita Caddy v2 (Spike 1b.S1) retornou erro persistente em
   todas as variantes testadas (primária + alternativas A e B documentadas em Dev Notes da story).
   Decisão tomada agora; promoção imediata.
2. **Fase 2 falhou:** bench VPS (Epic 10, story 10.X-bench-nfr a criar) retornou p95 TTFB > 2s com
   folga não-mitigável (>2.5s sustentado) para o cenário de 10 downloads concorrentes de PDF 200 MB.
   Promoção é feita junto com abertura de PR na Story 5.3 trocando `USE_PHP_PROXY=false` por `true`
   + atualização de `pm.max_children` no compose principal.

Critério negativo (NÃO promove): p95 TTFB entre 2.0s e 2.5s. Essa margem é tratada como "OK condicional"
— mantém X-Accel com monitoramento + item em backlog para revisar em 6 meses baseado em uso real.

## Resultado (Spike 1b.S1 Fase 1 — 2026-04-24)

A Fase 1 da Spike 1b.S1 passou os 2 critérios funcionais de aceitação:

- **AC2 (X-Accel via Caddy):** download de 200 MiB completo em 2.0s total / TTFB 30 ms / SHA-256 íntegro.
- **AC3 (PHP-proxy comparativo):** mesmo arquivo via rota `&use_proxy=php` em 1.2s total / TTFB 51 ms / SHA-256 íntegro. Plano B viável (e na verdade ligeiramente mais rápido em sanity single-stream — provável devido a ausência de chunk-encoding adicional do `handle_response`).

A receita primária do ADR 0004 (Caddy `handle_response + file_server` dentro do mesmo bloco) foi confirmada e adotada como definitiva (ver seção "Validação funcional" do ADR 0004). **Este draft NÃO foi promovido.**

**Caso a Fase 2 (bench VPS no Epic 10) revele regressão**, este documento é reativado:
- O fluxo de promoção é pré-aprovado (ver "Critério de promoção" abaixo) — basta abrir PR na Story 5.3 trocando `USE_PHP_PROXY=false` por `true`, ajustar `pm.max_children` no compose, e atualizar status deste draft para `Aceito`.

Relatório completo da Fase 1: [1b-S1-spike-x-accel-redirect-relatorio.md](../../../_bmad-output/implementation-artifacts/1b-S1-spike-x-accel-redirect-relatorio.md).

## Referências

- [ADR 0004 — Caddy como reverse proxy único](../0004-caddy-reverse-proxy.md)
- [Story 1b.S1 — Spike NFR1 X-Accel-Redirect](../../../_bmad-output/implementation-artifacts/1b-S1-spike-x-accel-redirect-pdf-200mb.md)
- [Relatório Spike 1b.S1 Fase 1](../../../_bmad-output/implementation-artifacts/1b-S1-spike-x-accel-redirect-relatorio.md)
- [PRD — NFR1 (p95 ≤ 2s TTFB PDF 200 MB)](../../../_bmad-output/planning-artifacts/prd.md)
- [Caddy v2 docs — reverse_proxy intercept](https://caddyserver.com/docs/caddyfile/directives/reverse_proxy#intercepting-responses)
