<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Controllers\Record;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\TogareCore\Entities\Fatura as FaturaEntity;
use Espo\Modules\TogareCore\Services\FaturaSaldoService;

/**
 * Stock Record controller para Fatura (Story 6.3 — T6.1).
 *
 * Espo `Record` cobre CRUD vanilla (POST/GET/PUT/DELETE /api/v1/Fatura).
 *
 * Action customizada `postActionCancelar`:
 *   POST /api/v1/Fatura/action/cancelar
 *   Body: { "id": "...", "motivo": "..." }
 *
 * Decisão #11 da Story 6.3: cancelamento é admin-only (Sócio/Admin) com
 * confirmação textual no frontend ("digite CANCELAR"). Motivo obrigatório
 * ≥10 chars.
 */
class Fatura extends Record
{
    private const MIN_MOTIVO_LENGTH = 10;

    /**
     * @throws BadRequest
     * @throws NotFound
     * @throws Forbidden
     */
    public function postActionCancelar(Request $request, Response $response): bool
    {
        $data = $request->getParsedBody();
        if (! \is_object($data) && ! \is_array($data)) {
            throw new BadRequest('Body inválido.');
        }
        $body = \is_array($data) ? $data : (array) $data;

        $faturaId = (string) ($body['id'] ?? '');
        $motivo = (string) ($body['motivo'] ?? '');

        if ($faturaId === '') {
            throw new BadRequest('Fatura não informada.');
        }
        if (\mb_strlen(\trim($motivo)) < self::MIN_MOTIVO_LENGTH) {
            throw new BadRequest('Informe o motivo do cancelamento (mínimo ' . self::MIN_MOTIVO_LENGTH . ' caracteres).');
        }

        $entityManager = $this->entityManager;
        $fatura = $entityManager->getEntityById(FaturaEntity::ENTITY_TYPE, $faturaId);
        if (! $fatura instanceof FaturaEntity) {
            throw new NotFound('Fatura não encontrada.');
        }

        // ACL: precisa permissão de DELETE (proxy para "ação destrutiva administrativa")
        // — coerente com togare-rbac V009 (Sócio/Admin + Financeiro têm full).
        if (! $this->acl->checkEntity($fatura, 'delete')) {
            throw new Forbidden('Sem permissão para cancelar esta fatura.');
        }

        $service = $this->injectableFactory->create(FaturaSaldoService::class);
        $ok = $service->transitionStatus($faturaId, FaturaEntity::STATUS_CANCELADA, \trim($motivo));

        if (! $ok) {
            throw new BadRequest('Não foi possível cancelar esta fatura.');
        }

        return true;
    }
}
