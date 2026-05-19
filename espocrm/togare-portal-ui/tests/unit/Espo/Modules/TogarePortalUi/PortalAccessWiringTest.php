<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogarePortalUi;

use PHPUnit\Framework\TestCase;

/**
 * Story 7a.2 — testes de CONTRATO/WIRING (sem container EspoCRM).
 *
 * Garante que o cabeamento nativo de auth/ACL do Portal está declarado
 * exatamente como o source 9.3.6 exige (regressão silenciosa = 404/200
 * em runtime), e que a copy exibida ao cliente final permanece o literal
 * aprovado por Felipe (Gate A2 + ajuste do mecanismo de link).
 */
final class PortalAccessWiringTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = dirname(__DIR__, 5)
            . '/src/files/custom/Espo/Modules/TogarePortalUi';
    }

    /** @return array<string, mixed> */
    private function json(string $rel): array
    {
        $file = $this->base . '/' . $rel;
        self::assertFileExists($file, "Arquivo ausente: $rel");

        $data = json_decode((string) file_get_contents($file), true);
        self::assertIsArray($data, "JSON inválido: $rel");

        return $data;
    }

    public function testProcessoScopeHabilitaAclPortal(): void
    {
        $scope = $this->json('Resources/metadata/scopes/Processo.json');
        self::assertTrue($scope['aclPortal'] ?? null, 'Processo.aclPortal deve ser true (AC5).');
    }

    public function testSelectDefsMapeiaPortalOnlyOwnParaFiltroDoModulo(): void
    {
        $defs = $this->json('Resources/metadata/selectDefs/Processo.json');

        self::assertSame(
            'Espo\\Modules\\TogarePortalUi\\Classes\\Select\\Processo\\AccessControlFilters\\PortalOnlyCliente',
            $defs['accessControlFilterClassNameMap']['portalOnlyOwn'] ?? null,
        );
    }

    public function testAclDefsRegistraOwnershipCheckerDePortal(): void
    {
        $defs = $this->json('Resources/metadata/aclDefs/Processo.json');

        self::assertSame(
            'Espo\\Modules\\TogarePortalUi\\Classes\\AclPortal\\Processo\\OwnershipChecker',
            $defs['portalOwnershipCheckerClassName'] ?? null,
        );
    }

    public function testRotaDeProvisionamentoRegistradaComActionClass(): void
    {
        $file = $this->base . '/Resources/routes.json';
        self::assertFileExists($file);

        $routes = json_decode((string) file_get_contents($file), true);
        self::assertIsArray($routes);

        $found = null;
        foreach ($routes as $r) {
            if (($r['route'] ?? null) === '/TogarePortalUi/PortalAccess/provision') {
                $found = $r;
                break;
            }
        }

        self::assertNotNull($found, 'Rota /TogarePortalUi/PortalAccess/provision ausente.');
        self::assertSame('post', $found['method']);
        self::assertSame(
            'Espo\\Modules\\TogarePortalUi\\Tools\\PortalAccess\\Api\\PostProvision',
            $found['actionClassName'],
        );
    }

    public function testVinculoUserClienteDeclaradoNosDoisLados(): void
    {
        $user = $this->json('Resources/metadata/entityDefs/User.json');
        self::assertSame('belongsTo', $user['links']['togareCliente']['type']);
        self::assertSame('Cliente', $user['links']['togareCliente']['entity']);
        self::assertSame('portalUsers', $user['links']['togareCliente']['foreign']);

        $cliente = $this->json('Resources/metadata/entityDefs/Cliente.json');
        self::assertSame('hasMany', $cliente['links']['portalUsers']['type']);
        self::assertSame('User', $cliente['links']['portalUsers']['entity']);
        self::assertSame('togareCliente', $cliente['links']['portalUsers']['foreign']);
    }

    public function testClientDefsClienteAdicionaRowActionLiberarAcesso(): void
    {
        $defs = $this->json('Resources/metadata/clientDefs/Cliente.json');

        $dropdown = $defs['menu']['detail']['dropdown'] ?? [];
        self::assertNotEmpty($dropdown);

        $item = $dropdown[0];
        self::assertSame('liberarAcessoPortal', $item['name']);
        self::assertSame('PortalAccess.labels.liberarAcessoPortal', $item['labelTranslation']);
        self::assertSame('togare-portal-ui:handlers/cliente/portal-access-handler', $item['handler']);
        self::assertSame('provision', $item['actionFunction']);
    }

    public function testI18nPortalAccessContemCopyAprovadaA2(): void
    {
        $i18n = $this->json('Resources/i18n/pt_BR/PortalAccess.json');

        self::assertSame(
            'Acesso ao Portal do Cliente — {escritorio}',
            $i18n['messages']['provisionEmailSubject'],
        );

        $body = $i18n['messages']['provisionEmailBody'];
        // Mecanismo = link único nativo: NENHUMA senha trafega por e-mail.
        self::assertStringContainsString('{link}', $body);
        self::assertStringContainsString('{userName}', $body);
        self::assertStringContainsString('crie a sua senha', $body);
        self::assertStringNotContainsStringIgnoringCase('senha temporária', $body);
        self::assertStringNotContainsStringIgnoringCase('sua senha é', $body);

        self::assertSame(
            'Liberar acesso ao Portal',
            $i18n['labels']['liberarAcessoPortal'],
        );
    }

    public function testI18nPortalSplashCopyErroLockoutLiteralAprovado(): void
    {
        $i18n = $this->json('Resources/i18n/pt_BR/PortalSplash.json');

        // senhaErrada: literal ajustado p/ o link, aprovado por Felipe
        // (AskUserQuestion 2026-05-17). NUNCA "Credenciais inválidas".
        self::assertSame(
            'Não conseguimos entrar com esses dados. Verifique a senha que você criou. Se esqueceu, ligue para o escritório.',
            $i18n['messages']['senhaErrada'],
        );
        self::assertStringNotContainsStringIgnoringCase('credenciais', $i18n['messages']['senhaErrada']);
        self::assertStringNotContainsStringIgnoringCase('acesso negado', $i18n['messages']['senhaErrada']);

        // lockout: literal Copy Default v1 + placeholder {telefone}.
        self::assertSame(
            'Por segurança, o acesso foi pausado por 15 minutos. Se precisar entrar agora, ligue para o escritório: {telefone}.',
            $i18n['messages']['lockout'],
        );
        self::assertStringContainsString('{telefone}', $i18n['messages']['lockout']);

        self::assertSame(
            'Seu advogado ainda não habilitou o acompanhamento online. Entre em contato com o escritório.',
            $i18n['messages']['semProcessoLiberado'],
        );
    }

    public function testProvisionServiceNuncaGeraSenhaEmClaro(): void
    {
        $src = (string) file_get_contents(
            $this->base . '/Tools/PortalAccess/ProvisionService.php',
        );

        // Mecanismo de link nativo: usa PasswordChangeRequest, NÃO seta
        // senha no User nem usa PasswordHash (NFR8 — nada em claro).
        // (Atenção: a string literal 'password' aparece em COMENTÁRIO
        // explicativo do ProvisionService — por isso checamos os padrões
        // perigosos REAIS de atribuição/geração, não o literal solto.)
        self::assertStringContainsString('createRequestForNewUser', $src);
        self::assertStringNotContainsString("set('password'", $src);
        self::assertStringNotContainsString("'password' =>", $src);
        self::assertStringNotContainsString('"password" =>', $src);
        self::assertStringNotContainsString('PasswordHash', $src);
        self::assertStringNotContainsString('generatePassword', $src);
        self::assertStringContainsString("'userName' => \$userName", $src);
        self::assertStringContainsString("'link' => \$link", $src);
        // Best-effort não-bloqueante: e-mail em try/catch que não propaga.
        self::assertStringContainsString('sendProvisionEmailBestEffort', $src);
        self::assertStringContainsString("portal.acesso_provisionado", $src);
    }

    /**
     * Regressão (bug smoke browser Felipe 2026-05-17): o link do entry
     * point `changePassword` (NoAuth, standalone) DEVE ser servido pela
     * RAIZ DO SITE — `{siteUrl}/?entryPoint=changePassword&id=…`. Neste
     * deploy o `/portal/<id>` é reverse-proxied (Caddy→Apache) e NÃO serve
     * entry point: com barra → loop/HTTP 414; sem barra → 404 + assets em
     * `/portal/client/`. Provado por HTTP que só a raiz responde 200.
     */
    public function testLinkChangePasswordUsaRaizDoSiteNaoOPortal(): void
    {
        $src = (string) file_get_contents(
            $this->base . '/Tools/PortalAccess/ProvisionService.php',
        );

        // Link montado a partir do siteUrl (raiz), não do portalUrl.
        self::assertStringContainsString("\$this->config->get('siteUrl')", $src);
        self::assertStringContainsString(
            "\$siteUrlRoot . '/?entryPoint=changePassword&id=' . \$requestId",
            $src,
            'Link deve ser {siteUrl}/?entryPoint=changePassword&id=… (raiz do site).',
        );
        // NÃO concatenar o entry point na URL do Portal (causa loop/414/404).
        self::assertStringNotContainsString(
            "\$portalUrl, '/') . '?entryPoint=changePassword",
            $src,
        );
        self::assertStringNotContainsString(
            "\$portalUrl, '/') . '/?entryPoint=changePassword",
            $src,
        );
        // portalUrl continua sendo passado ao request (redirect pós-troca);
        // resolvePortalUrl mantém o alinhamento com o core (loadUrlField).
        self::assertStringContainsString('loadUrlField', $src);
    }

    public function testPostProvisionEhFailClosedAdminOnly(): void
    {
        $src = (string) file_get_contents(
            $this->base . '/Tools/PortalAccess/Api/PostProvision.php',
        );

        self::assertStringContainsString('implements Action', $src);
        self::assertStringContainsString('isAdmin()', $src);
        self::assertStringContainsString('throw new Forbidden', $src);
    }

    public function testAfterInstallSemeiaPortalRoleReadOwnIdempotente(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 5) . '/src/scripts/AfterInstall.php',
        );

        self::assertStringContainsString('ensurePortalRole', $src);
        self::assertStringContainsString("'read' => 'own'", $src);
        self::assertStringContainsString("'create' => 'no'", $src);
        self::assertStringContainsString("'edit' => 'no'", $src);
        self::assertStringContainsString("'delete' => 'no'", $src);
        self::assertStringContainsString('PORTAL_ROLE_NAME', $src);
        // Idempotência: caminho de skip quando já presente e correto.
        self::assertStringContainsString('idempotente', $src);
    }

    /**
     * Regressão (bug Felipe 2026-05-17): AfterInstall normaliza
     * `passwordChangeRequest*Lifetime` inválido (ex.: número puro `168`)
     * — senão `DateTime::modify('+168')` falha, o cleanup roda na hora e
     * o link de senha nasce morto ("solicitação não encontrada").
     */
    public function testAfterInstallNormalizaLifetimeInvalido(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 5) . '/src/scripts/AfterInstall.php',
        );

        self::assertStringContainsString('ensureRecoveryRequestLifetimeValid', $src);
        self::assertStringContainsString('passwordChangeRequestNewUserLifetime', $src);
        self::assertStringContainsString('isValidRelativeTime', $src);
        // Número puro vira "N hours" (preserva intenção de "168" horas).
        self::assertStringContainsString("' hours'", $src);
    }
}
