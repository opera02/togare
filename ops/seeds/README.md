# ops/seeds — fixtures determinísticas para dev local

Diretório montado read-only em `/var/www/html/ops` no container EspoCRM via bind do `docker-compose.yml` (Story 1a.10 / AC2).

> **NUNCA distribuído em produção.** Este diretório existe apenas no monorepo de desenvolvimento — não vai para os zips de extensão e o bind mount não é replicado em receitas de produção.

## Como rodar

```bash
docker compose exec espocrm php /var/www/html/ops/seeds/load-seeds.php
```

Pré-requisito: togare-rbac instalado (8 roles seedados na tabela `role`). Sem isso, o script falha cedo com mensagem indicando como instalar.

Saída esperada (ambiente limpo, primeira execução):

```
[seeds] users: 4 seeded, 0 skipped, 0 errors (X.X sec)
[seeds] clientes: 0 seeded, 0 skipped (placeholder — pendente Story 3.1)
[seeds] processos: 0 seeded, 0 skipped (placeholder — pendente Story 3.4)
[seeds] tpu: 0 seeded, 0 skipped (placeholder — pendente Story 3.3)
[seeds] retention: 0 seeded, 0 skipped (placeholder — pendente Epic 8)
[seeds] TOTAL: 4 entidades novas em X.X sec.
```

## Senha única (apenas dev local)

Todos os usuários seedados têm senha `DevSeed2026!`. Esta senha **NUNCA** deve aparecer em produção — `ops/` não é distribuído nos zips de extensão e o bind mount não existe na receita de produção.

## Personas seedadas

| userName           | Persona                                | Role                              | Tipo    | Jornada PRD |
| ------------------ | -------------------------------------- | --------------------------------- | ------- | ----------- |
| `ricardo.ferraz`   | Dr. Ricardo Ferraz, sócio fundador     | `Sócio/Admin`                     | regular | 1           |
| `beatriz.souza`    | Dra. Beatriz Souza, advogada associada | `Advogado`                        | regular | 2           |
| `marli.nascimento` | Marli Nascimento, secretária           | `Secretária`                      | regular | 5           |
| `alberto.ferreira` | Sr. Alberto Ferreira, cliente idoso    | _(sem role atribuído — pendente)_ | portal  | 8           |

> **Notas sobre roles:**
>
> - Os nomes acima são os strings exatos da coluna `role.name` populados pelo togare-rbac (`AfterInstall.php` + `RoleSeeder`). O role do cliente portal foi seedado pelo togare-rbac como `Cliente-portal` (kebab-case com hífen) — não `Cliente Portal`. Use o nome exato em qualquer JSON futuro.
> - Em EspoCRM 9.x, **regular users** linkam com a tabela `role` via `roles[]` (link table `role_user`), e **portal users** linkam com `portal_role` via `portalRoles[]` (link table `portal_role_user`). São entidades distintas.
> - Limitação conhecida (2026-04-25, Story 1a.10): o togare-rbac (Story 2.1) seedou todos os 8 roles na tabela `role` regular — incluindo `Cliente-portal`, que deveria ter sido seedado como `portal_role`. Por isso Alberto é criado como portal user **sem role atribuído**. Quando uma story futura seedar o role de portal correspondente, basta editar `users.seed.json` movendo `Cliente-portal` de `portalRoles[]` (entrada placeholder atualmente vazia) e re-rodar o seed.

## Contrato JSON (uniforme entre seções)

Todo arquivo `*.seed.json` segue o esqueleto:

```json
{
  "_implemented": true,
  "_pendingStory": null,
  "_idempotencyKey": "userName",
  "_description": "humano-legível",
  "<entidade>": [ ... ]
}
```

Campos meta (todos com prefixo `_`):

- `_implemented` (bool obrigatório): se `false`, o orquestrador pula a seção sem erro e loga placeholder.
- `_pendingStory` (string|null): para placeholders, identifica a story que vai substituir o arquivo.
- `_pendingEntity` (string opcional): nome da entidade EspoCRM ainda não criada.
- `_idempotencyKey` (string): coluna ou campo usado como chave única para SELECT-before-INSERT.
- `_description` (string): texto humano-legível para futuros devs.
- `_schemaExpected` (object opcional, só em placeholders): documenta o formato esperado quando a story responsável implementar.

`load-seeds.php` lê na ordem fixa: `users` → `clientes` → `processos` → `tpu` → `retention`. Seções com `_implemented: false` são puladas com log informativo. **A ordem importa** porque seções futuras podem referenciar dados de seções anteriores (ex.: `clientes` referencia `userName` em `users`).

## Idempotência

Cada seção declara seu `_idempotencyKey`. O orquestrador faz `SELECT ... WHERE <key> = ? AND deleted = 0` antes de cada `INSERT` — match → skip. **Nunca sobrescreve** dados existentes (preserva customização do dev).

A 2ª execução em ambiente já seedado mostra:

```
[seeds] users: 0 seeded, 4 skipped, 0 errors (X.X sec)
...
```

Para resetar e re-seedar do zero:

```bash
cd docker
docker compose down -v   # destrói volume mariadb_data — perde TUDO
docker compose up -d
# aguardar healthcheck (~60s) + reinstalar togare-* extensions
docker compose exec espocrm php /var/www/html/ops/seeds/load-seeds.php
```

## Tratamento de erro

- Falha em UM user dentro de `seedUsers` é não-fatal — conta como `errors++` e segue para o próximo user da lista.
- Falha de role-not-found em `seedUsers` é fatal (AC6) — relança a exceção, o orquestrador detecta `essential: true`, sai com `exit 1` e mensagem indicando `togare-rbac` ausente.
- JSON inválido em arquivo essencial (`users.seed.json`) é fatal — `exit 1`.
- JSON inválido em placeholder não é fatal (apenas log de warning).

## Como adicionar nova seção (futuras stories)

1. Criar `ops/seeds/<nome>.seed.json` seguindo o contrato acima — começar como placeholder se a entidade ainda não existe.
2. Em `load-seeds.php`, registrar a seção no array `$sections` com handler dedicado (ex.: `seedClientes`).
3. Implementar o handler com a mesma assinatura de `seedUsers`:

   ```php
   function seedClientes(\Espo\ORM\EntityManager $em, \PDO $pdo, array $json, ?string $label = null): array
   ```

   E retorno `['seeded' => N, 'skipped' => N, 'errors' => N, 'durationSec' => float]`.

4. Documentar a nova seção neste README (tabela de personas se aplicável + qualquer dependência de outra seção).
5. Atualizar a story responsável (ex.: 3.1) para que o AC inclua "trocar `_implemented: false` por `_implemented: true` em `clientes.seed.json`".

## Stories envolvidas

- **1a.10** (esta story): infraestrutura + `users.seed.json`.
- **3.1**: substitui `clientes.seed.json` por dados reais.
- **3.4**: substitui `processos.seed.json` por dados reais.
- **3.3**: substitui `tpu-sample.seed.json` por amostra de TPU.
- **Epic 8**: substitui `retention-policies.seed.json` por políticas de retenção default.
