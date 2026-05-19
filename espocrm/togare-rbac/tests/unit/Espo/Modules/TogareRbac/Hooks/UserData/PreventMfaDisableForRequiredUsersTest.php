<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareRbac\Hooks\UserData;

use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Entities\UserData;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareRbac\Hooks\UserData\PreventMfaDisableForRequiredUsers;
use Espo\Modules\TogareRbac\Service\Mfa\MfaPolicyResolver;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

/**
 * Cobre AC7, AC8 — PreventMfaDisableForRequiredUsers hook.
 */
final class PreventMfaDisableForRequiredUsersTest extends TestCase
{
    protected function setUp(): void
    {
        $stdout = \fopen('php://memory', 'w+');
        $stderr = \fopen('php://memory', 'w+');
        TogareLogger::init('test-prevent-mfa', null, $stdout, $stderr);
    }

    private function makeUserData(string $userId, bool $auth2FA, ?bool $fetchedAuth2FA = null): UserData
    {
        $ud = new UserData();
        $ud->setId('ud-' . $userId);
        if ($fetchedAuth2FA !== null) {
            $ud->setFetched('auth2FA', $fetchedAuth2FA);
        }
        $ud->set([
            'userId' => $userId,
            'auth2FA' => $auth2FA,
        ]);
        return $ud;
    }

    private function makeUser(string $id, string $type = User::TYPE_REGULAR): User
    {
        $user = new User();
        $user->setId($id);
        $user->set('type', $type);
        $user->set('userName', 'user_' . $id);
        return $user;
    }

    private function makeEm(User $user): EntityManager
    {
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')
            ->willReturnCallback(function(string $class, string $id) use ($user) {
                if ($id === $user->getId()) {
                    return $user;
                }
                return null;
            });
        $em->method('getRDBRepository')->willReturn(
            new class {
                public function where(array $w): static { return $this; }
                public function count(): int { return 0; }
            }
        );
        return $em;
    }

    public function testSocioAdminDesativarLancaForbidden(): void
    {
        $user = $this->makeUser('socio-01');
        $user->set(['rolesIds' => ['role-socio-01']]);

        $em = $this->makeEm($user);

        // Resolver diz que MFA é obrigatório para este user
        $resolver = $this->createMock(MfaPolicyResolver::class);
        $resolver->method('isMfaRequired')->willReturn(true);

        $userData = $this->makeUserData('socio-01', false, true);
        $options = SaveOptions::create();

        $hook = new PreventMfaDisableForRequiredUsers($em, $resolver, new \tests\unit\Espo\Modules\TogareRbac\Stubs\AuditLogContractStub());

        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage('MFA não pode ser desativado para a role Sócio/Admin');

        $hook->beforeSave($userData, $options);
    }

    public function testAdvogadoDesativarPermitido(): void
    {
        $user = $this->makeUser('adv-01');
        $user->set(['rolesIds' => ['role-adv-01']]);

        $em = $this->makeEm($user);

        $resolver = $this->createMock(MfaPolicyResolver::class);
        $resolver->method('isMfaRequired')->willReturn(false);

        $userData = $this->makeUserData('adv-01', false, true);
        $options = SaveOptions::create();

        $hook = new PreventMfaDisableForRequiredUsers($em, $resolver, new \tests\unit\Espo\Modules\TogareRbac\Stubs\AuditLogContractStub());

        // Não lança exceção
        $hook->beforeSave($userData, $options);
        $this->assertTrue(true);
    }

    public function testAtivacaoNaoBloqueada(): void
    {
        $user = $this->makeUser('socio-01');

        $em = $this->makeEm($user);

        $resolver = $this->createMock(MfaPolicyResolver::class);
        // Mesmo que seja required, ativação (auth2FA: false→true) não deve bloquear
        $resolver->expects($this->never())->method('isMfaRequired');

        // auth2FA mudando de false para true (ativação)
        $userData = $this->makeUserData('socio-01', true, false);
        $options = SaveOptions::create();

        $hook = new PreventMfaDisableForRequiredUsers($em, $resolver, new \tests\unit\Espo\Modules\TogareRbac\Stubs\AuditLogContractStub());

        $hook->beforeSave($userData, $options);
        $this->assertTrue(true);
    }

    public function testCampoAuth2FaNaoAlteradoSkip(): void
    {
        $user = $this->makeUser('socio-01');
        $em = $this->makeEm($user);

        $resolver = $this->createMock(MfaPolicyResolver::class);
        $resolver->expects($this->never())->method('isMfaRequired');

        // auth2FA não foi alterado (sem setFetched, mas set igual ao valor atual)
        $userData = new UserData();
        $userData->setId('ud-socio-01');
        $userData->set(['userId' => 'socio-01', 'auth2FA' => true]);
        // Não chamamos setFetched → isAttributeChanged('auth2FA') = true
        // mas como não há fetched value, simula "novo" registro (skip pelo isNew check via UserData)
        // Para este teste, queremos que o hook não chame resolver quando auth2FA não mudou:
        // setFetched com o mesmo valor (true) → isAttributeChanged retorna false
        $userData->setFetched('auth2FA', true);

        $options = SaveOptions::create();
        $hook = new PreventMfaDisableForRequiredUsers($em, $resolver, new \tests\unit\Espo\Modules\TogareRbac\Stubs\AuditLogContractStub());

        $hook->beforeSave($userData, $options);
        $this->assertTrue(true);
    }
}
