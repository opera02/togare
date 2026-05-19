<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogarePortalUi;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Testes de CONTRATO da Story 7a.1 (não dependem do container EspoCRM):
 *
 *  - Copy default da frase de boas-vindas == literal APROVADO por Felipe
 *    no Gate A2 (regressão dessa string é mudança de domínio — bloquear).
 *  - Cor default curada == #0d47a1 (AAA folgado; 8.63:1 c/ texto branco).
 *  - Layout do painel admin (stock entity Settings) é JSON válido e
 *    referencia os 4 campos togarePortalSplash*.
 *  - entityDefs/Settings.json declara os 4 campos com os tipos esperados.
 *  - i18n PortalSplash.json: splashDefault == aprovado; phoneFallback tem
 *    o placeholder {telefone}.
 */
final class PortalSplashContractTest extends TestCase
{
    private const APPROVED_WELCOME = 'Olá. Aqui você acompanha o andamento do seu processo.';
    private const CURATED_COLOR = '#0d47a1';

    private string $moduleRoot;

    protected function setUp(): void
    {
        $this->moduleRoot = dirname(__DIR__, 5);
        require_once $this->moduleRoot . '/src/scripts/AfterInstall.php';
    }

    public function testAfterInstallCuratedDefaultsMatchApprovedCopyAndColor(): void
    {
        $ref = new ReflectionClass(\AfterInstall::class);

        $welcome = $ref->getConstant('DEFAULT_WELCOME');
        $color = $ref->getConstant('DEFAULT_COLOR');

        self::assertSame(
            self::APPROVED_WELCOME,
            $welcome,
            'Frase default divergiu do literal aprovado por Felipe no Gate A2.',
        );
        self::assertSame(self::CURATED_COLOR, $color);
        self::assertMatchesRegularExpression('/^#[0-9a-fA-F]{6}$/', $color);
    }

    public function testPortalAppearanceLayoutIsValidAndReferencesFourFields(): void
    {
        $file = $this->moduleRoot
            . '/src/files/custom/Espo/Modules/TogarePortalUi/Resources/layouts/Settings/portalAppearance.json';

        self::assertFileExists($file);

        $json = json_decode((string) file_get_contents($file), true);
        self::assertIsArray($json, 'Layout portalAppearance.json inválido.');

        $flat = json_encode($json);
        foreach ([
            'togarePortalSplashLogo',
            'togarePortalSplashPrimaryColor',
            'togarePortalSplashWelcome',
            'togarePortalSplashPhone',
        ] as $field) {
            self::assertStringContainsString($field, (string) $flat);
        }
    }

    public function testSettingsEntityDefsDeclaresFourFieldsWithTypes(): void
    {
        $file = $this->moduleRoot
            . '/src/files/custom/Espo/Modules/TogarePortalUi/Resources/metadata/entityDefs/Settings.json';

        self::assertFileExists($file);

        $defs = json_decode((string) file_get_contents($file), true);
        self::assertIsArray($defs);
        self::assertArrayHasKey('fields', $defs);

        $fields = $defs['fields'];

        self::assertSame('image', $fields['togarePortalSplashLogo']['type']);
        self::assertSame('varchar', $fields['togarePortalSplashPrimaryColor']['type']);
        self::assertSame(self::CURATED_COLOR, $fields['togarePortalSplashPrimaryColor']['default']);
        self::assertSame('text', $fields['togarePortalSplashWelcome']['type']);
        self::assertSame(self::APPROVED_WELCOME, $fields['togarePortalSplashWelcome']['default']);
        self::assertSame('varchar', $fields['togarePortalSplashPhone']['type']);
    }

    public function testI18nPortalSplashCopyMatchesApprovedAndHasPhonePlaceholder(): void
    {
        $file = $this->moduleRoot
            . '/src/files/custom/Espo/Modules/TogarePortalUi/Resources/i18n/pt_BR/PortalSplash.json';

        self::assertFileExists($file);

        $i18n = json_decode((string) file_get_contents($file), true);
        self::assertIsArray($i18n);

        self::assertSame(
            self::APPROVED_WELCOME,
            $i18n['messages']['splashDefault'],
            'splashDefault do i18n divergiu do literal aprovado no Gate A2.',
        );
        self::assertStringContainsString(
            '{telefone}',
            $i18n['messages']['phoneFallback'],
            'phoneFallback deve conter o placeholder {telefone}.',
        );
        self::assertNotEmpty($i18n['messages']['phonePlaceholder']);
    }

    public function testClientDefsRegistersCustomLoginView(): void
    {
        $file = $this->moduleRoot
            . '/src/files/custom/Espo/Modules/TogarePortalUi/Resources/metadata/clientDefs/App.json';

        self::assertFileExists($file);

        $defs = json_decode((string) file_get_contents($file), true);
        self::assertSame('togare-portal-ui:views/portal/login', $defs['loginView']);
    }

    public function testPortalSplashLogoEntryPointExists(): void
    {
        $file = $this->moduleRoot
            . '/src/files/custom/Espo/Modules/TogarePortalUi/EntryPoints/PortalSplashLogoImage.php';

        self::assertFileExists($file);

        $contents = (string) file_get_contents($file);
        self::assertStringContainsString("protected \$allowedRelatedTypeList = ['Settings'];", $contents);
        self::assertStringContainsString("protected \$allowedFieldList = ['togarePortalSplashLogo'];", $contents);
    }

    public function testAdminPanelAppendsPortalAppearanceItem(): void
    {
        $file = $this->moduleRoot
            . '/src/files/custom/Espo/Modules/TogarePortalUi/Resources/metadata/app/adminPanel.json';

        self::assertFileExists($file);

        $defs = json_decode((string) file_get_contents($file), true);
        self::assertIsArray($defs['portal']['itemList']);
        self::assertContains('__APPEND__', $defs['portal']['itemList']);

        $item = null;
        foreach ($defs['portal']['itemList'] as $entry) {
            if (is_array($entry) && ($entry['url'] ?? null) === '#Admin/portalAppearance') {
                $item = $entry;
                break;
            }
        }

        self::assertNotNull($item, 'Item #Admin/portalAppearance ausente no itemList.');
        self::assertSame(
            'togare-portal-ui:views/admin/portal-appearance',
            $item['recordView'],
        );
        self::assertSame('portalAppearance', $item['description']);
    }
}
