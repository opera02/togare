<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\Fatura;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Entities\User;
use Espo\Modules\TogareCore\Entities\Fatura;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Define `assignedUser` = current user na criação se vazio (Story 6.3
 * Decisão #10 — RBAC own scope para Advogado/Assistente via assignedUser).
 *
 * Order = 15 (executa após Validate=10 e antes de Audit=50).
 *
 * @implements BeforeSave<Fatura>
 */
final class DefaultFaturaAssignmentHook implements BeforeSave
{
    public static int $order = 15;

    public function __construct(
        private readonly User $user,
    ) {
    }

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (! $entity instanceof Fatura) {
            return;
        }
        if (! $entity->isNew()) {
            return;
        }

        $assignedUserId = (string) ($entity->get('assignedUserId') ?? '');
        if ($assignedUserId !== '') {
            return;
        }

        $currentUserId = (string) ($this->user->getId() ?? '');
        if ($currentUserId !== '') {
            $entity->set('assignedUserId', $currentUserId);
        }
    }
}
