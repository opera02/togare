<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareRbac\Service\Invitation;

use Espo\Core\Exceptions\BadRequest;
use Espo\Entities\User;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareRbac\Service\Invitation\InvitationService;
use Espo\ORM\EntityManager;
use Espo\Tools\UserSecurity\Password\Service as PasswordService;
use PHPUnit\Framework\TestCase;

/**
 * Cobre AC4 + AC5 — InvitationService valida roles e dispara
 * sendAccessInfoForNewUser.
 */
final class InvitationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $stdout = \fopen('php://memory', 'w+');
        $stderr = \fopen('php://memory', 'w+');
        TogareLogger::init('test-rbac-invitation', null, $stdout, $stderr);
    }

    public function testInviteSemRoleIdsLancaBadRequest(): void
    {
        $em = $this->createStub(EntityManager::class);
        $passwordService = $this->createMock(PasswordService::class);
        $passwordService->expects($this->never())->method('sendAccessInfoForNewUser');

        $service = new InvitationService($em, $passwordService);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('atribuir ao menos um role');

        $service->invite(
            userName: 'joao',
            emailAddress: 'joao@x.com',
            firstName: 'João',
            lastName: 'Silva',
            roleIds: [],
        );
    }

    public function testInviteRoleInexistenteLancaBadRequest(): void
    {
        $repo = $this->makeRoleRepo(0);  // 0 encontrados, mas pediu 1

        $em = $this->createStub(EntityManager::class);
        $em->method('getRDBRepository')->willReturn($repo);

        $passwordService = $this->createMock(PasswordService::class);
        $passwordService->expects($this->never())->method('sendAccessInfoForNewUser');

        $service = new InvitationService($em, $passwordService);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessageMatches('/role IDs.*não existem/');

        $service->invite(
            userName: 'joao',
            emailAddress: 'joao@x.com',
            firstName: 'João',
            lastName: 'Silva',
            roleIds: ['inexistente'],
        );
    }

    public function testInviteValidoCriaUserEDispara_sendAccessInfoForNewUser(): void
    {
        $repo = $this->makeRoleRepo(1);  // 1 role encontrado, igual ao pedido

        $newUser = new User();

        $em = $this->createMock(EntityManager::class);
        $em->method('getRDBRepository')->willReturn($repo);
        $em->method('getNewEntity')->willReturn($newUser);
        $em->expects($this->once())->method('saveEntity')->with($newUser);

        $passwordService = $this->createMock(PasswordService::class);
        $passwordService->expects($this->once())
            ->method('sendAccessInfoForNewUser')
            ->with($newUser);

        $service = new InvitationService($em, $passwordService);

        $result = $service->invite(
            userName: 'joao',
            emailAddress: 'joao@x.com',
            firstName: 'João',
            lastName: 'Silva',
            roleIds: ['role-id-advogado'],
        );

        $this->assertSame($newUser, $result);
        $this->assertSame('joao', $result->get('userName'));
        $this->assertSame('joao@x.com', $result->get('emailAddress'));
        $this->assertSame(User::TYPE_REGULAR, $result->get('type'));
        $this->assertTrue((bool) $result->get('isActive'));
        $this->assertSame(['role-id-advogado'], $result->getLinkMultipleIdList('roles'));
    }

    /**
     * Anonymous repo com `where(): self` + `count(): int`. PHPUnit 12 removeu
     * `addMethods` — reusamos uma classe anônima fluente.
     */
    private function makeRoleRepo(int $countResult): object
    {
        return new class ($countResult) {
            public function __construct(private int $countResult)
            {
            }

            /** @param array<string, mixed> $where */
            public function where(array $where): self
            {
                return $this;
            }

            public function count(): int
            {
                return $this->countResult;
            }
        };
    }
}
