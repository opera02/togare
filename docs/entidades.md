# Modelagem de Entidades — EspoCRM

> ⚠️ **Atualizado em 2026-04-22 para refletir decisões vinculantes da arquitetura BMAD e do PRD v1.2.**
>
> Este doc era um draft do kickoff; **o contrato final de entidades vive nos entityDefs de cada módulo Togare** (ex.: `espocrm/togare-core/src/files/custom/Espo/Modules/TogareCore/Resources/metadata/entityDefs/`). Este arquivo permanece como guia de **convenções de modelagem** e rascunho de 6 entidades base para referência humana — mas **entityDefs implementados prevalecem** em caso de conflito.

Proposta inicial (draft) das entidades customizadas do EspoCRM para o escritório, agora atualizada com as convenções BMAD vinculantes. Documento de **revisão conceitual**.

**Status:** 🟢 convenções atualizadas (v1.2 2026-04-22); draft de entidades mantido como referência
**Última atualização:** 2026-04-22

## Convenções (atualizadas em 2026-04-22)

### Nomenclatura

- **Nomes de entidade EspoCRM:** PascalCase com **prefixo obrigatório `Togare`** para entidades custom Togare oficiais (ex.: `TogareProcesso`, `TogareCliente`, `TogarePrazo`).
- **Namespace reservado para módulos de terceiros:** `TogareExt_<Vendor>_*` (ex.: `TogareExt_Acme_CustomEntity`). Garante que parceiros não colidam com entidades oficiais do produto. Ver ADR 0003 em `docs/decisoes/0003-espocrm-extensions-pattern.md` (pendente de escrita).
- **Tabelas SQL custom não-entidade:** prefixo `togare_` em snake_case (ex.: `togare_queue_items`, `togare_audit_log`, `togare_purge_log`, `togare_migrations_applied`).
- **Colunas SQL:** `snake_case` (ex.: `data_fatal`, `confirmed_at`).
- **Campos PHP/JS (em runtime):** `camelCase` (ex.: `dataFatal`, `confirmedAt`) — EspoCRM faz o mapeamento automaticamente via `ORM\Defs`.
- **Labels UI:** em português brasileiro, em `Resources/i18n/pt_BR/<Entity>.json`. Nunca inline no código.

### Campos obrigatórios universais (adicionados em 2026-04-22)

- Todas as entidades herdam dos campos padrão do EspoCRM: `id`, `name`, `createdAt`, `modifiedAt`, `createdBy`, `modifiedBy`, `assignedUser`, `teams` — não repetidos abaixo.
- **Toda entidade de negócio Togare tem `tenant_id VARCHAR(40) NULLABLE`** (desde o MVP — decisão arquitetural Winston/Party). Justificativa: mitigação barata para evitar migration dolorosa em ~15 tabelas quando multi-tenant real for ativado na Fase 2. No MVP single-tenant físico, o campo permanece NULL.
- Entidade de negócio = entidades que armazenam dados operacionais do escritório (Cliente, Processo, Prazo, Audiencia, LancamentoFinanceiro, ContratoHonorarios, Publicacao, Funcionario etc.). Entidades infraestruturais (QueueItem, AuditLog, MigrationApplied, etc.) não precisam do campo.
- Teste automatizado em CI (Story 1a.9 do Epic Breakdown) falha build se entidade de negócio nascer sem `tenant_id` NULLABLE.

### Legenda

- `*` = obrigatório. `?` = opcional.

---

## 1. Cliente

**Entidade:** `Cliente` (estende o padrão `Account` do EspoCRM? **a decidir** — ver "Pergunta 1" ao final).

| Campo                 | Tipo                                                  | Obrig. | Observação                                               |
| --------------------- | ----------------------------------------------------- | ------ | -------------------------------------------------------- |
| `tipoPessoa`          | enum `fisica` / `juridica`                            | \*     | controla quais campos ficam visíveis                     |
| `nomeCompleto`        | varchar (255)                                         | \*     | PF: nome civil completo; PJ: razão social                |
| `nomeFantasia`        | varchar (255)                                         | ?      | só PJ                                                    |
| `cpfCnpj`             | varchar (18)                                          | \*     | validação por tipoPessoa; índice único                   |
| `rgIe`                | varchar (20)                                          | ?      | RG (PF) ou Inscrição Estadual (PJ)                       |
| `dataNascimento`      | date                                                  | ?      | só PF                                                    |
| `estadoCivil`         | enum (solteiro/casado/divorciado/viúvo/união estável) | ?      | só PF — usado em petições                                |
| `nacionalidade`       | varchar (50)                                          | ?      | default "brasileira"; usado em petições                  |
| `profissao`           | varchar (100)                                         | ?      | só PF — usado em petições                                |
| `nomeMae`             | varchar (255)                                         | ?      | só PF — usado em petições e consultas                    |
| `email`               | email                                                 | ?      | principal; secundários em `emailAddress` padrão          |
| `telefone`            | phone                                                 | ?      | principal; secundários em `phoneNumber` padrão           |
| `endereco`            | address (group)                                       | ?      | logradouro, número, complemento, bairro, cidade, UF, CEP |
| `origemCaptacao`      | enum (indicação, site, Google, redes sociais, outro)  | ?      | relatórios de marketing                                  |
| `indicadoPor`         | link → `Cliente` ou `Contact`                         | ?      | quando `origemCaptacao = indicação`                      |
| `advogadoResponsavel` | link → `User`                                         | \*     | advogado titular da relação                              |
| `observacoes`         | text                                                  | ?      |                                                          |

