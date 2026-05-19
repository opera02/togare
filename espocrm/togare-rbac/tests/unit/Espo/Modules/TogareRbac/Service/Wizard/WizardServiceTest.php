<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareRbac\Service\Wizard;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Entities\Role;
use Espo\Entities\User;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareRbac\Service\Invitation\InvitationService;
use Espo\Modules\TogareRbac\Service\Mfa\MfaPolicyResolver;
use Espo\Modules\TogareRbac\Service\Wizard\WizardService;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use tests\unit\Espo\Modules\TogareRbac\Stubs\AuditLogContractStub;

/**
 * Cobre Story 2.6 ACs do WizardService:
 *  - AC3 applyOrgInfo persiste companyName + companyLogoId, valida vazio.
 *  - AC4 applyPrimaryColor valida regex hex.
 *  - AC5 confirmRoles registra rename + REJEITA "Sócio/Admin" (gotcha #6).
 *  - AC6 inviteBatch acumula sucesso/falha.
 *  - AC7/AC8/AC9 markCompleted seta flags + audit completed/skipped.
 */
final class WizardServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $stdout = \fopen('php://memory', 'w+');
        $stderr = \fopen('php://memory', 'w+');
        TogareLogger::init('test-rbac-wizard', null, $stdout, $stderr);
    }

    public function testApplyOrgInfoPersisteCompanyNameELogoNaConfig(): void
    {
        $config = new Config();
        $writer = new ConfigWriter();
        $audit = new AuditLogContractStub();
        // P32: stub com get() real (não \stdClass) para passar na validação P5 de MIME/size.
        $attachmentStub = new class {
            public function get(string $name): mixed
            {
                return match ($name) {
                    'type' => 'image/png',
                    'size' => 10000,
                    default => null,
                };
            }
        };

        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->with('Attachment', 'attach-001')->willReturn($attachmentStub);

        $service = new WizardService(
            $em,
            $config,
            $writer,
            $audit,
            $this->createStub(MfaPolicyResolver::class),
            $this->createStub(InvitationService::class),
        );

        $user = new User();
        $user->setId('user-socio-01');

        $service->applyOrgInfo($user, 'Escritório Smoke Ltda', 'attach-001');

        $this->assertSame('Escritório Smoke Ltda', $writer->applied['companyName'] ?? null);
        $this->assertSame('attach-001', $writer->applied['companyLogoId'] ?? null);
        $this->assertTrue($writer->saved);

        $stepEvents = \array_values(\array_filter(
            $audit->calls,
            static fn (array $c): bool => $c['event'] === 'wizard.step_completed',
        ));
        $this->assertCount(1, $stepEvents);
        $this->assertSame(1, $stepEvents[0]['context']['step'] ?? null);
    }

    public function testApplyOrgInfoLancaBadRequestQuandoCompanyNameVazio(): void
    {
        $service = new WizardService(
            $this->createStub(EntityManager::class),
            new Config(),
            new ConfigWriter(),
            new AuditLogContractStub(),
            $this->createStub(MfaPolicyResolver::class),
            $this->createStub(InvitationService::class),
        );

        $user = new User();
        $user->setId('user-x');

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('Informe o nome do escritório.');

        $service->applyOrgInfo($user, '   ', null);
    }

    public function testApplyPrimaryColorValidaRegexHexAceitando6Chars(): void
    {
        $writer = new ConfigWriter();
        $audit = new AuditLogContractStub();

        $service = new WizardService(
            $this->createStub(EntityManager::class),
            new Config(),
            $writer,
            $audit,
            $this->createStub(MfaPolicyResolver::class),
            $this->createStub(InvitationService::class),
        );

        $user = new User();
        $user->setId('user-x');

        $service->applyPrimaryColor($user, '#0a4D8c');

        // P21: WizardService normaliza para minúsculas antes de persistir.
        $this->assertSame('#0a4d8c', $writer->applied['togarePrimaryColor'] ?? null);
        $this->assertTrue($writer->saved);

        $stepEvents = \array_values(\array_filter(
            $audit->calls,
            static fn (array $c): bool => $c['event'] === 'wizard.step_completed',
        ));
        $this->assertCount(1, $stepEvents);
        $this->assertSame(2, $stepEvents[0]['context']['step'] ?? null);
    }

    #[DataProvider('invalidHexColors')]
    public function testApplyPrimaryColorRejeita3CharsOuMaiusculasSemHash(string $invalid): void
    {
        $service = new WizardService(
            $this->createStub(EntityManager::class),
            new Config(),
            new ConfigWriter(),
            new AuditLogContractStub(),
            $this->createStub(MfaPolicyResolver::class),
            $this->createStub(InvitationService::class),
        );

        $user = new User();
        $user->setId('user-x');

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('Cor inválida. Use formato #RRGGBB.');

        $service->applyPrimaryColor($user, $invalid);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidHexColors(): array
    {
        return [
            'sem hash' => ['0a4d8c'],
            'somente 3 chars' => ['#fff'],
            'chars não-hex' => ['#FFGGHH'],
            'xss attempt' => ['<script>'],
            'vazio' => [''],
            'maiúsculo G inválido' => ['#GGAA00'],
        ];
    }

    public function testConfirmRolesRegistraRenameNoAuditLog(): void
    {
        $audit = new AuditLogContractStub();
        $existingRole = new Role();
        $existingRole->setId('role-marketing-01');
        $existingRole->set('name', 'Marketing');

        $repoMock = new class ($existingRole) {
            private string $stage = 'find';
            public function __construct(private Role $existing) {}
            public function where(array $w): static
            {
                if (isset($w['name'])) {
                    $this->stage = $w['name'] === 'Marketing' ? 'find' : 'collision';
                }
                return $this;
            }
            public function findOne(): ?Role
            {
                return $this->stage === 'find' ? $this->existing : null;
            }
            public function count(): int
            {
                return 0; // sem colisão
            }
        };

        $em = $this->createMock(EntityManager::class);
        $em->method('getRDBRepository')->with('Role')->willReturn($repoMock);
        $em->expects($this->once())->method('saveEntity');

        $service = new WizardService(
            $em,
            new Config(),
            new ConfigWriter(),
            $audit,
            $this->createStub(MfaPolicyResolver::class),
            $this->createStub(InvitationService::class),
        );

        $user = new User();
        $user->setId('user-socio-01');

        $service->confirmRoles($user, ['Marketing' => 'Comercial']);

        $renameEvents = \array_values(\array_filter(
            $audit->calls,
            static fn (array $c): bool => $c['event'] === 'wizard.role_renamed',
        ));
        $this->assertCount(1, $renameEvents);
        $this->assertSame('Marketing', $renameEvents[0]['context']['oldName'] ?? null);
        $this->assertSame('Comercial', $renameEvents[0]['context']['newName'] ?? null);

        $this->assertSame('Comercial', $existingRole->get('name'));
    }

    public function testConfirmRolesRejeitaRenameDoRoleSocioAdmin(): void
    {
        $audit = new AuditLogContractStub();

        $service = new WizardService(
            $this->createStub(EntityManager::class),
            new Config(),
            new ConfigWriter(),
            $audit,
            $this->createStub(MfaPolicyResolver::class),
            $this->createStub(InvitationService::class),
        );

        $user = new User();
        $user->setId('user-socio-01');

        try {
            $service->confirmRoles($user, ['Sócio/Admin' => 'Partner Admin']);
            $this->fail('Expected BadRequest for Sócio/Admin rename.');
        } catch (BadRequest $e) {
            $this->assertStringContainsString('Sócio/Admin', $e->getMessage());
            $this->assertStringContainsString('reservado', $e->getMessage());
        }

        $renameEvents = \array_filter(
            $audit->calls,
            static fn (array $c): bool => $c['event'] === 'wizard.role_renamed',
        );
        $this->assertCount(0, $renameEvents, 'Nenhum rename deve ter sido registrado.');
    }

    public function testInviteBatchAcumulaSucessoEFalhaPorUsuario(): void
    {
        $audit = new AuditLogContractStub();

        $callCount = 0;
        $invitationService = $this->createMock(InvitationService::class);
        $invitationService->method('invite')->willReturnCallback(
            function (string $userName) use (&$callCount): User {
                $callCount++;
                if ($userName === 'duplicado') {
                    throw BadRequest::createWithBody('Email já cadastrado.', '{}');
                }
                $u = new User();
                $u->setId('user-' . $userName);

                return $u;
            },
        );

        $service = new WizardService(
            $this->createStub(EntityManager::class),
            new Config(),
            new ConfigWriter(),
            $audit,
            $this->createStub(MfaPolicyResolver::class),
            $invitationService,
        );

        $user = new User();
        $user->setId('user-socio-01');

        $invitees = [
            ['userName' => 'ana', 'emailAddress' => 'ana@test.com',
             'firstName' => 'Ana', 'lastName' => 'Souza', 'roleIds' => ['role-adv-01']],
            ['userName' => 'duplicado', 'emailAddress' => 'dup@test.com',
             'firstName' => 'D', 'lastName' => 'D', 'roleIds' => ['role-adv-01']],
            ['userName' => 'bia', 'emailAddress' => 'bia@test.com',
             'firstName' => 'Bia', 'lastName' => 'Lima', 'roleIds' => ['role-sec-01']],
        ];

        $result = $service->inviteBatch($user, $invitees);

        $this->assertCount(2, $result['succeeded']);
        $this->assertCount(1, $result['failed']);
        $this->assertSame('duplicado', $result['failed'][0]['userName']);
        $this->assertStringContainsString('Email já cadastrado', $result['failed'][0]['reason']);
        $this->assertSame(3, $callCount);

        $stepEvents = \array_values(\array_filter(
            $audit->calls,
            static fn (array $c): bool => $c['event'] === 'wizard.step_completed',
        ));
        $this->assertCount(1, $stepEvents);
        $this->assertSame(4, $stepEvents[0]['context']['step'] ?? null);
        $this->assertSame(2, $stepEvents[0]['context']['invitedCount'] ?? null);
        $this->assertSame(1, $stepEvents[0]['context']['failedCount'] ?? null);
    }

    public function testInviteBatchRejeitaLoteAcimaDoLimite(): void
    {
        $service = new WizardService(
            $this->createStub(EntityManager::class),
            new Config(),
            new ConfigWriter(),
            new AuditLogContractStub(),
            $this->createStub(MfaPolicyResolver::class),
            $this->createStub(InvitationService::class),
        );

        $user = new User();
        $user->setId('user-x');

        $invitees = [];
        for ($i = 0; $i < WizardService::MAX_INVITEES_PER_BATCH + 1; $i++) {
            $invitees[] = [
                'userName' => "u{$i}",
                'emailAddress' => "u{$i}@test.com",
                'firstName' => "U",
                'lastName' => "{$i}",
                'roleIds' => ['role-adv-01'],
            ];
        }

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('Máximo de 20 convites por lote.');

        $service->inviteBatch($user, $invitees);
    }

    public function testInviteBatchRejeitaEmailInvalidoFailFast(): void
    {
        $callCount = 0;
        $invitationService = $this->createMock(InvitationService::class);
        $invitationService->method('invite')->willReturnCallback(
            function () use (&$callCount): User {
                $callCount++;
                $usr = new User();
                $usr->setId('x');

                return $usr;
            },
        );

        $service = new WizardService(
            $this->createStub(EntityManager::class),
            new Config(),
            new ConfigWriter(),
            new AuditLogContractStub(),
            $this->createStub(MfaPolicyResolver::class),
            $invitationService,
        );

        $user = new User();
        $user->setId('user-x');

        $invitees = [
            ['userName' => 'ok', 'emailAddress' => 'ok@test.com',
             'firstName' => 'O', 'lastName' => 'K', 'roleIds' => ['r1']],
            ['userName' => 'mal', 'emailAddress' => 'not-an-email',
             'firstName' => 'M', 'lastName' => 'A', 'roleIds' => ['r1']],
        ];

        $caught = null;
        try {
            $service->inviteBatch($user, $invitees);
        } catch (BadRequest $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'Esperado BadRequest.');
        $this->assertStringContainsString('Linha 2: e-mail inválido.', $caught->getMessage());
        $this->assertSame(0, $callCount, 'Nenhum invite deve ter sido enviado em fail-fast.');
    }

    public function testMarkCompletedSetaFlagsEAuditLogCompleted(): void
    {
        $audit = new AuditLogContractStub();

        $em = $this->createMock(EntityManager::class);
        $em->expects($this->once())->method('saveEntity');

        $service = new WizardService(
            $em,
            new Config(),
            new ConfigWriter(),
            $audit,
            $this->createStub(MfaPolicyResolver::class),
            $this->createStub(InvitationService::class),
        );

        $user = new User();
        $user->setId('user-socio-01');

        $service->markCompleted($user, false);

        $this->assertTrue((bool) $user->get('togareWizardCompleted'));
        $this->assertNotNull($user->get('togareWizardCompletedAt'));

        $events = \array_values(\array_filter(
            $audit->calls,
            static fn (array $c): bool => $c['event'] === 'wizard.completed',
        ));
        $this->assertCount(1, $events);
        $this->assertSame('User', $events[0]['entityType']);
        $this->assertSame('user-socio-01', $events[0]['entityId']);
        $this->assertSame(false, $events[0]['context']['skipped'] ?? null);
    }

    public function testMarkCompletedSkippedRegistraEventoSkipped(): void
    {
        $audit = new AuditLogContractStub();

        $em = $this->createMock(EntityManager::class);
        $em->method('saveEntity');

        $service = new WizardService(
            $em,
            new Config(),
            new ConfigWriter(),
            $audit,
            $this->createStub(MfaPolicyResolver::class),
            $this->createStub(InvitationService::class),
        );

        $user = new User();
        $user->setId('user-socio-01');

        $service->markCompleted($user, true);

        $events = \array_values(\array_filter(
            $audit->calls,
            static fn (array $c): bool => $c['event'] === 'wizard.skipped',
        ));
        $this->assertCount(1, $events);
        $this->assertSame(true, $events[0]['context']['skipped'] ?? null);

        $completedEvents = \array_filter(
            $audit->calls,
            static fn (array $c): bool => $c['event'] === 'wizard.completed',
        );
        $this->assertCount(0, $completedEvents, 'Skipped não deve gerar wizard.completed.');
    }
}
