<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Metadata;

use PHPUnit\Framework\TestCase;

/**
 * Story 4b.1a — valida metadata da entity PublicacaoAmbigua (entityDefs +
 * clientDefs + selectDefs) + presença em layouts + i18n + Global.json patch.
 *
 * Defesa contra regressões silenciosas (campos sumindo, classes não bindadas
 * no boolFilterClassNameMap, scopeNames ausentes).
 *
 * Pattern espelha PrazoMetadataTest da Story 4a.5.1.
 */
final class PublicacaoAmbiguaMetadataTest extends TestCase
{
    private const METADATA_DIR = __DIR__ . '/../../../../../../src/files/custom/Espo/Modules/TogareCore/Resources/metadata';
    private const LAYOUTS_DIR = __DIR__ . '/../../../../../../src/files/custom/Espo/Modules/TogareCore/Resources/layouts';
    private const I18N_DIR = __DIR__ . '/../../../../../../src/files/custom/Espo/Modules/TogareCore/Resources/i18n/pt_BR';
    private const CLASSES_DIR = __DIR__ . '/../../../../../../src/files/custom/Espo/Modules/TogareCore/Classes';

    /**
     * @return array<string, mixed>
     */
    private function loadJson(string $path): array
    {
        self::assertFileExists($path, "Arquivo ausente: {$path}");

        /** @var array<string, mixed> $data */
        $data = \json_decode((string) \file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    public function testEntityDefsTemTodosOsCamposEsperados(): void
    {
        $entityDefs = $this->loadJson(self::METADATA_DIR . '/entityDefs/PublicacaoAmbigua.json');

        $fields = $entityDefs['fields'] ?? null;
        self::assertIsArray($fields);

        $expected = [
            'name', 'sourcePubId', 'numeroProcessoOriginal', 'payload', 'texto',
            'dataDisponibilizacao', 'dataInicioPrazo', 'dataFatal', 'prazoDias',
            'contagem', 'atoCodigo', 'referenciaLegal', 'confidence',
            'parserRegraVersao', 'fonteExcerpt', 'candidatos', 'ambiguityReason',
            'status', 'decisionType', 'decidedAt',
            'tenantId', 'createdAt', 'modifiedAt', 'createdBy', 'modifiedBy',
            'assignedUser', 'decidedBy', 'decisionProcesso', 'prazoCriado',
        ];

        foreach ($expected as $f) {
            self::assertArrayHasKey($f, $fields, "Field '{$f}' ausente em entityDefs/PublicacaoAmbigua.json");
        }
    }

    public function testStatusEnumTem4Opcoes(): void
    {
        $entityDefs = $this->loadJson(self::METADATA_DIR . '/entityDefs/PublicacaoAmbigua.json');
        $status = $entityDefs['fields']['status'] ?? null;

        self::assertIsArray($status);
        self::assertSame('enum', $status['type'] ?? null);
        self::assertSame(
            ['pendente_revisao', 'resolvido', 'ignorado', 'bulk_ignorado'],
            $status['options'] ?? [],
        );
        self::assertSame('pendente_revisao', $status['default'] ?? null);
    }

    public function testIndexesCriticosDeclarados(): void
    {
        $entityDefs = $this->loadJson(self::METADATA_DIR . '/entityDefs/PublicacaoAmbigua.json');
        $indexes = $entityDefs['indexes'] ?? null;

        self::assertIsArray($indexes);

        // sourcePubId UNIQUE — idempotência cross-table com prazo.sourcePubId.
        self::assertArrayHasKey('sourcePubId', $indexes);
        self::assertSame('unique', $indexes['sourcePubId']['type'] ?? null);

        // statusAssignedUser composto — cobre boolFilter PrecisaSuaLeitura sem filesort.
        self::assertArrayHasKey('statusAssignedUser', $indexes);
        self::assertSame(
            ['status', 'assignedUserId'],
            $indexes['statusAssignedUser']['columns'] ?? [],
        );
    }

    public function testClientDefsTemBoolFilterPrecisaSuaLeitura(): void
    {
        $clientDefs = $this->loadJson(self::METADATA_DIR . '/clientDefs/PublicacaoAmbigua.json');

        self::assertSame('controllers/record', $clientDefs['controller'] ?? null);

        $boolFilters = $clientDefs['boolFilterList'] ?? [];
        self::assertContains('precisaSuaLeitura', $boolFilters);
        self::assertContains('onlyMy', $boolFilters);

        // Defesa: portal não acessa fila ambíguos.
        $accessDataList = $clientDefs['accessDataList'] ?? [];
        $hasPortalDisabled = false;
        foreach ($accessDataList as $entry) {
            if (\is_array($entry) && ($entry['inPortalDisabled'] ?? false) === true) {
                $hasPortalDisabled = true;
                break;
            }
        }
        self::assertTrue($hasPortalDisabled, 'inPortalDisabled deve estar true em accessDataList');
    }

    public function testSelectDefsBindaPrecisaSuaLeituraNoMap(): void
    {
        $selectDefs = $this->loadJson(self::METADATA_DIR . '/selectDefs/PublicacaoAmbigua.json');
        $map = $selectDefs['boolFilterClassNameMap'] ?? null;

        self::assertIsArray($map);
        self::assertSame(
            'Espo\\Modules\\TogareCore\\Classes\\Select\\Bool\\PublicacaoAmbigua\\PrecisaSuaLeitura',
            $map['precisaSuaLeitura'] ?? null,
        );
    }

    public function testScopesMetadataDeclaraEntidadeTabEAcl(): void
    {
        $scopes = $this->loadJson(self::METADATA_DIR . '/scopes/PublicacaoAmbigua.json');

        self::assertTrue($scopes['entity'] ?? false);
        self::assertTrue($scopes['object'] ?? false);
        self::assertSame('TogareCore', $scopes['module'] ?? null);
        self::assertTrue($scopes['tab'] ?? false);
        self::assertTrue($scopes['acl'] ?? false);
        self::assertFalse($scopes['aclPortal'] ?? true);
    }

    public function testPrecisaSuaLeituraClassFileExiste(): void
    {
        $path = self::CLASSES_DIR . '/Select/Bool/PublicacaoAmbigua/PrecisaSuaLeitura.php';
        self::assertFileExists($path, 'PrecisaSuaLeitura.php deve existir no path declarado em selectDefs');
    }

    public function testGlobalJsonContemScopeNamePtBr(): void
    {
        $global = $this->loadJson(self::I18N_DIR . '/Global.json');

        self::assertSame(
            'Precisa sua leitura',
            $global['scopeNames']['PublicacaoAmbigua'] ?? null,
            'Global.json::scopeNames.PublicacaoAmbigua deve ser "Precisa sua leitura" (regra B19 Story 4a.5)',
        );

        self::assertSame(
            'Publicações em revisão',
            $global['scopeNamesPlural']['PublicacaoAmbigua'] ?? null,
        );

        // Defesa: tabs.PublicacaoAmbigua para AfterInstall.ensureTabsInNavbar.
        self::assertSame(
            'Precisa sua leitura',
            $global['labels']['Global']['tabs']['PublicacaoAmbigua'] ?? null,
        );
    }

    public function testI18nPublicacaoAmbiguaJsonTemFieldsEMessages(): void
    {
        $i18n = $this->loadJson(self::I18N_DIR . '/PublicacaoAmbigua.json');

        // Sample dos labels críticos.
        self::assertArrayHasKey('fields', $i18n);
        self::assertArrayHasKey('candidatos', $i18n['fields']);
        self::assertArrayHasKey('ambiguityReason', $i18n['fields']);
        self::assertArrayHasKey('status', $i18n['fields']);

        // Options.status traduzidos.
        self::assertSame(
            'Precisa sua leitura',
            $i18n['options']['status']['pendente_revisao'] ?? null,
        );

        // Messages para 4b.1b (mas declaradas aqui pra evitar refactor).
        self::assertArrayHasKey('messages', $i18n);
        self::assertArrayHasKey('alreadyResolved', $i18n['messages']);
        self::assertArrayHasKey('invalidCandidate', $i18n['messages']);
        self::assertArrayHasKey('bulkIgnoreSuccess', $i18n['messages']);

        // BoolFilter label.
        self::assertSame(
            'Precisa sua leitura',
            $i18n['boolFilters']['precisaSuaLeitura'] ?? null,
        );
    }

    public function testLayoutsListDetailFiltersExistem(): void
    {
        self::assertFileExists(self::LAYOUTS_DIR . '/PublicacaoAmbigua/list.json');
        self::assertFileExists(self::LAYOUTS_DIR . '/PublicacaoAmbigua/detail.json');
        self::assertFileExists(self::LAYOUTS_DIR . '/PublicacaoAmbigua/filters.json');
    }
}
