<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareRbac;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cobre AC1, AC4 e AC5 — valida que cada um dos 8 JSONs de role é parseável,
 * tem schema correto e respeita as invariantes do PRD/Story.
 */
final class SeedRolesTest extends TestCase
{
    private const SEED_DIR = __DIR__ . '/../../../../../src/files/custom/Espo/Modules/TogareRbac/Resources/seed/roles';

    private const VALID_PERMISSION_ENUM = ['no', 'own', 'team', 'all', 'yes'];

    private const VALID_ACL_LEVEL_ENUM = ['no', 'own', 'team', 'all'];

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function provideRoleFiles(): iterable
    {
        yield 'socio-admin' => ['socio-admin.json', 'Sócio/Admin'];
        yield 'advogado' => ['advogado.json', 'Advogado'];
        yield 'assistente' => ['assistente.json', 'Assistente/Estagiário'];
        yield 'secretaria' => ['secretaria.json', 'Secretária'];
        yield 'financeiro' => ['financeiro.json', 'Financeiro'];
        yield 'marketing' => ['marketing.json', 'Marketing'];
        yield 'rh-lite' => ['rh-lite.json', 'RH-lite'];
        yield 'cliente-portal' => ['cliente-portal.json', 'Cliente-portal'];
    }

    #[DataProvider('provideRoleFiles')]
    public function testRoleJsonHasValidSchema(string $filename, string $expectedName): void
    {
        $path = self::SEED_DIR . '/' . $filename;
        $this->assertFileExists($path, "Arquivo de seed ausente: {$filename}");

        $raw = \file_get_contents($path);
        $this->assertIsString($raw);

        $decoded = \json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        // Campo 'name' string non-empty e bate com o esperado.
        $this->assertArrayHasKey('name', $decoded);
        $this->assertSame($expectedName, $decoded['name']);

        // 'data' obrigatório com chaves canônicas.
        $this->assertArrayHasKey('data', $decoded);
        $this->assertIsArray($decoded['data']);
        $this->assertArrayHasKey('scopeList', $decoded['data']);
        $this->assertIsArray($decoded['data']['scopeList']);
        $this->assertNotEmpty($decoded['data']['scopeList']);

        $this->assertArrayHasKey('scopeLevel', $decoded['data']);
        $this->assertIsArray($decoded['data']['scopeLevel']);

        $this->assertArrayHasKey('fieldLevel', $decoded['data']);
        $this->assertIsArray($decoded['data']['fieldLevel']);

        // Permissões top-level são strings do enum válido.
        foreach (['assignmentPermission', 'userPermission', 'messagePermission', 'portalPermission'] as $key) {
            $this->assertArrayHasKey($key, $decoded, "Role {$expectedName} sem '{$key}'");
            $this->assertContains(
                $decoded[$key],
                self::VALID_PERMISSION_ENUM,
                "Role {$expectedName} '{$key}' fora do enum válido: " . (string) $decoded[$key],
            );
        }

        // ModuleStatus deve estar declarado em scopeLevel pra todos os 8 roles (AC5).
        $this->assertArrayHasKey(
            'ModuleStatus',
            $decoded['data']['scopeLevel'],
            "Role {$expectedName} sem ModuleStatus em scopeLevel — AC5 exige declaração explícita",
        );

        // Cada entrada de scopeLevel é string válida OU objeto granular {read,edit,create,delete}.
        foreach ($decoded['data']['scopeLevel'] as $scope => $level) {
            if (\is_string($level)) {
                $this->assertContains(
                    $level,
                    self::VALID_ACL_LEVEL_ENUM,
                    "Role {$expectedName} scope '{$scope}' tem aclLevel inválido: '{$level}'",
                );
            } else {
                $this->assertIsArray($level, "Role {$expectedName} scope '{$scope}' deve ser string ou array granular");
                foreach ($level as $action => $actionLevel) {
                    $this->assertContains(
                        $action,
                        ['read', 'edit', 'create', 'delete', 'stream'],
                        "Role {$expectedName} scope '{$scope}' tem ação desconhecida: '{$action}'",
                    );
                    $this->assertContains(
                        $actionLevel,
                        self::VALID_ACL_LEVEL_ENUM,
                        "Role {$expectedName} scope '{$scope}.{$action}' aclLevel inválido: '{$actionLevel}'",
                    );
                }
            }
        }
    }

    public function testSocioAdminTemAclAllEmTodosEscoposCriticos(): void
    {
        $decoded = $this->loadRole('socio-admin.json');
        $scopeLevel = $decoded['data']['scopeLevel'];

        // AC4 parte 1: Sócio/Admin tem aclLevel=all em todos os scopes.
        foreach ($scopeLevel as $scope => $level) {
            $this->assertSame(
                'all',
                $level,
                "Sócio/Admin deve ter 'all' em '{$scope}', tem '{$level}'",
            );
        }
    }

