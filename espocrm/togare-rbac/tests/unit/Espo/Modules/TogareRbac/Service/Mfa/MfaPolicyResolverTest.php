<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareRbac\Service\Mfa;

use Espo\Entities\User;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareRbac\Service\Mfa\MfaPolicyResolver;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

/**
 * Cobre AC3 — MfaPolicyResolver.isMfaRequired por role.
 */
final class MfaPolicyResolverTest extends TestCase
{
    private EntityManager $em;

    protected function setUp(): void
    {
        $stdout = \fopen('php://memory', 'w+');
        $stderr = \fopen('php://memory', 'w+');
        TogareLogger::init('test-mfa-policy', null, $stdout, $stderr);

        $this->em = $this->createStub(EntityManager::class);
    }

    public function testAdminNativoRetornaTrue(): void
    {
        $user = new User();
        $user->set('type', User::TYPE_ADMIN);

        $resolver = new MfaPolicyResolver($this->em);

        $this->assertTrue($resolver->isMfaRequired($user));
    }

    public function testSocioAdminRoleRetornaTrue(): void
    {
        $user = new User();
        $user->setId('user-socio-01');
        $user->set(['rolesIds' => ['role-socio-01']]);

        $repoMock = $this->createMock(\stdClass::class);

        $em = $this->createMock(EntityManager::class);
        $em->method('getRDBRepository')
            ->with('Role')
            ->willReturn(new class (1) {
                public function __construct(private int $returnCount) {}
                public function where(array $w): static { return $this; }
                public function count(): int { return $this->returnCount; }
            });

        $resolver = new MfaPolicyResolver($em);

        $this->assertTrue($resolver->isMfaRequired($user));
    }

    public function testAdvogadoRoleRetornaFalse(): void
    {
        $user = new User();
        $user->setId('user-adv-01');
        $user->set(['rolesIds' => ['role-adv-01']]);

        $em = $this->createMock(EntityManager::class);
        $em->method('getRDBRepository')
            ->with('Role')
            ->willReturn(new class (0) {
                public function __construct(private int $returnCount) {}
                public function where(array $w): static { return $this; }
                public function count(): int { return $this->returnCount; }
            });

        $resolver = new MfaPolicyResolver($em);

        $this->assertFalse($resolver->isMfaRequired($user));
    }

    public function testPortalUserRetornaFalse(): void
    {
        $user = new User();
        $user->set('type', User::TYPE_PORTAL);
        $user->set(['rolesIds' => ['role-socio-01']]);

        $resolver = new MfaPolicyResolver($this->em);

        $this->assertFalse($resolver->isMfaRequired($user));
    }

    public function testApiUserRetornaFalse(): void
    {
        $user = new User();
        $user->set('type', User::TYPE_API);

        $resolver = new MfaPolicyResolver($this->em);

        $this->assertFalse($resolver->isMfaRequired($user));
    }

    public function testUserSemRolesRetornaFalse(): void
    {
        $user = new User();
        $user->setId('user-sem-roles');
        // rolesIds vazio

        $resolver = new MfaPolicyResolver($this->em);

        $this->assertFalse($resolver->isMfaRequired($user));
    }

    public function testMultiRoleSocioAdminRetornaTrue(): void
    {
        $user = new User();
        $user->setId('user-multi-01');
        $user->set(['rolesIds' => ['role-adv-01', 'role-socio-01']]);

        $em = $this->createMock(EntityManager::class);
        $em->method('getRDBRepository')
            ->with('Role')
            ->willReturn(new class (1) {
                public function __construct(private int $returnCount) {}
                public function where(array $w): static { return $this; }
                public function count(): int { return $this->returnCount; }
            });

        $resolver = new MfaPolicyResolver($em);

        $this->assertTrue($resolver->isMfaRequired($user));
    }
}
