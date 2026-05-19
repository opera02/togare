<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\Processo;

use Espo\Core\ApplicationState;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\TogareCore\Entities\Processo;
use Espo\Modules\TogareCore\Hooks\Processo\EnforceAssignmentPolicyHook;
use Espo\Modules\TogareCore\Services\PrivilegedActorChecker;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

/**
 * Story 3.5 — cobre AC6, AC7 e AC8 (ACL by-assignment FR11).
 *
 * Não exercita a ACL nativa do EspoCRM (read=own + collaborators) — esta
 * camada é testada em smoke real F1 do Felipe (browser/API). O hook só
 * complementa cobrindo o caminho do colaborador tentando mudar atribuição.
 */
final class EnforceAssignmentPolicyHookTest extends TestCase
{
    public function testIsNewAdvogadoCriadorVirarTitular(): void
    {
        // AC7 — Advogado (não-privileged) que cria sem assignedUser vira titular auto.
        $appState = new ApplicationState();
        $advogado = $this->makeUser('user-adv-001', isAdmin: false);
        $appState->setUser($advogado);

        $checker = $this->createMock(PrivilegedActorChecker::class);
        $checker->method('isPrivileged')->willReturn(false);

        $hook = new EnforceAssignmentPolicyHook($appState, $checker);

        $proc = new Processo();
        $proc->set([
            'numeroCnj' => '00000007520238260100',
            'classeCodigo' => 436,
            'assuntoCodigo' => 10001,
            // sem assignedUserId
        ]);
        // isNew() = true (sem setId)

        $hook->beforeSave($proc, SaveOptions::create());

        self::assertSame('user-adv-001', $proc->get('assignedUserId'));
    }

    public function testIsNewAdvogadoComAssignedUserExplicitoNaoSobrescreve(): void
    {
        $appState = new ApplicationState();
        $appState->setUser($this->makeUser('user-adv-001', isAdmin: false));

        $checker = $this->createMock(PrivilegedActorChecker::class);
        $checker->method('isPrivileged')->willReturn(false);

        $hook = new EnforceAssignmentPolicyHook($appState, $checker);

        $proc = new Processo();
        $proc->set([
            'numeroCnj' => '00000007520238260100',
            'assignedUserId' => 'user-adv-002',
        ]);

        $hook->beforeSave($proc, SaveOptions::create());

        self::assertSame('user-adv-002', $proc->get('assignedUserId'));
    }

    public function testIsNewSocioAdminPodeCriarSemAssignedUser(): void
    {
        // AC7 — Sócio/Admin sem assignedUser deixa null (precisa atribuir explicitamente).
        $appState = new ApplicationState();
        $appState->setUser($this->makeUser('user-sa-001', isAdmin: true));

        $checker = $this->createMock(PrivilegedActorChecker::class);
        $checker->method('isPrivileged')->willReturn(true);

        $hook = new EnforceAssignmentPolicyHook($appState, $checker);

        $proc = new Processo();
        $proc->set([
            'numeroCnj' => '00000007520238260100',
        ]);

        $hook->beforeSave($proc, SaveOptions::create());

        self::assertNull($proc->get('assignedUserId'));
    }

    public function testUpdateSocioAdminPodeMudarAssignedUser(): void
    {
        // AC6.1 — Sócio/Admin pode mudar assignedUser de qualquer processo.
        $appState = new ApplicationState();
        $appState->setUser($this->makeUser('user-sa-001', isAdmin: true));

        $checker = $this->createMock(PrivilegedActorChecker::class);
        $checker->method('isPrivileged')->willReturn(true);

        $hook = new EnforceAssignmentPolicyHook($appState, $checker);

        $proc = $this->makeFetchedProcesso('proc-001', titularId: 'user-adv-002');
        $proc->set('assignedUserId', 'user-adv-003');

        $hook->beforeSave($proc, SaveOptions::create());

        self::assertSame('user-adv-003', $proc->get('assignedUserId'));
    }

    public function testUpdateTitularPodeMudarAssignedUser(): void
    {
        // AC6.2 — Advogado titular pode mudar assignedUser do próprio processo.
        $appState = new ApplicationState();
        $appState->setUser($this->makeUser('user-adv-001', isAdmin: false));

        $checker = $this->createMock(PrivilegedActorChecker::class);
        $checker->method('isPrivileged')->willReturn(false);

        $hook = new EnforceAssignmentPolicyHook($appState, $checker);

        // Titular pré-existente é o user corrente.
        $proc = $this->makeFetchedProcesso('proc-001', titularId: 'user-adv-001');
        $proc->set('assignedUserId', 'user-adv-002');

        $hook->beforeSave($proc, SaveOptions::create());

        self::assertSame('user-adv-002', $proc->get('assignedUserId'));
    }

    public function testUpdateColaboradorNaoPodeMudarAssignedUserForbidden(): void
    {
        // AC6.3 / AC8 — Advogado colaborador (não titular) tentando mudar assignedUser → 403.
        $appState = new ApplicationState();
        $appState->setUser($this->makeUser('user-adv-colab', isAdmin: false));

        $checker = $this->createMock(PrivilegedActorChecker::class);
        $checker->method('isPrivileged')->willReturn(false);

        $hook = new EnforceAssignmentPolicyHook($appState, $checker);

        // Titular original é OUTRO advogado.
        $proc = $this->makeFetchedProcesso('proc-001', titularId: 'user-adv-titular');
        $proc->set('assignedUserId', 'user-adv-colab');

        $this->expectException(Forbidden::class);
        $hook->beforeSave($proc, SaveOptions::create());
    }

