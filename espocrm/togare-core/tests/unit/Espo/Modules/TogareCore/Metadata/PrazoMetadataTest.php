<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Metadata;

use PHPUnit\Framework\TestCase;

/**
 * Story 4a.3 — valida metadata da entity Prazo (entityDefs/scopes/clientDefs/
 * aclDefs) + reverse link em Processo.json + boolFilter classes em
 * Classes/Select/Bool/Prazo/. Defesa contra regressões silenciosas.
 */
final class PrazoMetadataTest extends TestCase
{
    private const METADATA_DIR = __DIR__ . '/../../../../../../src/files/custom/Espo/Modules/TogareCore/Resources/metadata';
    private const LAYOUTS_DIR = __DIR__ . '/../../../../../../src/files/custom/Espo/Modules/TogareCore/Resources/layouts';
    private const I18N_DIR = __DIR__ . '/../../../../../../src/files/custom/Espo/Modules/TogareCore/Resources/i18n/pt_BR';
    private const CLASSES_DIR = __DIR__ . '/../../../../../../src/files/custom/Espo/Modules/TogareCore/Classes';

    /**
     * @return array<string, mixed>
     */
    private function loadJson(string $relPath): array
    {
        $path = self::METADATA_DIR . '/' . $relPath;
        self::assertFileExists($path, "Arquivo de metadata ausente: {$relPath}");

        /** @var array<string, mixed> $data */
        $data = \json_decode((string) \file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * @return array<int, mixed>
     */
    private function loadLayoutJson(string $relPath): array
    {
        $path = self::LAYOUTS_DIR . '/' . $relPath;
        self::assertFileExists($path, "Arquivo de layout ausente: {$relPath}");

        /** @var array<int, mixed> $data */
        $data = \json_decode((string) \file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    public function testEntityDefsTemTodosOsCamposDoSchema(): void
    {
        $entityDefs = $this->loadJson('entityDefs/Prazo.json');

        $fields = $entityDefs['fields'] ?? null;
        self::assertIsArray($fields);

        $expected = [
            'name', 'dataDisponibilizacao', 'dataInicioPrazo', 'dataFatal',
            'prazoDias', 'contagem', 'atoCodigo', 'referenciaLegal',
            'confidence', 'parserRegraVersao', 'fonteExcerpt',
            'source', 'sourcePubId', 'numeroProcessoOriginal',
            'publicacaoOrigemRaw', 'status',
            'tenantId', 'createdAt', 'modifiedAt', 'createdBy', 'modifiedBy',
            'assignedUser', 'processo', 'processoName',
            // Story 4a.3.1 — 5 campos novos + 1 link.
            'descricao', 'prioridade', 'tipoPrazo', 'motivoReagendamento',
            'cliente', 'parteContraria',
        ];
        foreach ($expected as $f) {
            self::assertArrayHasKey($f, $fields, "Field '{$f}' ausente no entityDefs/Prazo.json");
        }
    }

    /** Story 4a.3.1 — status enum 6→9 valores (8 visíveis + descartado oculto). */
    public function testStatusEnumTem9Opcoes(): void
    {
        $entityDefs = $this->loadJson('entityDefs/Prazo.json');
        $status = $entityDefs['fields']['status'] ?? null;

        self::assertIsArray($status);
        self::assertSame('enum', $status['type'] ?? null);
        // Default mudou de 'pendente' para 'rascunho' na 4a.3.1.
        self::assertSame('rascunho', $status['default'] ?? null);

        $expected = [
            'rascunho',
            'pendente',
            'atrasado_reagendado',
            'aguardando_cliente',
            'aguardando_correcao',
            'protocolado',
            'ciencia_renuncia',
            'acompanhamento',
            'descartado',
        ];
        foreach ($expected as $opt) {
            self::assertContains($opt, $status['options'] ?? [], "status enum sem '{$opt}'");
        }
        self::assertCount(9, $status['options'] ?? []);

        // Status legados removidos (V009 destrutiva).
        self::assertNotContains('rascunho_nao_vinculado', $status['options'] ?? []);
        self::assertNotContains('confirmado', $status['options'] ?? []);
        self::assertNotContains('cumprido', $status['options'] ?? []);
        self::assertNotContains('revertido', $status['options'] ?? []);
    }

    /** Story 4a.3.1 — novo enum prioridade. */
    public function testPrioridadeEnumTem4Opcoes(): void
    {
        $entityDefs = $this->loadJson('entityDefs/Prazo.json');
        $prioridade = $entityDefs['fields']['prioridade'] ?? null;

        self::assertIsArray($prioridade);
        self::assertSame('enum', $prioridade['type'] ?? null);
        self::assertSame('normal', $prioridade['default'] ?? null);
        self::assertContains('baixa', $prioridade['options'] ?? []);
        self::assertContains('normal', $prioridade['options'] ?? []);
        self::assertContains('alta', $prioridade['options'] ?? []);
        self::assertContains('urgente', $prioridade['options'] ?? []);
    }

    /** Story 4a.3.1 — tipoPrazo enum 17 + opção vazia (Apêndice A PRD). */
    public function testTipoPrazoEnumTem17OpcoesEhNullable(): void
    {
        $entityDefs = $this->loadJson('entityDefs/Prazo.json');
        $tipoPrazo = $entityDefs['fields']['tipoPrazo'] ?? null;

        self::assertIsArray($tipoPrazo);
        self::assertSame('enum', $tipoPrazo['type'] ?? null);
        self::assertFalse($tipoPrazo['required'] ?? true, 'tipoPrazo deve ser nullable (Decisão #3)');

        // Coerência com o Apêndice A do PRD v1.3 (17 valores + 1 vazio).
        $expected = [
            'peticao_inicial', 'contestacao', 'replica', 'apelacao',
            'agravo_instrumento', 'agravo_interno', 'embargos_declaracao',
            'resp_re', 'aresp_are', 'manifestacao_diversa',
            'impugnacao_laudo_cumprimento', 'contrarrazoes', 'idpj',
            'mandado_seguranca', 'audiencia', 'ciencia_renuncia',
            'diligencia_administrativa',
        ];
        foreach ($expected as $opt) {
            self::assertContains($opt, $tipoPrazo['options'] ?? [], "tipoPrazo sem '{$opt}'");
        }
    }

    /** Story 4a.3.1 — links Cliente + ParteContraria belongsTo. */
    public function testLinksClienteEParteContrariaExistem(): void
    {
        $entityDefs = $this->loadJson('entityDefs/Prazo.json');

        $cliente = $entityDefs['links']['cliente'] ?? null;
        self::assertIsArray($cliente);
        self::assertSame('belongsTo', $cliente['type']);
        self::assertSame('Cliente', $cliente['entity']);
        self::assertSame('prazos', $cliente['foreign'] ?? null);

        $parte = $entityDefs['links']['parteContraria'] ?? null;
        self::assertIsArray($parte);
        self::assertSame('belongsTo', $parte['type']);
        self::assertSame('ParteContraria', $parte['entity']);
        self::assertSame('prazos', $parte['foreign'] ?? null);
    }

    /** Story 4a.3.1 — indexes auxiliares novos. */
    public function testIndexesNovosExistem(): void
    {
        $entityDefs = $this->loadJson('entityDefs/Prazo.json');
        $indexes = $entityDefs['indexes'] ?? [];

        self::assertArrayHasKey('prioridade', $indexes);
        self::assertArrayHasKey('tipoPrazo', $indexes);
        self::assertArrayHasKey('clienteId', $indexes);
        self::assertArrayHasKey('parteContrariaId', $indexes);
    }

    /** Story 4a.5 — coluna `prioridadeWeight` derivada (oculta da UI; ordering only). */
    public function testPrioridadeWeightOcultoDaUI(): void
    {
        $entityDefs = $this->loadJson('entityDefs/Prazo.json');
        $field = $entityDefs['fields']['prioridadeWeight'] ?? null;

        self::assertIsArray($field, 'Field prioridadeWeight ausente no entityDefs');
        self::assertSame('int', $field['type'] ?? null);
        self::assertSame(2, $field['default'] ?? null, 'Default = 2 (normal)');
        self::assertTrue($field['readOnly'] ?? false);
        self::assertTrue($field['layoutListDisabled'] ?? false);
        self::assertTrue($field['layoutDetailDisabled'] ?? false);
        self::assertTrue($field['layoutMassUpdateDisabled'] ?? false);
        self::assertTrue($field['layoutFiltersDisabled'] ?? false);
    }

    /** Story 4a.5 — index simples + composto para ORDER BY do dashlet. */
    public function testIndexPrioridadeWeightExiste(): void
    {
        $entityDefs = $this->loadJson('entityDefs/Prazo.json');
        $indexes = $entityDefs['indexes'] ?? [];

        self::assertArrayHasKey('prioridadeWeight', $indexes);
        self::assertArrayHasKey('dataFatalPrioridadeWeight', $indexes);

        // Composto: dataFatal + prioridadeWeight (ordem do dashlet).
        $composto = $indexes['dataFatalPrioridadeWeight'] ?? null;
        self::assertIsArray($composto);
        self::assertSame(['dataFatal', 'prioridadeWeight'], $composto['columns'] ?? null);
    }

    public function testContagemEnumTem2Opcoes(): void
    {
        $entityDefs = $this->loadJson('entityDefs/Prazo.json');
        $contagem = $entityDefs['fields']['contagem'] ?? null;

        self::assertIsArray($contagem);
        self::assertSame('enum', $contagem['type'] ?? null);
        self::assertSame('uteis', $contagem['default'] ?? null);
        self::assertContains('uteis', $contagem['options'] ?? []);
        self::assertContains('corridos', $contagem['options'] ?? []);
    }

    public function testConfidenceEnumTem3Opcoes(): void
    {
        $entityDefs = $this->loadJson('entityDefs/Prazo.json');
        $confidence = $entityDefs['fields']['confidence'] ?? null;

        self::assertIsArray($confidence);
        self::assertContains('high', $confidence['options'] ?? []);
        self::assertContains('medium', $confidence['options'] ?? []);
        self::assertContains('low', $confidence['options'] ?? []);
    }

    public function testSourceEnumTem3Opcoes(): void
    {
        $entityDefs = $this->loadJson('entityDefs/Prazo.json');
        $source = $entityDefs['fields']['source'] ?? null;

        self::assertIsArray($source);
        self::assertContains('djen', $source['options'] ?? []);
        self::assertContains('manual', $source['options'] ?? []);
        self::assertContains('manual_ambiguo', $source['options'] ?? []);
    }

    /** Story 4a.3.1 / P5 review G1 — UNIQUE removido do entityDefs (vive isolado em V008). */
    public function testIndexSourcePubIdNaoEhUniqueNoEntityDefs(): void
    {
        $entityDefs = $this->loadJson('entityDefs/Prazo.json');
        $indexes = $entityDefs['indexes'] ?? [];

        self::assertArrayHasKey('sourcePubId', $indexes, 'Index sourcePubId ainda existe no entityDefs (sem unique:true)');
        self::assertFalse(
            $indexes['sourcePubId']['unique'] ?? false,
            'P5 review G1 — UNIQUE removido do entityDefs (dual-index com V008 era redundante)',
        );
    }

    public function testEntityDefsTemIndexesAuxiliares(): void
    {
        $entityDefs = $this->loadJson('entityDefs/Prazo.json');
        $indexes = $entityDefs['indexes'] ?? [];

        self::assertArrayHasKey('dataFatal', $indexes);
        self::assertArrayHasKey('statusDataFatal', $indexes);
        self::assertArrayHasKey('processoId', $indexes);
        self::assertArrayHasKey('assignedUserId', $indexes);
        self::assertArrayHasKey('numeroProcessoOriginal', $indexes);

        self::assertSame(['status', 'dataFatal'], $indexes['statusDataFatal']['columns']);
    }

    public function testEntityDefsTemLinkProcessoBelongsToOpcional(): void
    {
        $entityDefs = $this->loadJson('entityDefs/Prazo.json');

        $link = $entityDefs['links']['processo'] ?? null;
        self::assertIsArray($link);
        self::assertSame('belongsTo', $link['type']);
        self::assertSame('Processo', $link['entity']);
        self::assertSame('prazos', $link['foreign'] ?? null);

        // processo NÃO é required na entity (rascunho_nao_vinculado tem processoId NULL)
        $field = $entityDefs['fields']['processo'] ?? null;
        self::assertIsArray($field);
        self::assertFalse($field['required'] ?? true, 'processo não pode ser required (rascunho aceita NULL)');
    }

    public function testProcessoTemReverseLinkPrazos(): void
    {
        // Story 4a.3 adiciona reverse link prazos hasMany Prazo em Processo.json
        $entityDefs = $this->loadJson('entityDefs/Processo.json');

        $link = $entityDefs['links']['prazos'] ?? null;
        self::assertIsArray($link, 'links.prazos ausente em Processo.json — Story 4a.3 Task 1.15');
        self::assertSame('hasMany', $link['type']);
        self::assertSame('Prazo', $link['entity']);
        self::assertSame('processo', $link['foreign'] ?? null);
    }

    public function testProcessoRelationshipsLayoutMostraPrazos(): void
    {
        $layout = $this->loadLayoutJson('Processo/relationships.json');

        $names = \array_map(
            static fn (mixed $item): mixed => \is_array($item) ? ($item['name'] ?? null) : null,
            $layout,
        );

        self::assertContains(
            'prazos',
            $names,
            'Processo relationships layout precisa expor o painel Prazos (AC13 da Story 4a.3)',
        );
    }

    public function testCollectionOrderByDataFatalAsc(): void
    {
        $entityDefs = $this->loadJson('entityDefs/Prazo.json');
        $collection = $entityDefs['collection'] ?? null;

        self::assertIsArray($collection);
        self::assertSame('dataFatal', $collection['orderBy'] ?? null, 'OrderBy precisa ser dataFatal (mais urgentes primeiro)');
        self::assertSame('asc', $collection['order'] ?? null);
    }

    public function testScopeTemCalendarTrue(): void
    {
        $scope = $this->loadJson('scopes/Prazo.json');

        self::assertTrue(
            $scope['calendar'] ?? false,
            'scopes.Prazo.calendar precisa ser true (Calendar nativo exibe Prazos via dataFatal)',
        );
        self::assertTrue($scope['acl'] ?? false);
        self::assertTrue($scope['stream'] ?? false);
        self::assertSame('TogareCore', $scope['module'] ?? null);
    }

    public function testAclDefsForcaAssignedUser(): void
    {
        $aclDefs = $this->loadJson('aclDefs/Prazo.json');

        self::assertTrue($aclDefs['assignedUser'] ?? false, 'aclDefs.assignedUser=true (pattern Audiencia 3.6-magro)');
        self::assertArrayNotHasKey(
            'collaborators',
            $aclDefs,
            'Prazo NÃO tem collaborators — diferente do Processo da Story 3.5',
        );
    }

    public function testClientDefsTemCalendarEBoolFilters(): void
    {
        $clientDefs = $this->loadJson('clientDefs/Prazo.json');

        $calendar = $clientDefs['calendar'] ?? null;
        self::assertIsArray($calendar);
        self::assertSame('dataFatal', $calendar['dateField'] ?? null);

        $boolFilters = $clientDefs['boolFilterList'] ?? [];
        self::assertContains('naoVinculadas', $boolFilters);
        self::assertContains('meusPendentes', $boolFilters);
        self::assertContains('meusRascunhos', $boolFilters);
    }

    public function testGlobalI18nContemPrazoEScopes(): void
    {
        $path = self::I18N_DIR . '/Global.json';
        self::assertFileExists($path);
        /** @var array<string, mixed> $g */
        $g = \json_decode((string) \file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('Prazo', $g['scopeNames'] ?? []);
        self::assertSame('Prazo', $g['scopeNames']['Prazo']);
        self::assertSame('Prazos', $g['scopeNamesPlural']['Prazo'] ?? null);
        self::assertArrayHasKey('Prazo', $g['labels']['Global']['tabs'] ?? []);
        self::assertSame('Prazos', $g['labels']['Global']['tabs']['Prazo']);
    }

    public function testBoolFilterClassesExistem(): void
    {
        $base = self::CLASSES_DIR . '/Select/Bool/Prazo';
        self::assertFileExists($base . '/NaoVinculadas.php');
        self::assertFileExists($base . '/MeusPendentes.php');
        self::assertFileExists($base . '/MeusRascunhos.php');
        // Story 4a.3.1 — 3 boolFilter classes novas.
        self::assertFileExists($base . '/AguardandoCliente.php');
        self::assertFileExists($base . '/ProtocoladosUltimos30d.php');
        self::assertFileExists($base . '/Acompanhamento.php');
    }

    /** Story 4a.3.1 — selectDefs declara todos os boolFilter classes via classNameMap. */
    public function testSelectDefsDeclaraTodosOsBoolFilters(): void
    {
        $selectDefs = $this->loadJson('selectDefs/Prazo.json');
        $map = $selectDefs['boolFilterClassNameMap'] ?? [];

        self::assertArrayHasKey('naoVinculadas', $map);
        self::assertArrayHasKey('meusPendentes', $map);
        self::assertArrayHasKey('meusRascunhos', $map);
        self::assertArrayHasKey('aguardandoCliente', $map);
        self::assertArrayHasKey('protocoladosUltimos30d', $map);
        self::assertArrayHasKey('acompanhamento', $map);
    }

    /**
     * Story 4a.5 fix-pass v0.20.1 — Orderer custom para `dataFatal` registrado
     * em selectDefs.ordererClassNameMap. Garante ORDER BY data_fatal ASC,
     * prioridade_weight DESC, id ASC (sem o `id ASC` automático intercepta o
     * desempate por prioridade — bug detectado no smoke F1 da 4a.5).
     */
    public function testSelectDefsRegistraOrdererCustomParaDataFatal(): void
    {
        $selectDefs = $this->loadJson('selectDefs/Prazo.json');
        $map = $selectDefs['ordererClassNameMap'] ?? [];

        self::assertArrayHasKey('dataFatal', $map);
        self::assertSame(
            'Espo\\Modules\\TogareCore\\Classes\\Select\\Order\\Prazo\\DataFatalPriorizado',
            $map['dataFatal']
        );

        $ordererPath = self::CLASSES_DIR . '/Select/Order/Prazo/DataFatalPriorizado.php';
        self::assertFileExists($ordererPath);
    }

    /** Story 4a.5 — metadata dashlets/Prazos.json registra o dashlet BriefingDoDia. */
    public function testDashletPrazosMetadataExisteEContemDefaults(): void
    {
        $dashlet = $this->loadJson('dashlets/Prazos.json');

        self::assertSame('togare-core:views/dashlets/togare-prazos-do-dia', $dashlet['view'] ?? null);
        self::assertSame('Prazo', $dashlet['aclScope'] ?? null);
        self::assertSame('Prazo', $dashlet['entityType'] ?? null);

        // Defaults críticos para AC2/AC3/AC9.
        $defaults = $dashlet['options']['defaults'] ?? [];
        self::assertSame('dataFatal', $defaults['orderBy'] ?? null);
        self::assertSame('asc', $defaults['order'] ?? null);
        self::assertSame(8, $defaults['displayRecords'] ?? null);
        self::assertSame(0.5, $defaults['autorefreshInterval'] ?? null);

        // Story 4a.5.1: searchData agora usa `pendentesParaHoje` (substitui
        // `meusPendentes`) — filtra prazos cuja `dataCumprimento <= today` OR
        // `dataCumprimento IS NULL` (default seguro Decisão #4). Orderer custom
        // DataFatalPriorizado em selectDefs continua cuidando do desempate
        // `prioridadeWeight DESC` sem precisar de filtro separado.
        self::assertTrue($defaults['searchData']['bool']['pendentesParaHoje'] ?? false);
        self::assertArrayNotHasKey('meusPendentes', $defaults['searchData']['bool'] ?? []);

        // Portal disabled (CRM-only).
        $access = $dashlet['accessDataList'] ?? [];
        self::assertCount(1, $access);
        self::assertTrue($access[0]['inPortalDisabled'] ?? false);
    }

    /**
     * Story 4a.3.1 smoke F1: D7 revisada — descartado FICA visível no dropdown.
     * Felipe pediu para manter descartado como opção legítima de UI (advogado pode
     * querer marcar manualmente). dynamicLogic.options.status removido.
     */
    public function testClientDefsNaoFiltraStatusDoDropdown(): void
    {
        $clientDefs = $this->loadJson('clientDefs/Prazo.json');

        // dynamicLogic.options.status removido após smoke F1 — todos os 9 status
        // visíveis no dropdown (incluindo descartado).
        self::assertArrayNotHasKey(
            'options',
            $clientDefs['dynamicLogic'] ?? [],
            'dynamicLogic.options foi removido — descartado é status válido pra advogado',
        );
    }

    /** Story 4a.3.1 — clientDefs.dynamicLogic.fields.motivoReagendamento condicional. */
    public function testClientDefsMotivoReagendamentoVisibleQuandoStatusReagendado(): void
    {
        $clientDefs = $this->loadJson('clientDefs/Prazo.json');

        $visible = $clientDefs['dynamicLogic']['fields']['motivoReagendamento']['visible'] ?? null;
        self::assertIsArray($visible);
        $required = $clientDefs['dynamicLogic']['fields']['motivoReagendamento']['required'] ?? null;
        self::assertIsArray($required);
    }

    /** Story 4a.3.1 — Cliente.json tem reverse link prazos hasMany. */
    public function testClienteTemReverseLinkPrazos(): void
    {
        $entityDefs = $this->loadJson('entityDefs/Cliente.json');

        $link = $entityDefs['links']['prazos'] ?? null;
        self::assertIsArray($link);
        self::assertSame('hasMany', $link['type']);
        self::assertSame('Prazo', $link['entity']);
        self::assertSame('cliente', $link['foreign'] ?? null);
    }

    /** Story 4a.3.1 — ParteContraria.json tem reverse link prazos hasMany. */
    public function testParteContrariaTemReverseLinkPrazos(): void
    {
        $entityDefs = $this->loadJson('entityDefs/ParteContraria.json');

        $link = $entityDefs['links']['prazos'] ?? null;
        self::assertIsArray($link);
        self::assertSame('hasMany', $link['type']);
        self::assertSame('Prazo', $link['entity']);
        self::assertSame('parteContraria', $link['foreign'] ?? null);
    }

    /** Story 4a.5.1 — campo dataCumprimento (DATE NULL) declarado em entityDefs. */
    public function testDataCumprimentoFieldDeclarado(): void
    {
        $entityDefs = $this->loadJson('entityDefs/Prazo.json');
        $field = $entityDefs['fields']['dataCumprimento'] ?? null;

        self::assertIsArray($field, 'Field dataCumprimento ausente no entityDefs');
        self::assertSame('date', $field['type'] ?? null);
        // tooltip: true → string vem de i18n tooltips.dataCumprimento.
        self::assertTrue($field['tooltip'] ?? false);
        // Não é required — nullable por design (Decisão #1).
        self::assertFalse($field['required'] ?? false);
    }

    /** Story 4a.5.1 — indexes auxiliares novos (simples + composto). */
    public function testIndexesDataCumprimentoExistem(): void
    {
        $entityDefs = $this->loadJson('entityDefs/Prazo.json');
        $indexes = $entityDefs['indexes'] ?? [];

        self::assertArrayHasKey('dataCumprimento', $indexes);
        self::assertArrayHasKey('statusDataCumprimento', $indexes);

        // Composto cobre PendentesParaHoje::apply() WHERE clause.
        $composto = $indexes['statusDataCumprimento'] ?? null;
        self::assertIsArray($composto);
        self::assertSame(['status', 'dataCumprimento'], $composto['columns'] ?? null);
    }

    /** Story 4a.5.1 — boolFilter PendentesParaHoje registrado. */
    public function testBoolFilterPendentesParaHojeRegistrado(): void
    {
        // Classe PHP existe.
        $classFile = self::CLASSES_DIR . '/Select/Bool/Prazo/PendentesParaHoje.php';
        self::assertFileExists($classFile);

        // selectDefs declara em boolFilterClassNameMap.
        $selectDefs = $this->loadJson('selectDefs/Prazo.json');
        $map = $selectDefs['boolFilterClassNameMap'] ?? [];
        self::assertArrayHasKey('pendentesParaHoje', $map);
        self::assertSame(
            'Espo\\Modules\\TogareCore\\Classes\\Select\\Bool\\Prazo\\PendentesParaHoje',
            $map['pendentesParaHoje'],
        );

        // clientDefs declara em boolFilterList.
        $clientDefs = $this->loadJson('clientDefs/Prazo.json');
        self::assertContains('pendentesParaHoje', $clientDefs['boolFilterList'] ?? []);
    }

    /** Story 4a.5.1 — layout detail tem dataCumprimento no painel "Detalhamento". */
    public function testDataCumprimentoNoLayoutDetail(): void
    {
        $detail = $this->loadLayoutJson('Prazo/detail.json');

        $detalhamentoPanel = null;
        foreach ($detail as $panel) {
            if (\is_array($panel) && ($panel['label'] ?? null) === 'Detalhamento') {
                $detalhamentoPanel = $panel;
                break;
            }
        }
        self::assertIsArray($detalhamentoPanel, 'Painel "Detalhamento" não encontrado em detail.json');

        $allFieldsInPanel = [];
        foreach ($detalhamentoPanel['rows'] ?? [] as $row) {
            foreach ($row as $cell) {
                if (\is_array($cell) && isset($cell['name'])) {
                    $allFieldsInPanel[] = $cell['name'];
                }
            }
        }

        self::assertContains(
            'dataCumprimento',
            $allFieldsInPanel,
            'dataCumprimento deve estar no painel "Detalhamento" (Decisão #6)',
        );
    }

    /** Story 4a.5.1 — filters.json inclui dataCumprimento (search bar avançada). */
    public function testDataCumprimentoNoLayoutFilters(): void
    {
        $filters = $this->loadLayoutJson('Prazo/filters.json');

        self::assertContains(
            'dataCumprimento',
            $filters,
            'dataCumprimento deve estar em filters.json (Decisão #6)',
        );
    }

    /** Story 4a.5.1 — list.json NÃO inclui dataCumprimento (decisão MVP — viewport apertado). */
    public function testDataCumprimentoNAOEstaNoLayoutList(): void
    {
        $list = $this->loadLayoutJson('Prazo/list.json');

        $names = [];
        foreach ($list as $col) {
            if (\is_array($col) && isset($col['name'])) {
                $names[] = $col['name'];
            }
        }

        self::assertNotContains(
            'dataCumprimento',
            $names,
            'dataCumprimento NÃO deve estar em list.json (Decisão #6 MVP — viewport apertado)',
        );
    }

    /** Story 4a.5.1 — i18n: label, tooltip e boolFilter pendentesParaHoje. */
    public function testI18nDataCumprimentoEPendentesParaHoje(): void
    {
        $path = self::I18N_DIR . '/Prazo.json';
        self::assertFileExists($path);
        /** @var array<string, mixed> $i18n */
        $i18n = \json_decode((string) \file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        // Field label.
        self::assertSame(
            'Data de cumprimento (interno)',
            $i18n['fields']['dataCumprimento'] ?? null,
        );

        // Tooltip.
        self::assertIsArray($i18n['tooltips'] ?? null, 'Objeto tooltips precisa existir em i18n');
        self::assertNotEmpty(
            $i18n['tooltips']['dataCumprimento'] ?? '',
            'Tooltip dataCumprimento deve ter conteúdo descritivo',
        );

        // BoolFilter label.
        self::assertSame(
            'Para hoje',
            $i18n['boolFilters']['pendentesParaHoje'] ?? null,
        );
    }
}
