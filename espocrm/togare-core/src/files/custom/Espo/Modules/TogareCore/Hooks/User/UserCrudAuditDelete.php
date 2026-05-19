<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\User;

use Espo\Core\Hook\Hook\AfterRemove;
use Espo\Entities\User;
use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\RemoveOptions;

/**
 * Audit afterRemove em `User`: emite `user.deleted` (FR37).
 *
 * @implements AfterRemove<User>
 */
final class UserCrudAuditDelete implements AfterRemove
{
    public static int $order = 50;

    public function __construct(
        private readonly AuditLogContract $auditLog,
    ) {
    }

    public function afterRemove(Entity $entity, RemoveOptions $options): void
    {
        if (! $entity instanceof User) {
            return;
        }

        // getFetched preserva o valor pré-delete; get() pode vir mangled após
        // soft-delete (suffix _deleted_<id>_<ts>) ou null em hard-delete parcial.
        $userName = $entity->getFetched('userName') ?? $entity->get('userName');
        $type = $entity->getFetched('type') ?? $entity->get('type');

        $this->auditLog->log(
            'user.deleted',
            'User',
            (string) $entity->getId(),
            [
                'userName' => $userName,
                'type' => $type,
                'rolesIds' => $entity->getLinkMultipleIdList('roles'),
            ],
        );
    }
}
