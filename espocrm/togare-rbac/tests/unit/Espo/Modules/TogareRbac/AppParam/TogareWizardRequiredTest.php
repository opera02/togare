<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareRbac\AppParam;

use Espo\Entities\User;
use Espo\Entities\UserData;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareRbac\AppParam\TogareWizardRequired;
use Espo\Modules\TogareRbac\Service\Mfa\MfaPolicyResolver;
use Espo\ORM\EntityManager;
use Espo\Repositories\UserData as UserDataRepository;
use PHPUnit\Framework\TestCase;

/**
 * Cobre Story 2.6 ACs:
 *  - AC1 — true para Sócio/Admin com MFA configurado e wizard pendente.
 *  - AC2 — false para não-Sócio/Admin.
 *  - AC2 (precedência MFA) — false se UserData.auth2FA != true.
 *  - AC7 — false após wizard completed.
 */
final class TogareWizardRequiredTest extends TestCase
{
    protected function setUp(): void
    {
        $stdout = \fopen('php://memory', 'w+');
        $stderr = \fopen('php://memory', 'w+');
        TogareLogger::init('test-rbac-wizard-appparam', null, $stdout, $stderr);
    }

    public function testRetornaFalseSeWizardJaCompletado(): void
    {
        $user = new User();
        $user->setId('user-socio-01');
        $user->set('togareWizardCompleted', true);

        $resolver = $this->createMock(MfaPolicyResolver::class);
        $resolver->method('isMfaRequired')->willReturn(true);

        $em = $this->createStub(EntityManager::class);

        $appParam = new TogareWizardRequired($user, $em, $resolver);

        $this->assertFalse($appParam->get());
    }

    public function testRetornaFalseSeMfaAindaNaoConfigurado(): void
    {
        $user = new User();
        $user->setId('user-socio-01');
        $user->set('togareWizardCompleted', false);

        $resolver = $this->createMock(MfaPolicyResolver::class);
        $resolver->method('isMfaRequired')->willReturn(true);

        $userData = new UserData();
        $userData->set('auth2FA', false);

        $repo = $this->createMock(UserDataRepository::class);
        $repo->method('getByUserId')->with('user-socio-01')->willReturn($userData);

        $em = $this->createMock(EntityManager::class);
        $em->method('getRepository')->with('UserData')->willReturn($repo);

        $appParam = new TogareWizardRequired($user, $em, $resolver);

        $this->assertFalse($appParam->get());
    }

    public function testRetornaTrueSeSocioAdminMfaConfiguradoEWizardPendente(): void
    {
        $user = new User();
        $user->setId('user-socio-01');
        $user->set('togareWizardCompleted', false);

        $resolver = $this->createMock(MfaPolicyResolver::class);
        $resolver->method('isMfaRequired')->willReturn(true);

        $userData = new UserData();
        $userData->set('auth2FA', true);

        $repo = $this->createMock(UserDataRepository::class);
        $repo->method('getByUserId')->with('user-socio-01')->willReturn($userData);

        $em = $this->createMock(EntityManager::class);
        $em->method('getRepository')->with('UserData')->willReturn($repo);

        $appParam = new TogareWizardRequired($user, $em, $resolver);

        $this->assertTrue($appParam->get());
    }

    public function testRetornaFalseSeRoleNaoEhSocioAdmin(): void
    {
        $user = new User();
        $user->setId('user-adv-01');
        $user->set('togareWizardCompleted', false);

        $resolver = $this->createMock(MfaPolicyResolver::class);
        $resolver->method('isMfaRequired')->willReturn(false);

        $em = $this->createStub(EntityManager::class);

        $appParam = new TogareWizardRequired($user, $em, $resolver);

        $this->assertFalse($appParam->get());
    }

    public function testRetornaFalseSeUserDataInexistente(): void
    {
        $user = new User();
        $user->setId('user-socio-01');
        $user->set('togareWizardCompleted', false);

        $resolver = $this->createMock(MfaPolicyResolver::class);
        $resolver->method('isMfaRequired')->willReturn(true);

        $repo = $this->createMock(UserDataRepository::class);
        $repo->method('getByUserId')->with('user-socio-01')->willReturn(null);

        $em = $this->createMock(EntityManager::class);
        $em->method('getRepository')->with('UserData')->willReturn($repo);

        $appParam = new TogareWizardRequired($user, $em, $resolver);

        $this->assertFalse($appParam->get());
    }
}
