<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareRbac\Hooks\User;

use Espo\Core\Exceptions\BadRequest;
use Espo\Entities\User;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareRbac\Hooks\User\UserRoleRequired;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

/**
 * Cobre AC5 — bloqueio de criação de User regular sem role.
 */
final class UserRoleRequiredTest extends TestCase
{
    private UserRoleRequired $hook;

    protected function setUp(): void
    {
        $stdout = \fopen('php://memory', 'w+');
        $stderr = \fopen('php://memory', 'w+');
        TogareLogger::init('test-rbac-hook', null, $stdout, $stderr);

        $this->hook = new UserRoleRequired();
    }

    public function testNovoUserRegularSemRolesLancaBadRequest(): void
    {
        $user = new User();
        $user->set([
            'userName' => 'novo',
            'type' => User::TYPE_REGULAR,
        ]);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('role atribuído');

        $this->hook->beforeSave($user, SaveOptions::create());
    }

    public function testNovoUserRegularComRolesPassa(): void
    {
        $user = new User();
        $user->set([
            'userName' => 'joao',
            'type' => User::TYPE_REGULAR,
            'rolesIds' => ['role-id-advogado'],
        ]);

        $this->hook->beforeSave($user, SaveOptions::create());

        $this->assertTrue(true, 'Não deve lançar exceção.');
    }

    public function testUpdateUserSemRolesPassaSemErro(): void
    {
        $user = new User();
        $user->set(['type' => User::TYPE_REGULAR]);
        $user->setNotNew();

        $this->hook->beforeSave($user, SaveOptions::create());

        $this->assertTrue(true);
    }

    public function testAdminBypassaValidacaoMesmoSemRoles(): void
    {
        $user = new User();
        $user->set([
            'userName' => 'admin-tecnico',
            'type' => User::TYPE_ADMIN,
        ]);

        $this->hook->beforeSave($user, SaveOptions::create());

        $this->assertTrue(true, 'Admin pode existir sem role nominal.');
    }

    public function testApiBypassaValidacao(): void
    {
        $user = new User();
        $user->set([
            'userName' => 'api-key-1',
            'type' => User::TYPE_API,
        ]);

        $this->hook->beforeSave($user, SaveOptions::create());

        $this->assertTrue(true);
    }

    public function testPortalBypassaValidacao(): void
    {
        $user = new User();
        $user->set([
            'userName' => 'cliente-x',
            'type' => User::TYPE_PORTAL,
        ]);

        $this->hook->beforeSave($user, SaveOptions::create());

        $this->assertTrue(true);
    }

    public function testBadRequestTemBodyComRazaoEstruturada(): void
    {
        $user = new User();
        $user->set([
            'userName' => 'novo',
            'type' => User::TYPE_REGULAR,
        ]);

        try {
            $this->hook->beforeSave($user, SaveOptions::create());
            $this->fail('Deveria ter lançado BadRequest.');
        } catch (BadRequest $e) {
            $body = $e->getBody();
            $this->assertNotNull($body);
            $decoded = \json_decode($body, true);
            $this->assertSame('role_required', $decoded['reason'] ?? null);
        }
    }
}
