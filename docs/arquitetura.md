# Arquitetura

> ⚠️ **Este documento foi SUPERADO em 2026-04-22 pela arquitetura BMAD finalizada em [\_bmad-output/planning-artifacts/architecture.md](../_bmad-output/planning-artifacts/architecture.md).**
>
> Mantido aqui como referência histórica do kickoff Fase 1 (pré-BMAD). Decisões desta página foram revistas, estendidas e/ou reclassificadas ao longo dos Steps da arquitetura BMAD e do bump PRD v1.2.
>
> **Referência atual vinculante:** `_bmad-output/planning-artifacts/architecture.md` (7 módulos custom EspoCRM + togare-backup container + stack fixa docker-compose + 5 ADRs 0003-0007 pendentes + 3 spikes obrigatórios).
>
> Em caso de conflito entre este doc e a arquitetura BMAD, **prevalece a arquitetura BMAD**.

---

Visão de alto nível da plataforma — **artefato histórico do kickoff**.

## Princípios

1. **EspoCRM é a fonte da verdade** para clientes, processos, prazos, audiências e dados financeiros. Todos os outros componentes leem/escrevem via API do EspoCRM.
2. **Customizações vivem em módulos**, nunca no core do EspoCRM — preserva o caminho de atualização.
3. **Resiliência em integrações externas**: toda chamada externa (DataJud, AASP, provedores de IA) deve ter timeout, retries e degradação graciosa.
4. **IA é enriquecimento, não bloqueio**: bot de prazos tem parsing determinístico como caminho-base; IA adiciona qualidade mas não pode travar o fluxo.

## Fluxo de dados (rascunho)

```
┌─────────────┐      ┌──────────────┐      ┌────────────────┐
│  DataJud    │─────▶│              │◀─────│  Bot Prazos    │
└─────────────┘      │              │      │  (parsing +    │
                     │   EspoCRM    │      │   IA opcional) │
┌─────────────┐      │  (núcleo)    │      └────────────────┘
│  AASP       │─────▶│              │
│  (+ fallback)│     │              │─────▶┌────────────────┐
└─────────────┘      └──────┬───────┘      │ Portal Cliente │
                            │               │  (read-only    │
                            ▼               │   API)         │
                     ┌──────────────┐      └────────────────┘
                     │  Nextcloud   │
                     │  (arquivos)  │
                     └──────────────┘
```

## Resoluções posteriores (registrado em 2026-04-22)

Os pontos abaixo eram "a definir no próximo ciclo BMAD" — agora **resolvidos** na arquitetura BMAD (`_bmad-output/planning-artifacts/architecture.md`):

- **Entidades customizadas do EspoCRM** → resolvidas em `architecture.md` (módulo `togare-core`) + 7 módulos custom (`togare-core`, `togare-licensing`, `togare-tpu`, `togare-djen`, `togare-nextcloud-bridge`, `togare-lgpd`, `togare-portal-ui`). Prefixo `Togare*` + namespace reservado `TogareExt_<Vendor>_*` para terceiros (ADR 0003).
- **Contrato da API consumida pelo Portal do Cliente** → decidido usar **Portal nativo do EspoCRM** (não app web separado) + módulo `togare-portal-ui` com camada de linguagem + splash branded + CSS acessibilidade.
- **Abstração de provedores de publicações** → `PublicationSourceAdapterContract` em `togare-core/Resources/contracts/`. MVP tem **DJEN como fonte única**; AASP + diários estaduais ficam como redundância Growth.
- **Estratégia de sincronização EspoCRM ↔ Nextcloud** → resolvida via `togare-nextcloud-bridge` com OCS API + download proxy (ACL no PHP + X-Accel-Redirect Caddy). EspoCRM é fonte de metadados; Nextcloud é fonte única de binários (NFR26).
- **Stack do Portal do Cliente** → Portal nativo EspoCRM (Backbone + Handlebars + tema EspoCRM), sem SPA separada.