**Relacionamentos:**

- `Processo` (1:N) — processos em que o cliente é parte.
- `LancamentoFinanceiro` (1:N).
- `Audiencia` e `Prazo` vêm indiretamente via `Processo`.

---

## 2. Processo

**Entidade:** `Processo` (totalmente custom, sem base em Case padrão).

| Campo                        | Tipo                                                                                                              | Obrig. | Observação                                                          |
| ---------------------------- | ----------------------------------------------------------------------------------------------------------------- | ------ | ------------------------------------------------------------------- |
| `numeroCnj`                  | varchar (25)                                                                                                      | \*     | formato `NNNNNNN-DD.AAAA.J.TR.OOOO`; índice único quando preenchido |
| `numeroAntigo`               | varchar (30)                                                                                                      | ?      | processos pré-CNJ                                                   |
| `segredoJustica`             | bool                                                                                                              | \*     | default `false`                                                     |
| `area`                       | enum (cível, trabalhista, criminal, família, empresarial, tributário, administrativo, previdenciário, consumidor) | \*     |                                                                     |
| `tipoAcao`                   | varchar (255)                                                                                                     | ?      | livre; preenchido manualmente ou do DataJud                         |
| `classeCnj`                  | varchar (100)                                                                                                     | ?      | código + descrição (tabela CNJ)                                     |
| `assuntoCnj`                 | varchar (255)                                                                                                     | ?      |                                                                     |
| `instancia`                  | enum (1ª, 2ª, superior)                                                                                           | \*     |                                                                     |
| `orgaoJulgador`              | varchar (255)                                                                                                     | ?      | ex.: TJSP; vem do DataJud                                           |
| `varaCamara`                 | varchar (255)                                                                                                     | ?      | ex.: "3ª Vara Cível - Central"                                      |
| `comarca`                    | varchar (100)                                                                                                     | ?      |                                                                     |
| `uf`                         | enum (UFs)                                                                                                        | ?      |                                                                     |
| `faseProcessual`             | enum (conhecimento, liquidação, cumprimento de sentença, recurso, arquivado)                                      | \*     |                                                                     |
| `status`                     | enum (ativo, suspenso, arquivado, baixado)                                                                        | \*     |                                                                     |
| `poloCliente`                | enum (ativo, passivo, outros)                                                                                     | \*     | polo do cliente (autor/réu)                                         |
| `valorCausa`                 | currency                                                                                                          | ?      |                                                                     |
| `dataDistribuicao`           | date                                                                                                              | ?      | do DataJud                                                          |
| `dataAutuacao`               | date                                                                                                              | ?      | do DataJud                                                          |
| `cliente`                    | link → `Cliente`                                                                                                  | \*     | cliente representado                                                |
| `parteContraria`             | link-multiple → `ParteContraria`                                                                                  | ?      | zero ou mais                                                        |
| `advogadoResponsavel`        | link → `User`                                                                                                     | \*     | titular do processo                                                 |
| `advogadosColaboradores`     | link-multiple → `User`                                                                                            | ?      |                                                                     |
| `fonteDados`                 | enum (manual, DataJud)                                                                                            | \*     | para auditoria de origem dos campos                                 |
| `ultimaSincronizacaoDataJud` | datetime                                                                                                          | ?      | quando a integração rodar                                           |
| `linkNextcloud`              | url                                                                                                               | ?      | pasta de documentos no Nextcloud                                    |
| `observacoes`                | text                                                                                                              | ?      |                                                                     |

**Relacionamentos:**

- `Cliente` (N:1).
- `ParteContraria` (N:N).
- `Prazo` (1:N).
- `Audiencia` (1:N).
- `LancamentoFinanceiro` (1:N).
- `Publicacao` (1:N) — publicações da AASP vinculadas.

