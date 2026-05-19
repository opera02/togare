<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\Role;

use Espo\Core\Hook\Hook\AfterRemove;
use Espo\Entities\Role;
use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\RemoveOptions;

/**
 * Audit afterRemove em `Role`: `role.deleted` (FR37).
 *
 * @implements AfterRemove<Role>
 */
final class RoleCrudAuditDelete implements AfterRemove
{
    public static int $order = 50;

    public function __construct(
        private readonly AuditLogContract $auditLog,
    ) {
    }

    public function afterRemove(Entity $entity, RemoveOptions $options): void
    {
        if (! $entity instanceof Role) {
            return;
        }

        // getFetched preserva o valor pré-delete; get() pode vir mangled após
        // soft-delete ou null em hard-delete parcial.
        $name = $entity->getFetched('name') ?? $entity->get('name');

        $this->auditLog->log(
            'role.deleted',
            'Role',
            (string) $entity->getId(),
            ['name' => $name],
        );
    }
}