    public function testClientePortalBloqueiaCoreCrmEPermitePortalEntities(): void
    {
        $decoded = $this->loadRole('cliente-portal.json');
        $scopeLevel = $decoded['data']['scopeLevel'];

        // AC4 parte 2: portalPermission habilitado.
        $this->assertSame('yes', $decoded['portalPermission']);

        // Bloqueia entidades core CRM.
        $blocked = ['Account', 'Contact', 'Lead', 'Campaign', 'Email'];
        foreach ($blocked as $scope) {
            $this->assertSame(
                'no',
                $scopeLevel[$scope],
                "Cliente-portal deve bloquear '{$scope}' com 'no', tem: " . \json_encode($scopeLevel[$scope]),
            );
        }

        // Permite Document/Note/PortalProcess/PortalMessage com 'own'.
        $allowed = ['Document', 'Note', 'PortalProcess', 'PortalMessage'];
        foreach ($allowed as $scope) {
            $this->assertSame(
                'own',
                $scopeLevel[$scope],
                "Cliente-portal deve permitir '{$scope}' com 'own', tem: " . \json_encode($scopeLevel[$scope]),
            );
        }

        // User só self.
        $userLevel = $scopeLevel['User'];
        $this->assertIsArray($userLevel);
        $this->assertSame('own', $userLevel['read']);
        $this->assertSame('no', $userLevel['edit']);
    }

    public function testApenasSocioAdminTemModuleStatusAll(): void
    {
        // AC5: ModuleStatus = 'all' só em Sócio/Admin; demais 7 roles têm 'no'.
        $expectations = [
            'socio-admin.json' => 'all',
            'advogado.json' => 'no',
            'assistente.json' => 'no',
            'secretaria.json' => 'no',
            'financeiro.json' => 'no',
            'marketing.json' => 'no',
            'rh-lite.json' => 'no',
            'cliente-portal.json' => 'no',
        ];

        foreach ($expectations as $file => $expected) {
            $decoded = $this->loadRole($file);
            $this->assertSame(
                $expected,
                $decoded['data']['scopeLevel']['ModuleStatus'],
                "{$file} deve ter ModuleStatus='{$expected}'",
            );
        }
    }

    /**
     * Story 3.1 (v0.6.1) — patch assistente.json para alinhar com FR6.
     *
     * Antes: Cliente: {read=team, edit=no, create=no, delete=no} (read-only).
     * Depois: Cliente: {read=team, edit=team, create=team, delete=no}.
     *
     * Delete continua exclusivo do Sócio/Admin (FR6 só lista cadastrar/editar/consultar).
     */
    public function testAssistenteTemCreateEEditEmCliente(): void
    {
        $decoded = $this->loadRole('assistente.json');
        $cliente = $decoded['data']['scopeLevel']['Cliente'];

        $this->assertIsArray($cliente, 'Cliente deve ter aclLevel granular (read/edit/create/delete)');
        $this->assertSame('team', $cliente['read'] ?? null, 'Assistente.Cliente.read deve ser team (FR6)');
        $this->assertSame('team', $cliente['edit'] ?? null, 'Assistente.Cliente.edit deve ser team (FR6)');
        $this->assertSame('team', $cliente['create'] ?? null, 'Assistente.Cliente.create deve ser team (FR6)');
        $this->assertSame('no', $cliente['delete'] ?? null, 'Assistente.Cliente.delete deve ser no (delete é exclusivo do Sócio/Admin)');
    }

    /**
     * Story 3.2 (v0.6.2) — patch assistente.json para alinhar com FR7.
     *
     * Antes: ParteContraria: {read=team, edit=no, create=no, delete=no} (read-only).
     * Depois: ParteContraria: {read=team, edit=team, create=team, delete=no}.
     *
     * Delete continua exclusivo do Sócio/Admin (mesma política do Cliente — FR7).
     */
    public function testAssistentePodeCreateEmParteContraria(): void
    {
        $decoded = $this->loadRole('assistente.json');
        $parte = $decoded['data']['scopeLevel']['ParteContraria'];

        $this->assertIsArray($parte, 'ParteContraria deve ter aclLevel granular (read/edit/create/delete)');
        $this->assertSame('team', $parte['read'] ?? null, 'Assistente.ParteContraria.read deve ser team (FR7)');
        $this->assertSame('team', $parte['edit'] ?? null, 'Assistente.ParteContraria.edit deve ser team (FR7)');
        $this->assertSame('team', $parte['create'] ?? null, 'Assistente.ParteContraria.create deve ser team (FR7)');
        $this->assertSame('no', $parte['delete'] ?? null, 'Assistente.ParteContraria.delete deve ser no (delete é exclusivo do Sócio/Admin)');
    }

