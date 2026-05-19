# ADR-02 — Evolução da regra de prazo do DjenParserService

**Status:** aceito
**Data:** 2026-05-03
**Story de origem:** 4a.2 (DjenParserService aplicando regra Res. CNJ 455 art. 5º)
**Módulo:** togare-djen ≥ 0.2.0
**Componente:** `Espo\Modules\TogareDjen\Services\DjenPrazoRules` + DTO `PrazoCalculado`

## Contexto

O `DjenParserService` (Story 4a.2) aplica a **regra do art. 5º da Resolução
CNJ 455/2022** combinada com o **dicionário de prazos do CPC** (`DjenPrazoRules`)
para calcular a `dataFatal` de cada publicação DJEN. Esse cálculo é a **raiz
da promessa comercial** do Togare ("reduz drasticamente o risco de prazos
perdidos" — PRD §1.4) e o produto entrega uma cláusula explícita de hedge
jurídico exatamente porque a regra pode evoluir.

Cenários previsíveis de evolução:

1. **CPC reformado** — congresso aprova alteração de prazo de algum ato
   (ex.: contestação passa de 15 para 20 dias). Toda publicação afetada que
   já foi processada precisa ser re-calculada.
2. **Resolução CNJ atualizada** — CNJ pode mudar a contagem do art. 5º
   (ex.: "+ 2 dias úteis" em vez de "+ 1") via nova resolução.
3. **Lei estadual / norma específica** — Justiça do Trabalho ou Justiça
   Federal podem ter regras específicas que diferem do CPC.
4. **Adição de ato novo** ao dicionário (ex.: agravo retido, embargos
   infringentes) — não-breaking, apenas cobre mais casos.
5. **Correção de bug** identificado em produção (ex.: pattern de regex
   classificando errado) — mantém número de dias, ajusta detector.

Sem versionamento explícito, o time de operações não consegue distinguir
"essa publicação foi calculada com regra antiga" de "essa publicação está
com cálculo correto" ao auditar registros históricos.

## Decisão

**Versionar a regra do parser via campo `regraVersao` em todo `PrazoCalculado`,
seguindo semver:**

- `MAJOR.MINOR.PATCH` em string (ex.: `"1.0.0"`).
- **Fonte única de verdade:** `DjenPrazoRules::REGRA_VERSAO` (constante).
- **Cada `PrazoCalculado`** carrega a versão no momento do cálculo (campo
  obrigatório no DTO + log JSON via `djen.publication.parsed`).

**Política de bump:**

| Mudança | Bump |
|---|---|
| Adição de novo ato ao dicionário (não-breaking) | **MINOR** (1.0.0 → 1.1.0) |
| Refinamento de regex do classifier que melhora detecção sem alterar dias | **PATCH** (1.0.0 → 1.0.1) |
| Mudança de `dias` ou `contagem` de ato existente (breaking) | **MAJOR** (1.0.0 → 2.0.0) |
| Ajuste do art. 5º (ex.: T+2 dias úteis em vez de T+1) | **MAJOR** |
| Adição de calendário forense estadual / regra de UF específica | **MAJOR** se altera saída de pubs já existentes; **MINOR** se é puro adição opt-in |

**Trilha auditável obrigatória em mudança da regra:**

1. PR no monorepo com:
   - Mudança em `DjenPrazoRules` (constante `RULES`) ou `DjenAtoClassifier`
     (PATTERNS_*).
   - Bump da `REGRA_VERSAO`.
   - Atualização deste ADR-02 com entrada nova na seção **Histórico de versões**
     (Y-m-d, autor, motivo, breaking?).
   - Atualização da seção pertinente no README do togare-djen.
   - Testes PHPUnit cobrindo cenários antes/depois.

2. Commit message no formato:
   `feat(togare-djen): bump REGRA_VERSAO X.Y.Z — <motivo curto>`

3. Quando MAJOR (breaking): job housekeeping de re-cálculo histórico:
   - Story dedicada (Epic 10 housekeeping ou story curta intercalada).
   - Job CLI `php bin/togare-djen-reparse.php --from=YYYY-MM-DD --to=YYYY-MM-DD`
     que itera entidades Prazo (Story 4a.3+) com `regraVersao < REGRA_VERSAO`
     e re-aplica o parser. Resultado vai para audit log com evento
     `djen.parser.reparsed` (event-name reservado).
   - Pré-condição: Prazo entity da Story 4a.3 PRECISA carregar campo
     `parserRegraVersao` (string). Esta story (4a.2) já produz o valor;
     4a.3 só persiste.

## Estado atual (versão 1.0.0)

- 11 atos cobertos no dicionário (`DjenPrazoRules::RULES`):
  contestacao, recurso_apelacao, embargos_declaracao, agravo_instrumento,
  agravo_interno, cumprimento_sentenca, impugnacao_cumprimento, replica,
  quesitos_pericia, manifestacao_geral_intimacao, manifestacao_generica
  (fallback).
- Calendário BR cobre 2024-2030 (`BrazilianBusinessCalendar` em togare-core
  0.16.0).
- Regra do art. 5º aplicada como `nextBusinessDay(dataDisp) +
  prazoDias[uteis|corridos]`.
- Override de prazo do texto vale APENAS para
  `manifestacao_geral_intimacao`.

## Histórico de versões

### 1.0.0 — 2026-05-03

- Release inicial (Story 4a.2).
- 11 atos cobertos.
- 2 contagens (uteis CPC art. 219 / corridos CPC art. 523).
- Calendário 2024-2030 com Lei 14.759/2023 (Consciência Negra).

## Considerações

- **Por que semver e não data?** Datas misturam mudança de produto com
  liberação. Semver expressa **compatibilidade com payloads antigos**
  diretamente: `2.0.0` significa "se você tem PrazoCalculado regraVersao=1.x,
  re-calcule".
- **Por que constante e não DB?** Decisão #3 da Story 4a.2 (override por
  escritório fica para Growth). Mudança de regra exige revisão de código
  com PR + ADR + testes — operações jurídicas sensíveis não devem ser
  configuráveis por usuário-admin via UI.
- **Por que fallback `manifestacao_generica` (low) vs lançar exception?**
  Princípio "zero perda silenciosa" da arquitetura Togare (NFR18). Parser
  sempre dá um palpite quando não consegue classificar; Story 4a.3 cria
  Prazo em rascunho com flag `confidence=low` e o advogado revisa
  manualmente (Story 4b.1 — fila de ambíguos).

## Referências

- Story 4a.2 — `_bmad-output/implementation-artifacts/4a-2-djen-parser-service-resolucao-cnj-455.md`
- PRD §1.4 — Resolução CNJ 455/2022 (raiz da promessa comercial).
- CPC arts. 218, 219, 220, 335, 350, 465, 523, 525, 1003, 1021, 1023.
- Lei 14.759/2023 — Consciência Negra como feriado nacional.
- [ADR-01 (togare-tpu)](../../togare-tpu/docs/ADR-01-tpu-cache-strategy.md) — pattern de adapter externo + cache resiliente
  (precedente arquitetural de "regra externa versionada").
