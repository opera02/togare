<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Services;

use Espo\Entities\Role;
use Espo\Entities\User;
use Espo\Modules\TogareCore\Services\HealthCheckService;
use Espo\Modules\TogareCore\Services\TogareBriefingService;
use Espo\ORM\EntityManager;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Story 10.1 / FR40 — TogareBriefingService (lógica de negócio pura).
 *
 * Exercita:
 *  - Cada role retorna APENAS os próprios badges (blindagem cruzada AC2)
 *  - Role desconhecido → badges vazio (AC3)
 *  - Admin → trata como Sócio/Admin (resolveRoleName)
 *  - Sócio/Admin → badge health com tipo "health" (AC1, reuso HealthCheckService)
 *  - Estrutura de retorno: { badges, role, generatedAt }
 *  - Nunca lança — queries que falham retornam badges vazio (AC6)
 *
 * Usa Fake repository pattern (mesmo de FaturaSaldoServiceTest, ContratoHonorariosLookupServiceTest)
 * para evitar mock de RDBRepository::where() que não é configurável no PHPUnit 12.
 * HealthCheckService é final — instanciado com PDO SQLite in-memory (mesmo HealthCheckServiceTest).
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class TogareBriefingServiceTest extends TestCase
{
    // ── Helpers ─────────────────────────────────────────────────────────────

    private function makeUser(string $roleNome, bool $isAdmin = false): User
    {
        $user = $this->createMock(User::class);
        $user->method('isAdmin')->willReturn($isAdmin);
        $user->method('getId')->willReturn('user-id-1');
        $user->method('getLinkMultipleIdList')->with('roles')->willReturn(
            ($isAdmin || $roleNome === '') ? [] : ['role-id-1']
        );
        return $user;
    }

    private function makeRole(string $nome): Role
    {
        $role = $this->createMock(Role::class);
        $role->method('get')->with('name')->willReturn($nome);
        return $role;
    }

    private function makeEntityManager(string $roleNome, int $defaultCount = 0): EntityManager
    {
        $role = $this->makeRole($roleNome);
        $repo = new FakeCountingRepository($defaultCount);

        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')
            ->with(Role::ENTITY_TYPE, 'role-id-1')
            ->willReturn($role);
        $em->method('getRDBRepository')->willReturn($repo);

        return $em;
    }

    private function makeHealthCheckService(): HealthCheckService
    {
        // HealthCheckService é final — usa instância real com SQLite in-memory.
        // Redis aponta para porta 1 (recusa na hora, sem timeout longo).
        \putenv('TOGARE_REDIS_HOST=127.0.0.1');
        \putenv('TOGARE_REDIS_PORT=1');
        \putenv('TOGARE_REDIS_PASSWORD=');
        \putenv('TOGARE_BACKUP_SENTINEL_PATH=/inexistente/last-success.json');

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $emForHcs = $this->createMock(EntityManager::class);
        $emForHcs->method('getPDO')->willReturn($pdo);

        return new HealthCheckService($emForHcs);
    }

    // ── Estrutura de retorno ─────────────────────────────────────────────────

    public function testGetSummaryRetornaEstruturaNecessaria(): void
    {
        $em   = $this->makeEntityManager('Advogado');
        $user = $this->makeUser('Advogado');
        $svc  = new TogareBriefingService($em, $this->makeHealthCheckService());

        $result = $svc->getSummaryForUser($user);

        self::assertArrayHasKey('badges', $result);
        self::assertArrayHasKey('role', $result);
        self::assertArrayHasKey('generatedAt', $result);
        self::assertIsArray($result['badges']);
        self::assertSame('advogado', $result['role']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $result['generatedAt']);
    }

    // ── Role desconhecido / admin ────────────────────────────────────────────

    public function testRoleDesconhecidoRetornaBadgesVazio(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn(null);

        $user = $this->makeUser('');
        $svc  = new TogareBriefingService($em, $this->makeHealthCheckService());

        $result = $svc->getSummaryForUser($user);

        self::assertSame([], $result['badges']);
        self::assertSame('', $result['role']);
    }

    public function testAdminRetornaSocioAdminBadges(): void
    {
        $repo = new FakeCountingRepository(0);
        $em   = $this->createMock(EntityManager::class);
        $em->method('getRDBRepository')->willReturn($repo);
        $em->method('getEntityById')->willReturn(null);

        $user = $this->makeUser('', isAdmin: true);
        $svc  = new TogareBriefingService($em, $this->makeHealthCheckService());

        $result = $svc->getSummaryForUser($user);

        self::assertSame('socio-admin', $result['role']);
        $keys = array_column($result['badges'], 'key');
        self::assertContains('health', $keys);
    }

    // ── Por role — badges corretos (blindagem cruzada AC2) ──────────────────

    public function testSocioAdminTemHealthBadge(): void
    {
        $em   = $this->makeEntityManager('Sócio/Admin');
        $user = $this->makeUser('Sócio/Admin');
        $svc  = new TogareBriefingService($em, $this->makeHealthCheckService());

        $result = $svc->getSummaryForUser($user);

        self::assertSame('socio-admin', $result['role']);
        $badge = $result['badges'][0] ?? null;
        self::assertNotNull($badge, 'Deve ter ao menos 1 badge (health)');
        self::assertSame('health', $badge['key']);
        self::assertSame('health', $badge['type']);
        self::assertContains($badge['healthStatus'], ['ok', 'lento', 'offline']);
    }

    public function testAdvogadoTemBadgesPrazoPublicacaoAudiencia(): void
    {
        $em   = $this->makeEntityManager('Advogado');
        $user = $this->makeUser('Advogado');
        $svc  = new TogareBriefingService($em, $this->makeHealthCheckService());

        $result = $svc->getSummaryForUser($user);

        self::assertSame('advogado', $result['role']);
        $keys = array_column($result['badges'], 'key');
        self::assertContains('prazo-pendente', $keys);
        self::assertContains('publicacao-nova', $keys);
        self::assertContains('audiencia-semana', $keys);
        // NÃO deve ter badges de outros roles (blindagem AC2).
        self::assertNotContains('fatura-pendente', $keys);
        self::assertNotContains('funcionario-ativo', $keys);
    }

    public function testAssistenteTemBadgesTarefaAudiencia(): void
    {
        $em   = $this->makeEntityManager('Assistente');
        $user = $this->makeUser('Assistente');
        $svc  = new TogareBriefingService($em, $this->makeHealthCheckService());

        $result = $svc->getSummaryForUser($user);

        $keys = array_column($result['badges'], 'key');
        self::assertContains('tarefa-atribuida', $keys);
        self::assertContains('audiencia-semana', $keys);
        self::assertNotContains('prazo-pendente', $keys);
    }

    public function testSecretariaTemBadgesAudienciaCliente(): void
    {
        $em   = $this->makeEntityManager('Secretária');
        $user = $this->makeUser('Secretária');
        $svc  = new TogareBriefingService($em, $this->makeHealthCheckService());

        $result = $svc->getSummaryForUser($user);

        $keys = array_column($result['badges'], 'key');
        self::assertContains('audiencia-hoje', $keys);
        self::assertContains('cliente-recente', $keys);
        self::assertNotContains('fatura-pendente', $keys);
    }

    public function testFinanceiroTemBadgesFaturaLancamento(): void
    {
        $em   = $this->makeEntityManager('Financeiro');
        $user = $this->makeUser('Financeiro');
        $svc  = new TogareBriefingService($em, $this->makeHealthCheckService());

        $result = $svc->getSummaryForUser($user);

        $keys = array_column($result['badges'], 'key');
        self::assertContains('fatura-pendente', $keys);
        self::assertContains('lancamento-mes', $keys);
        self::assertNotContains('lead-ativo', $keys);
    }

    public function testMarketingTemBadgesLeadOportunidade(): void
    {
        $em   = $this->makeEntityManager('Marketing');
        $user = $this->makeUser('Marketing');
        $svc  = new TogareBriefingService($em, $this->makeHealthCheckService());

        $result = $svc->getSummaryForUser($user);

        $keys = array_column($result['badges'], 'key');
        self::assertContains('lead-ativo', $keys);
        self::assertContains('oportunidade-andamento', $keys);
        self::assertNotContains('funcionario-ativo', $keys);
    }

    public function testRhLiteTemBadgeFuncionario(): void
    {
        $em   = $this->makeEntityManager('RH-lite');
        $user = $this->makeUser('RH-lite');
        $svc  = new TogareBriefingService($em, $this->makeHealthCheckService());

        $result = $svc->getSummaryForUser($user);

        $keys = array_column($result['badges'], 'key');
        self::assertContains('funcionario-ativo', $keys);
        self::assertNotContains('prazo-pendente', $keys);
    }

    // ── Nunca lança (AC6) ────────────────────────────────────────────────────

    public function testQueryFalhandoNaoLanca(): void
    {
        // EntityManager que lança em tudo → TogareBriefingService NÃO deve relançar.
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willThrowException(new \RuntimeException('DB down'));
        $em->method('getRDBRepository')->willThrowException(new \RuntimeException('DB down'));

        // HealthCheckService real com SQLite — getPanel() nunca lança (AC5 próprio).
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $emForHcs = $this->createMock(EntityManager::class);
        $emForHcs->method('getPDO')->willReturn($pdo);
        $hcs = new HealthCheckService($emForHcs);

        $user = $this->makeUser('', isAdmin: true);
        $svc  = new TogareBriefingService($em, $hcs);

        // Para role socio-admin, getRDBRepository é chamado para usuarios-ativos.
        // Se lançar, deve ser capturado internamente.
        $result = null;
        try {
            $result = $svc->getSummaryForUser($user);
        } catch (\Throwable $e) {
            self::fail('getSummaryForUser lançou: ' . $e::class . ': ' . $e->getMessage());
        }

        self::assertIsArray($result);
        self::assertArrayHasKey('badges', $result);
    }
}

/**
 * @internal Fake repository que retorna um count fixo e suporta encadeamento.
 *
 * Cobre os dois casos de uso de TogareBriefingService:
 *   $repo->where([...])->count()  → count
 *   $repo->where(['x>=' => ...])->where(['y<=' => ...])->count()  → count
 */
final class FakeCountingRepository
{
    public function __construct(private readonly int $count = 0)
    {
    }

    /** @param mixed $_clause */
    public function where($_clause = [], mixed $_value = null): self
    {
        return $this;
    }

    public function count(): int
    {
        return $this->count;
    }

    // Ignora outros métodos que possam ser chamados indiretamente.
    public function __call(string $_name, array $_args): mixed
    {
        return $this;
    }
}
