# Solução Nextcloud + EspoCRM + Portal do Cliente (codinome: **Togare**)

Plataforma integrada para escritório de advocacia, composta por múltiplos sistemas open source. O **EspoCRM** é o núcleo operacional (fonte da verdade) e o foco principal de customização.

## Componentes

| Componente        | Papel                                                            | Pasta                        |
| ----------------- | ---------------------------------------------------------------- | ---------------------------- |
| EspoCRM           | CRM central: clientes, processos, prazos, audiências, financeiro | [espocrm/](espocrm/)         |
| Nextcloud         | Hub de arquivos do escritório                                    | [nextcloud/](nextcloud/)     |
| Portal do Cliente | Interface web para clientes (Portal nativo do EspoCRM)           | _(integrado ao EspoCRM)_     |
| Integrações       | DataJud, AASP (com fallback), bot de prazos                      | [integracoes/](integracoes/) |

Ver [CLAUDE.md](CLAUDE.md) para princípios de desenvolvimento e [\_bmad-output/planning-artifacts/architecture.md](_bmad-output/planning-artifacts/architecture.md) para a arquitetura BMAD (fonte da verdade atual).

## Metodologia

Este projeto segue o **BMAD-METHOD** (https://github.com/bmad-code-org/BMAD-METHOD) para planejamento e execução. Artefatos em [\_bmad-output/planning-artifacts/](_bmad-output/planning-artifacts/) (brief, PRD, arquitetura, UX, epics).

## Pré-requisitos

**Mandatórios:**

- Docker Desktop 24+ com WSL2 (Windows) ou Docker Engine (Linux/macOS).
- Git 2.28+.

**Recomendados no host** (aceleram dev, eliminam spinup Docker em cada commit):

- Node.js ≥ 20 — para `prettier` e `lefthook`.
- PHP ≥ 8.2 — para `php-cs-fixer` e `tools/validate-togare-naming.php`.

Se não quiser instalar PHP/Node no host, o repositório funciona em **modo Docker one-shot** (ver "Como desenvolver" abaixo).

## Instalação em servidor (do zero — para o escritório)

Para colocar o sistema no ar num **servidor Linux** (VPS ou máquina própria),
sem precisar montar nada à mão. Baixe/clone o projeto no servidor e rode, na
pasta do projeto:

```bash
bash instalar.sh
```

O instalador confere o servidor, **instala o Docker se faltar**, **gera todas
as senhas fortes sozinho**, sobe a stack, instala os 6 módulos Togare e valida
tudo. As credenciais ficam em `docker/CREDENCIAIS-TOGARE.txt` (guarde fora do
servidor). Para domínio próprio com HTTPS automático:

```bash
bash instalar.sh --dominio crm.seuescritorio.adv.br --email voce@seuescritorio.adv.br
```

Rodar de novo é seguro (modo retomada). Detalhes e demais opções:
[docker/scripts/README.md](docker/scripts/README.md) → `install.sh`. Para
**atualizar** depois: `bash docker/scripts/update.sh`.

## Como rodar a stack em desenvolvimento

> Para produção use o instalador acima. O passo manual abaixo é só para
> desenvolvimento local.

```bash
cd docker
cp .env.example .env     # ajustar senhas antes de subir
docker compose up -d
```

URLs, smoke test e troubleshooting em [docker/README.md](docker/README.md).

## Como desenvolver

Pre-commit hooks via `lefthook` validam formatação (php-cs-fixer + prettier) e convenções Togare (prefixos, README obrigatório, prefixo `togare_` em tabelas, labels pt-BR). Referência: arquitetura Step 5 "Enforcement local (sem CI)".

### Primeira clonagem (setup 1x)

```bash
git clone <url>
cd nextcloud-crm

# 1) Instala dev deps Node (prettier + lefthook) e ativa hooks no .git/hooks/
npm install

# 2) Instala dev deps PHP (php-cs-fixer)
composer install
# ...ou, sem PHP no host:
docker run --rm -v "${PWD}:/app" -w /app composer:2 composer install
```

### Comandos do dia-a-dia

```bash
# Aplica auto-fix em tudo (formatação + validação Togare)
composer fix

# Só checagem, sem modificar nada (modo CI-like)
composer lint

# Prettier (JS/JSON/MD/YAML) sozinho
npm run fix        # aplica
npm run lint       # só verifica

# Rodar smoke test do validador Togare
bash tools/tests/run.sh
```

### Configurar git user (1x por clone)

```bash
git config --local user.name "Seu Nome"
git config --local user.email "seu-email@..."
```

### Quando um hook falhar

1. **Leia a mensagem do hook** — ela aponta arquivo, linha e convenção violada.
2. Rode `composer fix` (auto-corrige formatação) ou `npm run fix` (JS/MD).
3. Para violações de convenção Togare (prefixo, README, tabela), corrija manualmente seguindo o que a mensagem instruir.
4. Tente commitar de novo.

### Escape hatch — pular hooks em caso legítimo

Casos legítimos: fixture propositalmente mal formatada, código importado de upstream sem PSR-12, commit de merge com resolução manual.

```bash
git commit --no-verify -m "descreva a mudança [skip hooks: motivo aqui]"
```

A tag `[skip hooks: <motivo>]` é convenção (não é enforced) — deixa rastreável via `git log --grep="skip hooks"`.

### Modo Docker one-shot (sem PHP/Node no host)

O repo funciona sem PHP/Node no host, com overhead de alguns segundos por comando:

```bash
# Instalar deps PHP sem PHP no host
docker run --rm -v "${PWD}:/app" -w /app composer:2 composer install

# Instalar deps Node sem Node no host
docker run --rm -v "${PWD}:/app" -w /app node:20-alpine npm install

# Rodar validator sem PHP no host
docker run --rm -v "${PWD}:/app" -w /app php:8.3-cli \
  php tools/validate-togare-naming.php
```

O `lefthook.yml` já detecta ausência de PHP/Node no host e cai automaticamente em Docker fallback — **nada a mudar no fluxo de commit**.

## Decisões de arquitetura

ADRs numeradas em [docs/decisoes/](docs/decisoes/). Arquivos 0003-0007 formalizam decisões da arquitetura BMAD: extensions pattern, Caddy, outbox MariaDB, LGPD MVP, correlation header.

## Plano e status

- **Plano completo** (brief, PRD, arquitetura, UX, epics): [\_bmad-output/planning-artifacts/](_bmad-output/planning-artifacts/).
- **Sprint atual** e status de stories: `_bmad-output/implementation-artifacts/` (1 arquivo por story conforme são implementadas).
- **Plano de kickoff** histórico: [docs/plano-kickoff.md](docs/plano-kickoff.md).