    /**
     * Story 3.4 (v0.6.3) — entidade Processo (rename "Process" → "Processo").
     *
     * Sócio/Admin tem all em todos escopos críticos — Processo deve estar em "all".
     */
    public function testSocioAdminPodeAllProcesso(): void
    {
        $decoded = $this->loadRole('socio-admin.json');
        $this->assertContains('Processo', $decoded['data']['scopeList'], 'Processo deve estar no scopeList do Sócio/Admin');
        $this->assertSame('all', $decoded['data']['scopeLevel']['Processo'] ?? null, 'Sócio/Admin.Processo deve ser all (FR11)');
    }

    /**
     * Story 3.4 (v0.6.3) — Advogado pode criar processos (FR7).
     * Story 3.5 (v0.7.0) — read=own (ACL by-assignment FR11).
     */
    public function testAdvogadoPodeCreateProcesso(): void
    {
        $decoded = $this->loadRole('advogado.json');
        $proc = $decoded['data']['scopeLevel']['Processo'];

        $this->assertIsArray($proc, 'Advogado.Processo deve ter aclLevel granular');
        $this->assertSame('own', $proc['read'] ?? null, 'Advogado.Processo.read deve ser own (FR11; Story 3.5)');
        $this->assertSame('own', $proc['edit'] ?? null, 'Advogado.Processo.edit deve ser own');
        $this->assertSame('team', $proc['create'] ?? null, 'Advogado.Processo.create deve ser team (FR7)');
        $this->assertSame('no', $proc['delete'] ?? null, 'Advogado.Processo.delete deve ser no');
    }

    /**
     * Story 3.5 (v0.7.0) — FR11 explícito.
     *
     * Garante que Advogado tem `read=own` e que NÃO há regressão para `team`
     * (que daria visibilidade ampla violando FR11). Cobertura redundante com
     * `testAdvogadoPodeCreateProcesso` por desenho — testes que falham por
     * razões diferentes ao mesmo arquivo de role evidenciam erros distintos
     * em logs de CI.
     */
    public function testAdvogadoTemReadOwnEmProcessoFR11(): void
    {
        $decoded = $this->loadRole('advogado.json');
        $proc = $decoded['data']['scopeLevel']['Processo'];

        $this->assertIsArray($proc, 'Advogado.Processo precisa de aclLevel granular para FR11');
        $this->assertSame('own', $proc['read'], 'FR11: Advogado só vê processos atribuídos (titular ou colaborador)');
        $this->assertSame('own', $proc['edit'], 'Advogado edita o próprio processo');
        $this->assertSame('team', $proc['create'], 'Advogado cria processo no time');
        $this->assertSame('no', $proc['delete'], 'Apenas Sócio/Admin deleta');
    }

    /**
     * Story 3.5 (v0.7.0) — guarda contra regressão acidental nos demais 7 roles.
     *
     * O patch desta story muda APENAS Advogado.Processo.read (`team` → `own`).
     * Nenhum outro role deve ter sido tocado. Se este teste falhar, alguém
     * editou o seed por engano.
     *
     * @return iterable<string, array{0: string, 1: array<string, string>|string}>
     */
    public static function provideNonAdvogadoProcessoExpectations(): iterable
    {
        yield 'socio-admin' => ['socio-admin.json', 'all'];
        yield 'assistente' => ['assistente.json', ['read' => 'team', 'edit' => 'team', 'create' => 'team', 'delete' => 'no']];
        yield 'secretaria' => ['secretaria.json', ['read' => 'team', 'edit' => 'no', 'create' => 'no', 'delete' => 'no']];
        yield 'financeiro' => ['financeiro.json', ['read' => 'team', 'edit' => 'no', 'create' => 'no', 'delete' => 'no']];
        yield 'marketing' => ['marketing.json', 'no'];
        yield 'rh-lite' => ['rh-lite.json', 'no'];
        yield 'cliente-portal' => ['cliente-portal.json', 'no'];
    }

    /**
     * @param array<string, string>|string $expected
     */
    #[DataProvider('provideNonAdvogadoProcessoExpectations')]
    public function testRolesNaoAdvogadoNaoForamAlteradosNaProcesso(string $filename, array|string $expected): void
    {
        $decoded = $this->loadRole($filename);
        $actual = $decoded['data']['scopeLevel']['Processo'] ?? null;

        $this->assertSame(
            $expected,
            $actual,
            "Story 3.5 NÃO deveria mudar {$filename}.scopeLevel.Processo — só advogado.json muda. "
                . 'Se este teste falhou, alguém editou o seed por engano.',
        );
    }

