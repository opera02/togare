<?php

declare(strict_types=1);

namespace Espo\Modules\TogarePortalUi\Tools\PortalAccess\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\TogarePortalUi\Tools\PortalAccess\ProvisionService;

/**
 * Api action: libera o acesso ao Portal do Cliente para um `Cliente`
 * (Story 7a.2, AC1). Rota `POST /TogarePortalUi/PortalAccess/provision`
 * (Resources/routes.json).
 *
 * A5 (retro Épico 6 — 1 Controller fino por entity): action class própria
 * do módulo no padrão nativo `Tools/UserSecurity/Api/*`. NÃO toca o
 * `Controllers/Cliente.php` do togare-core.
 *
 * ACL: fail-closed admin-only. Provisionar acesso de Portal é operação de
 * Sócio/Admin; portal users e usuários regulares não podem disparar.
 * Verificação explícita (`$user->isAdmin()`) além da ACL de rota — defesa
 * em profundidade (mesma postura do isolamento A4).
 */
class PostProvision implements Action
{
    public function __construct(
        private readonly ProvisionService $provisionService,
        private readonly User $user,
    ) {
    }

    public function process(Request $request): Response
    {
        if (!$this->user->isAdmin()) {
            throw new Forbidden("Apenas administradores podem liberar acesso ao Portal.");
        }

        $data = $request->getParsedBody();

        $clienteId = is_object($data) ? ($data->clienteId ?? null) : null;

        if (!is_string($clienteId) || $clienteId === '') {
            throw new BadRequest("Informe o `clienteId`.");
        }

        $result = $this->provisionService->provisionForCliente($clienteId);

        return ResponseComposer::json($result);
    }
}
