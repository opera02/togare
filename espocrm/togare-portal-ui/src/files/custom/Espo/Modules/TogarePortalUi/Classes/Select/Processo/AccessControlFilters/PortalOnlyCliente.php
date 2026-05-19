<?php

declare(strict_types=1);

namespace Espo\Modules\TogarePortalUi\Classes\Select\Processo\AccessControlFilters;

use Espo\Core\Select\AccessControl\Filter;
use Espo\Entities\User;
use Espo\ORM\EntityManager;
use Espo\ORM\Name\Attribute;
use Espo\ORM\Query\SelectBuilder;

/**
 * Filtro de isolamento de dados do Portal para `Processo` (Story 7a.2,
 * AC5 — FR26 "vejo apenas os meus processos, nunca de outro cliente").
 *
 * Resolução nativa: PortalRole semeado com `Processo.read = own` →
 * `DefaultPortalFilterResolver` retorna o nome de filtro `portalOnlyOwn` →
 * `selectDefs/Processo.json` mapeia `portalOnlyOwn` para ESTA classe
 * (mesmo padrão do core `Modules\Crm\...\AccessControlFilters\
 * PortalOnlyAccount`). Aplica-se a list/search/related panels/REST list.
 * O acesso by-id é coberto pelo OwnershipChecker irmão (AC4 + audit).
 *
 * Estratégia (espelha PortalOnlyAccount — id-list, sem join, previsível e
 * testável): restringe a `Processo` vinculados (via `ClienteProcesso`) ao
 * `Cliente` do portal user (`User.togareCliente`). Sem `togareCliente`
 * vinculado, ou Cliente sem processos → conjunto vazio (`id = null`):
 * nega TUDO (fail-closed; nunca lista de terceiros).
 *
 * Bindings (interface `Filter`): `Espo\Entities\User` (o portal user
 * atual); `EntityManager` resolvido pelo InjectableFactory.
 */
class PortalOnlyCliente implements Filter
{
    public function __construct(
        private User $user,
        private EntityManager $entityManager,
    ) {
    }

    public function apply(SelectBuilder $queryBuilder): void
    {
        $clienteId = $this->user->get('togareClienteId');

        if (!is_string($clienteId) || $clienteId === '') {
            $queryBuilder->where([Attribute::ID => null]);

            return;
        }

        $cliente = $this->entityManager->getEntityById('Cliente', $clienteId);

        if (!$cliente) {
            $queryBuilder->where([Attribute::ID => null]);

            return;
        }

        $processoIdList = [];

        $processoList = $this->entityManager
            ->getRDBRepository('Cliente')
            ->getRelation($cliente, 'processos')
            ->select([Attribute::ID])
            ->find();

        foreach ($processoList as $processo) {
            $processoIdList[] = $processo->getId();
        }

        if (!count($processoIdList)) {
            $queryBuilder->where([Attribute::ID => null]);

            return;
        }

        $queryBuilder->where([Attribute::ID => $processoIdList]);
    }
}