    /**
     * Story 3.4 (v0.6.3) — Assistente pode editar processos (FR7).
     */
    public function testAssistentePodeEditProcesso(): void
    {
        $decoded = $this->loadRole('assistente.json');
        $proc = $decoded['data']['scopeLevel']['Processo'];

        $this->assertIsArray($proc, 'Assistente.Processo deve ter aclLevel granular');
        $this->assertSame('team', $proc['read'] ?? null, 'Assistente.Processo.read deve ser team (FR7)');
        $this->assertSame('team', $proc['edit'] ?? null, 'Assistente.Processo.edit deve ser team (FR7)');
        $this->assertSame('team', $proc['create'] ?? null, 'Assistente.Processo.create deve ser team (FR7)');
        $this->assertSame('no', $proc['delete'] ?? null, 'Assistente.Processo.delete deve ser no (FR7)');
    }

    /**
     * Story 3.4 (v0.6.3) — Secretária só vê processos (apoio operacional FR7).
     */
    public function testSecretariaSoLeProcesso(): void
    {
        $decoded = $this->loadRole('secretaria.json');
        $proc = $decoded['data']['scopeLevel']['Processo'];

        $this->assertIsArray($proc, 'Secretária.Processo deve ter aclLevel granular');
        $this->assertSame('team', $proc['read'] ?? null, 'Secretária.Processo.read deve ser team (apoio)');
        $this->assertSame('no', $proc['edit'] ?? null, 'Secretária.Processo.edit deve ser no (FR7)');
        $this->assertSame('no', $proc['create'] ?? null, 'Secretária.Processo.create deve ser no (FR7)');
        $this->assertSame('no', $proc['delete'] ?? null, 'Secretária.Processo.delete deve ser no');
    }

    /**
     * Story 3.6-magro (v0.7.1) — entidade Audiencia (FR16, CRUD simples).
     *
     * Sócio/Admin tem all em todos os escopos críticos — Audiencia deve estar
     * em "all". Cobre que o seed de Sócio/Admin lista Audiencia e respeita
     * a invariante "Sócio/Admin = aclLevel.all em tudo".
     */
    public function testSocioAdminPodeAllAudiencia(): void
    {
        $decoded = $this->loadRole('socio-admin.json');
        $this->assertContains(
            'Audiencia',
            $decoded['data']['scopeList'],
            'Audiencia deve estar no scopeList do Sócio/Admin (Story 3.6-magro)',
        );
        $this->assertSame(
            'all',
            $decoded['data']['scopeLevel']['Audiencia'] ?? null,
            'Sócio/Admin.Audiencia deve ser all (FR16)',
        );
    }

    /**
     * Story 3.6-magro — FR16 visibilidade by-assignment.
     *
     * Advogado vê apenas audiências em que é o `assignedUser` (responsável).
     * O hook `EnforceAudienciaAssignmentHook` faz auto-titular create —
     * advogado criando audiência sem assignedUser vira responsável.
     *
     * Diferente do Processo/Story 3.5, Audiencia NÃO tem collaborators
     * (Decisão #5 da story). ACL `read=own` resolve via `assignedUserId`
     * apenas.
     */
    public function testAdvogadoTemReadOwnEmAudiencia(): void
    {
        $decoded = $this->loadRole('advogado.json');
        $aud = $decoded['data']['scopeLevel']['Audiencia'] ?? null;

        $this->assertSame(
            'own',
            $aud,
            'Advogado.Audiencia deve ser "own" (FR16 — só vê audiências em que é responsável)',
        );
    }

    /**
     * Story 3.6-magro — Secretária consulta agenda (apoio operacional FR16/FR17),
     * mas não cria/edita/deleta audiências.
     */
    public function testSecretariaSoLeAudiencia(): void
    {
        $decoded = $this->loadRole('secretaria.json');
        $aud = $decoded['data']['scopeLevel']['Audiencia'] ?? null;

        $this->assertIsArray($aud, 'Secretária.Audiencia deve ter aclLevel granular (read-only)');
        $this->assertSame('team', $aud['read'] ?? null, 'Secretária.Audiencia.read deve ser team (apoio agenda consolidada)');
        $this->assertSame('no', $aud['edit'] ?? null, 'Secretária.Audiencia.edit deve ser no');
        $this->assertSame('no', $aud['create'] ?? null, 'Secretária.Audiencia.create deve ser no');
        $this->assertSame('no', $aud['delete'] ?? null, 'Secretária.Audiencia.delete deve ser no');
    }

