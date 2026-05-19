<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\Documento;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Entities\User;
use Espo\Modules\TogareCore\Entities\Documento;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Auto-set uploadedBy + assignedUser na criação do Documento.
 *
 * uploadedBy = $this->user (usuário logado).
 * assignedUser = cadeia de herança 3-elos (Story 5.6 Decisão #6):
 *   1. Processo.assignedUserId, se processoId setado;
 *   2. Cliente.assignedUserId, se clienteId setado;
 *   3. Prazo.assignedUserId, se prazoId setado;
 *   4. fallback: user logado (preserva ACL by-assignment ainda assim).
 *
 * Order = 15 (depois de Validate=10, antes de Move=30).
 *
 * Defensivo \Throwable em torno de leituras de link — auto-fill nunca pode
 * bloquear save (pattern DefaultDataCumprimentoHook da 4a.5.1).
 *
 * @implements BeforeSave<Documento>
 */
final class DefaultUploadedByHook implements BeforeSave
{
    public static int $order = 15;

    public function __construct(
        private readonly User $user,
        private readonly EntityManager $entityManager,
    ) {
    }

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (! $entity instanceof Documento) {
            return;
        }

        if (! $entity->isNew()) {
            return;
        }

        $entity->set('uploadedById', $this->user->getId());

        $existingAssigned = (string) ($entity->get('assignedUserId') ?? '');
        if ($existingAssigned !== '') {
            return;
        }

        // Tenta herdar assignedUser do Processo/Cliente vinculado.
        try {
            $processoId = (string) ($entity->get('processoId') ?? '');
            $processo = $processoId !== ''
                ? $this->entityManager->getEntityById('Processo', $processoId)
                : $entity->get('processo');
            if ($processo instanceof Entity) {
                $procAssigned = (string) ($processo->get('assignedUserId') ?? '');
                if ($procAssigned !== '') {
                    $entity->set('assignedUserId', $procAssigned);
                    return;
                }
            }

            $clienteId = (string) ($entity->get('clienteId') ?? '');
            $cliente = $clienteId !== ''
                ? $this->entityManager->getEntityById('Cliente', $clienteId)
                : $entity->get('cliente');
            if ($cliente instanceof Entity) {
                $cliAssigned = (string) ($cliente->get('assignedUserId') ?? '');
                if ($cliAssigned !== '') {
                    $entity->set('assignedUserId', $cliAssigned);
                    return;
                }
            }

            $prazoId = (string) ($entity->get('prazoId') ?? '');
            $prazo = $prazoId !== ''
                ? $this->entityManager->getEntityById('Prazo', $prazoId)
                : $entity->get('prazo');
            if ($prazo instanceof Entity) {
                $przAssigned = (string) ($prazo->get('assignedUserId') ?? '');
                if ($przAssigned !== '') {
                    $entity->set('assignedUserId', $przAssigned);
                    return;
                }
            }

            // Fallback: assigned = uploadedBy (ainda preserva ACL).
            $entity->set('assignedUserId', $this->user->getId());
        } catch (\Throwable $e) {
            TogareLogger::event(
                'warning',
                'documento.default_assigned_failed',
                'DefaultUploadedByHook: falha ao inferir assignedUser; fallback para uploadedBy',
                ['error' => $e->getMessage(), 'documentoId' => (string) ($entity->getId() ?? 'new')],
            );
            $entity->set('assignedUserId', $this->user->getId());
        }
    }
}
