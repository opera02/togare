# ADR-04 — Subsistema togare-core/Notifications & Reminders

**Status:** Aceito (2026-05-04 — sprint-change-proposal-2026-05-04.md)
**Stories relacionadas:** 4a.3 (entity Prazo + smoke F1 que disparou o requisito), 4b.2 (alertas D-7/D-3/D-1/D-0 ampliada com canal duplo + LembreteConfigPanel)
**Decisões antecedentes:** ADR-03 v1.1 (pipeline DJEN→Prazo)
**ADRs derivados:** —
**Próximas dependências:** Story 4b.2 (implementação)

---

## Contexto

O smoke F1 da Story 4a.3 (DJEN→Prazo persistido) validou os 4 ACs originais (10/11/12/13) mas Felipe (PO) identificou via uso real que o canal único de notificação previsto na arquitetura v1.0 é insuficiente. PRD v1.3.1 (bumped 2026-05-04) introduz **canal duplo** (in-app pop-up + e-mail) com SLA medido (NFR37 ≤10min p95).

O subsistema atende:

- **FR15 PRD v1.3.1:** alerta D-7/D-3/D-1/D-0 + status `atrasado_reagendado`/`aguardando_cliente`/`aguardando_correcao` via canal duplo + preferences por usuário
- **NFR37 PRD v1.3.1:** entrega ≤10min p95 (1ª entrega bem-sucedida em qualquer canal); retry exponencial paralelo no SMTP (1/5/30min); fallback automático para in-app
- **FR37 PRD v1.3.1:** falhas de canal registradas no audit log

A entidade Audiencia (Story 3.6-magro) já usa um pattern de reminder nativo do EspoCRM (`reminders` array em entityDefs). O Prazo precisa de algo similar mas com SLA medido + UI de configuração + retry resiliente.

## Decisão

Criar **subsistema togare-core/Notifications & Reminders** — não em togare-djen, não em módulo dedicado.

### 1. Localização: togare-core (cross-cutting)

**Por que togare-core:** notificação é capability cross-cutting. Audiencia já usa reminder nativo; Prazo precisa de canal duplo com SLA; futuras entidades (AcaoTribunalica, ContratoHonorarios em vencimento) consumirão o mesmo subsistema. Manter em togare-core evita duplicação por módulo.

**Por que NÃO togare-djen:** togare-djen é producer DJEN-específico. Lembrete de prazo é independente da origem (manual, DJEN, CSV import futuro).

**Por que NÃO módulo dedicado:** subsistema é pequeno (~1 entity + 1 job + 1 view UI) e cross-cutting; criar `togare-notifications` separado violaria a regra "não inflar n módulos sem necessidade" do Step 6 (Party Mode arch).

### 2. Entidade `togare_prazo_lembrete` (Migration V011 togare-core 0.18.0)

| Campo | Tipo | Descrição |
|---|---|---|
| `id` | varchar(17) | PK |
| `prazo_id` | varchar(17) | FK → prazo.id |
| `user_id` | varchar(17) | FK → user.id (destinatário) |
| `marco` | varchar enum | `D-7`, `D-3`, `D-1`, `D-0` |
| `canal` | varchar enum | `popup`, `email`, `both` |
| `scheduled_for` | datetime | quando o lembrete deve disparar |
| `status` | varchar enum | `pending`, `sent`, `failed`, `cancelled` |
| `sent_at` | datetime nullable | timestamp de entrega bem-sucedida |
| `attempt_count` | int | retry counter |
| `last_error` | text nullable | última mensagem de erro |
| `created_at` / `modified_at` | datetime | sysfields |

**Indexes:**
- `UNIQUE (prazo_id, user_id, marco)` — idempotência
- `(status, scheduled_for)` — query do PrazoReminderJob
- `(user_id)` — listagem "meus lembretes"

### 3. `PrazoReminderJob` em togare-core/Jobs/

- Pool: `internal` (não `lgpd_purge`).
- Cadência: a cada 5 minutos.
- Query: `SELECT ... FROM togare_prazo_lembrete WHERE status='pending' AND scheduled_for <= NOW() FOR UPDATE SKIP LOCKED`.
- Para cada item:
  1. Resolve canal preferido do usuário (preferences) e cruza com canal configurado no lembrete
  2. Dispara pop-up in-app via `Notification` entity nativa do EspoCRM (instantâneo, <1min) **e** e-mail via `Espo\Tools\Email\Service\Sender` em paralelo
  3. Marca `status='sent'` na 1ª entrega bem-sucedida
  4. Para SMTP falho, agenda retry exponencial (1/5/30min via `attempt_count` + `scheduled_for` recalculado); após 3 falhas → `status='failed'` + audit `notification.email_failed`
- Idempotência: o UNIQUE INDEX `(prazo_id, user_id, marco)` impede duplicação mesmo em re-execução do job.