    /**
     * Story 3.6-magro — guarda contra regressão acidental: roles fora do
     * fluxo operacional jurídico não devem ver Audiencia. Marketing/RH-lite
     * /Cliente-portal são domínios disjuntos. Financeiro vê processo para
     * cobrar, mas não precisa abrir audiência (toca o relógio do escritório,
     * não a agenda do tribunal).
     *
     * @return iterable<string, array{0: string}>
     */
    public static function provideRolesNaoOperacionalAudiencia(): iterable
    {
        yield 'financeiro' => ['financeiro.json'];
        yield 'marketing' => ['marketing.json'];
        yield 'rh-lite' => ['rh-lite.json'];
        yield 'cliente-portal' => ['cliente-portal.json'];
    }

    #[DataProvider('provideRolesNaoOperacionalAudiencia')]
    public function testRolesNaoOperacionalNaoVeemAudiencia(string $filename): void
    {
        $decoded = $this->loadRole($filename);
        $aud = $decoded['data']['scopeLevel']['Audiencia'] ?? null;

        $this->assertSame(
            'no',
            $aud,
            "{$filename}.Audiencia deve ser 'no' (Story 3.6-magro — fora do fluxo operacional jurídico)",
        );
    }

    /**
     * Story 3.8 (v0.7.2) — FR31 invariant Marketing.Lead=all.
     *
     * Marketing é o único role com aclLevel=all em Lead — Marketing pode
     * cadastrar/editar/listar/deletar leads sem restrição de team.
     */
    public function testMarketingTemAclAllEmLead(): void
    {
        $decoded = $this->loadRole('marketing.json');
        $lead = $decoded['data']['scopeLevel']['Lead'] ?? null;

        $this->assertSame(
            'all',
            $lead,
            'Marketing.Lead deve ser "all" (FR31 — pipeline de captação não tem restrição de visibilidade)',
        );
    }

    /**
     * Story 3.8 (v0.7.2) — FR31 invariant Marketing.Opportunity=team.
     *
     * Pipeline de Opportunities é compartilhado entre Marketing do escritório.
     * Sem assignedUser-only no MVP — `team` é o nível mais permissivo abaixo
     * de `all` que respeita a invariante NFR9 de não dar all em entidades
     * pré-comerciais (`all` fica reservado para Sócio/Admin).
     */
    public function testMarketingTemAclTeamEmOpportunity(): void
    {
        $decoded = $this->loadRole('marketing.json');
        $opp = $decoded['data']['scopeLevel']['Opportunity'] ?? null;

        $this->assertSame(
            'team',
            $opp,
            'Marketing.Opportunity deve ser "team" (FR31 — pipeline compartilhado entre marketing do escritório)',
        );
    }

    /**
     * Story 3.8 (v0.7.2) — Sócio/Admin tem all em Lead E Opportunity.
     *
     * Cobertura redundante com testSocioAdminTemAclAllEmTodosEscoposCriticos
     * por desenho — testes que falham por razões diferentes ao mesmo arquivo
     * de role evidenciam erros distintos em logs de CI.
     */
    public function testSocioAdminPodeAllLeadEOpportunity(): void
    {
        $decoded = $this->loadRole('socio-admin.json');
        $scopeLevel = $decoded['data']['scopeLevel'];

        $this->assertContains('Lead', $decoded['data']['scopeList'], 'Lead deve estar no scopeList do Sócio/Admin');
        $this->assertContains('Opportunity', $decoded['data']['scopeList'], 'Opportunity deve estar no scopeList do Sócio/Admin');
        $this->assertSame('all', $scopeLevel['Lead'] ?? null, 'Sócio/Admin.Lead deve ser all');
        $this->assertSame('all', $scopeLevel['Opportunity'] ?? null, 'Sócio/Admin.Opportunity deve ser all');
    }

    /**
     * Story 3.8 (v0.7.2) — guarda contra regressão: roles fora Marketing/Sócio-Admin
     * não devem ter qualquer acesso a Lead. Pipeline de captação é exclusivo do
     * Marketing — Advogados, Assistentes, Secretárias etc. não interagem com
     * Lead pré-conversão (jornada Rafael, PRD §342-352).
     *
     * @return iterable<string, array{0: string}>
     */
    public static function provideRolesNaoMarketingLead(): iterable
    {
        yield 'advogado' => ['advogado.json'];
        yield 'assistente' => ['assistente.json'];
        yield 'secretaria' => ['secretaria.json'];
        yield 'financeiro' => ['financeiro.json'];
        yield 'rh-lite' => ['rh-lite.json'];
        yield 'cliente-portal' => ['cliente-portal.json'];
    }

    #[DataProvider('provideRolesNaoMarketingLead')]
    public function testRolesNaoMarketingNaoVeemLead(string $filename): void
    {
        $decoded = $this->loadRole($filename);
        $lead = $decoded['data']['scopeLevel']['Lead'] ?? null;

        $this->assertSame(
            'no',
            $lead,
            "{$filename}.Lead deve ser 'no' (FR31 — pipeline de captação é exclusivo do Marketing)",
        );
    }

