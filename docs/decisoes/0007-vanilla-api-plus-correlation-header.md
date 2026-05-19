# ADR 0007 — API vanilla EspoCRM + correlation via header `X-Togare-Correlation-Id`

**Data:** 2026-04-22
**Status:** Aceito

## Contexto

Durante o Step 5 da arquitetura BMAD (Implementation Patterns), a proposta inicial foi:

- Endpoints REST vanilla do EspoCRM → formato nativo.
- Endpoints custom Togare (ex.: `/api/v1/togare/health`, `/api/v1/togare/lgpd/purge/dry-run`) → **wrapper** `{data, error, correlationId}` para carregar correlation no body.

Party Mode Step 5 (Amelia, Winston, Paige) convergiu unanimemente por **abandonar o wrapper**:

- **Amelia:** "dois contratos, dois clients, dois testes. Escolhe um. CorrelationId vive em header, não em body."
- **Winston** (após retificar posição inicial): "'Regra com exceção' é dívida cognitiva permanente. Eliminar a exceção é eliminar a pergunta." Argumenta também que Fase 2 API Enterprise será versionada (`/api/v2/...`) com contrato próprio desenhado para consumidores externos — especulação sobre wrapper agora é prematura.
- **Paige:** "vanilla + header é muito mais fácil de ensinar e documentar. Wrapper é caixa dentro de caixa."

Mary (PM/Analyst) adicionou em rodada complementar: Astrea/Projuris não publicam API documentada no tier básico — "open-core sério" não se ganha por envelope JSON uniforme, se ganha por ter qualquer API documentada e código no GitHub.

## Decisão

**Todos os endpoints HTTP do Togare retornam formato nativo EspoCRM.** Nenhum wrapper custom.

1. **Endpoints REST vanilla** (CRUD de entidades EspoCRM): formato nativo do framework — objeto da entidade para GET único, `{list: [...], total: N}` para coleções.

2. **Endpoints custom Togare** (health, licensing, purge, etc.): **mesmo formato vanilla**. Sem wrapper. Sem `{data, error, correlationId}` no body.

3. **Erros:** seguem o padrão EspoCRM nativo — HTTP status adequado (4xx/5xx) + body com `{statusCode, reasonPhrase, message}`.

4. **Correlation tracking via header:**
   - **`X-Togare-Correlation-Id`** injetado no request pelo Caddy (gerado como UUID v4 se ausente).
   - Propagado na response (mesma correlation volta ao cliente).
   - Middleware EspoCRM anexa automaticamente aos logs estruturados via `TogareLogger::event()` (ADR associado: padrão de logs pt-BR do Step 5 da arquitetura).

5. **Fase 2 API Enterprise** (se/quando demandada): será versionada em caminho separado (`/api/v2/...`) com contrato desenhado para consumidores externos — OpenAPI, eventual gRPC, auth separada, SLA. **Não retrofitar wrapper nos endpoints atuais em antecipação.**

## Consequências

- ✅ Um único contrato de API no produto — um client HTTP, um interceptor, um parser de erro.
- ✅ Correlation ID em header é padrão da indústria (W3C Trace Context alinha, nome é apenas customizado com prefixo `X-Togare-` conforme ADR 0003).
- ✅ DevTools Network tab mostra correlation sem precisar abrir response body — debug fácil para Felipe e suporte.
- ✅ Documentação futura (OpenAPI/Swagger) cabe em uma frase: "seguimos EspoCRM + header `X-Togare-Correlation-Id` de rastreio". Em vez de um capítulo explicando quando é um formato, quando é outro.
- ✅ Frontend nativo EspoCRM (Backbone) já consome vanilla — zero adaptação.
- ✅ Portal nativo EspoCRM idem.
- ⚠️ Integradores externos que não inspecionam headers de resposta perdem correlation no body. Muito raro em 2026; integradores profissionais sabem checar headers.
- ⚠️ Para suporte/debug, desenvolvedor precisa saber buscar correlation em logs (não no body). É prática padrão em toda indústria.
