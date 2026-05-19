<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\User;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Entities\User;
use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Audit afterSave em `User`: emite `user.created` ou `user.updated`
 * conforme `isNew()`. Atualizações só geram audit se mudaram atributos
 * dentro da allowlist de campos sensíveis (evita ruído de touch ao
 * salvar perfil sem mudança real).
 *
 * @implements AfterSave<User>
 */
final class UserCrudAudit implements AfterSave
{
    public static int $order = 50;

    /** @var list<string> Allowlist de atributos cuja mudança merece audit. */
    private const SENSITIVE_FIELDS = [
        'userName',
        'firstName',
        'lastName',
        'emailAddress',
        'isActive',
        'type',
        'rolesIds',
        'teamsIds',
        'isAdmin',
    ];

    public function __construct(
        private readonly AuditLogContract $auditLog,
    ) {
    }

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (! $entity instanceof User) {
            return;
        }

        $userId = (string) $entity->getId();

        if ($entity->isNew()) {
            $this->auditLog->log(
                'user.created',
                'User',
                $userId,
                [
                    'userName' => $entity->get('userName'),
                    'type' => $entity->get('type'),
                    'isAdmin' => (bool) $entity->get('isAdmin'),
                    'rolesIds' => $entity->getLinkMultipleIdList('roles'),
                ],
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

        $before = [];
        $after = [];
        foreach ($changed as $field) {
            if ($field === 'rolesIds' || $field === 'teamsIds') {
                // Link-multiple: get/getFetched do *Ids retornam null/stale.
                // getLinkMultipleIdList consulta a relation table corretamente.
                // 'before' usa getFetched (pode ser null se a relation não foi
                // hidratada antes do save; aceito como best-effort) e 'after'
                // usa a API canônica.
                $linkName = $field === 'rolesIds' ? 'roles' : 'teams';
                $beforeIds = $entity->getFetched($field);
                $before[$field] = \is_array($beforeIds) ? $beforeIds : null;
                $after[$field] = $entity->getLinkMultipleIdList($linkName);
                continue;
            }
            $before[$field] = $entity->getFetched($field);
            $after[$field] = $entity->get($field);
        }

        $this->auditLog->log(
            'user.updated',
            'User',
            $userId,
            [
                'userName' => $entity->get('userName'),
                'changedFields' => $changed,
                'before' => $before,
                'after' => $after,
            ],
        );
    }
}
