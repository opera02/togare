<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\Processo;

use Espo\Core\ApplicationState;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\TogareCore\Entities\Processo;
use Espo\Modules\TogareCore\Services\PrivilegedActorChecker;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Implementa **ACL by-assignment** para Processo (Story 3.5, FR11).
 *
 * Roda **antes** dos demais hooks (order=5; NormalizeCnj=10, ValidateProcessoFields=20,
 * ResolveTpuFields=30, AuditProcesso=50) — assim, se o salvamento for ilegítimo
 * por reatribuição não-autorizada, dispara 403 antes de gastar ciclo em validar
 * CNJ ou bater no Redis pra TPU lookup.
 *
 * **Política aplicada:**
 *
 *  1. **Auto-titular em create.** Se Advogado (não-privileged) cria processo
 *     sem `assignedUserId`, o sistema atribui automaticamente o user corrente
 *     como titular (AC7). Sócio/Admin sem `assignedUserId` deixa null — precisa
 *     atribuir explicitamente (regra de produto: dono do escritório nunca
 *     "esquece" um processo na fila de ninguém).
 *
 *  2. **Mudança de atribuição em update.** Quando `assignedUserId` ou
 *     `collaboratorsIds` mudam, somente é permitido se o user corrente:
 *      - É privileged actor (Sócio/Admin ou system superuser EspoCRM); OU
 *      - É o titular pré-existente (`fetched.assignedUserId === user.id`).
 *
 *  3. **Caso contrário** → `Forbidden` com `X-Status-Reason: assignment-not-allowed`
 *     e mensagem pt-BR "Apenas o titular ou Sócio/Admin pode alterar a atribuição
 *     deste processo." (chave i18n `Processo.labels.assignmentNotAllowed`).
 *
 * **Quem testa o quê:**
 *
 *  - A ACL nativa do EspoCRM (`scopes.collaborators=true` + `aclDefs` +
 *    role `read=own`) já bloqueia o acesso de não-titulares/não-colaboradores
 *    antes mesmo do hook rodar (HTTP 403 na list/get). Este hook complementa
 *    cobrindo o caso do **colaborador** que tem read/edit own mas tenta
 *    reatribuir sem ser titular — caminho que a ACL declarativa por si só
 *    não cobre.
 *
 * **Falha do ApplicationState** (sem usuário corrente, ex.: install via CLI,
 * migration job, scheduledJob): hook NÃO bloqueia — let it pass. Operações
 * sistêmicas não são alvo desta política. Verificamos `appState->hasUser()`
 * primeiro.
 *
 * @implements BeforeSave<Processo>
 */
final class EnforceAssignmentPolicyHook implements BeforeSave
{
    public static int $order = 5;

    public const REASON_ASSIGNMENT_NOT_ALLOWED = 'assignment-not-allowed';

    public function __construct(
        private readonly ApplicationState $applicationState,
        private readonly PrivilegedActorChecker $privilegedActorChecker,
    ) {
    }

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (! $entity instanceof Processo) {
            return;
        }

        if (! $this->applicationState->hasUser()) {
            return;
        }

        $user = $this->applicationState->getUser();
        if ($user === null) {
            return;
        }

        $isPrivileged = $this->privilegedActorChecker->isPrivileged($user);

        if ($entity->isNew()) {
            $this->handleCreate($entity, $user->getId(), $isPrivileged);
            return;
        }

        $this->handleUpdate($entity, $user->getId(), $isPrivileged);
    }

    /**
     * Auto-titular: Advogado (não-privileged) que cria processo sem assignedUser
     * vira titular automaticamente. Sócio/Admin precisa atribuir explicitamente.
     */
    private function handleCreate(Processo $entity, ?string $userId, bool $isPrivileged): void
    {
        if ($userId === null) {
            return;
        }

        if ($isPrivileged) {
            return;
        }

        $assignedUserId = $entity->get('assignedUserId');
        if ($assignedUserId === null || $assignedUserId === '') {
            $entity->set('assignedUserId', $userId);
        }
    }

    /**
     * Em update, se assignedUserId ou collaboratorsIds mudaram, só permite
     * se o user corrente é privileged ou era o titular pré-existente.
     */
    private function handleUpdate(Processo $entity, ?string $userId, bool $isPrivileged): void
    {
        if (! $this->isAssignmentChanged($entity)) {
            return;
        }

        if ($isPrivileged) {
            return;
        }

        $previousTitularId = $entity->getFetched('assignedUserId');

        if ($userId !== null && $userId === $previousTitularId) {
            return;
        }

        throw Forbidden::createWithBody(
            self::REASON_ASSIGNMENT_NOT_ALLOWED,
            (string) \json_encode([
                'reason' => self::REASON_ASSIGNMENT_NOT_ALLOWED,
                'messageTranslation' => [
                    'label' => 'assignmentNotAllowed',
                    'scope' => 'Processo',
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }

    private function isAssignmentChanged(Processo $entity): bool
    {
        if ($entity->isAttributeChanged('assignedUserId')) {
            return true;
        }

        if ($entity->isAttributeChanged('collaboratorsIds')) {
            return true;
        }

        return false;
    }
}
