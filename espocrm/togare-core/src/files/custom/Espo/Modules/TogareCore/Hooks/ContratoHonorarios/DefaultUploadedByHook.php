<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\ContratoHonorarios;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Entities\User;
use Espo\Modules\TogareCore\Entities\ContratoHonorarios;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Auto-set uploadedBy + assignedUser na criação do ContratoHonorarios.
 *
 * uploadedBy = $this->user (usuário logado).
 *
 * assignedUser = herdado de cliente.assignedUserId (Decisão #10 da spec +
 * Dev Decision D10.1.1):
 *  - Cliente é OBRIGATÓRIO (N:1) — não há cadeia 3-elos como no Documento.
 *  - Pattern simples: tenta carregar cliente, lê assignedUserId, atribui.
 *  - Fallback: assigned = uploadedBy (preserva ACL `own` para roles que
 *    sejam advogado/assistente do próprio contrato).
 *
 * Esse pattern habilita a Decisão #10 da spec (Advogado/Assistente read OWN
 * via ACL native do Espo) SEM precisar de Classes/Select/OwnByClienteAssignment
 * custom. Idiomático.
 *
 * Order = 15 (depois de Validate=10, antes de Move=30).
 *
 * Defensivo \Throwable — auto-fill nunca pode bloquear save (pattern
 * DefaultDataCumprimentoHook da 4a.5.1).
 *
 * @implements BeforeSave<ContratoHonorarios>
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
        if (! $entity instanceof ContratoHonorarios) {
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

        try {
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

            // Fallback: assigned = uploadedBy (preserva ACL own pro autor).
            $entity->set('assignedUserId', $this->user->getId());
        } catch (\Throwable $e) {
            TogareLogger::event(
                'warning',
                'contrato.default_assigned_failed',
                'DefaultUploadedByHook: falha ao herdar assignedUser do cliente; fallback para uploadedBy',
                ['error' => $e->getMessage(), 'contratoId' => (string) ($entity->getId() ?? 'new')],
            );
            $entity->set('assignedUserId', $this->user->getId());
        }
    }
}
