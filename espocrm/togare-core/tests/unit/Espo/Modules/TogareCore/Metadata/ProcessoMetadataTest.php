<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Metadata;

use PHPUnit\Framework\TestCase;

final class ProcessoMetadataTest extends TestCase
{
    private const METADATA_DIR = __DIR__ . '/../../../../../../src/files/custom/Espo/Modules/TogareCore/Resources/metadata';

    /**
     * @return array<string, mixed>
     */
    private function loadProcessoEntityDefs(): array
    {
        $path = self::METADATA_DIR . '/entityDefs/Processo.json';

        self::assertFileExists($path);

        /** @var array<string, mixed> $data */
        $data = \json_decode((string) \file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

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

    public function testNumeroCnjUsaFieldViewComMascara(): void
    {
        // Hotfix v0.11.2 (smoke real Story 3.5) — bundle mapping de extensão
        // não resolve `togare-core:fields/cnj`; precisa do prefixo `views/`
        // em entityDefs (`togare-core:views/fields/cnj`). Pattern bundled
        // (memória feedback_extension_bundled_pattern.md).
        $entityDefs = $this->loadProcessoEntityDefs();

        self::assertSame(
            'togare-core:views/fields/cnj',
            $entityDefs['fields']['numeroCnj']['view'] ?? null,
        );
    }

    public function testCamposTpuUsamLookupCustomizado(): void
    {
        // Hotfix v0.11.2 — paths views/ obrigatórios para extensões bundled.
        $entityDefs = $this->loadProcessoEntityDefs();

        self::assertSame('togare-tpu:views/fields/tpu-lookup', $entityDefs['fields']['classeCodigo']['view'] ?? null);
        self::assertSame('classe', $entityDefs['fields']['classeCodigo']['tpuTipo'] ?? null);

        self::assertSame('togare-tpu:views/fields/tpu-lookup', $entityDefs['fields']['assuntoCodigo']['view'] ?? null);
        self::assertSame('assunto', $entityDefs['fields']['assuntoCodigo']['tpuTipo'] ?? null);

        self::assertSame('togare-tpu:views/fields/tpu-lookup', $entityDefs['fields']['movimentoCodigo']['view'] ?? null);
        self::assertSame('movimento', $entityDefs['fields']['movimentoCodigo']['tpuTipo'] ?? null);
    }

    /**
     * Story 3.5 — link `collaborators` declarado para feature nativo
     * EspoCRM `scopes.collaborators=true` funcionar corretamente.
     */
    public function testEntityDefsTemLinkCollaborators(): void
    {
        $entityDefs = $this->loadProcessoEntityDefs();

        $link = $entityDefs['links']['collaborators'] ?? null;
        self::assertIsArray($link, 'links.collaborators ausente — Story 3.5 requer link hasMany User');
        self::assertSame('hasMany', $link['type']);
        self::assertSame('User', $link['entity']);
        self::assertSame(
            'ProcessoCollaborator',
            $link['relationshipName'] ?? null,
            'relationshipName define a tabela join processo_collaborator',
        );
    }

    /**
     * Story 3.5 — campo `collaborators` linkMultiple para edit form +
     * detail panel renderizar chips automaticamente.
     */
    public function testEntityDefsTemFieldLinkMultipleCollaborators(): void
    {
        $entityDefs = $this->loadProcessoEntityDefs();

        $field = $entityDefs['fields']['collaborators'] ?? null;
        self::assertIsArray($field, 'fields.collaborators ausente — Story 3.5');
        self::assertSame('linkMultiple', $field['type']);
    }

    /**
     * Story 3.5 — flag `scopes.Processo.collaborators=true` ativa o WHERE
     * automático do EspoCRM com EXISTS sobre processo_collaborator.
     */
    public function testScopeTemCollaboratorsTrue(): void
    {
        $scope = $this->loadJson('scopes/Processo.json');

        self::assertTrue(
            $scope['collaborators'] ?? false,
            'scopes.Processo.collaborators precisa ser true (Story 3.5 — feature nativo EspoCRM)',
        );
        self::assertTrue($scope['acl'] ?? false, 'ACL declarativa permanece habilitada');
    }

    /**
     * Story 3.5 — aclDefs declara explicitamente os 2 vetores de assignment
     * que o framework usa para resolver `read=own`.
     */
    public function testAclDefsForcaAssignedUserECollaborators(): void
    {
        $aclDefs = $this->loadJson('aclDefs/Processo.json');

        self::assertTrue($aclDefs['assignedUser'] ?? false, 'aclDefs.assignedUser=true (FR11)');
        self::assertTrue($aclDefs['collaborators'] ?? false, 'aclDefs.collaborators=true (FR11)');
    }

    /**
     * Story 4a.3 — reverse link `prazos hasMany Prazo, foreign=processo`
     * em Processo.json (Task 1.15). Habilita painel relacional "Prazos"
     * no detail do Processo (AC13).
     */
    public function testReverseLinkPrazosExiste(): void
    {
        $entityDefs = $this->loadProcessoEntityDefs();

        $link = $entityDefs['links']['prazos'] ?? null;
        self::assertIsArray($link, 'links.prazos ausente — Story 4a.3 Task 1.15');
        self::assertSame('hasMany', $link['type']);
        self::assertSame('Prazo', $link['entity']);
        self::assertSame('processo', $link['foreign'] ?? null);
    }
}
