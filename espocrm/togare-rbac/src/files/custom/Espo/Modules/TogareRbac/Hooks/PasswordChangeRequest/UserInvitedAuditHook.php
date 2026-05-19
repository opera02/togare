<?php

declare(strict_types=1);

namespace Espo\Modules\TogareRbac\Hooks\PasswordChangeRequest;

use DateTimeImmutable;
use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Utils\Config;
use Espo\Entities\PasswordChangeRequest;
use Espo\Entities\User;
use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Hook afterSave em PasswordChangeRequest: quando uma request é criada (via
 * `sendAccessInfoForNewUser` do core EspoCRM ou via password recovery), emite
 * log estruturado de audit `event=user.invited` com {userId, email,
 * rolesNames, expiresAt}.
 *
 * Cobre AC4 da Story 2.2 (FR1 do PRD). Substitui temporariamente o audit log
 * append-only da Story 2.4 (ainda backlog) — quando 2.4 chegar, este mesmo
 * evento poderá ser duplicado para a tabela `togare_audit_log` via subscriber
 * adicional.
 *
 * Hook anexado em PasswordChangeRequest (não User) porque a request é criada
 * **depois** dos hooks de User durante o flow de invite — afterSave em User
 * não vê a request ainda.
 *
 * @implements AfterSave<PasswordChangeRequest>
 */
final class UserInvitedAuditHook implements AfterSave
{
    public static int $order = 50;

    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly Config $config,
        private readonly AuditLogContract $auditLog,
    ) {
    }

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (! $entity instanceof PasswordChangeRequest) {
            return;
        }

        if (! $entity->isNew()) {
            return;
        }

        $userId = $entity->get('userId');
        if (! \is_string($userId) || $userId === '') {
            return;
        }

        $user = $this->entityManager->getEntityById(User::ENTITY_TYPE, $userId);
        if (! $user instanceof User) {
            return;
        }

        $lifetimeHours = (int) ($this->config->get('passwordChangeRequestNewUserLifetime') ?? 168);
        $createdAt = $entity->get('createdAt');
        $expiresAt = $createdAt
            ? (new DateTimeImmutable($createdAt))->modify("+{$lifetimeHours} hours")->format('c')
            : (new DateTimeImmutable())->modify("+{$lifetimeHours} hours")->format('c');

        $rolesNames = $this->resolveRoleNames($user);

        $context = [
            'userId' => $userId,
            'userName' => $user->get('userName'),
            'email' => $user->get('emailAddress'),
            'rolesNames' => $rolesNames,
            'expiresAt' => $expiresAt,
            'lifetimeHours' => $lifetimeHours,
        ];

        TogareLogger::event(
            'info',
            'user.invited',
            \sprintf(
                "Convite enviado: user '%s' (%s).",
                (string) $user->get('userName'),
                $rolesNames === [] ? 'sem role' : \implode(', ', $rolesNames),
            ),
            $context,
        );

        // Dual-write: persiste em togare_audit_log (Story 2.4 — FR37 + NFR10).
        $this->auditLog->log('user.invited', 'User', $userId, $context);
    }

    /**
     * @return list<string>
     */
    private function resolveRoleNames(User $user): array
    {
        $ids = $user->getLinkMultipleIdList('roles');
        if ($ids === []) {
            return [];
        }

        $names = [];
        foreach ($ids as $id) {
            // EspoCRM 9.3: getLinkMultipleName(field, id). Lê o name do hash
            // já carregado em loadLinkMultipleField; se não carregado, fallback
            // pra repository.
            $name = null;
            if (\method_exists($user, 'getLinkMultipleName')) {
                $name = $user->getLinkMultipleName('roles', $id);
            }
            if ($name === null) {
                $role = $this->entityManager->getEntityById('Role', $id);
                $name = $role?->get('name');
            }
            if (\is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }
}
