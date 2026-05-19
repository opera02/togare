<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\User;

use Espo\Entities\User;
use Espo\Modules\TogareCore\Hooks\User\UserCrudAudit;
use Espo\Modules\TogareCore\Hooks\User\UserCrudAuditDelete;
use Espo\ORM\Repository\Option\RemoveOptions;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;
use tests\unit\Espo\Modules\TogareCore\Stubs\AuditLogContractStub;

/**
 * Cobre AC7 — UserCrudAudit (created / updated com changedFields apenas
 * sensíveis) + UserCrudAuditDelete (afterRemove → user.deleted).
 */
final class UserCrudAuditTest extends TestCase
{
    public function testCreatedDispatchaEventoComUserNameERoles(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new UserCrudAudit($stub);

        $user = new User();
        $user->set([
            'userName' => 'novo_advogado',
            'type' => 'regular',
            'isAdmin' => false,
            'rolesIds' => ['role-adv-01'],
        ]);
        // sem setId() → isNew()=true.

        $hook->afterSave($user, SaveOptions::create());

        self::assertCount(1, $stub->calls);
        self::assertSame('user.created', $stub->calls[0]['event']);
        self::assertSame('User', $stub->calls[0]['entityType']);
        self::assertSame('novo_advogado', $stub->calls[0]['context']['userName']);
        self::assertSame(['role-adv-01'], $stub->calls[0]['context']['rolesIds']);
    }

    public function testUpdatedSomenteSeMudouCampoSensitivel(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new UserCrudAudit($stub);
        $opts = SaveOptions::create();

        // Caso 1: mudou só `description` (FORA da allowlist) → skip.
        $u1 = new User();
        $u1->setId('user-01');
        $u1->setFetched('description', 'old desc');
        $u1->set(['userName' => 'manolo']);
        $u1->setFetched('userName', 'manolo'); // mesmo valor
        $u1->set(['description' => 'new desc']);
        $hook->afterSave($u1, $opts);

        self::assertCount(0, $stub->calls);

        // Caso 2: mudou `firstName` (DENTRO da allowlist) → emite user.updated.
        $u2 = new User();
        $u2->setId('user-02');
        $u2->setFetched('firstName', 'João');
        $u2->set(['userName' => 'jp', 'firstName' => 'João Pedro']);
        $u2->setFetched('userName', 'jp');
        $hook->afterSave($u2, $opts);

        self::assertCount(1, $stub->calls);
        self::assertSame('user.updated', $stub->calls[0]['event']);
        self::assertSame(['firstName'], $stub->calls[0]['context']['changedFields']);
        self::assertSame('João', $stub->calls[0]['context']['before']['firstName']);
        self::assertSame('João Pedro', $stub->calls[0]['context']['after']['firstName']);
    }

    public function testDeleteDispatchaUserDeleted(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new UserCrudAuditDelete($stub);

        $user = new User();
        $user->setId('user-99');
        $user->set([
            'userName' => 'deletado',
            'type' => 'regular',
            'rolesIds' => ['role-adv-01'],
        ]);

        $hook->afterRemove($user, RemoveOptions::create());

        self::assertCount(1, $stub->calls);
        self::assertSame('user.deleted', $stub->calls[0]['event']);
        self::assertSame('User', $stub->calls[0]['entityType']);
        self::assertSame('user-99', $stub->calls[0]['entityId']);
        self::assertSame('deletado', $stub->calls[0]['context']['userName']);
    }

    public function testDeletePrefereFetchedUserName(): void
    {
        // Após soft-delete o EspoCRM pode mangle userName (sufixo _deleted_<id>_<ts>).
        // O hook deve capturar o nome original via getFetched, não o mangled via get.
        $stub = new AuditLogContractStub();
        $hook = new UserCrudAuditDelete($stub);

        $user = new User();
        $user->setId('user-88');
        $user->setFetched('userName', 'nome_original');
        $user->set('userName', 'nome_original_deleted_user-88_123456');

        $hook->afterRemove($user, RemoveOptions::create());

        self::assertCount(1, $stub->calls);
        self::assertSame('nome_original', $stub->calls[0]['context']['userName']);
    }

    public function testUpdatedTeamsIdsEmiteComLinkName(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new UserCrudAudit($stub);

        $user = new User();
        $user->setId('user-77');
        $user->setFetched('userName', 'rafa');
        $user->set('userName', 'rafa');
        $user->setFetched('teamsIds', ['team-old']);
        $user->set(['teamsIds' => ['team-new']]);

        $hook->afterSave($user, SaveOptions::create());

        self::assertCount(1, $stub->calls);
        self::assertSame('user.updated', $stub->calls[0]['event']);
        self::assertContains('teamsIds', $stub->calls[0]['context']['changedFields']);
        self::assertSame(['team-old'], $stub->calls[0]['context']['before']['teamsIds']);
        self::assertSame(['team-new'], $stub->calls[0]['context']['after']['teamsIds']);
    }
}
