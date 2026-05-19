<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\Audiencia;

use Espo\Core\ApplicationState;
use Espo\Entities\User;
use Espo\Modules\TogareCore\Entities\Audiencia;
use Espo\Modules\TogareCore\Hooks\Audiencia\EnforceAudienciaAssignmentHook;
use Espo\Modules\TogareCore\Services\PrivilegedActorChecker;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

/**
 * Story 3.6-magro — cobre auto-titular em create (versão LIGHT da Story 3.5).
 *
 * Diferente do `EnforceAssignmentPolicyHookTest` (Processo), aqui não há
 * teste de bloqueio em update — versão MAGRO permite admins delegando
 * audiência específica para outro advogado.
 */
final class EnforceAudienciaAssignmentHookTest extends TestCase
{
    public function testIsNewAdvogadoCriadorVirarTitular(): void
    {
        // AC4 — Advogado (não-privileged) sem assignedUser vira responsável auto.
        $appState = new ApplicationState();
        $appState->setUser($this->makeUser('user-adv-001', isAdmin: false));

        $checker = $this->createMock(PrivilegedActorChecker::class);
        $checker->method('isPrivileged')->willReturn(false);

        $hook = new EnforceAudienciaAssignmentHook($appState, $checker);

        $aud = $this->makeNewAudiencia();
        $hook->beforeSave($aud, SaveOptions::create());

        self::assertSame('user-adv-001', $aud->get('assignedUserId'));
    }

    public function testIsNewAdvogadoComAssignedUserExplicitoNaoSobrescreve(): void
    {
        $appState = new ApplicationState();
        $appState->setUser($this->makeUser('user-adv-001', isAdmin: false));

        $checker = $this->createMock(PrivilegedActorChecker::class);
        $checker->method('isPrivileged')->willReturn(false);

        $hook = new EnforceAudienciaAssignmentHook($appState, $checker);

        $aud = $this->makeNewAudiencia();
        $aud->set('assignedUserId', 'user-adv-002');

        $hook->beforeSave($aud, SaveOptions::create());

        self::assertSame('user-adv-002', $aud->get('assignedUserId'));
    }

    public function testIsNewSocioAdminPodeCriarSemAssignedUser(): void
    {
        // AC4 — Sócio/Admin sem assignedUser deixa null (precisa atribuir explicitamente).
        $appState = new ApplicationState();
        $appState->setUser($this->makeUser('user-sa-001', isAdmin: true));

        $checker = $this->createMock(PrivilegedActorChecker::class);
        $checker->method('isPrivileged')->willReturn(true);

        $hook = new EnforceAudienciaAssignmentHook($appState, $checker);

        $aud = $this->makeNewAudiencia();
        $hook->beforeSave($aud, SaveOptions::create());

        self::assertNull($aud->get('assignedUserId'));
    }

    public function testUpdateNaoBloqueiaMudancaDeAssignment(): void
    {
        // Decisão #5 da story — versão MAGRO permite admin delegar
        // audiência para outro advogado sem bloqueio. ACL by-assignment
        // pesada (Story 3.5 do Processo) NÃO se aplica em Audiencia.
        $appState = new ApplicationState();
        $appState->setUser($this->makeUser('user-adv-001', isAdmin: false));

        $checker = $this->createMock(PrivilegedActorChecker::class);
        // Em update, hook nem chama isPrivileged() — early return.
        $checker->expects(self::never())->method('isPrivileged');

        $hook = new EnforceAudienciaAssignmentHook($appState, $checker);

        $aud = new Audiencia();
        $aud->setId('aud-001');
        $aud->setFetched('assignedUserId', 'user-adv-other');
        $aud->set('assignedUserId', 'user-adv-001');

        $hook->beforeSave($aud, SaveOptions::create());

        self::assertSame('user-adv-001', $aud->get('assignedUserId'));
    }

    public function testSemUsuarioCorrenteNaoBloqueia(): void
    {
        // Operações sistêmicas (CLI install, scheduledJob): hook NÃO bloqueia.
        $appState = new ApplicationState();
        // appState->setUser() não chamado → hasUser() = false

        $checker = $this->createMock(PrivilegedActorChecker::class);
        $checker->expects(self::never())->method('isPrivileged');

        $hook = new EnforceAudienciaAssignmentHook($appState, $checker);

        $aud = $this->makeNewAudiencia();
        $hook->beforeSave($aud, SaveOptions::create());

        self::assertNull($aud->get('assignedUserId'));
    }

    public function testNaoEhAudienciaIgnora(): void
    {
        $appState = new ApplicationState();
        $appState->setUser($this->makeUser('user-x', isAdmin: false));

        $checker = $this->createMock(PrivilegedActorChecker::class);
        $checker->expects(self::never())->method('isPrivileged');

        $hook = new EnforceAudienciaAssignmentHook($appState, $checker);

        $other = new \Espo\Core\ORM\Entity();
        $hook->beforeSave($other, SaveOptions::create());

        self::assertNull($other->get('assignedUserId'));
    }

    private function makeUser(string $id, bool $isAdmin): User
    {
        $u = new User();
        $u->setId($id);
        $u->set([
            'userName' => $id,
            'type' => $isAdmin ? User::TYPE_ADMIN : User::TYPE_REGULAR,
        ]);

        return $u;
    }

    private function makeNewAudiencia(): Audiencia
    {
        $aud = new Audiencia();
        $aud->set([
            'dataHora' => '2026-05-15 14:00:00',
            'tipo' => Audiencia::TIPO_CONCILIACAO,
            'modalidade' => Audiencia::MODALIDADE_PRESENCIAL,
            'status' => Audiencia::STATUS_AGENDADA,
            'processoId' => 'proc-001',
        ]);
        // sem setId() → isNew()=true.

        return $aud;
    }
}