    /**
     * Story 3.8 (v0.7.2) — invariante FR31 + blindagem da Story 2.1: Marketing
     * NÃO tem visibilidade sobre conteúdo jurídico/processual/financeiro/RH/
     * Portal. Esta blindagem é fundamental para FR31 ("sem visibilidade sobre
     * clientes efetivos ou conteúdo processual") e a separação de domínios do
     * PRD jornada Rafael.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function provideMarketingScopesBlindados(): iterable
    {
        yield 'Cliente' => ['Cliente'];
        yield 'Processo' => ['Processo'];
        yield 'Audiencia' => ['Audiencia'];
        yield 'Fatura' => ['Fatura'];
        yield 'LancamentoFinanceiro' => ['LancamentoFinanceiro'];
        yield 'ContratoHonorarios' => ['ContratoHonorarios'];
        yield 'Funcionario' => ['Funcionario'];
        yield 'PortalProcess' => ['PortalProcess'];
        yield 'PortalMessage' => ['PortalMessage'];
    }

    #[DataProvider('provideMarketingScopesBlindados')]
    public function testMarketingNaoVeContentoJuridico(string $scope): void
    {
        $decoded = $this->loadRole('marketing.json');
        $level = $decoded['data']['scopeLevel'][$scope] ?? null;

        $this->assertSame(
            'no',
            $level,
            "Marketing.{$scope} deve ser 'no' (FR31 + Story 2.1 — blindagem do role Marketing contra conteúdo jurídico/financeiro/RH/Portal)",
        );
    }

    public function testTodosOitoArquivosExistemEPossuemNomesUnicos(): void
    {
        $files = \glob(self::SEED_DIR . '/*.json');
        $this->assertIsArray($files);
        $this->assertCount(8, $files, 'Devem existir exatamente 8 arquivos JSON de role.');

        $names = [];
        foreach ($files as $file) {
            $decoded = \json_decode((string) \file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $names[] = $decoded['name'];
        }

        $this->assertCount(8, \array_unique($names), 'Os 8 roles devem ter nomes únicos.');
    }

    /**
     * Story 4a.3 (v0.8.0) — FR12+FR13+FR14 invariant Sócio/Admin.Prazo=all.
     */
    public function testSocioAdminPodeAllPrazo(): void
    {
        $decoded = $this->loadRole('socio-admin.json');
        $this->assertContains(
            'Prazo',
            $decoded['data']['scopeList'],
            'Prazo deve estar no scopeList do Sócio/Admin (Story 4a.3)',
        );
        $this->assertSame(
            'all',
            $decoded['data']['scopeLevel']['Prazo'] ?? null,
            'Sócio/Admin.Prazo deve ser all (Story 4a.3 / FR12+FR13+FR14)',
        );
    }

    /**
     * Story 4a.3 — Advogado vê apenas prazos onde é o `assignedUser`
     * (read=own); pode editar próprios; cria apenas no team; sem deletar.
     *
     * Diferente do Processo/Story 3.5 (que tem collaborators), Prazo
     * usa apenas `assignedUser` (Decisão da story — pattern Audiencia).
     */
    public function testAdvogadoTemReadOwnEmPrazo(): void
    {
        $decoded = $this->loadRole('advogado.json');
        $prazo = $decoded['data']['scopeLevel']['Prazo'] ?? null;

        $this->assertIsArray($prazo, 'Advogado.Prazo deve ter aclLevel granular');
        $this->assertSame('own', $prazo['read'] ?? null, 'Advogado.Prazo.read deve ser own (Story 4a.3)');
        $this->assertSame('own', $prazo['edit'] ?? null, 'Advogado.Prazo.edit deve ser own');
        $this->assertSame('team', $prazo['create'] ?? null, 'Advogado.Prazo.create deve ser team');
        $this->assertSame('no', $prazo['delete'] ?? null, 'Advogado.Prazo.delete deve ser no');
    }

    /**
     * Story 4a.3 — Secretária consulta prazos do time para apoio operacional
     * (preparar peças, alertar advogado), mas não edita/cria/deleta.
     */
    public function testSecretariaSoLePrazo(): void
    {
        $decoded = $this->loadRole('secretaria.json');
        $prazo = $decoded['data']['scopeLevel']['Prazo'] ?? null;

        $this->assertIsArray($prazo, 'Secretária.Prazo deve ter aclLevel granular (read-only)');
        $this->assertSame('team', $prazo['read'] ?? null, 'Secretária.Prazo.read deve ser team');
        $this->assertSame('no', $prazo['edit'] ?? null);
        $this->assertSame('no', $prazo['create'] ?? null);
        $this->assertSame('no', $prazo['delete'] ?? null);
    }