    public function testUpdateColaboradorNaoPodeMudarCollaboratorsIdsForbidden(): void
    {
        // AC6.3 — colaborador tentando adicionar/remover outro colaborador → 403.
        $appState = new ApplicationState();
        $appState->setUser($this->makeUser('user-adv-colab', isAdmin: false));

        $checker = $this->createMock(PrivilegedActorChecker::class);
        $checker->method('isPrivileged')->willReturn(false);

        $hook = new EnforceAssignmentPolicyHook($appState, $checker);

        $proc = $this->makeFetchedProcesso('proc-001', titularId: 'user-adv-titular');
        $proc->setFetched('collaboratorsIds', ['user-adv-colab']);
        $proc->set('collaboratorsIds', ['user-adv-colab', 'user-adv-x']);

        $this->expectException(Forbidden::class);
        $hook->beforeSave($proc, SaveOptions::create());
    }

    public function testUpdateForbiddenCarregaXStatusReason(): void
    {
        // AC6 — corpo da exceção tem reason canônico 'assignment-not-allowed'.
        $appState = new ApplicationState();
        $appState->setUser($this->makeUser('user-x', isAdmin: false));

        $checker = $this->createMock(PrivilegedActorChecker::class);
        $checker->method('isPrivileged')->willReturn(false);

        $hook = new EnforceAssignmentPolicyHook($appState, $checker);

        $proc = $this->makeFetchedProcesso('proc-001', titularId: 'user-y');
        $proc->set('assignedUserId', 'user-z');

        try {
            $hook->beforeSave($proc, SaveOptions::create());
            self::fail('Esperava Forbidden');
        } catch (Forbidden $e) {
            self::assertSame(
                EnforceAssignmentPolicyHook::REASON_ASSIGNMENT_NOT_ALLOWED,
                $e->getMessage(),
                'Reason canônico para X-Status-Reason header',
            );

            $body = $e->getBody();
            self::assertIsString($body);
            $decoded = \json_decode($body, true);
            self::assertIsArray($decoded);
            self::assertSame('assignment-not-allowed', $decoded['reason']);
            self::assertSame('Processo', $decoded['messageTranslation']['scope']);
            self::assertSame('assignmentNotAllowed', $decoded['messageTranslation']['label']);
        }
    }

    public function testUpdateSemMudancaDeAssignmentNaoBloqueia(): void
    {
        // Edits que não tocam assignedUserId/collaboratorsIds passam livre,
        // mesmo de não-titular não-privileged (FR11 não impede edição de campos
        // não-sensíveis — outras stories cobrem isso via field-level ACL).
        $appState = new ApplicationState();
        $appState->setUser($this->makeUser('user-adv-colab', isAdmin: false));

        $checker = $this->createMock(PrivilegedActorChecker::class);
        $checker->method('isPrivileged')->willReturn(false);

        $hook = new EnforceAssignmentPolicyHook($appState, $checker);

        $proc = $this->makeFetchedProcesso('proc-001', titularId: 'user-other');
        // Sincroniza assignedUserId / collaboratorsIds com fetched (sem mudança)
        // — assim isAttributeChanged retorna false e o hook não dispara.
        $proc->set('assignedUserId', 'user-other');
        $proc->setFetched('collaboratorsIds', ['user-adv-colab']);
        $proc->set('collaboratorsIds', ['user-adv-colab']);
        $proc->setFetched('observacoes', null);
        $proc->set('observacoes', 'Anotação nova do colaborador');

        $hook->beforeSave($proc, SaveOptions::create());

        self::assertSame('Anotação nova do colaborador', $proc->get('observacoes'));
    }

    public function testNaoEhProcessoIgnora(): void
    {
        $appState = new ApplicationState();
        $appState->setUser($this->makeUser('user-x', isAdmin: false));

        $checker = $this->createMock(PrivilegedActorChecker::class);
        $checker->expects(self::never())->method('isPrivileged');

        $hook = new EnforceAssignmentPolicyHook($appState, $checker);

        $other = new \Espo\Core\ORM\Entity();
        // assignedUserId não é tocado por entity não-Processo — verifica via
        // estado da entidade (não há propagação) + mock expects(never) acima.
        $hook->beforeSave($other, SaveOptions::create());

        self::assertNull($other->get('assignedUserId'));
    }

    public function testSemUsuarioCorrenteNaoBloqueia(): void
    {
        // Operações sistêmicas (CLI install, scheduledJob): hook NÃO bloqueia.
        $appState = new ApplicationState();
        // appState->setUser() não chamado → hasUser() = false

        $checker = $this->createMock(PrivilegedActorChecker::class);
        $checker->expects(self::never())->method('isPrivileged');

        $hook = new EnforceAssignmentPolicyHook($appState, $checker);

        $proc = $this->makeFetchedProcesso('proc-001', titularId: 'user-y');
        $proc->set('assignedUserId', 'user-z');

        $hook->beforeSave($proc, SaveOptions::create());

        self::assertSame('user-z', $proc->get('assignedUserId'));
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

    private function makeFetchedProcesso(string $id, ?string $titularId): Processo
    {
        $proc = new Processo();
        $proc->setId($id);
        $proc->setFetched('assignedUserId', $titularId);

        return $proc;
    }
}
