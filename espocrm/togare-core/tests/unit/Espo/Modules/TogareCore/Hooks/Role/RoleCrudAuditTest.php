<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\Role;

use Espo\Entities\Role;
use Espo\Modules\TogareCore\Hooks\Role\RoleCrudAudit;
use Espo\Modules\TogareCore\Hooks\Role\RoleCrudAuditDelete;
use Espo\ORM\Repository\Option\RemoveOptions;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;
use tests\unit\Espo\Modules\TogareCore\Stubs\AuditLogContractStub;

/**
 * Cobre AC8 — RoleCrudAudit (role.created / role.updated com changedFields
 * apenas sensíveis) + RoleCrudAuditDelete (afterRemove → role.deleted).
 */
final class RoleCrudAuditTest extends TestCase
{
    public function testCreatedDispatchaRoleCreated(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new RoleCrudAudit($stub);

        $role = new Role();
        $role->set('name', 'Sócio');
        // sem setId() → isNew()=true

        $hook->afterSave($role, SaveOptions::create());

        self::assertCount(1, $stub->calls);
        self::assertSame('role.created', $stub->calls[0]['event']);
        self::assertSame('Role', $stub->calls[0]['entityType']);
        self::assertSame('Sócio', $stub->calls[0]['context']['name']);
    }

    public function testUpdatedSomenteSeMudouCampoSensivel(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new RoleCrudAudit($stub);
        $opts = SaveOptions::create();

        // Campo fora da allowlist → skip.
        $r1 = new Role();
        $r1->setId('role-01');
        $r1->setFetched('description', 'old');
        $r1->set(['name' => 'Advogado']);
        $r1->setFetched('name', 'Advogado');
        $r1->set(['description' => 'new']);
        $hook->afterSave($r1, $opts);

        self::assertCount(0, $stub->calls);

        // Campo dentro da allowlist → emite role.updated.
        $r2 = new Role();
        $r2->setId('role-02');
        $r2->setFetched('name', 'Estagiário');
        $r2->set(['name' => 'Advogado Júnior']);
        $hook->afterSave($r2, $opts);

        self::assertCount(1, $stub->calls);
        self::assertSame('role.updated', $stub->calls[0]['event']);
        self::assertSame('Role', $stub->calls[0]['entityType']);
        self::assertSame('role-02', $stub->calls[0]['entityId']);
        self::assertContains('name', $stub->calls[0]['context']['changedFields']);
    }

    public function testDeleteDispatchaRoleDeleted(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new RoleCrudAuditDelete($stub);

        $role = new Role();
        $role->setId('role-99');
        $role->setFetched('name', 'Sócio Sênior'); // getFetched preserva pré-delete
        $role->set('name', null);                   // get() pode estar nulo após delete

        $hook->afterRemove($role, RemoveOptions::create());

        self::assertCount(1, $stub->calls);
        self::assertSame('role.deleted', $stub->calls[0]['event']);
        self::assertSame('Role', $stub->calls[0]['entityType']);
        self::assertSame('role-99', $stub->calls[0]['entityId']);
        self::assertSame('Sócio Sênior', $stub->calls[0]['context']['name']);
    }
}
