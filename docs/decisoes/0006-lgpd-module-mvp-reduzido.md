# ADR 0006 — Módulo togare-lgpd reduzido no MVP (purga manual com PurgeLog ed25519 + obrigação documental)

**Data:** 2026-04-22
**Status:** Aceito (versão reduzida) — Spike 1b.S3 validou ReadOnlyGate condicionalmente em 2026-04-24; invariante de captura `Forbidden` + `markFailed(license_expired)` transferida como contrato vinculante para Story 4a.1

## Contexto

PRD v1.1 incluiu **FR42-FR45** (automação completa de purga LGPD) no MVP por decisão Party Mode da arquitetura (3×1 Mary/John/Winston vs. Amelia):

- FR42: `RetentionPolicy` CRUD — políticas por tipo de entidade com janela em dias e ação (anonymize/delete/archive-to-cold).
- FR43: `TogarePurgeJob` agendado diário processando batch com `hasActiveRelations()` check.
- FR44: `PurgeLog` append-only com assinatura ed25519 verificável externamente.
- FR45: dry-run preview obrigatório.

Party Mode do Epic Breakdown (Step 2, liderado por John) retomou a discussão e apresentou evidência nova:

- Piloto inicial é 5-10 escritórios via indicação Opera Consultoria.
- Nesse volume, controle manual com SQL + checklist auditado resolve obrigação LGPD legalmente (art. 50 LGPD — proporcionalidade).
- FR42+FR43 somam ~3 sprints de engenharia defensiva.
- Mary concordou: automação não é requisito legal, é requisito operacional. No volume piloto foi "overengineering por medo regulatório, não risco real".

Felipe escolheu **D2-meio** no Party: manter FR44+FR45+NFR36 no MVP; diferir FR42+FR43 para Growth.

Bump PRD v1.2 formalizou:
- FR42, FR43 → Growth (com gatilho "≥3 escritórios contratados ou exigência regulatória").
- FR44, FR45 ajustados para execução **manual** via painel admin.
- **FR46 novo**: obrigação contratual do escritório manter política de retenção escrita + revisão trimestral em audit log.

## Decisão

O módulo `togare-lgpd` no MVP v1.2 entrega:

1. **Execução manual de purga** via painel admin do Sócio/Admin (Story 8.3).
   - Sem `RetentionPolicy` CRUD (diferido FR42).
   - Sem job agendado diário (diferido FR43).
   - Operador preenche parâmetros da purga na UI: entidade, trigger field, windowDays, ação — sob demanda.

2. **Dry-run obrigatório** antes de execução real (FR45).
   - Preview com amostra de 10 registros em tabela.
   - Botão "Exportar CSV completo" da lista afetada.
   - `hasActiveRelations()` check: registros com relações ativas são skipped com log de motivo.

3. **PurgeLog append-only com ed25519** (FR44 + NFR36).
   - Campos obrigatórios: `timestamp` ISO 8601 do gatilho manual + `operador` (userId) + `hash SHA-256 do dataset pré-purga` + identificador da política (texto livre no MVP) + `entity_type` + `entity_id` + `signature` ed25519.
   - Chave privada em `/etc/togare/instance.key` (gerada na instalação); chave pública distribuída junto do PDF de relatório.
   - Relatório PDF exportável verificável externamente sem depender do servidor Togare empresa.

4. **Obrigação documental FR46** (novo em v1.2).
   - Sócio/Admin mantém política de retenção **escrita e versionada** (documento no escritório).
   - Registra revisão trimestral em `togare_audit_log` com entrada `lgpd.policy.reviewed` (data + operador + link do documento).
   - Alerta visual no painel admin se >4 meses sem nova entrada de revisão.

5. **Diferidos para Growth** (documentados no PRD §Scoping Post-MVP Fase 2):
   - FR42 (policy CRUD) + FR43 (job agendado diário).
   - Gatilho de reativação: base de ≥3 escritórios contratados **ou** exigência regulatória específica em auditoria ANPD.

**Plano B (advisory lock MariaDB):** Spike 1b.S3 (2026-04-24) validou empiricamente o isolamento entre `togare_module_status` e `togare_queue_items` e aceitou o design natural do ReadOnlyGate **condicionalmente**. Invariante transferida para a Story do worker canônico (Story 4a.1 ou equivalente): handler deve capturar `Forbidden` e chamar `markFailed(motivo='license_expired')` para que item premium vá para `failed_retry` em vez de ficar órfão em `processing`. Se a implementação do worker violar essa invariante, o plano B (GET_LOCK advisory em `RevalidateLicensesJob` + `QueueService::claim()`) permanece documentado no draft [ADR 0006-extra](drafts/0006-extra-licensing-advisory-lock.md) e vira Story `1b.1.1-patch-licensing-advisory-lock`. Relatório completo da spike em [_bmad-output/implementation-artifacts/1b-S3-spike-licensing-expiration-relatorio.md](../../_bmad-output/implementation-artifacts/1b-S3-spike-licensing-expiration-relatorio.md).

## Consequências

- ✅ MVP ganha ~3 sprints de capacidade (automação diferida).
- ✅ LGPD art. 16 (descarte de dados vencidos) satisfeito via execução manual proporcional ao volume piloto.
- ✅ LGPD art. 50 (proporcionalidade) — nota explícita no PRD §9 como defesa documental em auditoria ANPD.
- ✅ Prova de descarte criptograficamente verificável externamente (ed25519 + chave pública distribuída) — diferencial jurídico vs. Astrea/Projuris, preservado.
- ✅ FR46 transfere risco regulatório para o controlador (escritório) onde ele reside legalmente (LGPD arts. 37-40). Inverte ônus argumentativo em auditoria.
- ⚠️ Sócio/Admin precisa lembrar de disparar purga manualmente — mitigado por alerta visual no painel admin baseado na data da última revisão registrada.
- ⚠️ Reativação de FR42+FR43 (Growth) exigirá bump PRD futuro + design do `RetentionPolicyEngine` + reimplementação de scheduled job + migração dos operadores manuais. Custo de reativação aceito.
- ⚠️ Decisão dependente de Spike 1b.S3 passar (expiração de licença em transação aberta não corrompe `togare_queue_items`). Plano B documentado.