    /**
     * Story 4a.3 — guarda contra regressão acidental: roles fora do fluxo
     * operacional jurídico não devem ver Prazo.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function provideRolesNaoOperacionalPrazo(): iterable
    {
        yield 'financeiro' => ['financeiro.json'];
        yield 'marketing' => ['marketing.json'];
        yield 'rh-lite' => ['rh-lite.json'];
        yield 'cliente-portal' => ['cliente-portal.json'];
    }

    #[DataProvider('provideRolesNaoOperacionalPrazo')]
    public function testRolesNaoOperacionalNaoVeemPrazo(string $filename): void
    {
        $decoded = $this->loadRole($filename);
        $prazo = $decoded['data']['scopeLevel']['Prazo'] ?? null;

        $this->assertSame(
            'no',
            $prazo,
            "{$filename}.Prazo deve ser 'no' (Story 4a.3 — fora do fluxo operacional jurídico)",
        );
    }

    /**
     * Story 4a.3 — Assistente/Estagiário tem cobertura `team` em Prazo
     * (apoio operacional ao Advogado titular dentro do team — pattern
     * Audiencia/Processo).
     */
    public function testAssistenteTemTeamEmPrazo(): void
    {
        $decoded = $this->loadRole('assistente.json');
        $prazo = $decoded['data']['scopeLevel']['Prazo'] ?? null;

        $this->assertIsArray($prazo, 'Assistente.Prazo deve ter aclLevel granular');
        $this->assertSame('team', $prazo['read'] ?? null);
        $this->assertSame('team', $prazo['edit'] ?? null);
        $this->assertSame('team', $prazo['create'] ?? null);
        $this->assertSame('no', $prazo['delete'] ?? null, 'Assistente.Prazo.delete deve ser no — só Sócio/Admin pode deletar');
    }

    // ---------------------------------------------------------------------
    // Story 4a.3.1 — fieldLevel.Prazo nas 4 roles operacionais (V005 migration)
    // ---------------------------------------------------------------------

    /**
     * Story 4a.3.1 — Sócio/Admin tem yes em todos os 6 campos novos do Prazo
     * (descricao, prioridade, tipoPrazo, motivoReagendamento, cliente,
     * parteContraria). Política Dev Notes §3 da story.
     */
    public function testSocioAdminFieldLevelPrazoYesEm6Campos(): void
    {
        $decoded = $this->loadRole('socio-admin.json');
        $fieldLevel = $decoded['data']['fieldLevel']['Prazo'] ?? null;

        $this->assertIsArray($fieldLevel, 'Sócio/Admin.fieldLevel.Prazo ausente — V005 não aplicada (Story 4a.3.1)');
        foreach (['descricao', 'prioridade', 'tipoPrazo', 'motivoReagendamento', 'cliente', 'parteContraria'] as $f) {
            $this->assertSame('yes', $fieldLevel[$f] ?? null, "Sócio/Admin.fieldLevel.Prazo.{$f} deve ser 'yes'");
        }
    }

    /**
     * Story 4a.3.1 — Advogado tem yes em todos os 6 campos novos (advogado
     * opera os Prazos do dia-a-dia).
     */
    public function testAdvogadoFieldLevelPrazoYesEm6Campos(): void
    {
        $decoded = $this->loadRole('advogado.json');
        $fieldLevel = $decoded['data']['fieldLevel']['Prazo'] ?? null;

        $this->assertIsArray($fieldLevel);
        foreach (['descricao', 'prioridade', 'tipoPrazo', 'motivoReagendamento', 'cliente', 'parteContraria'] as $f) {
            $this->assertSame('yes', $fieldLevel[$f] ?? null, "Advogado.fieldLevel.Prazo.{$f} deve ser 'yes'");
        }
    }

    /**
     * Story 4a.3.1 — Secretária tem `read` (read-only) em todos os 6 campos
     * (alinhado a scope.Prazo.edit=no — pode consultar mas não modificar).
     */
    public function testSecretariaFieldLevelPrazoReadEm6Campos(): void
    {
        $decoded = $this->loadRole('secretaria.json');
        $fieldLevel = $decoded['data']['fieldLevel']['Prazo'] ?? null;

        $this->assertIsArray($fieldLevel);
        foreach (['descricao', 'prioridade', 'tipoPrazo', 'motivoReagendamento', 'cliente', 'parteContraria'] as $f) {
            $this->assertSame('read', $fieldLevel[$f] ?? null, "Secretária.fieldLevel.Prazo.{$f} deve ser 'read'");
        }
    }

