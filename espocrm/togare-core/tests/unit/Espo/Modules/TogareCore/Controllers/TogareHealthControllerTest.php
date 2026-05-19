<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\TogareCore\Controllers\TogareHealth;
use Espo\Modules\TogareCore\Services\HealthCheckService;
use Espo\ORM\EntityManager;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Story 10.2 / AC4 (NÃO-DEFERÍVEL) — RBAC do endpoint TogareHealth.
 *
 * Sócio/Admin (admin do sistema OU role "Sócio/Admin") → 200.
 * Demais roles → 403 Forbidden. Blindagem cruzada (UX KDC#1).
 */
final class TogareHealthControllerTest extends TestCase
{
    private function makeRole(string $name): object
    {
        return new class ($name) {
            public function __construct(private string $name)
            {
            }

            public function get(string $field): mixed
            {
                return $field === 'name' ? $this->name : null;
            }
        };
    }

    /**
     * @param list<object> $roles
     */
    private function makeEntityManager(array $roles): EntityManager
    {
        $relation = new class ($roles) {
            /** @param list<object> $roles */
            public function __construct(private array $roles)
            {
            }

            public function find(): array
            {
                return $this->roles;
            }
        };
        $repo = new class ($relation) {
            public function __construct(private object $relation)
            {
            }

            public function getRelation(object $user, string $link): object
            {
                return $this->relation;
            }
        };
        $em = $this->createMock(EntityManager::class);
        $em->method('getRDBRepository')->willReturn($repo);

        return $em;
    }

    /**
     * HealthCheckService é `final` (não pode ser mockado) — usamos a classe
     * real com EntityManager mockado (SQLite + Redis fail-fast), igual ao
     * HealthCheckServiceTest. O controller só chama getPanel() quando o
     * acesso é concedido; nos testes de 403 nunca é chamado.
     */
    private function makeService(): HealthCheckService
    {
        \putenv('TOGARE_REDIS_HOST=127.0.0.1');
        \putenv('TOGARE_REDIS_PORT=1');
        \putenv('TOGARE_BACKUP_SENTINEL_PATH=/caminho/inexistente/last-success.json');

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willReturn($pdo);

        return new HealthCheckService($em);
    }

    protected function tearDown(): void
    {
        \putenv('TOGARE_REDIS_HOST');
        \putenv('TOGARE_REDIS_PORT');
        \putenv('TOGARE_BACKUP_SENTINEL_PATH');
    }

    public function testAdminDoSistemaRecebe200(): void
    {
        $user = $this->createMock(User::class);
        $user->method('isAdmin')->willReturn(true);

        $controller = new TogareHealth(
            $user,
            $this->makeEntityManager([]),
            $this->makeService(),
        );

        $resp = $controller->getActionData($this->createMock(Request::class));

        self::assertIsObject($resp);
        self::assertObjectHasProperty('tiles', $resp);
    }

    public function testRoleSocioAdminRecebe200MesmoSemFlagAdmin(): void
    {
        $user = $this->createMock(User::class);
        $user->method('isAdmin')->willReturn(false);

        $controller = new TogareHealth(
            $user,
            $this->makeEntityManager([$this->makeRole('Sócio/Admin')]),
            $this->makeService(),
        );

        $resp = $controller->getActionData($this->createMock(Request::class));

        self::assertIsObject($resp);
        self::assertObjectHasProperty('generatedAt', $resp);
    }

    public function testRoleComumRecebe403(): void
    {
        $user = $this->createMock(User::class);
        $user->method('isAdmin')->willReturn(false);

        $controller = new TogareHealth(
            $user,
            $this->makeEntityManager([$this->makeRole('Advogado'), $this->makeRole('Secretária')]),
            $this->makeService(),
        );

        $this->expectException(Forbidden::class);
        $controller->getActionData($this->createMock(Request::class));
    }

    public function testSemRoleNenhumaRecebe403FailClosed(): void
    {
        $user = $this->createMock(User::class);
        $user->method('isAdmin')->willReturn(false);

        $controller = new TogareHealth(
            $user,
            $this->makeEntityManager([]),
            $this->makeService(),
        );

        $this->expectException(Forbidden::class);
        $controller->getActionData($this->createMock(Request::class));
    }
}
