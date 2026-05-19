<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Metadata;

use PHPUnit\Framework\TestCase;

/**
 * Story 3.8 — valida os overrides vanilla Lead/Opportunity.
 */
final class LeadOpportunityMetadataTest extends TestCase
{
    private const METADATA_DIR = __DIR__ . '/../../../../../../src/files/custom/Espo/Modules/TogareCore/Resources/metadata';
    private const I18N_DIR = __DIR__ . '/../../../../../../src/files/custom/Espo/Modules/TogareCore/Resources/i18n/pt_BR';

    /**
     * @return array<string, mixed>
     */
    private function loadMetadataJson(string $relPath): array
    {
        $path = self::METADATA_DIR . '/' . $relPath;
        self::assertFileExists($path, "Arquivo de metadata ausente: {$relPath}");

        /** @var array<string, mixed> $data */
        $data = \json_decode((string) \file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadI18nJson(string $filename): array
    {
        $path = self::I18N_DIR . '/' . $filename;
        self::assertFileExists($path, "Arquivo i18n ausente: {$filename}");

        /** @var array<string, mixed> $data */
        $data = \json_decode((string) \file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    public function testLeadStatusEConvertEntityListFicamEmEntityDefs(): void
    {
        $entityDefs = $this->loadMetadataJson('entityDefs/Lead.json');
        $status = $entityDefs['fields']['status'] ?? null;

        self::assertIsArray($status, 'fields.status ausente em entityDefs/Lead.json');
        self::assertSame(['Novo Lead', 'Qualificado', 'Descartado', 'Converted'], $status['options'] ?? null);
        self::assertSame('Novo Lead', $status['default'] ?? null);
        self::assertSame(['Descartado', 'Converted'], $status['notActualOptions'] ?? null);
        self::assertSame(['Opportunity'], $entityDefs['convertEntityList'] ?? null);

        self::assertFileDoesNotExist(
            self::METADATA_DIR . '/clientDefs/Lead.json',
            'convertEntityList pertence a entityDefs/Lead.json; em clientDefs seria ignorado pelo flow de Convert.',
        );
    }

    public function testOpportunityStageProbabilityMapFicaNoCampoStage(): void
    {
        $entityDefs = $this->loadMetadataJson('entityDefs/Opportunity.json');
        $stage = $entityDefs['fields']['stage'] ?? null;

        self::assertIsArray($stage, 'fields.stage ausente em entityDefs/Opportunity.json');
        self::assertSame(
            ['Proposta Enviada', 'Oportunidade Aceita', 'Cliente Convertido', 'Perdido'],
            $stage['options'] ?? null,
        );
        self::assertSame('Proposta Enviada', $stage['default'] ?? null);
        self::assertSame(
            [
                'Proposta Enviada' => 30,
                'Oportunidade Aceita' => 70,
                'Cliente Convertido' => 100,
                'Perdido' => 0,
            ],
            $stage['probabilityMap'] ?? null,
            'EspoCRM lê probabilityMap em entityDefs.Opportunity.fields.stage.',
        );
        self::assertArrayNotHasKey(
            'probabilityMap',
            $entityDefs,
            'probabilityMap na raiz de Opportunity não alimenta o stage field vanilla.',
        );
    }

    public function testI18nPtBrCobreStatusEStage(): void
    {
        $lead = $this->loadI18nJson('Lead.json');
        $opportunity = $this->loadI18nJson('Opportunity.json');

        self::assertSame('Convertido', $lead['options']['status']['Converted'] ?? null);
        self::assertSame('Estágio', $opportunity['fields']['stage'] ?? null);
        self::assertSame(
            'Cliente Convertido',
            $opportunity['options']['stage']['Cliente Convertido'] ?? null,
        );
    }
}
