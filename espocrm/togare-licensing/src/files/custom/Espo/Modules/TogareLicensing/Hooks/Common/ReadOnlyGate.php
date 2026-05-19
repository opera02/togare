<?php

declare(strict_types=1);

namespace Espo\Modules\TogareLicensing\Hooks\Common;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Hook\Hook\BeforeRemove;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Utils\Metadata;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareLicensing\Service\ReadOnlyGate as ReadOnlyGateService;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\RemoveOptions;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Hook global ReadOnlyGate — bloqueia INSERT/UPDATE/DELETE em entidades de
 * módulos premium quando licença está em modo read-only.
 *
 * Discovery: lê metadata `togarePremium.module` no entityDef. Entidades sem
 * essa flag passam direto (return early — <1ms overhead).
 *
 * Hook em Hooks/Common/ → roda em TODAS as entidades. Performance ok porque
 * dispatch por metadata cacheado.
 */
class ReadOnlyGate implements BeforeSave, BeforeRemove
{
    public static int $order = 1;

    public function __construct(
        private readonly Metadata $metadata,
        private readonly EntityManager $entityManager,
    ) {
    }

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $this->guard($entity, 'save');
    }

    public function beforeRemove(Entity $entity, RemoveOptions $options): void
    {
        $this->guard($entity, 'remove');
    }

    private function guard(Entity $entity, string $operation): void
    {
        $module = $this->metadata->get([
            'entityDefs',
            $entity->getEntityType(),
            'togarePremium',
            'module',
        ]);

        if (! \is_string($module) || $module === '') {
            // Entidade não-premium: sai imediatamente.
            return;
        }

        $gate = new ReadOnlyGateService($this->entityManager);
        if (! $gate->isBlocked($module)) {
            return;
        }

        $info = $gate->getStatus($module);
        $expiresAt = $info['expiresAt'] ?? null;

        TogareLogger::event('warning', 'licensing.read_only_gate.blocked', 'Operação bloqueada por licença expirada', [
            'module' => $module,
            'entity' => $entity->getEntityType(),
            'operation' => $operation,
        ]);

        throw new Forbidden(
            "Módulo '{$module}' em modo read-only — licença expirada"
            . ($expiresAt !== null ? " em {$expiresAt}" : '')
            . '. Contate o Sócio/Admin para renovar.',
        );
    }
}
