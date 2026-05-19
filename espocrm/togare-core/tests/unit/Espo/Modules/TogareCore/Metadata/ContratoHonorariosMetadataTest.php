<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Metadata;

use PHPUnit\Framework\TestCase;

/**
 * Story 6.1 - metadata de ContratoHonorarios e relationship panels.
 */
final class ContratoHonorariosMetadataTest extends TestCase
{
    private const METADATA_DIR = __DIR__ . '/../../../../../../src/files/custom/Espo/Modules/TogareCore/Resources/metadata';
    private const I18N_DIR = __DIR__ . '/../../../../../../src/files/custom/Espo/Modules/TogareCore/Resources/i18n/pt_BR';

    public function testAclDefsDeclaraAssignedUserParaOwnAcl(): void
    {
        $aclDefs = $this->loadJson(self::METADATA_DIR . '/aclDefs/ContratoHonorarios.json');

        self::assertTrue(
            $aclDefs['assignedUser'] ?? false,
            'ContratoHonorarios precisa declarar assignedUser no aclDefs para seguir o padrao own ACL.',
        );
    }

    public function testClienteRelationshipPanelContratoNaoPermiteUnlink(): void
    {
        $clientDefs = $this->loadJson(self::METADATA_DIR . '/clientDefs/Cliente.json');
        $panel = $clientDefs['relationshipPanels']['contratosHonorarios'] ?? null;

        self::assertIsArray($panel, 'Cliente.relationshipPanels.contratosHonorarios ausente.');
        self::assertTrue(
            $panel['unlinkDisabled'] ?? false,
            'Cliente.contratosHonorarios deve impedir unlink porque clienteId e obrigatorio e imutavel.',
        );
    }

    public function testProcessoRelationshipPanelContratoEhReadOnly(): void
    {
        $clientDefs = $this->loadJson(self::METADATA_DIR . '/clientDefs/Processo.json');
        $panel = $clientDefs['relationshipPanels']['contratosHonorarios'] ?? null;

        self::assertIsArray($panel, 'Processo.relationshipPanels.contratosHonorarios ausente.');
        self::assertTrue($panel['createDisabled'] ?? false);
        self::assertTrue($panel['selectDisabled'] ?? false);
        self::assertTrue($panel['unlinkDisabled'] ?? false);
        self::assertTrue($panel['editDisabled'] ?? false);
        self::assertTrue($panel['removeDisabled'] ?? false);
    }

    public function testProcessosFieldUsaViewFiltradaPorCliente(): void
    {
        $entityDefs = $this->loadJson(self::METADATA_DIR . '/entityDefs/ContratoHonorarios.json');
        $field = $entityDefs['fields']['processos'] ?? null;

        self::assertIsArray($field);
        self::assertSame('linkMultiple', $field['type'] ?? null);
        // Story 6.3 — view renomeada para `views/common/fields/` para reuso
        // (LancamentoFinanceiro + Fatura agora usam o pattern).
        self::assertSame(
            'togare-core:views/common/fields/processos-by-cliente',
            $field['view'] ?? null,
        );
    }

    public function testI18nDeclaraLinkContratosHonorariosEmClienteEProcesso(): void
    {
        $cliente = $this->loadJson(self::I18N_DIR . '/Cliente.json');
        $processo = $this->loadJson(self::I18N_DIR . '/Processo.json');

        self::assertSame('Contratos de honorarios', $this->withoutAccents($cliente['links']['contratosHonorarios'] ?? null));
        self::assertSame('Contratos de honorarios', $this->withoutAccents($processo['links']['contratosHonorarios'] ?? null));
    }

    /**
     * @return array<string, mixed>
     */
    private function loadJson(string $path): array
    {
        self::assertFileExists($path);
        $decoded = \json_decode((string) \file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function withoutAccents(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $converted = \iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return \is_string($converted) ? $converted : $value;
    }
}
