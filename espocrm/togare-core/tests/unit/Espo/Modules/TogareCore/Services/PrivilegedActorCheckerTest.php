<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Services;

use Espo\Entities\Role;
use Espo\Entities\User;
use Espo\Modules\TogareCore\Services\PrivilegedActorChecker;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

/**
 * Cobre PrivilegedActorChecker::isPrivileged() — Story 3.5, FR11.
 *
 * Definição canônica de "privileged actor":
 *  - User::isAdmin() === true (system superuser EspoCRM); OU
 *  - User tem role com name === 'Sócio/Admin'.
 *
 * Falha graciosa: EntityManager lança exceção → false (fail-closed).
 */
final class PrivilegedActorCheckerTest extends TestCase
{
    public function testNullUserRetornaFalse(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->expects(self::never())->method('getEntityById');

        $checker = new PrivilegedActorChecker($em);

        self::assertFalse($checker->isPrivileged(null));
    }

    public function testSuperadminNativoRetornaTrue(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->expects(self::never())->method('getEntityById');

        $checker = new PrivilegedActorChecker($em);

        $user = new User();
        $user->set('type', User::TYPE_ADMIN);

        self::assertTrue($checker->isPrivileged($user));
    }

    public function testRoleSocioAdminRetornaTrue(): void
    {
        $role = new Role();
        $role->setId('role-sa');
        $role->set('name', 'Sócio/Admin');

        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')
            ->with(Role::ENTITY_TYPE, 'role-sa')
            ->willReturn($role);

        $checker = new PrivilegedActorChecker($em);

        $user = new User();
        $user->set(['type' => User::TYPE_REGULAR, 'rolesIds' => ['role-sa']]);

        self::assertTrue($checker->isPrivileged($user));
    }

    public function testRoleAdvogadoRetornaFalse(): void
    {
        $role = new Role();
        $role->setId('role-adv');
        $role->set('name', 'Advogado');

        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')
            ->with(Role::ENTITY_TYPE, 'role-adv')
            ->willReturn($role);

        $checker = new PrivilegedActorChecker($em);

        $user = new User();
        $user->set(['type' => User::TYPE_REGULAR, 'rolesIds' => ['role-adv']]);

        self::assertFalse($checker->isPrivileged($user));
    }

    public function testSemRolesRetornaFalse(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->expects(self::never())->method('getEntityById');

        $checker = new PrivilegedActorChecker($em);

        $user = new User();
        $user->set('type', User::TYPE_REGULAR);

        self::assertFalse($checker->isPrivileged($user));
    }

    public function testEntityManagerExcecaoRetornaFalse(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->expects(self::once())->method('getEntityById')
            ->willThrowException(new \RuntimeException('DB unavailable'));

        $checker = new PrivilegedActorChecker($em);

        $user = new User();
        $user->set(['type' => User::TYPE_REGULAR, 'rolesIds' => ['role-broken']]);

        self::assertFalse($checker->isPrivileged($user));
    }
}
