<?php

declare(strict_types=1);

namespace Espo\Modules\TogarePortalUi\Classes\AclPortal\Processo;

use Espo\Core\Acl\OwnershipOwnChecker;
use Espo\Entities\User;
use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * OwnershipChecker de Portal para `Processo` (Story 7a.2 — Action Item A4,
 * AC#4 NÃO DEFERÍVEL).
 *
 * Resolução nativa: com PortalRole `Processo.read = own`, o portal
 * `DefaultAccessChecker` chama `checkOwnershipOwn` → ESTE checker no acesso
 * by-id (REST `GET /Processo/{id}`, related panel single fetch). Pattern
 * nativo idêntico a `Espo\Classes\AclPortal\Note\OwnershipChecker`,
 * registrado via `aclDefs/Processo.json.portalOwnershipCheckerClassName`.
 *
 * Regra: o Processo "pertence" ao portal user sse está vinculado (via
 * `ClienteProcesso`) ao `Cliente` do user (`User.togareCliente`). Caso
 * contrário é tentativa de acesso cruzado:
 *  - grava `portal.acesso_cruzado_tentado` no audit append-only
 *    (`AuditLogContract::log`, NFR10, convenção dot-separated pt-BR);
 *  - retorna false → `Core\Record\Service` lança `ForbiddenSilent`
 *    ⇒ HTTP **403** sem corpo (zero vazamento de dados de terceiro — AC4).
 *
 * @implements OwnershipOwnChecker<Entity>
 */
class OwnershipChecker implements OwnershipOwnChecker
{
    public function __construct(
        private EntityManager $entityManager,
        private AuditLogContract $auditLog,
    ) {
    }

    public function checkOwn(User $user, Entity $entity): bool
    {
        $clienteId = $user->get('togareClienteId');

        if (is_string($clienteId) && $clienteId !== '') {
            $linked = $this->entityManager
                ->getRDBRepository('Processo')
                ->getRelation($entity, 'clientes')
                ->where(['id' => $clienteId])
                ->findOne();

            if ($linked) {
                return true;
            }
        }

        $this->auditLog->log(
            'portal.acesso_cruzado_tentado',
            'Processo',
            $entity->getId(),
            [
                'portalUserId' => $user->getId(),
                'portalClienteId' => is_string($clienteId) ? $clienteId : null,
                'targetEntityType' => 'Processo',
                'targetRecordId' => $entity->getId(),
            ],
        );

        return false;
    }
}
