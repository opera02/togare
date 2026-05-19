<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Metadata;

use PHPUnit\Framework\TestCase;

/**
 * Story 3.6-magro — valida metadata da Audiencia (entityDefs/scopes/aclDefs)
 * + reverse link em Processo.json. Defesa contra regressões silenciosas
 * (mexer no JSON sem rodar smoke real).
 */
final class AudienciaMetadataTest extends TestCase
{
    private const METADATA_DIR = __DIR__ . '/../../../../../../src/files/custom/Espo/Modules/TogareCore/Resources/metadata';
    private const LAYOUTS_DIR = __DIR__ . '/../../../../../../src/files/custom/Espo/Modules/TogareCore/Resources/layouts';

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

    public function testEntityDefsTemCamposBasicos(): void
    {
        $entityDefs = $this->loadJson('entityDefs/Audiencia.json');

        $fields = $entityDefs['fields'] ?? null;
        self::assertIsArray($fields);

        // dataHora required
        self::assertSame('datetime', $fields['dataHora']['type'] ?? null);
        self::assertTrue($fields['dataHora']['required'] ?? false);

        // duracaoMinutos com min/max
        self::assertSame('int', $fields['duracaoMinutos']['type'] ?? null);
        self::assertSame(15, $fields['duracaoMinutos']['min'] ?? null);
        self::assertSame(480, $fields['duracaoMinutos']['max'] ?? null);

        // tipo enum
        self::assertSame('enum', $fields['tipo']['type'] ?? null);
        self::assertContains('conciliacao', $fields['tipo']['options'] ?? []);
        self::assertContains('conciliacao_mediacao', $fields['tipo']['options'] ?? []);

        // modalidade enum
        self::assertSame('enum', $fields['modalidade']['type'] ?? null);
        self::assertContains('presencial', $fields['modalidade']['options'] ?? []);
        self::assertContains('virtual', $fields['modalidade']['options'] ?? []);
        self::assertContains('hibrida', $fields['modalidade']['options'] ?? []);

        // status enum
        self::assertSame('enum', $fields['status']['type'] ?? null);
        self::assertContains('agendada', $fields['status']['options'] ?? []);
        self::assertContains('realizada', $fields['status']['options'] ?? []);
        self::assertContains('cancelada', $fields['status']['options'] ?? []);
    }

    public function testEntityDefsTemLinkProcessoBelongsTo(): void
    {
        $entityDefs = $this->loadJson('entityDefs/Audiencia.json');

        $link = $entityDefs['links']['processo'] ?? null;
        self::assertIsArray($link, 'links.processo ausente — Audiencia precisa belongsTo Processo');
        self::assertSame('belongsTo', $link['type']);
        self::assertSame('Processo', $link['entity']);
        self::assertSame('audiencias', $link['foreign'] ?? null);

        // processo é required
        $field = $entityDefs['fields']['processo'] ?? null;
        self::assertIsArray($field);
        self::assertTrue($field['required'] ?? false, 'processo deve ser required (FR16)');
    }

    public function testScopeTemCalendarTrue(): void
    {
        // Decisão #3 — Calendar nativo cobre Story 3.7 cortada (D2).
        $scope = $this->loadJson('scopes/Audiencia.json');

        self::assertTrue(
            $scope['calendar'] ?? false,
            'scopes.Audiencia.calendar precisa ser true (Decisão #3 — Calendar nativo EspoCRM)',
        );
        self::assertTrue($scope['acl'] ?? false, 'ACL declarativa habilitada');
        self::assertTrue($scope['stream'] ?? false, 'Stream EspoCRM habilitado');
        self::assertSame('TogareCore', $scope['module'] ?? null);
    }

    public function testAclDefsForcaAssignedUser(): void
    {
        // Decisão #5 — ACL by-assignment via assignedUser apenas (sem collaborators).
        $aclDefs = $this->loadJson('aclDefs/Audiencia.json');

        self::assertTrue($aclDefs['assignedUser'] ?? false, 'aclDefs.assignedUser=true (Decisão #5)');
        self::assertArrayNotHasKey(
            'collaborators',
            $aclDefs,
            'Audiencia (versão MAGRO) NÃO tem collaborators — diferente do Processo da Story 3.5',
        );
    }

    public function testClientDefsTemCalendarComDateField(): void
    {
        $clientDefs = $this->loadJson('clientDefs/Audiencia.json');

        $calendar = $clientDefs['calendar'] ?? null;
        self::assertIsArray($calendar, 'clientDefs.calendar ausente — Calendar nativo não vai indexar Audiencia');
        self::assertSame('dataHora', $calendar['dateField'] ?? null);
        self::assertSame('tipo', $calendar['nameField'] ?? null);

        self::assertSame('controllers/record', $clientDefs['controller'] ?? null);
        self::assertContains('onlyMy', $clientDefs['boolFilterList'] ?? []);
    }

    public function testProcessoTemReverseLinkAudiencias(): void
    {
        // Story 3.6-magro adiciona o lado reverso em Processo.json.
        $entityDefs = $this->loadJson('entityDefs/Processo.json');

        $link = $entityDefs['links']['audiencias'] ?? null;
        self::assertIsArray(
            $link,
            'links.audiencias ausente em Processo.json — Story 3.6-magro Task 1.7',
        );
        self::assertSame('hasMany', $link['type']);
        self::assertSame('Audiencia', $link['entity']);
        self::assertSame('processo', $link['foreign'] ?? null);
    }

    public function testProcessoRelationshipsLayoutMostraAudiencias(): void
    {
        $layout = $this->loadLayoutJson('Processo/relationships.json');

        $names = \array_map(
            static fn (mixed $item): mixed => \is_array($item) ? ($item['name'] ?? null) : null,
            $layout,
        );

        self::assertContains(
            'audiencias',
            $names,
            'Processo relationships layout precisa expor o painel Audiencias (AC8)',
        );
    }

    public function testEntityDefsTemIndexes(): void
    {
        $entityDefs = $this->loadJson('entityDefs/Audiencia.json');

        $indexes = $entityDefs['indexes'] ?? [];
        self::assertArrayHasKey('dataHora', $indexes);
        self::assertArrayHasKey('statusDataHora', $indexes);
        self::assertArrayHasKey('processoId', $indexes);
        self::assertArrayHasKey('assignedUserId', $indexes);

        // statusDataHora cobre filtros frequentes "agendadas próximas"
        self::assertSame(['status', 'dataHora'], $indexes['statusDataHora']['columns']);
    }
}
