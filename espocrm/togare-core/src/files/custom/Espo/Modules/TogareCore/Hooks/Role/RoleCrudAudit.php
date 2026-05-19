<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\Role;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Entities\Role;
use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Audit afterSave em `Role`: `role.created` ou `role.updated` (FR37 — RBAC
 * sensível). Updates só geram audit se mudou atributo da allowlist.
 *
 * @implements AfterSave<Role>
 */
final class RoleCrudAudit implements AfterSave
{
    public static int $order = 50;

    /** @var list<string> Atributos cujo update merece audit. */
    private const SENSITIVE_FIELDS = [
        'name',
        'data',
        'fieldData',
        'assignmentPermission',
        'userPermission',
        'portalPermission',
    ];

    public function __construct(
        private readonly AuditLogContract $auditLog,
    ) {
    }

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (! $entity instanceof Role) {
            return;
        }

        $roleId = (string) $entity->getId();

        if ($entity->isNew()) {
            $this->auditLog->log(
                'role.created',
                'Role',
                $roleId,
                ['name' => $entity->get('name')],
            );
            return;
        }

        $changed = [];
        foreach (self::SENSITIVE_FIELDS as $field) {
            if ($entity->isAttributeChanged($field)) {
                $changed[] = $field;
            }
        }

        if ($changed === []) {
            return;
        }

        $this->auditLog->log(
            'role.updated',
            'Role',
            $roleId,
            [
                'name' => $entity->get('name'),
                'changedFields' => $changed,
            ],
        );
    }
}