**Geração de lembretes:** quando o Prazo entra em status que dispara alerta (`pendente`, `atrasado_reagendado`, `aguardando_cliente`, `aguardando_correcao`), um hook AfterSave em togare-core/Hooks/Prazo/EnqueuePrazoLembretesHook calcula os marcos (D-7, D-3, D-1, D-0 a partir de `dataFatal`) e cria entries em `togare_prazo_lembrete` para o `assigned_user_id` e Sócio/Admin (com idempotência via UNIQUE).

### 4. Canal pop-up in-app

- Reutilizar `Notification` entity nativa do EspoCRM (`/api/v1/Notification`).
- Pop-up persistente quando aba inativa (notification badge no browser via Notification API HTML5 quando permitido).
- Badge no fluxo de atividade (Stream) quando aba ativa.
- Acessibilidade: respeita `Reduced Motion` (sem animação se preferência do usuário); `aria-live=polite` no badge.

### 5. Canal e-mail

- Reutilizar `Espo\Tools\Email\Service\Sender` nativo (cobre SMTP config existente).
- Template em `togare-core/Resources/templates/email/prazo-reminder.tpl.html`:
  - Pt-BR
  - Cabeçalho com label do marco ("Prazo vence em 7 dias", "Prazo vence amanhã", etc.)
  - Body com CNJ formatado, descrição, dataFatal, link direto para o Prazo no CRM
  - **Hedge jurídico FR39 incluído no rodapé** ("Esta notificação é uma cortesia operacional. Responsabilidade final do prazo é do advogado.")
- SLA: NFR37 ≤10min p95 (1ª entrega bem-sucedida em qualquer canal).
- Retry: 3 tentativas com backoff exponencial (1/5/30min) em paralelo ao pop-up — não afeta p95 porque pop-up cumpre SLA em <1min.

### 6. UI Preferences — `LembreteConfigPanel`

- Nova seção "Meus lembretes" em `Preferences` do EspoCRM (cada usuário).
- View: `togare-core/Resources/views/preferences/lembrete-config.js`.
- Campos:
  - Canais ativos: checkbox `popup`, `email`, ambos (default: ambos)
  - Cadência por marco: dropdown D-7 / D-3 / D-1 / D-0 (default: todos ativos)
  - Botão "Aplicar a partir do próximo prazo" (não recalcula lembretes já agendados)
- Persistência: campo `togareLembreteConfig` (JSON) em `Preferences` entity.
- Acessibilidade: WCAG AA (NFR28); labels claros + hint para idoso (perfil ICP).

## Trade-offs aceitos para MVP

**Aceitos:**
- 1 retry plan único (1/5/30min) — sem configuração por usuário; suficiente para 95%+ dos casos.
- Sem fallback SMS/push mobile — Growth.
- Sem agregação de lembretes (1 prazo = 1 e-mail por marco) — risco de spam se usuário tem muitos prazos urgentes; mitigação: cadência configurável por usuário.
- LembreteConfigPanel é UI básica (sem preview, sem teste de envio) — UX polish em Epic 10.

**Mitigados:**
- Retry exponencial paralelo ao fallback in-app garante que p95 NÃO depende do SMTP funcionando.
- UNIQUE INDEX `(prazo_id, user_id, marco)` impede duplicação em re-execução do job.
- Audit log captura `notification.email_failed` após 3 retries para diagnóstico SRE/admin.

## Consequências

**Positivas:**
- Capability nova entregue sem novo módulo (subsistema interno em togare-core).
- Pattern reutilizável para Audiencia, ContratoHonorarios e futuras entidades.
- SLA medível (NFR37) com instrumentação clara (correlation_id timestamp diff em audit log).
- Idempotência garantida sem locks distribuídos.

**Negativas:**
- togare-core cresce (~1500 linhas de código novo em Jobs + Resources).
- Acoplamento entre togare-core e EspoCRM Notification entity (aceitável — Notification é API estável).
- LGPD: e-mail carrega metadado pessoal (e-mail destinatário, conteúdo de prazo). Cobertura via NFR10 (audit log 24m); retenção do `togare_prazo_lembrete` segue o Prazo associado (cascade delete via FK).

**Neutros:**
- Volume estimado: 50 advs × 5 prazos pendentes médios × 4 marcos = 1000 lembretes/dia. MariaDB lida tranquilo. SMTP throughput (server gmail/sendgrid) suporta.

---

## Histórico

| Data | Versão | Mudança |
|---|---|---|
| 2026-05-04 | 1.0 | ADR criado durante bump editorial v1.1 da architecture (sprint-change-proposal-2026-05-04 aprovado). 6 decisões registradas: localização togare-core, entidade `togare_prazo_lembrete`, PrazoReminderJob, canal pop-up, canal e-mail, UI LembreteConfigPanel. |
