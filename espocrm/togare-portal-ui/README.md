# TogarePortalUi

## O que faz

Camada de UX do **Portal do Cliente** do Togare sobre o Portal **nativo** do EspoCRM (arquitetura módulo #7; FR26-FR28 suporte UX; UX-DR11/9/8/13/14; NFR28). Não existe app de Portal separado — o Portal é o nativo do EspoCRM + esta camada.

Story **7a.1** entrega o **PortalSplash branded configurável**:

- Sócio/Admin configura **logo, cor primária, frase de boas-vindas e telefone de fallback** em **Admin → Portal → Aparência**, com **defaults curados** do Togare (cor `#0d47a1` ≥7:1, frase aprovada no Gate A2).
- O cliente (perfil Alberto, idoso) vê uma tela de login **full-screen branded** — primeira impressão paga do produto. Rota crítica **AAA #1** (fonte ≥18px, alvos ≥48px, foco 2px, `prefers-reduced-motion`, zoom 200% sem scroll horizontal).
- Branding aplicado **somente no contexto Portal** (login operacional do CRM permanece o padrão EspoCRM — zero regressão).
- Storage: params globais de `Settings` (`togarePortalSplash*`), entregues **pré-autenticação** pelo canal `Config::getAllNonInternalData()` (mesmo do `companyLogoId` stock). Logo servido pelo entry point público `PortalSplashLogoImage` (NoAuth, restrito a `Settings.togarePortalSplashLogo`).

**O que NÃO faz** (escopo de outras stories do Épico 7a):

- Autenticação (senha temporária, troca obrigatória), ACL cross-cliente, estados de erro/lockout do splash → **Story 7a.2**.
- CardProcessoPortal / TimelinePortal / canal de mensagens → **Stories 7a.3–7a.5**.
- Gate **automatizado** de build que falha publicação se rota AAA cai <7:1 → **Story 7a.6** (consome o helper `helpers/contrast.js` deste módulo).
- Budget perf 3G / skeleton / lazy-PDF → **Story 7a.7**.

## Como instalar

Módulo **bundled** (`jsTranspiled`), depende de `togare-core >=0.37.2`. Ordem de install (ADR-03): `togare-core` → … → `togare-portal-ui`.

```bash
cd espocrm/togare-portal-ui
npm install && composer install
node build --extension                       # gera build/togare-portal-ui-<versao>.zip
# no container EspoCRM:
php command.php extension --file="/caminho/togare-portal-ui-<versao>.zip"
php rebuild.php
```

O `AfterInstall.php` (idempotente) seeda os defaults curados em config (só quando ausentes — preserva customização do admin) e publica o layout do painel em `data/layouts/Settings/portalAppearance.json` (Settings é entity STOCK — layout vai via `data/`, não `Resources/layouts`).

## Entidades expostas

Nenhuma entidade nova. O módulo **estende a entity STOCK `Settings`** com 4 campos de config (não-internos, entregues pré-auth):

| Campo | Tipo | Default |
|---|---|---|
| `togarePortalSplashLogo` | image | — (fallback: logo do escritório) |
| `togarePortalSplashPrimaryColor` | varchar (hex) | `#0d47a1` |
| `togarePortalSplashWelcome` | text | "Olá. Aqui você acompanha o andamento do seu processo." |
| `togarePortalSplashPhone` | varchar | — (placeholder pt-BR quando vazio) |

A5 N/A (sem entity nova — Controller de Settings é core). A6 N/A (config singleton, sem campo único/soft-delete).

## Hooks disparados / consumidos

Nenhum hook de ORM (entity hook) — não há entidade de domínio. Pontos de integração com o EspoCRM 9.x:

- **Consome** `clientDefs/App.loginView` → `togare-portal-ui:views/portal/login` (injetado em `config.loginView` por `SettingsService`, lido por `controllers/base.js`).
- **Consome** `app/adminPanel.json` (grupo `portal`, `__APPEND__`) → painel `#Admin/portalAppearance` (recordView Settings-backed).
- **Expõe** o entry point público `PortalSplashLogoImage` (`use NoAuth`; `allowedRelatedTypeList=['Settings']`, `allowedFieldList=['togarePortalSplashLogo']`).
- **Script** `AfterInstall.php` (seed de defaults curados + publicação de layout).

## Como testar

```bash
# JS (host)
npm test                                     # vitest 27/27

# PHP (container — PHP ausente no host)
docker run --rm -v "<monorepo>:/work" -w /work/espocrm/togare-portal-ui \
  --entrypoint php espocrm/espocrm:9.3 vendor/bin/phpunit   # 7/7

# Validador de imports do bundle (raiz do monorepo)
node tools/validate-bundle-imports.mjs        # 71 files OK

# Smoke F1 (Claude CLI — no container, pós install + rebuild)
php /tmp/smoke-7a-1-cli.php                    # 15/15
# setup do Portal de smoke + correção de usuário:
php /tmp/smoke-7a-1-setup-portal.php
php /tmp/smoke-7a-1-fix-portal-user.php        # senha precisa de PasswordHash (EspoCRM não hasheia via EntityManager)
```

Smoke browser (Felipe): roteiro de 7 passos nas Completion Notes de `_bmad-output/implementation-artifacts/7a-1-portal-splash-branded.md`. As 2 traps de ES module do EspoCRM 9.x só são pegas em browser.