    /**
     * Story 4a.3.1 — Assistente também tem yes (suporte completo ao Advogado
     * dentro do team).
     */
    public function testAssistenteFieldLevelPrazoYesEm6Campos(): void
    {
        $decoded = $this->loadRole('assistente.json');
        $fieldLevel = $decoded['data']['fieldLevel']['Prazo'] ?? null;

        $this->assertIsArray($fieldLevel);
        foreach (['descricao', 'prioridade', 'tipoPrazo', 'motivoReagendamento', 'cliente', 'parteContraria'] as $f) {
            $this->assertSame('yes', $fieldLevel[$f] ?? null, "Assistente.fieldLevel.Prazo.{$f} deve ser 'yes'");
        }
    }

    /**
     * Story 4a.3.1 — guarda contra regressão: roles não-operacionais NÃO têm
     * fieldLevel.Prazo (scope.Prazo='no' já bloqueia tudo, fieldLevel é
     * deliberadamente ausente).
     *
     * @return iterable<string, array{0: string}>
     */
    public static function provideRolesNaoOperacionalFieldLevelPrazo(): iterable
    {
        yield 'financeiro' => ['financeiro.json'];
        yield 'marketing' => ['marketing.json'];
        yield 'rh-lite' => ['rh-lite.json'];
        yield 'cliente-portal' => ['cliente-portal.json'];
    }

    #[DataProvider('provideRolesNaoOperacionalFieldLevelPrazo')]
    public function testRolesNaoOperacionaisNaoTemFieldLevelPrazo(string $filename): void
    {
        $decoded = $this->loadRole($filename);
        $fieldLevel = $decoded['data']['fieldLevel'] ?? [];

        $this->assertArrayNotHasKey(
            'Prazo',
            $fieldLevel,
            "{$filename}.fieldLevel não deve ter Prazo (scope.no já bloqueia — Story 4a.3.1)",
        );
    }

    // ---------------------------------------------------------------------
    // Story 4b.1a - PublicacaoAmbigua scope
    // ---------------------------------------------------------------------

    public function testSocioAdminPodeAllPublicacaoAmbigua(): void
    {
        $decoded = $this->loadRole('socio-admin.json');

        $this->assertContains('PublicacaoAmbigua', $decoded['data']['scopeList']);
        $this->assertSame('all', $decoded['data']['scopeLevel']['PublicacaoAmbigua'] ?? null);
    }

    public function testAdvogadoTemTeamReadEditPublicacaoAmbiguaSemCreateDelete(): void
    {
        $decoded = $this->loadRole('advogado.json');
        $level = $decoded['data']['scopeLevel']['PublicacaoAmbigua'] ?? null;

        $this->assertContains('PublicacaoAmbigua', $decoded['data']['scopeList']);
        $this->assertSame(
            ['read' => 'team', 'edit' => 'team', 'create' => 'no', 'delete' => 'no'],
            $level,
        );
    }

    public function testAssistenteLePublicacaoAmbiguaMasNaoEditaNemCria(): void
    {
        $decoded = $this->loadRole('assistente.json');
        $level = $decoded['data']['scopeLevel']['PublicacaoAmbigua'] ?? null;

        $this->assertContains('PublicacaoAmbigua', $decoded['data']['scopeList']);
        $this->assertSame(
            ['read' => 'team', 'edit' => 'no', 'create' => 'no', 'delete' => 'no'],
            $level,
        );
    }

    public function testSecretariaNaoTemPublicacaoAmbigua(): void
    {
        $decoded = $this->loadRole('secretaria.json');

        $this->assertNotContains('PublicacaoAmbigua', $decoded['data']['scopeList']);
        $this->assertArrayNotHasKey('PublicacaoAmbigua', $decoded['data']['scopeLevel']);
    }

    public function testSeedsIncluemFaturaComPoliticaCanonicaDaStory63(): void
    {
        $expectations = [
            'socio-admin.json' => 'all',
            'financeiro.json' => 'all',
            'advogado.json' => ['read' => 'own', 'edit' => 'no', 'create' => 'no', 'delete' => 'no'],
            'assistente.json' => ['read' => 'own', 'edit' => 'no', 'create' => 'no', 'delete' => 'no'],
            'secretaria.json' => 'no',
            'marketing.json' => 'no',
            'rh-lite.json' => 'no',
            'cliente-portal.json' => 'no',
        ];

        foreach ($expectations as $filename => $expected) {
            $decoded = $this->loadRole($filename);
            $this->assertContains('Fatura', $decoded['data']['scopeList'], "{$filename} deve listar Fatura.");
            $this->assertSame($expected, $decoded['data']['scopeLevel']['Fatura'] ?? null, "{$filename}.Fatura divergente.");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadRole(string $filename): array
    {
        $raw = (string) \file_get_contents(self::SEED_DIR . '/' . $filename);

        return \json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }
}
