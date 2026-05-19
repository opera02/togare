<?php

declare(strict_types=1);

namespace Espo\Modules\TogareRbac\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\TogareRbac\Service\Mfa\MfaPolicyResolver;
use Espo\Modules\TogareRbac\Service\Wizard\WizardService;
use stdClass;

/**
 * Endpoints REST do wizard pós-primeiro-login (Story 2.6, FR34).
 *
 * P11: gate de acesso movido do construtor para checkWizardAccess() chamado
 * em cada action — evita side-effects em construção lazy/cached pelo framework.
 *
 * Endpoints sob /api/v1/TogareRbacWizard/action/*:
 *  - GET  /state             → estado corrente + roles + dados.
 *  - POST /applyOrgInfo      → passo 1.
 *  - POST /applyPrimaryColor → passo 2.
 *  - POST /confirmRoles      → passo 3.
 *  - POST /inviteBatch       → passo 4.
 *  - POST /complete          → marcar concluído (skipped opcional).
 */
class TogareRbacWizard
{
    public function __construct(
        private readonly User $user,
        private readonly WizardService $wizardService,
        private readonly MfaPolicyResolver $mfaResolver,
    ) {
    }

    public function getActionState(Request $request): stdClass
    {
        $this->checkWizardAccess();
        $state = $this->wizardService->getState($this->user);

        return (object) $state;
    }

    public function postActionApplyOrgInfo(Request $request): stdClass
    {
        $this->checkWizardAccess();
        $body = $request->getParsedBody();
        $companyName = \is_object($body) && isset($body->companyName) ? (string) $body->companyName : '';

        // P26: trim whitespace antes de verificar string vazia.
        $rawLogo = \is_object($body) && isset($body->companyLogoFileId)
            ? \trim((string) $body->companyLogoFileId)
            : '';
        $companyLogoFileId = $rawLogo !== '' ? $rawLogo : null;

        $this->wizardService->applyOrgInfo($this->user, $companyName, $companyLogoFileId);

        return (object) ['step' => 1, 'status' => 'applied'];
    }

    public function postActionApplyPrimaryColor(Request $request): stdClass
    {
        $this->checkWizardAccess();
        $body = $request->getParsedBody();
        $primaryColor = \is_object($body) && isset($body->primaryColor) ? (string) $body->primaryColor : '';

        $this->wizardService->applyPrimaryColor($this->user, $primaryColor);

        return (object) ['step' => 2, 'status' => 'applied', 'togarePrimaryColor' => $primaryColor];
    }

    public function postActionConfirmRoles(Request $request): stdClass
    {
        $this->checkWizardAccess();
        $body = $request->getParsedBody();
        $renameMap = [];

        if (\is_object($body) && isset($body->roleRenameMap)) {
            // P27: rejeitar se roleRenameMap for array (JSON array em vez de objeto).
            if (\is_array($body->roleRenameMap)) {
                throw new BadRequest('roleRenameMap deve ser um objeto JSON, não um array.');
            }
            if (\is_object($body->roleRenameMap)) {
                foreach ((array) $body->roleRenameMap as $oldName => $newName) {
                    $renameMap[(string) $oldName] = \is_string($newName) ? $newName : '';
                }
            }
        }

        // P33: incluir skipped[] na resposta para feedback no frontend.
        $result = $this->wizardService->confirmRoles($this->user, $renameMap);

        return (object) [
            'step' => 3,
            'status' => 'applied',
            'renamedCount' => \count($result['renamed']),
            'skipped' => $result['skipped'],
        ];
    }

    public function postActionInviteBatch(Request $request): stdClass
    {
        $this->checkWizardAccess();
        $body = $request->getParsedBody();
        $invitees = [];

        if (\is_object($body) && isset($body->invitees) && \is_array($body->invitees)) {
            foreach ($body->invitees as $idx => $row) {
                // P28: rejeitar linha não-objeto com BadRequest em vez de ignorar silenciosamente.
                if (!\is_object($row)) {
                    throw new BadRequest(\sprintf('Linha %d: formato inválido. Esperado objeto JSON.', $idx + 1));
                }
                $invitees[] = [
                    'userName' => isset($row->userName) ? (string) $row->userName : '',
                    'emailAddress' => isset($row->emailAddress) ? (string) $row->emailAddress : '',
                    'firstName' => isset($row->firstName) ? (string) $row->firstName : '',
                    'lastName' => isset($row->lastName) ? (string) $row->lastName : '',
                    'roleIds' => isset($row->roleIds) && \is_array($row->roleIds)
                        ? \array_values(\array_map('strval', $row->roleIds))
                        : [],
                ];
            }
        }

        $result = $this->wizardService->inviteBatch($this->user, $invitees);

        $response = new stdClass();
        $response->step = 4;
        $response->status = 'applied';
        $response->succeeded = $result['succeeded'];
        $response->failed = $result['failed'];

        return $response;
    }

    public function postActionComplete(Request $request): stdClass
    {
        $this->checkWizardAccess();
        $body = $request->getParsedBody();

        // P29: (bool) "false" → true em PHP; usar filter_var para parse correto.
        $skipped = false;
        if (\is_object($body) && isset($body->skipped)) {
            $parsed = \filter_var($body->skipped, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $skipped = $parsed ?? false;
        }

        $this->wizardService->markCompleted($this->user, $skipped);

        $response = new stdClass();
        $response->wizardCompleted = true;
        $response->skipped = $skipped;
        $response->completedAt = (string) $this->user->get('togareWizardCompletedAt');

        return $response;
    }

    /**
     * P11: gate de acesso por método (não no construtor) para evitar side-effects
     * em instanciação lazy/cached pelo EspoCRM DI container.
     */
    private function checkWizardAccess(): void
    {
        if ($this->user->isPortal() || $this->user->isApi()) {
            throw new Forbidden('Endpoint não disponível para este tipo de usuário.');
        }

        if (!$this->mfaResolver->isMfaRequired($this->user)) {
            throw new Forbidden('Apenas Sócio/Admin pode acessar o wizard de instalação.');
        }
    }
}