---

## 3. ParteContraria (esboço)

Campos principais: `tipoPessoa`, `nome`, `cpfCnpj?`, `advogadoAdversario?` (nome), `oabAdversario?`, `enderecoConhecido?`, `observacoes?`. Relacionamento N:N com `Processo`.

## 4. Prazo (esboço)

Campos principais: `processo*`, `tipo*` (enum: contestação, tréplica, recurso, memoriais, impugnação, outros), `descricao*`, `dataFatal*`, `dataProtocolo?`, `responsavel*`, `status*` (enum: pendente, cumprido, perdido, cancelado), `publicacaoOrigem?` (link), `anexos?`, `observacoes?`.

> Integração: o bot de prazos cria registros aqui; IA enriquece `tipo` e `dataFatal`, mas fallback determinístico sempre grava pelo menos `descricao` e `dataFatal` da publicação.

## 5. Audiencia (esboço)

Campos principais: `processo*`, `tipo*` (enum: conciliação, instrução, una, julgamento, virtual, outros), `dataHora*`, `modalidade*` (enum: presencial, virtual, híbrida), `endereco?`, `sala?`, `linkVirtual?`, `status*` (enum: agendada, realizada, cancelada, adiada, redesignada), `participantesEsperados?`, `ata?` (text), `resultado?`.

## 6. LancamentoFinanceiro (esboço)

Campos principais: `tipo*` (enum: receita, despesa), `cliente?`, `processo?`, `categoria*` (enum: honorários contratuais, honorários sucumbenciais, custas, diligências, deslocamento, outros), `descricao*`, `valor*`, `dataVencimento*`, `dataPagamento?`, `status*` (enum: pendente, pago, atrasado, cancelado), `formaPagamento?`, `comprovante?` (file, opcionalmente apontando para Nextcloud).

---

## Entidade auxiliar — Publicacao (proposta)

Entrou porque ficou implícita na integração AASP. Vale definir agora para não ficar solta.

Campos: `provedor*` (enum: AASP, DJEN, outros), `dataPublicacao*`, `conteudoBruto*` (text), `orgaoPublicador?`, `processo?` (link — pode ser nulo até a IA ou o operador vincular), `prazosGerados` (1:N → Prazo), `classificacaoIA?` (enum + score), `statusProcessamento*` (enum: recebida, vinculada, gerou_prazo, ignorada).

---

## Perguntas ao usuário (decisões pendentes)

1. **`Cliente` deve estender a entidade `Account` padrão do EspoCRM ou ser totalmente custom?**
   Estender `Account` reaproveita relacionamentos nativos (Opportunity, Contact, Document) mas carrega campos de "empresa" que podem não fazer sentido para PF. Custom dá controle total mas exige re-implementar o que já existe. **Minha sugestão:** custom, para deixar o modelo limpo ao domínio jurídico.

2. **`ParteContraria` é entidade separada ou é um `Cliente` com um flag `tipoRelacionamento`?**
   Em muitos escritórios, a parte contrária de hoje pode virar cliente amanhã. **Minha sugestão:** entidade separada agora, mas manter campo `clienteRelacionado?` para o dia em que migrar.

3. **`numeroCnj` é único globalmente ou só por escritório?**
   Pode acontecer de dois clientes do mesmo escritório estarem em polos opostos do mesmo processo. **Minha sugestão:** único globalmente; quando houver dois clientes do escritório no mesmo processo, ele aparece em ambos via relacionamento N:N `processo_cliente`.

4. **Áreas jurídicas do escritório:** a lista em `Processo.area` cobre o que vocês atuam? Alguma específica a adicionar (ex.: ambiental, eleitoral, internacional)?

5. **Segregação por time/advogado (Teams do EspoCRM):** todo advogado vê todos os processos, ou há departamentos estanques? Isso define como usar o recurso de `teams` nativo.

6. **`LancamentoFinanceiro` granular ou agrupado?**
   Por contrato de honorários (um lançamento por vínculo) ou por evento (um lançamento por cobrança/despesa individual)? **Minha sugestão:** por evento, e criar entidade `ContratoHonorarios` separada se necessário.

---

## Próximos passos depois da revisão

1. Fixar decisões das 6 perguntas.
2. Detalhar os esboços 3-6 no mesmo nível da `Cliente`/`Processo`.
3. Traduzir para `entityDefs` JSON do EspoCRM (módulo customizado em [espocrm/custom](../espocrm/custom)).
4. Criar as entidades via interface Admin → Entity Manager (ou via manifest), validar fluxo de criar cliente + processo no CRM rodando.
